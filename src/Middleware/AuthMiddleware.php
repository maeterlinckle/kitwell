<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\PasswordPolicy;

final class AuthMiddleware
{
    /**
     * Where somebody with an expired password is allowed to go.
     *
     * Setting the new password, and leaving. Nothing else: an expiry that can
     * be clicked past is not an expiry.
     */
    private const ALLOWED_WHILE_EXPIRED = [
        '/password/expired',
        '/logout',
    ];

    public static function handle(): void
    {
        if (!Auth::check()) {
            if (Request::isAjax()) {
                Response::json(['error' => 'Not signed in.'], 401);
            }

            // Remember where they were headed so login can send them back.
            if (Request::method() === 'GET') {
                Session::put('__intended_url', Request::path());
            }

            Response::redirect('/login');
        }

        self::stopIfPasswordExpired();
    }

    /**
     * An expired password interrupts the session rather than the sign-in.
     *
     * Refusing at the door would be the obvious place, but it leaves somebody
     * with a correct password and no way back in — which is the failure this
     * feature exists to avoid, not to cause. So the sign-in succeeds, and every
     * page after it lands here until a new password is set. It is enforced in
     * the `auth` middleware rather than route by route because a rule that has
     * to be remembered on each of a few hundred routes is a rule that will be
     * missed on one of them.
     */
    private static function stopIfPasswordExpired(): void
    {
        $user = Auth::user();

        if ($user === null || !PasswordPolicy::hasExpired($user)) {
            return;
        }

        $path = Request::path();

        foreach (self::ALLOWED_WHILE_EXPIRED as $allowed) {
            if ($path === $allowed) {
                return;
            }
        }

        if (Request::isAjax()) {
            Response::json(['error' => 'Your password has expired. Sign in again to set a new one.'], 403);
        }

        if (Request::method() === 'GET') {
            Session::put('__intended_url', $path);
        }

        Response::redirect('/password/expired');
    }
}