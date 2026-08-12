<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\LoginThrottle;
use App\Core\Request;
use App\Core\Session;
use App\Core\Totp;
use App\Mail\Mailer;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\TrustedDevice;
use App\Models\User;

/**
 * The second factor: what is enrolled, what is required, and what happens
 * between the password being right and the user actually being signed in.
 *
 * **The pending state is a session key, not a login.** `Auth::attempt()` stops
 * short of signing anybody in when a challenge is owed and leaves
 * `__2fa_pending` behind instead. Until that is answered there is no
 * `__auth_user_id`, so every `auth` route, every permission check and every
 * template helper behaves exactly as it does for a stranger. Nothing had to be
 * taught about a "half signed-in" user, because there is no such thing here.
 *
 * **The emailed code lives in that same session state, hashed.** A six-digit
 * code that is only valid for one sign-in attempt does not need a table, a
 * cleanup job, or a row left behind by every abandoned login. It does need an
 * attempt counter, and that is next to it.
 *
 * **Attempts are rate-limited on the sign-in counters.** Six digits is a
 * million guesses, which is nothing to a script, so wrong codes are recorded in
 * `login_attempts` exactly as wrong passwords are. Guessing a code therefore
 * burns the same budget as guessing a password, and the account locks either
 * way.
 */
final class TwoFactor
{
    private const PENDING = '__2fa_pending';

    public const METHOD_TOTP  = 'totp';
    public const METHOD_EMAIL = 'email';

    // -- Policy -------------------------------------------------------------

    /** Is every account required to have a second factor? */
    public static function requiredForEveryone(): bool
    {
        return Setting::bool('two_factor_required', false);
    }

    /** Can this installation send a code by email at all? */
    public static function emailAvailable(): bool
    {
        return Mailer::isReady();
    }

    /** @param array<string,mixed> $user */
    public static function hasTotp(array $user): bool
    {
        return !empty($user['totp_confirmed_at']) && !empty($user['totp_secret']);
    }

    /** @param array<string,mixed> $user */
    public static function isEnabled(array $user): bool
    {
        return (int) ($user['two_factor_enabled'] ?? 0) === 1;
    }

    /**
     * Which method would this account be challenged with?
     *
     * An authenticator app if one is enrolled; otherwise an emailed code, and
     * only if the server can actually send one. Null means "no second factor is
     * possible for this account right now", which is the case an administrator
     * needs to see before switching the requirement on for everybody.
     *
     * @param array<string,mixed> $user
     */
    public static function methodFor(array $user): ?string
    {
        if (self::hasTotp($user)) {
            return self::METHOD_TOTP;
        }

        return self::emailAvailable() ? self::METHOD_EMAIL : null;
    }

    /**
     * Does this sign-in owe a challenge?
     *
     * @param array<string,mixed> $user
     */
    public static function challengeRequired(array $user): bool
    {
        if (self::isEnabled($user)) {
            return true;
        }

        // Required site-wide but not yet set up: still a challenge, and the
        // challenge screen is where they are sent to set it up.
        return self::requiredForEveryone();
    }

    /**
     * Site-wide enforcement with nothing able to deliver a code would lock
     * every account out of the application at once. The settings screen refuses
     * it, and says so.
     */
    public static function canEnforceSiteWide(): bool
    {
        return self::emailAvailable();
    }

    // -- The pending state --------------------------------------------------

    /**
     * Park a verified password until the second factor is answered.
     *
     * Deliberately regenerates the session id: the id that carried an anonymous
     * visitor must not be the one that ends up carrying a signed-in user, and
     * this is the moment the two are separated.
     */
    public static function beginChallenge(int $userId, string $method): void
    {
        Session::regenerate();

        Session::put(self::PENDING, [
            'user_id'   => $userId,
            'method'    => $method,
            'started'   => time(),
            'attempts'  => 0,
            'code_hash' => null,
            'code_until'=> 0,
        ]);
    }

    /** @return array<string,mixed>|null */
    public static function pending(): ?array
    {
        $state = Session::get(self::PENDING);

        if (!is_array($state) || !isset($state['user_id'])) {
            return null;
        }

        // A challenge left open for a long time is an abandoned tab, not
        // somebody still signing in.
        if (time() - (int) $state['started'] > 900) {
            self::clear();

            return null;
        }

        return $state;
    }

    /** @return array<string,mixed>|null */
    public static function pendingUser(): ?array
    {
        $state = self::pending();

        return $state === null ? null : User::find((int) $state['user_id']);
    }

    public static function clear(): void
    {
        Session::forget(self::PENDING);
    }

    /** @param array<string,mixed> $changes */
    private static function updatePending(array $changes): void
    {
        $state = self::pending();

        if ($state !== null) {
            Session::put(self::PENDING, array_merge($state, $changes));
        }
    }

    // -- Emailed codes ------------------------------------------------------

    public static function codeMinutes(): int
    {
        return max(1, min(60, Setting::int('email_otp_minutes', 10)));
    }

    public static function maxAttempts(): int
    {
        return max(3, min(10, Setting::int('two_factor_max_attempts', 5)));
    }

    /**
     * Send a one-time code to the pending user, and remember its hash.
     *
     * Returns false when the message could not be sent — the caller has to say
     * so rather than leaving somebody waiting for an email that is not coming.
     */
    public static function sendEmailCode(): bool
    {
        $state = self::pending();
        $user  = self::pendingUser();

        if ($state === null || $user === null) {
            return false;
        }

        // Issuing costs an attempt on the sign-in counters too, so "resend"
        // cannot be used to post mail at a chosen address indefinitely.
        LoginThrottle::record((string) $user['email'], Request::ip(), false);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $sent = Mailer::sendTemplate(
            'two_factor_code',
            (string) $user['email'],
            (string) $user['name'],
            [
                'code'       => $code,
                'minutes'    => (string) self::codeMinutes(),
                'ip_address' => Request::ip(),
                'device'     => TrustedDevice::describeBrowser(Request::userAgent()),
            ],
            ['entity_type' => 'user', 'entity_id' => (int) $user['id'], 'trigger' => 'system']
        );

        if (!$sent) {
            return false;
        }

        // Hashed, so the session store is not a list of live codes. Not
        // password_hash: this is high-entropy-per-attempt only because it is
        // rate limited and short lived, and a slow hash on every page of a
        // login flow buys nothing a counter does not.
        self::updatePending([
            'code_hash'  => hash('sha256', $code),
            'code_until' => time() + self::codeMinutes() * 60,
        ]);

        return true;
    }

    // -- Verifying ----------------------------------------------------------

    /**
     * Check a submitted code against whichever method is in play, plus the
     * backup codes, which are always accepted.
     *
     * @return array{ok:bool,error:?string,used_backup:bool}
     */
    public static function verify(string $submitted): array
    {
        $state = self::pending();
        $user  = self::pendingUser();

        if ($state === null || $user === null) {
            return ['ok' => false, 'error' => 'That sign-in took too long. Please start again.', 'used_backup' => false];
        }

        $submitted = trim($submitted);

        // The counter is the real defence against six digits, so it moves
        // before anything is compared.
        $attempts = (int) $state['attempts'] + 1;
        self::updatePending(['attempts' => $attempts]);

        LoginThrottle::record((string) $user['email'], Request::ip(), false);

        if ($attempts > self::maxAttempts()) {
            self::clear();

            ActivityLog::recordAs(
                (int) $user['id'],
                (string) $user['name'],
                'two_factor_failed',
                'user',
                (int) $user['id'],
                'Too many wrong two-factor codes; the sign-in was abandoned'
            );

            return ['ok' => false, 'error' => 'Too many wrong codes. Please sign in again.', 'used_backup' => false];
        }

        // A backup code works whatever the method — that is what it is for.
        if (self::consumeBackupCode((int) $user['id'], $submitted)) {
            return ['ok' => true, 'error' => null, 'used_backup' => true];
        }

        $ok = match ($state['method']) {
            self::METHOD_TOTP  => self::verifyTotp($user, $submitted),
            self::METHOD_EMAIL => self::verifyEmailCode($state, $submitted),
            default            => false,
        };

        if (!$ok) {
            $left = self::maxAttempts() - $attempts;

            return [
                'ok'    => false,
                'error' => $left > 0
                    ? 'That code was not right. ' . $left . ' attempt(s) left.'
                    : 'That code was not right.',
                'used_backup' => false,
            ];
        }

        return ['ok' => true, 'error' => null, 'used_backup' => false];
    }

    /** @param array<string,mixed> $user */
    private static function verifyTotp(array $user, string $code): bool
    {
        $secret = self::secretFor($user);

        return $secret !== null && Totp::verify($secret, $code);
    }

    /** @param array<string,mixed> $state */
    private static function verifyEmailCode(array $state, string $code): bool
    {
        if (empty($state['code_hash']) || time() > (int) $state['code_until']) {
            return false;
        }

        return hash_equals((string) $state['code_hash'], hash('sha256', preg_replace('/\s+/', '', $code) ?? ''));
    }

    // -- Secrets ------------------------------------------------------------

    /**
     * The stored TOTP secret, decrypted.
     *
     * Null when it will not decrypt — a changed APP_KEY, most likely. Treated
     * as "no secret" rather than passed on as a wrong one, so the failure is
     * "your codes stopped working" rather than a stream of confusing rejections.
     *
     * @param array<string,mixed> $user
     */
    public static function secretFor(array $user): ?string
    {
        $stored = (string) ($user['totp_secret'] ?? '');

        return $stored === '' ? null : Crypto::decrypt($stored);
    }

    /**
     * Store a secret against a user, encrypted.
     *
     * Returns false when it could not be encrypted — Crypto fails closed, and
     * a TOTP secret in the clear is a shared password, so the caller has to
     * refuse rather than fall back.
     */
    public static function storeSecret(int $userId, string $secret): bool
    {
        $ciphertext = Crypto::encrypt($secret);

        if ($ciphertext === null) {
            return false;
        }

        User::update($userId, ['totp_secret' => $ciphertext, 'totp_confirmed_at' => null]);

        return true;
    }

    /** Enrolment is finished only when a code from the app has been verified. */
    public static function confirmTotp(int $userId): void
    {
        User::update($userId, [
            'totp_confirmed_at'  => date('Y-m-d H:i:s'),
            'two_factor_enabled' => 1,
        ]);
    }

    /**
     * Turn the second factor off for one account.
     *
     * Everything goes at once: the secret, the unused backup codes and every
     * trusted device. A leftover from a previous enrolment is a way back in
     * that nobody is thinking about.
     */
    public static function disable(int $userId): void
    {
        User::update($userId, [
            'two_factor_enabled' => 0,
            'totp_secret'        => null,
            'totp_confirmed_at'  => null,
        ]);

        Database::run('DELETE FROM user_backup_codes WHERE user_id = ?', [$userId]);
        TrustedDevice::forgetAll($userId);
    }

    /** Switch on email codes without an authenticator app. */
    public static function enableEmailOnly(int $userId): void
    {
        User::update($userId, ['two_factor_enabled' => 1]);
    }

    // -- Backup codes -------------------------------------------------------

    /**
     * Ten fresh codes, replacing any that already exist.
     *
     * The alphabet leaves out the characters people mistranscribe — no O/0, no
     * I/1/L — because these get written on paper and typed back months later,
     * in a hurry, by somebody who has lost their phone.
     *
     * @return array<int,string> The plain codes: the only time they exist
     */
    public static function issueBackupCodes(int $userId, int $count = 10): array
    {
        Database::run('DELETE FROM user_backup_codes WHERE user_id = ?', [$userId]);

        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $codes    = [];

        for ($i = 0; $i < $count; $i++) {
            $code = '';

            for ($c = 0; $c < 10; $c++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            // Shown in two groups, stored without the separator.
            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);

            Database::insert('user_backup_codes', [
                'user_id'   => $userId,
                'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            ]);
        }

        return $codes;
    }

    /** How many are left to use. */
    public static function backupCodesLeft(int $userId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );
    }

    /**
     * Spend a backup code if the submitted string is one.
     *
     * Every unused hash is checked rather than looked up, because the stored
     * form is a salted slow hash — there is nothing to look up by. Ten
     * password_verify calls is the cost of not being able to index them, and
     * ten is the whole list.
     */
    private static function consumeBackupCode(int $userId, string $submitted): bool
    {
        $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $submitted) ?? '');

        if (strlen($normalised) !== 10) {
            return false;
        }

        $rows = Database::select(
            'SELECT id, code_hash FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );

        foreach ($rows as $row) {
            if (password_verify($normalised, (string) $row['code_hash'])) {
                Database::run('UPDATE user_backup_codes SET used_at = NOW() WHERE id = ?', [(int) $row['id']]);

                return true;
            }
        }

        return false;
    }

    // -- Completing the sign-in ---------------------------------------------

    /**
     * Finish a challenged sign-in.
     *
     * The one place that turns a pending state into a session, so the audit
     * entry and the trusted-device cookie both have exactly one home.
     */
    public static function completeSignIn(bool $trustDevice, bool $usedBackupCode): ?array
    {
        $user = self::pendingUser();

        if ($user === null) {
            return null;
        }

        $userId = (int) $user['id'];

        self::clear();
        Auth::login($userId);

        User::touchLogin($userId, Request::ip());
        LoginThrottle::clear((string) $user['email'], Request::ip());

        ActivityLog::record(
            'login',
            'user',
            $userId,
            $usedBackupCode
                ? 'Signed in with a backup code (' . self::backupCodesLeft($userId) . ' left)'
                : 'Signed in with two-factor authentication'
        );

        if ($trustDevice) {
            self::setDeviceCookie(TrustedDevice::remember($userId));

            ActivityLog::record(
                'device_trusted',
                'user',
                $userId,
                'Trusted ' . TrustedDevice::describeBrowser(Request::userAgent())
                . ' for ' . TrustedDevice::days() . ' days'
            );
        }

        return $user;
    }

    /** The cookie carrying a trusted-device token. */
    public static function setDeviceCookie(string $token): void
    {
        setcookie(TrustedDevice::COOKIE, $token, [
            'expires'  => time() + TrustedDevice::days() * 86400,
            'path'     => '/',
            'secure'   => Request::isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearDeviceCookie(): void
    {
        setcookie(TrustedDevice::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => Request::isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** The token this browser is presenting, if any. */
    public static function deviceToken(): string
    {
        return (string) ($_COOKIE[TrustedDevice::COOKIE] ?? '');
    }
}
