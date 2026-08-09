<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;

/**
 * Audit trail: who changed what, when, and from where.
 */
final class ActivityLog
{
    /**
     * @param array<string,mixed>|null $changes Before/after payload, stored as JSON.
     */
    public static function record(
        string $action,
        string $entityType,
        ?int $entityId = null,
        string $description = '',
        ?array $changes = null
    ): void {
        $user = Auth::user();

        Database::insert('activity_log', [
            'user_id'     => $user['id'] ?? null,
            'user_name'   => $user['name'] ?? 'System',
            'action'      => mb_substr($action, 0, 100),
            'entity_type' => mb_substr($entityType, 0, 64),
            'entity_id'   => $entityId,
            'changes'     => self::encodeChanges($changes),
            'description' => mb_substr($description, 0, 500),
            'ip_address'  => Request::ip(),
            'user_agent'  => Request::userAgent(),
        ]);
    }

    /**
     * MariaDB implements JSON as LONGTEXT with a `json_valid()` CHECK
     * constraint, so anything that is not valid JSON is rejected outright.
     * json_encode() returns false on malformed UTF-8 — store NULL rather than
     * let an audit-log write take down the operation being logged.
     *
     * @param array<string,mixed>|null $changes
     */
    private static function encodeChanges(?array $changes): ?string
    {
        if ($changes === null || $changes === []) {
            return null;
        }

        $json = json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? null : $json;
    }

    /**
     * Compute a before/after diff, ignoring unchanged fields.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,array{from:mixed,to:mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $key => $value) {
            $old = $before[$key] ?? null;
            if ((string) $old !== (string) $value) {
                $changes[$key] = ['from' => $old, 'to' => $value];
            }
        }

        return $changes;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $limit = 25, array $filters = []): array
    {
        $sql    = 'SELECT * FROM activity_log';
        $where  = [];
        $params = [];

        if (!empty($filters['entity_type'])) {
            $where[]               = 'entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }

        if (!empty($filters['entity_id'])) {
            $where[]             = 'entity_id = :entity_id';
            $params['entity_id'] = (int) $filters['entity_id'];
        }

        if (!empty($filters['user_id'])) {
            $where[]           = 'user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        // LIMIT is cast to int, never interpolated from raw input.
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(500, $limit));

        return Database::select($sql, $params);
    }

    public static function countAll(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM activity_log');
    }
}
