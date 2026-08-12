<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\ActivityLog;
use App\Models\TrustedDevice;
use App\Services\TwoFactor;

/**
 * The challenge that stands between a correct password and a session.
 *
 * Every route here is in the `guest` group, which is exactly right: there is no
 * session yet. What identifies the request is the pending state
 * `Auth::attempt()` left in it, and every action starts by checking that is
 * still there — a bookmarked `/two-factor` with nothing pending is not an
 * error to explain, it is somebody who needs to sign in.
 */
final class TwoFactorController extends Controller
{
    /** The code entry screen. */
    public function challenge(): void
    {
        $state = TwoFactor::pending();
        $user  = TwoFactor::pendingUser();

        if ($state === null || $user === null) {
            Response::redirect('/login');
        }

        // No method at all: required site-wide, no authenticator enrolled, and
        // no SMTP to send a code with. Enrolment is the only way forward.
        if ($state['method'] === '' || $state['method'] === 'null') {
            Response::redirect('/two-factor/setup');
        }

        // Email codes are sent when the screen is first reached, not when the
        // password is submitted: a code that goes out before anybody has seen a
        // page is a code that has already started expiring.
        if ($state['method'] === TwoFactor::METHOD_EMAIL && empty($state['code_hash'])) {
            if (!TwoFactor::sendEmailCode()) {
                Flash::error('The code could not be emailed. Tell an administrator — the reason is in the email log.');
            }
        }

        $state = TwoFactor::pending();

        View::render('auth/two-factor', [
            'pageTitle'   => 'Two-factor authentication',
            'method'      => $state['method'],
            'user'        => $user,
            'minutes'     => TwoFactor::codeMinutes(),
            'backupCodes' => TwoFactor::backupCodesLeft((int) $user['id']),
            'trustDays'   => TrustedDevice::days(),
        ], 'layouts/auth');
    }

    public function verify(): void
    {
        if (TwoFactor::pending() === null) {
            Response::redirect('/login');
        }

        $result = TwoFactor::verify((string) Request::post('code', ''));

        if (!$result['ok']) {
            // A cleared pending state means the attempt budget ran out, so
            // there is nothing left to go back to.
            if (TwoFactor::pending() === null) {
                Flash::error((string) $result['error']);
                Response::redirect('/login');
            }

            Flash::error((string) $result['error']);
            Response::redirect('/two-factor');
        }

        $user = TwoFactor::completeSignIn(Request::boolean('trust_device'), (bool) $result['used_backup']);

        if ($user === null) {
            Response::redirect('/login');
        }

        if ($result['used_backup']) {
            $left = TwoFactor::backupCodesLeft((int) $user['id']);

            Flash::warning($left === 0
                ? 'That was your last backup code. Generate a new set from your account page.'
                : 'You signed in with a backup code. ' . $left . ' left — each one works once.');
        }

        Flash::success('Welcome back, ' . $user['name'] . '.');

        $intended = Session::pull('__intended_url');
        Response::redirect(is_string($intended) && $intended !== '' ? $intended : '/');
    }

    /** Send another emailed code. */
    public function resend(): void
    {
        $state = TwoFactor::pending();

        if ($state === null) {
            Response::redirect('/login');
        }

        if ($state['method'] !== TwoFactor::METHOD_EMAIL) {
            Response::redirect('/two-factor');
        }

        if (TwoFactor::sendEmailCode()) {
            Flash::success('A new code is on its way. The previous one no longer works.');
        } else {
            Flash::error('That code could not be sent. Tell an administrator — the reason is in the email log.');
        }

        Response::redirect('/two-factor');
    }

    /**
     * Enrolment at sign-in, for an account that has to have a second factor and
     * does not yet.
     *
     * Reached only from a pending challenge, so it is still an anonymous
     * request — which is why it cannot simply reuse the profile page.
     */
    public function setup(): void
    {
        $user = TwoFactor::pendingUser();

        if ($user === null) {
            Response::redirect('/login');
        }

        View::render('auth/two-factor-setup', [
            'pageTitle'      => 'Set up two-factor authentication',
            'user'           => $user,
            'emailAvailable' => TwoFactor::emailAvailable(),
        ], 'layouts/auth');
    }

    /** Take the email-code option and carry on into the challenge. */
    public function setupEmail(): void
    {
        $user = TwoFactor::pendingUser();

        if ($user === null) {
            Response::redirect('/login');
        }

        if (!TwoFactor::emailAvailable()) {
            Flash::error('Email is not configured, so codes cannot be sent. Ask an administrator to set up an authenticator app for you.');
            Response::redirect('/two-factor/setup');
        }

        TwoFactor::enableEmailOnly((int) $user['id']);

        ActivityLog::recordAs(
            (int) $user['id'],
            (string) $user['name'],
            'two_factor_enabled',
            'user',
            (int) $user['id'],
            'Turned on two-factor authentication by email'
        );

        TwoFactor::beginChallenge((int) $user['id'], TwoFactor::METHOD_EMAIL);

        Response::redirect('/two-factor');
    }

    /** Abandon the sign-in. */
    public function cancel(): void
    {
        TwoFactor::clear();
        Flash::info('Sign-in cancelled.');

        Response::redirect('/login');
    }
}
