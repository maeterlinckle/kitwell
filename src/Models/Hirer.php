<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Hirer
{
    public const TYPES = ['Person', 'Company'];

    private const SELECT = 'SELECT b.*,
                                   u.name AS user_name, u.email AS user_email, u.is_active AS user_is_active,
                                   r.slug AS user_role,
                                   (SELECT COUNT(*) FROM hires l WHERE l.hirer_id = b.id AND l.returned_at IS NULL) AS open_hires,
                                   (SELECT COUNT(*) FROM hires l WHERE l.hirer_id = b.id AND l.returned_at IS NULL AND l.due_back_date < CURDATE()) AS overdue_hires,
                                   (SELECT COUNT(*) FROM hires l WHERE l.hirer_id = b.id) AS total_hires
                              FROM hirers b
                              LEFT JOIN users u ON u.id = b.user_id
                              LEFT JOIN roles r ON r.id = u.role_id';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE b.id = ?', [$id]);
    }

    /**
     * The hirer record linked to a login.
     *
     * This is the single hinge of the hirer portal: no link, no hires.
     */
    public static function findByUserId(int $userId): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE b.user_id = ?', [$userId]);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function all(array $filters = []): array
    {
        $sql    = self::SELECT;
        $where  = [];
        $params = [];

        $keywords = trim((string) ($filters['q'] ?? ''));
        if ($keywords !== '') {
            $like    = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keywords) . '%';
            $columns = ['b.name', 'b.company_name', 'b.email', 'b.phone', 'b.reference'];

            $clauses = [];
            foreach ($columns as $column) {
                $clauses[] = $column . " LIKE ? ESCAPE '!'";
                $params[]  = $like;
            }

            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        if (!empty($filters['type'])) {
            $where[]  = 'b.hirer_type = ?';
            $params[] = (string) $filters['type'];
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = 'b.is_active = ?';
            $params[] = (int) $filters['is_active'];
        }

        if (!empty($filters['with_open_hires'])) {
            $where[] = 'EXISTS (SELECT 1 FROM hires l WHERE l.hirer_id = b.id AND l.returned_at IS NULL)';
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        return Database::select($sql . ' ORDER BY b.is_active DESC, b.name ASC', $params);
    }

    /** Active hirers, for the checkout picker. */
    public static function forSelect(): array
    {
        return Database::select(
            'SELECT id, name, company_name, hirer_type, reference
               FROM hirers WHERE is_active = 1 ORDER BY name'
        );
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('hirers', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('hirers', $data, $id);
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM hirers WHERE id = ?', [$id]);
    }

    public static function hasHires(int $id): bool
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM hires WHERE hirer_id = ?', [$id]) > 0;
    }

    /**
     * Users who could be linked to a hirer record: anyone not already
     * linked to a different one.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function linkableUsers(int $currentHirerId = 0): array
    {
        return Database::select(
            'SELECT u.id, u.name, u.email, r.name AS role_name, r.slug AS role_slug
               FROM users u
               INNER JOIN roles r ON r.id = u.role_id
              WHERE u.is_active = 1
                AND (u.id NOT IN (SELECT user_id FROM hirers WHERE user_id IS NOT NULL) OR u.id = (
                        SELECT user_id FROM hirers WHERE id = ?
                    ))
              ORDER BY r.sort_order, u.name',
            [$currentHirerId]
        );
    }

    /** Display label: "Jo Bloggs (Northfield Electrical)". */
    public static function label(array $hirer): string
    {
        $name = (string) $hirer['name'];

        if (!empty($hirer['company_name']) && $hirer['company_name'] !== $name) {
            return $name . ' (' . $hirer['company_name'] . ')';
        }

        return $name;
    }
}
