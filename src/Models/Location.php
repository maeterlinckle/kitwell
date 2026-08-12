<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Location
{
    /** @return array<int,array<string,mixed>> */
    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT l.*, p.name AS parent_name,
                       (SELECT COUNT(*) FROM assets a WHERE a.location_id = l.id) AS asset_count
                  FROM locations l
                  LEFT JOIN locations p ON p.id = l.parent_id';

        if ($activeOnly) {
            $sql .= ' WHERE l.is_active = 1';
        }

        $sql .= ' ORDER BY COALESCE(p.name, l.name), l.name';

        return Database::select($sql);
    }

    /**
     * Locations with their parent prefixed, for select menus:
     * "Main Workshop → Bench 3".
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forSelect(): array
    {
        $rows = self::all(true);

        foreach ($rows as &$row) {
            $row['display_name'] = $row['parent_name'] !== null
                ? $row['parent_name'] . ' → ' . $row['name']
                : $row['name'];
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            'SELECT l.*, (SELECT COUNT(*) FROM assets a WHERE a.location_id = l.id) AS asset_count
               FROM locations l WHERE l.id = ?',
            [$id]
        );
    }

    /**
     * Everything one entry may be moved inside. See Tree::options() — the
     * exclusion of an entry's own descendants is what stops a cycle.
     *
     * @return array<int,array{id:int,path:string,depth:int}>
     */
    public static function parentOptions(int $excludeId = 0): array
    {
        return Tree::options(self::all(), $excludeId);
    }

    /** @return array<int,int> */
    public static function descendantIds(int $id): array
    {
        return Tree::descendantIds(self::all(), $id);
    }

    public static function create(string $name, ?string $code, ?int $parentId, ?string $description): int
    {
        return Database::insert('locations', [
            'name'        => $name,
            'code'        => $code,
            'parent_id'   => $parentId,
            'description' => $description,
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('locations', $data, $id);
    }

    public static function inUse(int $id): bool
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM assets WHERE location_id = ?', [$id]) > 0
            || (int) Database::scalar('SELECT COUNT(*) FROM locations WHERE parent_id = ?', [$id]) > 0;
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM locations WHERE id = ?', [$id]);
    }
}
