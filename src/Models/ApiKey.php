<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * An API key: a way to act as one user without a browser.
 *
 * The secret exists exactly once, in the response to the request that created
 * it. After that only its SHA-256 is on disk, so a copy of the database is not
 * a set of working credentials and "show me that key again" has one honest
 * answer: issue a new one.
 */
final class ApiKey
{
    /**
     * Prefix on every key.
     *
     * Not decoration. A distinctive, greppable prefix is what lets a secret
     * scanner recognise one of these in a commit or a paste, and what lets a
     * person looking at a config file know what they are holding.
     */
    public const PREFIX = 'ark_';

    public const SCOPES = ['read' => 'Read only (GET)', 'full' => 'Full access, within the user’s permissions'];

    private const SELECT = 'SELECT k.*,
                                   u.name AS user_name,
                                   u.email AS user_email,
                                   u.is_active AS user_is_active,
                                   r.name AS role_name,
                                   r.slug AS role_slug,
                                   cu.name AS created_by_name
                              FROM api_keys k
                              INNER JOIN users u ON u.id = k.user_id
                              INNER JOIN roles r ON r.id = u.role_id
                              LEFT JOIN users cu ON cu.id = k.created_by';

    /**
     * Mint a key. Returns the secret — the only time it exists in clear.
     *
     * @return array{id:int,token:string}
     */
    public static function issue(string $name, int $userId, string $scope, ?string $expiresAt, ?int $createdBy): array
    {
        $token = self::PREFIX . bin2hex(random_bytes(24));

        $id = Database::insert('api_keys', [
            'name'         => $name,
            'user_id'      => $userId,
            'token_prefix' => substr($token, 0, 12),
            'token_hash'   => hash('sha256', $token),
            'scope'        => in_array($scope, array_keys(self::SCOPES), true) ? $scope : 'read',
            'expires_at'   => $expiresAt,
            'created_by'   => $createdBy,
        ]);

        return ['id' => $id, 'token' => $token];
    }

    /**
     * The key a presented secret belongs to, whatever state it is in.
     *
     * Deliberately returns revoked and expired keys as well as live ones: the
     * caller decides, and telling somebody "that key was revoked" is more use
     * than "unauthorised" when they are holding a key that worked yesterday.
     *
     * @return array<string,mixed>|null
     */
    public static function findByToken(string $token): ?array
    {
        $token = trim($token);

        if ($token === '' || !str_starts_with($token, self::PREFIX)) {
            return null;
        }

        return Database::selectOne(self::SELECT . ' WHERE k.token_hash = ?', [hash('sha256', $token)]);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE k.id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Database::select(
            self::SELECT . ' ORDER BY k.revoked_at IS NOT NULL, k.created_at DESC'
        );
    }

    public static function revoke(int $id): void
    {
        Database::run(
            'UPDATE api_keys SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL',
            [$id]
        );
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM api_keys WHERE id = ?', [$id]);
    }

    /**
     * Why a key cannot be used, or null when it can.
     *
     * @param array<string,mixed> $key
     */
    public static function unusableReason(array $key): ?string
    {
        if ($key['revoked_at'] !== null) {
            return 'That API key has been revoked.';
        }

        if ($key['expires_at'] !== null && strtotime((string) $key['expires_at']) < time()) {
            return 'That API key expired on ' . date('j M Y', (int) strtotime((string) $key['expires_at'])) . '.';
        }

        if ((int) $key['user_is_active'] !== 1) {
            return 'The account this API key belongs to has been deactivated.';
        }

        return null;
    }

    /**
     * Count one request against the key's window, and say whether it is over.
     *
     * A fixed window: the first request of a minute starts it, and the counter
     * resets when the next one begins. It can allow up to twice the limit
     * across a boundary — accepted, and written down here rather than discovered
     * later — in exchange for one UPDATE per request and a table that does not
     * grow. A sliding window means a row per request, which on the hosting this
     * is built for is a bigger problem than the burst it prevents.
     *
     * @return array{allowed:bool,limit:int,remaining:int,reset_in:int}
     */
    public static function countRequest(int $id, int $limit): array
    {
        $limit = max(1, $limit);
        $now   = time();

        $row = Database::selectOne(
            'SELECT rate_window_started_at, rate_count FROM api_keys WHERE id = ?',
            [$id]
        );

        $startedAt = $row === null || $row['rate_window_started_at'] === null
            ? 0
            : (int) strtotime((string) $row['rate_window_started_at']);

        $inWindow = $startedAt > 0 && ($now - $startedAt) < 60;
        $count    = $inWindow ? (int) $row['rate_count'] + 1 : 1;

        Database::run(
            'UPDATE api_keys
                SET rate_window_started_at = ?,
                    rate_count = ?,
                    request_count = request_count + 1,
                    last_used_at = NOW(),
                    last_used_ip = ?
              WHERE id = ?',
            [
                $inWindow ? date('Y-m-d H:i:s', $startedAt) : date('Y-m-d H:i:s', $now),
                $count,
                \App\Core\Request::ip(),
                $id,
            ]
        );

        $windowStart = $inWindow ? $startedAt : $now;

        return [
            'allowed'   => $count <= $limit,
            'limit'     => $limit,
            'remaining' => max(0, $limit - $count),
            'reset_in'  => max(1, 60 - ($now - $windowStart)),
        ];
    }

    /** Users a key may be issued for: anyone with a live account. */
    public static function candidateUsers(): array
    {
        return Database::select(
            'SELECT u.id, u.name, u.email, r.name AS role_name
               FROM users u
               INNER JOIN roles r ON r.id = u.role_id
              WHERE u.is_active = 1
              ORDER BY u.name'
        );
    }

    public static function liveCount(): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM api_keys
              WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())'
        );
    }
}
