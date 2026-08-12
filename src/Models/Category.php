<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Category
{
    /** @return array<int,array<string,mixed>> */
    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT c.*, p.name AS parent_name,
                       (SELECT COUNT(*) FROM assets a WHERE a.category_id = c.id) AS asset_count
                  FROM categories c
                  LEFT JOIN categories p ON p.id = c.parent_id';

        if ($activeOnly) {
            $sql .= ' WHERE c.is_active = 1';
        }

        $sql .= ' ORDER BY COALESCE(p.name, c.name), c.name';

        return Database::select($sql);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            'SELECT c.*, (SELECT COUNT(*) FROM assets a WHERE a.category_id = c.id) AS asset_count
               FROM categories c WHERE c.id = ?',
            [$id]
        );
    }

    /**
     * Everything one entry may be moved inside, as a depth-ordered list with a
     * readable path ("Power tools → Cordless").
     *
     * Excludes the entry itself and everything under it. Moving a branch inside
     * its own child would make a cycle, and a cycle in a self-nesting table is
     * not a validation error you notice later — it is a tree walk that never
     * ends, and every screen that renders it hangs.
     *
     * @return array<int,array{id:int,path:string}>
     */
    public static function parentOptions(int $excludeId = 0): array
    {
        return Tree::options(self::all(), $excludeId);
    }

    /**
     * The ids of one entry and all of its descendants.
     *
     * @return array<int,int>
     */
    public static function descendantIds(int $id): array
    {
        return Tree::descendantIds(self::all(), $id);
    }

    public static function create(string $name, ?int $parentId, ?string $description): int
    {
        return Database::insert('categories', [
            'name'        => $name,
            'slug'        => self::uniqueSlug($name),
            'parent_id'   => $parentId,
            'description' => $description,
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('categories', $data, $id);
    }

    public static function inUse(int $id): bool
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM assets WHERE category_id = ?', [$id]) > 0;
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM categories WHERE id = ?', [$id]);
    }

    public static function uniqueSlug(string $name, int $ignoreId = 0): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? '', '-');
        if ($base === '') {
            $base = 'category';
        }

        $slug   = $base;
        $suffix = 1;

        while (true) {
            $sql    = 'SELECT COUNT(*) FROM categories WHERE slug = ?';
            $params = [$slug];

            if ($ignoreId > 0) {
                $sql     .= ' AND id <> ?';
                $params[] = $ignoreId;
            }

            if ((int) Database::scalar($sql, $params) === 0) {
                return $slug;
            }

            $slug = $base . '-' . (++$suffix);
        }
    }
}
