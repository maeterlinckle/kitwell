<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Asset
{
    public const CONDITIONS = ['Excellent', 'Good', 'Fair', 'Poor', 'Out of Service'];
    public const STATUSES   = ['In Stock', 'On Loan', 'In Maintenance', 'Retired'];
    public const RELATIONSHIPS = ['sub-asset', 'accessory', 'related'];

    /** Columns a user may sort the register by, mapped to safe SQL. */
    private const SORTS = [
        'tag'       => 'a.asset_tag ASC',
        'name'      => 'a.name ASC',
        'newest'    => 'a.created_at DESC',
        'oldest'    => 'a.created_at ASC',
        'updated'   => 'a.updated_at DESC',
        'status'    => 'a.status ASC, a.name ASC',
        'condition' => "FIELD(a.condition_rating,'Out of Service','Poor','Fair','Good','Excellent') ASC, a.name ASC",
        'value'     => 'a.purchase_cost DESC',
    ];

    private const SELECT = 'SELECT a.*,
                                   c.name AS category_name,
                                   l.name AS location_name,
                                   lp.name AS location_parent_name,
                                   p.name AS parent_name,
                                   p.asset_tag AS parent_tag,
                                   cu.name AS created_by_name,
                                   uu.name AS updated_by_name
                              FROM assets a
                              LEFT JOIN categories c ON c.id = a.category_id
                              LEFT JOIN locations l ON l.id = a.location_id
                              LEFT JOIN locations lp ON lp.id = l.parent_id
                              LEFT JOIN assets p ON p.id = a.parent_asset_id
                              LEFT JOIN users cu ON cu.id = a.created_by
                              LEFT JOIN users uu ON uu.id = a.updated_by';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE a.id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByTag(string $tag): ?array
    {
        return Database::selectOne(
            self::SELECT . ' WHERE a.asset_tag = ? OR a.barcode = ? LIMIT 1',
            [$tag, $tag]
        );
    }

    /**
     * Search and filter the register.
     *
     * Keyword search is a multi-term LIKE across the identifying columns:
     * every term must match somewhere, which is what people expect when they
     * type "makita drill". MariaDB FULLTEXT was considered and rejected here —
     * it tokenises badly on asset tags and serials ("AST-0001", "MK-884213-A")
     * and ignores words shorter than its minimum token length.
     *
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function search(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = self::buildFilters($filters);

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM assets a
               LEFT JOIN categories c ON c.id = a.category_id
               LEFT JOIN locations l ON l.id = a.location_id' . $whereSql,
            $params
        );

        $perPage = max(5, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $sortKey = (string) ($filters['sort'] ?? 'tag');
        $orderBy = self::SORTS[$sortKey] ?? self::SORTS['tag'];

        // LIMIT/OFFSET are integers computed here, never interpolated user input.
        $rows = Database::select(
            self::SELECT . $whereSql . ' ORDER BY ' . $orderBy . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
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

    /**
     * Every matching row, ignoring pagination — for reports and exports.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function searchAll(array $filters = [], int $limit = 5000): array
    {
        [$where, $params] = self::buildFilters($filters);

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $sortKey  = (string) ($filters['sort'] ?? 'tag');
        $orderBy  = self::SORTS[$sortKey] ?? self::SORTS['tag'];

        return Database::select(
            self::SELECT . $whereSql . ' ORDER BY ' . $orderBy . ' LIMIT ' . max(1, min(20000, $limit)),
            $params
        );
    }

    /**
     * Every matching id, ignoring pagination — used by "select all" on bulk
     * actions and by the label sheet.
     *
     * @param array<string,mixed> $filters
     * @return array<int,int>
     */
    public static function searchIds(array $filters = [], int $limit = 500): array
    {
        [$where, $params] = self::buildFilters($filters);
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $rows = Database::select(
            'SELECT a.id FROM assets a
               LEFT JOIN categories c ON c.id = a.category_id
               LEFT JOIN locations l ON l.id = a.location_id'
            . $whereSql . ' ORDER BY a.asset_tag ASC LIMIT ' . max(1, min(2000, $limit)),
            $params
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:array<int,string>,1:array<string,mixed>}
     */
    private static function buildFilters(array $filters): array
    {
        $where  = [];
        $params = [];

        // Positional parameters throughout: PDO with native prepares cannot
        // reuse one named placeholder in several places, and a keyword has to
        // be tested against every searchable column.
        $searchable = [
            'a.asset_tag', 'a.barcode', 'a.name', 'a.description', 'a.serial_number',
            'a.manufacturer', 'a.model', 'a.supplier', 'a.notes', 'c.name', 'l.name',
        ];

        $keywords = trim((string) ($filters['q'] ?? ''));
        if ($keywords !== '') {
            $terms = preg_split('/\s+/', $keywords) ?: [];
            $terms = array_slice(array_values(array_filter($terms)), 0, 6);

            foreach ($terms as $term) {
                $like    = '%' . self::escapeLike($term) . '%';
                $clauses = [];

                foreach ($searchable as $column) {
                    $clauses[] = $column . " LIKE ? ESCAPE '!'";
                    $params[]  = $like;
                }

                $where[] = '(' . implode(' OR ', $clauses) . ')';
            }
        }

        if (!empty($filters['category_id'])) {
            $where[]  = 'a.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }

        if (!empty($filters['location_id'])) {
            $where[]  = 'a.location_id = ?';
            $params[] = (int) $filters['location_id'];
        }

        if (!empty($filters['status'])) {
            $statuses = array_values(array_intersect((array) $filters['status'], self::STATUSES));
            if ($statuses !== []) {
                $where[] = 'a.status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
                foreach ($statuses as $status) {
                    $params[] = $status;
                }
            }
        }

        if (!empty($filters['condition'])) {
            $conditions = array_values(array_intersect((array) $filters['condition'], self::CONDITIONS));
            if ($conditions !== []) {
                $where[] = 'a.condition_rating IN (' . implode(', ', array_fill(0, count($conditions), '?')) . ')';
                foreach ($conditions as $condition) {
                    $params[] = $condition;
                }
            }
        }

        if (isset($filters['requires_pat']) && $filters['requires_pat'] !== '') {
            $where[]  = 'a.requires_pat = ?';
            $params[] = (int) $filters['requires_pat'];
        }

        // Hide retired items unless the user asks for them or filters by status.
        if (empty($filters['include_archived']) && empty($filters['status'])) {
            $where[] = "a.status <> 'Retired'";
        }

        $type = (string) ($filters['type'] ?? '');
        if ($type === 'top') {
            $where[] = 'a.parent_asset_id IS NULL';
        } elseif ($type === 'sub') {
            $where[] = 'a.parent_asset_id IS NOT NULL';
        }

        if (!empty($filters['parent_asset_id'])) {
            $where[]  = 'a.parent_asset_id = ?';
            $params[] = (int) $filters['parent_asset_id'];
        }

        if (!empty($filters['exclude_id'])) {
            $where[]  = 'a.id <> ?';
            $params[] = (int) $filters['exclude_id'];
        }

        return [$where, $params];
    }

    /** Escape LIKE wildcards so a search for "50%" does not match everything. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Sub-assets, accessories and related items belonging to an asset.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function children(int $parentId): array
    {
        return Database::select(
            self::SELECT . ' WHERE a.parent_asset_id = ? ORDER BY a.relationship_type, a.name',
            [$parentId]
        );
    }

    public static function childCount(int $parentId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM assets WHERE parent_asset_id = ?', [$parentId]);
    }

    /**
     * Candidate parents for the asset form: anything that is not this asset and
     * not already one of its children (one level of nesting is enough).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function parentOptions(int $excludeId = 0): array
    {
        $sql    = "SELECT id, asset_tag, name FROM assets WHERE parent_asset_id IS NULL AND status <> 'Retired'";
        $params = [];

        if ($excludeId > 0) {
            $sql     .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        $sql .= ' ORDER BY asset_tag';

        return Database::select($sql, $params);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('assets', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('assets', $data, $id);
    }

    public static function tagExists(string $tag, int $ignoreId = 0): bool
    {
        $sql    = 'SELECT COUNT(*) FROM assets WHERE asset_tag = ?';
        $params = [$tag];

        if ($ignoreId > 0) {
            $sql     .= ' AND id <> ?';
            $params[] = $ignoreId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    public static function barcodeExists(string $barcode, int $ignoreId = 0): bool
    {
        $sql    = 'SELECT COUNT(*) FROM assets WHERE barcode = ?';
        $params = [$barcode];

        if ($ignoreId > 0) {
            $sql     .= ' AND id <> ?';
            $params[] = $ignoreId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    /** Retire an asset (the archive action) and retire its children with it. */
    public static function archive(int $id): void
    {
        Database::run(
            "UPDATE assets SET status = 'Retired', retired_on = CURDATE() WHERE id = ? OR parent_asset_id = ?",
            [$id, $id]
        );
    }

    public static function restore(int $id, string $status = 'In Stock'): void
    {
        Database::run(
            'UPDATE assets SET status = ?, retired_on = NULL WHERE id = ?',
            [in_array($status, self::STATUSES, true) ? $status : 'In Stock', $id]
        );
    }

    /**
     * Things that would be lost by a permanent delete. Used to steer people
     * towards archiving instead.
     *
     * @return array<string,int>
     */
    public static function historyCounts(int $id): array
    {
        return [
            'loans'       => (int) Database::scalar('SELECT COUNT(*) FROM loans WHERE asset_id = ?', [$id]),
            'pat'         => (int) Database::scalar('SELECT COUNT(*) FROM pat_records WHERE asset_id = ?', [$id]),
            'maintenance' => (int) Database::scalar('SELECT COUNT(*) FROM maintenance_logs WHERE asset_id = ?', [$id]),
            'children'    => self::childCount($id),
        ];
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM assets WHERE id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return Database::select(
            self::SELECT . ' WHERE a.id IN (' . $placeholders . ') ORDER BY a.asset_tag',
            $ids
        );
    }
}
