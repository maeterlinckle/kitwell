<?php

declare(strict_types=1);

namespace App\Models;

/**
 * The bits of self-nesting that categories and locations share.
 *
 * Both tables are the same shape — `id`, `name`, `parent_id` — and both need
 * the same two answers: what may this be moved inside, and what is underneath
 * it. Written once here rather than twice in the models, because the second
 * copy is the one that quietly grows a different idea of a cycle.
 *
 * Everything works on a flat array already fetched by the caller. These are
 * small reference tables — tens of rows, not thousands — so one query and a
 * walk in PHP beats a recursive CTE that MariaDB 10.4 would need feature
 * checks for.
 */
final class Tree
{
    /**
     * A readable, depth-ordered list of the entries something may sit inside.
     *
     * Excludes `$excludeId` and everything under it: moving a branch inside its
     * own child creates a cycle, and a cycle here is not a mild data problem —
     * it is a walk that never terminates, so every page that draws the tree
     * hangs rather than showing something wrong.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array{id:int,path:string,depth:int}>
     */
    public static function options(array $items, int $excludeId = 0): array
    {
        $forbidden = $excludeId > 0
            ? array_fill_keys(self::descendantIds($items, $excludeId), true) + [$excludeId => true]
            : [];

        $byParent = self::byParent($items);
        $options  = [];

        $walk = static function (int $parentId, int $depth, string $prefix) use (&$walk, $byParent, $forbidden, &$options): void {
            foreach ($byParent[$parentId] ?? [] as $item) {
                $id = (int) $item['id'];

                if (isset($forbidden[$id])) {
                    continue;
                }

                $path = $prefix === '' ? (string) $item['name'] : $prefix . ' → ' . (string) $item['name'];

                $options[] = ['id' => $id, 'path' => $path, 'depth' => $depth];

                $walk($id, $depth + 1, $path);
            }
        };

        $walk(0, 0, '');

        return $options;
    }

    /**
     * Every id beneath one entry, at any depth. Does not include the entry.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,int>
     */
    public static function descendantIds(array $items, int $id): array
    {
        $byParent = self::byParent($items);
        $found    = [];
        $queue    = [$id];

        // Iterative, and it never revisits an id. If the data already contains
        // a cycle — put there by hand, or by a bug this guards against — the
        // walk still ends.
        while ($queue !== []) {
            $current = (int) array_shift($queue);

            foreach ($byParent[$current] ?? [] as $child) {
                $childId = (int) $child['id'];

                if (isset($found[$childId])) {
                    continue;
                }

                $found[$childId] = true;
                $queue[]         = $childId;
            }
        }

        return array_map('intval', array_keys($found));
    }

    /**
     * Rows grouped by parent id, with the top level under key 0.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<int,array<string,mixed>>>
     */
    private static function byParent(array $items): array
    {
        $byParent = [];

        foreach ($items as $item) {
            $byParent[(int) ($item['parent_id'] ?? 0)][] = $item;
        }

        return $byParent;
    }
}
