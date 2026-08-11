<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Request;

/**
 * Single-use, expiring links: the invite that lets a new user set their own
 * password, and the self-service password reset.
 *
 * Both are the same object with a different purpose, so they share one table
 * and one lifecycle — issue, expire, consume — rather than two near-identical
 * implementations that can drift apart on the details that matter.
 *
 * Three rules, and none of them is optional:
 *
 *   1. **The token is hashed at rest.** `issue()` returns the only copy of the
 *      raw value, and it goes straight into an email. A database dump is
 *      therefore not a set of working account-takeover links.
 *   2. **Issuing invalidates the outstanding ones.** "Resend the invite" must
 *      not leave the previous link working, or revoking a mis-sent invite would
 *      be impossible.
 *   3. **Lookup is constant in shape.** consume() checks expiry and prior use
 *      separately from existence, so the caller can say *why* a link failed —
 *      an expired invite has a resend path, and telling somebody "not found"
 *      when the truth is "you left it a week" wastes everyone's time.
 */
final class UserToken
{
    public const INVITE = 'invite';
    public const RESET  = 'password_reset';

    /** Why a token could not be used. */
    public const OK      = 'ok';
    public const UNKNOWN = 'unknown';
    public const EXPIRED = 'expired';
    public const USED    = 'used';

    /**
     * Issue a link and return the raw token — the only time it exists.
     *
     * 32 bytes from random_bytes: the same strength as the calendar feed token,
     * and for the same reason. This value is the entire credential.
     */
    public static function issue(int $userId, string $purpose, int $expiresInHours, ?int $createdBy): string
    {
        self::revokeAll($userId, $purpose);

        $token = bin2hex(random_bytes(32));

        Database::insert('user_tokens', [
            'user_id'    => $userId,
            'purpose'    => $purpose,
            'token_hash' => self::hash($token),
            'expires_at' => date('Y-m-d H:i:s', time() + ($expiresInHours * 3600)),
            'created_by' => $createdBy,
            'created_ip' => Request::ip(),
        ]);

        return $token;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Look a token up without consuming it — for rendering the form.
     *
     * @return array{status:string,token:array<string,mixed>|null,user:array<string,mixed>|null}
     */
    public static function inspect(string $token, string $purpose): array
    {
        $blank = ['status' => self::UNKNOWN, 'token' => null, 'user' => null];

        // Cheap guard, and it keeps a pathological string out of the query.
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return $blank;
        }

        $row = Database::selectOne(
            'SELECT * FROM user_tokens WHERE token_hash = ? AND purpose = ?',
            [self::hash($token), $purpose]
        );

        if ($row === null) {
            return $blank;
        }

        $user = User::find((int) $row['user_id']);

        if ($user === null) {
            return $blank;
        }

        if ($row['used_at'] !== null) {
            return ['status' => self::USED, 'token' => $row, 'user' => $user];
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return ['status' => self::EXPIRED, 'token' => $row, 'user' => $user];
        }

        return ['status' => self::OK, 'token' => $row, 'user' => $user];
    }

    /**
     * Mark a token used.
     *
     * The `used_at IS NULL` in the WHERE clause is the actual protection
     * against a double submission: two requests arriving together both see an
     * unused row, and only one of them updates it.
     */
    public static function consume(int $tokenId): bool
    {
        Database::run('UPDATE user_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL', [$tokenId]);

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM user_tokens WHERE id = ? AND used_at IS NOT NULL',
            [$tokenId]
        ) > 0;
    }

    /** Expire every outstanding link of one kind for one user. */
    public static function revokeAll(int $userId, string $purpose): void
    {
        Database::run(
            'UPDATE user_tokens SET expires_at = NOW() - INTERVAL 1 SECOND
              WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()',
            [$userId, $purpose]
        );
    }

    /**
     * The state of a user's invite, for the user list and the edit page.
     *
     * 'none' — never invited (created with a password, the pre-email way)
     * 'pending' | 'expired' | 'accepted'
     *
     * @return array<int,string> Keyed by user id
     */
    public static function inviteStates(): array
    {
        $rows = Database::select(
            "SELECT user_id,
                    MAX(used_at IS NOT NULL)                            AS accepted,
                    MAX(used_at IS NULL AND expires_at > NOW())          AS pending
               FROM user_tokens
              WHERE purpose = 'invite'
              GROUP BY user_id"
        );

        $states = [];

        foreach ($rows as $row) {
            $states[(int) $row['user_id']] = match (true) {
                (int) $row['accepted'] === 1 => 'accepted',
                (int) $row['pending'] === 1  => 'pending',
                default                      => 'expired',
            };
        }

        return $states;
    }

    /** @return array<string,mixed>|null */
    public static function latest(int $userId, string $purpose): ?array
    {
        return Database::selectOne(
            'SELECT * FROM user_tokens WHERE user_id = ? AND purpose = ? ORDER BY id DESC LIMIT 1',
            [$userId, $purpose]
        );
    }

    /**
     * How long a link of each kind lasts, in hours.
     *
     * Clamped rather than trusted: a setting of 0 would issue links that expire
     * before the mail is delivered, and one of 100000 would issue links that
     * never expire at all. Both are more likely to be a typo than an intention.
     */
    public static function expiryHours(string $purpose): int
    {
        return $purpose === self::INVITE
            ? max(1, min(720, Setting::int('invite_expiry_hours', 72)))
            : max(1, min(168, Setting::int('password_reset_expiry_hours', 2)));
    }

    /** "3 days" / "2 hours" — for the email and the on-screen copy. */
    public static function describeExpiry(int $hours): string
    {
        if ($hours % 24 === 0) {
            $days = intdiv($hours, 24);

            return $days === 1 ? '24 hours' : $days . ' days';
        }

        return $hours === 1 ? '1 hour' : $hours . ' hours';
    }
}
