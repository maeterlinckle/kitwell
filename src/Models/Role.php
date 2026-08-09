<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Role
{
    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Database::select(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
               FROM roles r
              ORDER BY r.sort_order ASC, r.name ASC'
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM roles WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        return Database::selectOne('SELECT * FROM roles WHERE slug = ?', [$slug]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function permissions(int $roleId): array
    {
        return Database::select(
            'SELECT p.*
               FROM permissions p
               INNER JOIN role_permissions rp ON rp.permission_id = p.id
              WHERE rp.role_id = ?
              ORDER BY p.group_name, p.name',
            [$roleId]
        );
    }

    /** @return array<int,int> */
    public static function permissionIds(int $roleId): array
    {
        $rows = Database::select('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$roleId]);

        return array_map(static fn (array $r): int => (int) $r['permission_id'], $rows);
    }

    /** @param array<int,int> $permissionIds */
    public static function syncPermissions(int $roleId, array $permissionIds): void
    {
        Database::beginTransaction();

        try {
            Database::run('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);

            foreach (array_unique(array_map('intval', $permissionIds)) as $permissionId) {
                if ($permissionId <= 0) {
                    continue;
                }
                Database::run(
                    'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                    [$roleId, $permissionId]
                );
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }
}
