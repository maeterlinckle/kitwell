<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Auth;
use App\Core\Config;
use App\Models\UserToken;

/**
 * The two messages that carry a link into somebody's account: the invite for a
 * new user, and the self-service password reset.
 *
 * Issuing the link and sending it live together on purpose. A token written to
 * the database but never emailed is a link nobody can use; an email sent
 * without one is a message nobody can act on. Both halves belong to whichever
 * of these methods the caller chose, and there is no way to do one without the
 * other.
 *
 * Neither method throws. Mailer::send() logs its own failures, and the callers
 * — creating a user, asking for a reset — have to carry on sensibly when the
 * mail server is having a bad afternoon.
 */
final class AccountMail
{
    /**
     * Can this installation actually send one of these?
     *
     * Everything downstream branches on this: the user form shows password
     * fields when it is false, and the forgotten-password page explains itself
     * rather than offering a flow that cannot finish.
     */
    public static function isAvailable(): bool
    {
        return Mailer::isReady();
    }

    /**
     * Invite a new user to set their own password.
     *
     * @param array<string,mixed> $user
     */
    public static function sendInvite(array $user, ?string $invitedByName = null): bool
    {
        $hours = UserToken::expiryHours(UserToken::INVITE);
        $token = UserToken::issue((int) $user['id'], UserToken::INVITE, $hours, Auth::id());

        return Mailer::sendTemplate(
            'user_invite',
            (string) $user['email'],
            (string) $user['name'],
            [
                'email'      => (string) $user['email'],
                'role_name'  => (string) ($user['role_name'] ?? ''),
                'invited_by' => $invitedByName ?? (Auth::name() ?? 'An administrator'),
                'invite_url' => self::link('/invite/' . $token),
                'expires_in' => UserToken::describeExpiry($hours),
            ],
            ['entity_type' => 'user', 'entity_id' => (int) $user['id'], 'trigger' => 'user']
        );
    }

    /**
     * Send a password-reset link.
     *
     * @param array<string,mixed> $user
     */
    public static function sendPasswordReset(array $user): bool
    {
        $hours = UserToken::expiryHours(UserToken::RESET);

        // No created_by: nobody is signed in, and recording the requester as
        // the account's owner would be a guess dressed up as a fact.
        $token = UserToken::issue((int) $user['id'], UserToken::RESET, $hours, null);

        return Mailer::sendTemplate(
            'password_reset',
            (string) $user['email'],
            (string) $user['name'],
            [
                'email'        => (string) $user['email'],
                'reset_url'    => self::link('/reset-password/' . $token),
                'expires_in'   => UserToken::describeExpiry($hours),
                'requested_at' => date('j M Y, H:i'),
            ],
            ['entity_type' => 'user', 'entity_id' => (int) $user['id'], 'trigger' => 'system']
        );
    }

    /**
     * An absolute URL for a link in an email.
     *
     * `url()` produces a site-relative path, which is right in a page and
     * useless in a mail client. APP_URL is the only thing that knows the
     * public address of this installation, and if it is unset the message is
     * still worth sending — a bare path at least tells the reader where to go
     * once they are on the right host.
     */
    private static function link(string $path): string
    {
        $base = rtrim((string) Config::get('app.url', ''), '/');

        return $base === '' ? $path : $base . $path;
    }
}
