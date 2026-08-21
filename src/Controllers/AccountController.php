<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\LoginThrottle;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Mail\AccountMail;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserToken;
use App\Models\PasswordPolicy;

/**
 * The two ways somebody gets into an account without an administrator typing a
 * password for them: accepting an invitation, and resetting a forgotten
 * password.
 *
 * Both routes are open to the world, so the rules are stricter than anywhere
 * else in the application:
 *
 *   - **The response never reveals whether an address is registered.** Asking
 *     for a reset says the same thing either way. An open form that answers
 *     "no such user" is a way to enumerate the staff list of an organisation.
 *   - **Requests are throttled** on the same counters as failed sign-ins, so
 *     this cannot become an unmetered way to send mail to a chosen address.
 *   - **A link is used once**, and using it invalidates every other outstanding
 *     link for that account.
 *   - **Setting a password never signs anybody in.** Proving control of a
 *     mailbox is enough to *set* a password; the password is then what gets you
 *     in, and it goes through the ordinary sign-in path with its own throttle
 *     and its own audit entry.
 *   - **Nothing degrades into a dead end.** With no SMTP configured, the
 *     forgotten-password page says so and names what to do instead, rather than
 *     offering a form whose submit button cannot possibly work.
 */
final class AccountController extends Controller
{
    // -- Invitations --------------------------------------------------------

    public function showInvite(string $token): void
    {
        $result = UserToken::inspect($token, UserToken::INVITE);

        View::render('auth/invite', [
            'pageTitle' => 'Set your password',
            'status'    => $result['status'],
            'user'      => $result['user'],
            'token'     => $token,
        ], 'layouts/auth');
    }

    public function acceptInvite(string $token): void
    {
        $result = UserToken::inspect($token, UserToken::INVITE);

        if ($result['status'] !== UserToken::OK) {
            // Re-render rather than redirect: the page already knows how to
            // explain an expired or spent link, and a flash on the sign-in
            // page would lose that.
            $this->showInvite($token);

            return;
        }

        $user     = $result['user'];
        $password = $this->validatedPassword('/invite/' . $token, $user);

        if (!UserToken::consume((int) $result['token']['id'])) {
            $this->showInvite($token);

            return;
        }

        User::updatePassword((int) $user['id'], $password);

        // Activating here is the point of the exercise: an invited account is
        // created switched off in all but name, and accepting is what turns it
        // into one somebody can sign in to.
        User::update((int) $user['id'], ['is_active' => 1]);

        // Any reset link issued in the meantime is stale now.
        UserToken::revokeAll((int) $user['id'], UserToken::RESET);
        LoginThrottle::clear((string) $user['email'], Request::ip());

        ActivityLog::recordAs(
            (int) $user['id'],
            (string) $user['name'],
            'invite_accepted',
            'user',
            (int) $user['id'],
            $user['name'] . ' accepted their invitation and set a password'
        );

        Flash::success('Your password is set. Sign in with it below.');
        Response::redirect('/login');
    }

    // -- Forgotten password -------------------------------------------------

    public function showForgot(): void
    {
        View::render('auth/forgot-password', [
            'pageTitle' => 'Forgotten password',
            'available' => AccountMail::isAvailable(),
        ], 'layouts/auth');
    }

    public function sendReset(): void
    {
        if (!AccountMail::isAvailable()) {
            // Nothing to do and nothing to hide: the page says as much.
            $this->showForgot();

            return;
        }

        $email = trim((string) Request::post('email', ''));
        $ip    = Request::ip();

        $validator = Validator::make(
            ['email' => $email],
            ['email' => 'required|email|max:190'],
            ['email' => 'Email address']
        );

        if ($validator->failed()) {
            Flash::errors($validator->errors());
            Flash::old(['email' => $email]);
            Response::redirect('/forgot-password');
        }

        // Shares the sign-in counters deliberately. Somebody working through a
        // list of addresses is doing the same thing here as they would be at
        // the sign-in form, and one budget is harder to slip past than two.
        if (LoginThrottle::isLocked($email, $ip)) {
            Flash::warning('Too many attempts from here just now. Try again in a few minutes.');
            Response::redirect('/forgot-password');
        }

        $user = User::findByEmail($email);

        // The counter moves whether or not the address exists. If it only moved
        // on a miss, the lockout behaviour would itself say which addresses are
        // real — the one thing the wording above is careful not to.
        LoginThrottle::record($email, $ip, false);

        if ($user !== null && (int) $user['is_active'] === 1) {
            $sent = AccountMail::sendPasswordReset($user);

            ActivityLog::recordAs(
                null,
                'System',
                'password_reset_requested',
                'user',
                (int) $user['id'],
                sprintf('A password reset was requested for %s (%s)', $user['email'], $sent ? 'sent' : 'failed')
            );
        }

        // One message, whatever happened above — including when the send itself
        // failed. Somebody watching for a different answer learns nothing; the
        // failure is in the email log, where an administrator will see it.
        Flash::success('If that address has an account here, a link to reset the password is on its way. It expires in '
            . UserToken::describeExpiry(UserToken::expiryHours(UserToken::RESET)) . '.');

        Response::redirect('/login');
    }

    public function showReset(string $token): void
    {
        $result = UserToken::inspect($token, UserToken::RESET);

        View::render('auth/reset-password', [
            'pageTitle' => 'Choose a new password',
            'status'    => $result['status'],
            'user'      => $result['user'],
            'token'     => $token,
        ], 'layouts/auth');
    }

    public function resetPassword(string $token): void
    {
        $result = UserToken::inspect($token, UserToken::RESET);

        if ($result['status'] !== UserToken::OK) {
            $this->showReset($token);

            return;
        }

        $user     = $result['user'];
        $password = $this->validatedPassword('/reset-password/' . $token, $user);

        if (!UserToken::consume((int) $result['token']['id'])) {
            $this->showReset($token);

            return;
        }

        User::updatePassword((int) $user['id'], $password);
        UserToken::revokeAll((int) $user['id'], UserToken::INVITE);

        // A forgotten password and a locked-out account arrive together often
        // enough that leaving the lockout in place would send them straight
        // back here. Proving control of the mailbox is a better signal than the
        // failed attempts that caused the lock.
        LoginThrottle::clear((string) $user['email'], Request::ip());

        ActivityLog::recordAs(
            (int) $user['id'],
            (string) $user['name'],
            'password_reset',
            'user',
            (int) $user['id'],
            $user['name'] . ' reset their password using an emailed link'
        );

        Flash::success('Your password has been changed. Sign in with it below.');
        Response::redirect('/login');
    }

    // -- Shared -------------------------------------------------------------

    /**
     * The password rules, in one place, matching the ones an administrator is
     * held to on /admin/users.
     *
     * The account is passed in because the policy may be overridden for it —
     * a shared account can be held to different rules from everybody else, and
     * the rule applied when its password is *set* has to be the same one
     * `PasswordPolicy` will judge it by afterwards.
     *
     * @param array<string,mixed>|null $user
     */
    private function validatedPassword(string $redirectTo, ?array $user = null): string
    {
        $this->validate([
            'password'              => 'required|max:255|' . PasswordPolicy::rule($user),
            'password_confirmation' => 'required|matches:password',
        ], [
            'password'              => 'Password',
            'password_confirmation' => 'Confirmation',
        ], $redirectTo);

        // Read from $_POST rather than the validated array for the same reason
        // Admin\UserController does: validate() trims and normalises, and a
        // password is bytes the user typed, trailing space and all.
        return (string) ($_POST['password'] ?? '');
    }
}
