<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Completed maintenance events.
 *
 * A log entry either belongs to a schedule (a planned job that got done) or
 * stands alone (a repair nobody planned for). Both are first-class: the second
 * is how most workshop repairs actually get recorded.
 */
final class MaintenanceLog
{
    public const TYPES   = ['routine', 'periodic', 'ad-hoc', 'repair', 'inspection'];
    public const RESULTS = ['Completed', 'Partial', 'Failed', 'Deferred'];

    private const SELECT = 'SELECT m.*,
                                   a.asset_tag, a.name AS asset_name,
                                   s.title AS schedule_title, s.maintenance_type AS schedule_type,
                                   u.name AS performed_by_user_name,
                                   cu.name AS created_by_name,
                                   (SELECT COUNT(*) FROM maintenance_log_photos p WHERE p.maintenance_log_id = m.id) AS photo_count
                              FROM maintenance_logs m
                              INNER JOIN assets a ON a.id = m.asset_id
                              LEFT JOIN maintenance_schedules s ON s.id = m.schedule_id
                              LEFT JOIN users u ON u.id = m.performed_by_user_id
                              LEFT JOIN users cu ON cu.id = m.created_by';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE m.id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function forAsset(int $assetId, ?int $limit = null): array
    {
        $sql = self::SELECT . ' WHERE m.asset_id = ? ORDER BY m.performed_on DESC, m.id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, min(200, $limit));
        }

        return Database::select($sql, [$assetId]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function forSchedule(int $scheduleId): array
    {
        return Database::select(
            self::SELECT . ' WHERE m.schedule_id = ? ORDER BY m.performed_on DESC, m.id DESC',
            [$scheduleId]
        );
    }

    /**
     * Completion history across every asset, for the history view and reports.
     *
     * Filters: q, asset_id, schedule_id, type, result, performed_by, from, to.
     *
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function search(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where  = [];
        $params = [];

        $keywords = trim((string) ($filters['q'] ?? ''));
        if ($keywords !== '') {
            $like    = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keywords) . '%';
            $columns = ['m.work_done', 'm.notes', 'm.parts_used', 'm.performed_by_name', 'a.asset_tag', 'a.name'];

            $clauses = [];
            foreach ($columns as $column) {
                $clauses[] = $column . " LIKE ? ESCAPE '!'";
                $params[]  = $like;
            }

            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        if (!empty($filters['asset_id'])) {
            $where[]  = 'm.asset_id = ?';
            $params[] = (int) $filters['asset_id'];
        }

        if (!empty($filters['schedule_id'])) {
            $where[]  = 'm.schedule_id = ?';
            $params[] = (int) $filters['schedule_id'];
        }

        if (!empty($filters['type'])) {
            $types = array_values(array_intersect((array) $filters['type'], self::TYPES));

            if ($types !== []) {
                $where[] = 'm.maintenance_type IN (' . implode(', ', array_fill(0, count($types), '?')) . ')';
                foreach ($types as $type) {
                    $params[] = $type;
                }
            }
        }

        if (!empty($filters['result'])) {
            $results = array_values(array_intersect((array) $filters['result'], self::RESULTS));

            if ($results !== []) {
                $where[] = 'm.result IN (' . implode(', ', array_fill(0, count($results), '?')) . ')';
                foreach ($results as $result) {
                    $params[] = $result;
                }
            }
        }

        if (!empty($filters['performed_by'])) {
            $where[]  = 'm.performed_by_user_id = ?';
            $params[] = (int) $filters['performed_by'];
        }

        if (!empty($filters['from'])) {
            $where[]  = 'm.performed_on >= ?';
            $params[] = (string) $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[]  = 'm.performed_on <= ?';
            $params[] = (string) $filters['to'];
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM maintenance_logs m INNER JOIN assets a ON a.id = m.asset_id' . $whereSql,
            $params
        );

        $perPage = max(5, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            self::SELECT . $whereSql . ' ORDER BY m.performed_on DESC, m.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('maintenance_logs', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('maintenance_logs', $data, $id);
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM maintenance_logs WHERE id = ?', [$id]);
    }

    /** Total spend on maintenance in a period — used by reports. */
    public static function totalCost(?string $from = null, ?string $to = null): float
    {
        $sql    = 'SELECT COALESCE(SUM(cost), 0) FROM maintenance_logs WHERE 1 = 1';
        $params = [];

        if ($from !== null) {
            $sql     .= ' AND performed_on >= ?';
            $params[] = $from;
        }

        if ($to !== null) {
            $sql     .= ' AND performed_on <= ?';
            $params[] = $to;
        }

        return (float) Database::scalar($sql, $params);
    }

    /** @return array<int,array<string,mixed>> */
    public static function photos(int $logId): array
    {
        return Database::select(
            'SELECT p.*, u.name AS uploaded_by_name
               FROM maintenance_log_photos p
               LEFT JOIN users u ON u.id = p.uploaded_by
              WHERE p.maintenance_log_id = ?
              ORDER BY p.id',
            [$logId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findPhoto(int $photoId): ?array
    {
        return Database::selectOne('SELECT * FROM maintenance_log_photos WHERE id = ?', [$photoId]);
    }

    /** @param array<string,mixed> $data */
    public static function addPhoto(array $data): int
    {
        return Database::insert('maintenance_log_photos', $data);
    }

    public static function deletePhoto(int $photoId): void
    {
        Database::run('DELETE FROM maintenance_log_photos WHERE id = ?', [$photoId]);
    }
}
