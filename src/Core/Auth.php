<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\ActivityLog;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\TwoFactor;

/**
 * Authentication and the read side of role-based access control.
 *
 * Permissions are data (roles -> role_permissions -> permissions), so new roles
 * and finer-grained permissions can be added without touching the schema.
 */
final class Auth
{
    private const SESSION_KEY = '__auth_user_id';

    /** @var array<string,mixed>|null */
    private static ?array $user = null;
    /** @var array<int,string>|null */
    private static ?array $permissions = null;

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function guest(): bool
    {
        return !self::check();
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $id = Session::get(self::SESSION_KEY);
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }

        $user = User::findActive((int) $id);
        if ($user === null) {
            self::forgetSession();

            return null;
        }

        self::$user = $user;

        return self::$user;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function name(): string
    {
        return (string) (self::user()['name'] ?? 'Guest');
    }

    public static function roleSlug(): ?string
    {
        $user = self::user();

        return $user === null ? null : (string) $user['role_slug'];
    }

    public static function isAdmin(): bool
    {
        return self::roleSlug() === 'admin';
    }

    /**
     * Attempt a login.
     *
     * Returns an error message on failure, null on success — where "success"
     * now means one of two things, and the caller has to ask which:
     *
     *   - signed in, if no second factor is owed;
     *   - **password accepted, challenge pending**, if one is. In that case no
     *     session is created at all. `TwoFactor::pending()` is what says so,
     *     and until it is answered the request is anonymous to every route,
     *     permission check and template helper in the application. There is no
     *     half-signed-in user to have taught anything about.
     */
    public static function attempt(string $email, string $password): ?string
    {
        $ip     = Request::ip();
        $email  = mb_strtolower(trim($email));
        $config = (array) Config::get('security.login');

        if (LoginThrottle::isLocked($email, $ip)) {
            $minutes = (int) $config['lockout_minutes'];
            LoginThrottle::record($email, $ip, false);

            return "Too many failed sign-in attempts. Please try again in {$minutes} minutes.";
        }

        $user = User::findByEmail($email);

        // Always run a hash comparison so the response time does not reveal
        // whether the account exists.
        $hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $ok   = password_verify($password, (string) $hash);

        if (!$ok || $user === null) {
            LoginThrottle::record($email, $ip, false);

            return 'Those sign-in details were not recognised.';
        }

        if ((int) $user['is_active'] !== 1) {
            LoginThrottle::record($email, $ip, false);

            return 'That account has been deactivated. Please contact an administrator.';
        }

        // Re-hashing the same password is housekeeping, not a password change:
        // `false` keeps it from revoking every trusted device the first time
        // PASSWORD_DEFAULT moves on.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            User::updatePassword((int) $user['id'], $password, false);
        }

        LoginThrottle::record($email, $ip, true);

        $userId  = (int) $user['id'];
        $trusted = false;

        // The password was right. Whether that is enough depends on the second
        // factor, and on whether this browser has already answered one.
        if (TwoFactor::challengeRequired($user)) {
            $trusted = TrustedDevice::isTrusted($userId, TwoFactor::deviceToken());

            if (!$trusted) {
                // Note what this does *not* do: no session, no "Signed in"
                // audit entry, no clearing of the lockout counter. None of them
                // has been earned yet, and the request stays anonymous until
                // the challenge is answered.
                //
                // methodFor() returning null means the account has no
                // authenticator and the server cannot send email either. That
                // is not a refusal — refusing would lock everybody out the
                // moment SMTP broke — it is what sends the challenge screen to
                // enrolment instead.
                TwoFactor::beginChallenge($userId, (string) TwoFactor::methodFor($user));

                return null;
            }
        }

        LoginThrottle::clear($email, $ip);

        self::login($userId);

        User::touchLogin($userId, $ip);
        ActivityLog::record(
            'login',
            'user',
            $userId,
            $trusted ? 'Signed in from a trusted device (no code needed)' : 'Signed in'
        );

        return null;
    }

    /**
     * Act as this user for the rest of the request, with no session.
     *
     * The API's way in. Everything downstream — `can()`, `id()`, the audit
     * trail — then behaves exactly as it does for the same person in a browser,
     * which is what makes "an API key never allows more than its owner could
     * already do" a property of the code rather than a promise.
     *
     * Deliberately writes no session and rotates no CSRF token: an API request
     * must not leave the caller signed in, and a browser session must not be
     * created by presenting a key.
     *
     * @param array<string,mixed> $user A row from User::findActive()
     */
    public static function actAs(array $user): void
    {
        self::$user        = $user;
        self::$permissions = null;
    }

    public static function login(int $userId): void
    {
        Session::regenerate();
        Session::put(self::SESSION_KEY, $userId);
        Csrf::rotate();

        self::$user        = null;
        self::$permissions = null;
    }

    public static function logout(): void
    {
        if (self::check()) {
            ActivityLog::record('logout', 'user', self::id(), 'Signed out');
        }

        self::forgetSession();
        Session::destroy();
    }

    private static function forgetSession(): void
    {
        Session::forget(self::SESSION_KEY);
        self::$user        = null;
        self::$permissions = null;
    }

    /** @return array<int,string> */
    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }

        $user = self::user();
        if ($user === null) {
            return self::$permissions = [];
        }

        $rows = Database::select(
            'SELECT p.slug
               FROM permissions p
               INNER JOIN role_permissions rp ON rp.permission_id = p.id
              WHERE rp.role_id = ?',
            [(int) $user['role_id']]
        );

        return self::$permissions = array_map(static fn (array $r): string => (string) $r['slug'], $rows);
    }

    /**
     * Does the signed-in user hold this permission?
     *
     * Supports a trailing wildcard: can('assets.*').
     */
    public static function can(string $permission): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }

        // The admin role is a superuser by design; everything else is explicit.
        if ((int) ($user['role_is_superuser'] ?? 0) === 1) {
            return true;
        }

        $held = self::permissions();

        if (str_ends_with($permission, '.*')) {
            $prefix = substr($permission, 0, -1);
            foreach ($held as $slug) {
                if (str_starts_with($slug, $prefix)) {
                    return true;
                }
            }

            return false;
        }

        return in_array($permission, $held, true);
    }

    /** True when the user holds at least one of the given permissions. */
    public static function canAny(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** Abort with 403 unless the permission is held. */
    public static function authorize(string $permission): void
    {
        if (self::can($permission)) {
            return;
        }

        if (Request::isAjax()) {
            Response::json(['error' => 'You do not have permission to do that.'], 403);
        }

        View::renderError(403, 'Not permitted', 'You do not have permission to do that. If you think you should, ask an administrator to review your role.');
        exit;
    }
}
