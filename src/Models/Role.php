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

    /**
     * Create a role. Never a superuser and never a system role: those two flags
     * are what protect the built-in Administrator from being edited away, and
     * nothing reachable from the web should be able to mint either.
     */
    public static function create(string $name, string $description): int
    {
        // On its own line, not inline in the array literal below: a
        // Database::…() call ending inside `)]` is invisible to the SQL audit's
        // statement scanner, which then reports the whole file.
        $sortOrder = (int) Database::scalar('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM roles');

        return Database::insert('roles', [
            'slug'         => self::uniqueSlug($name),
            'name'         => $name,
            'description'  => $description === '' ? null : $description,
            'is_superuser' => 0,
            'is_system'    => 0,
            // After everything that ships with the application, so a new role
            // lands at the end of the list rather than in the middle of it.
            'sort_order'   => $sortOrder,
        ]);
    }

    public static function update(int $id, string $name, string $description): void
    {
        Database::update('roles', [
            'name'        => $name,
            'description' => $description === '' ? null : $description,
        ], $id);
    }

    /**
     * A stable machine name derived from the display name, with a numeric
     * suffix if that is already taken. The slug is what code refers to, so it
     * is generated once and never changes when the name is edited.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));

        if ($base === '') {
            $base = 'role';
        }

        $base = substr($base, 0, 40);
        $slug = $base;
        $n    = 2;

        while (self::findBySlug($slug) !== null) {
            $slug = $base . '-' . $n;
            $n++;
        }

        return $slug;
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
