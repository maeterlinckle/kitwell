<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * A saved report definition.
 *
 * Storage only. What a definition *means* — which filters are legal, which
 * columns exist, how the rows are fetched — belongs to
 * `App\Reports\DataSourceRegistry`, and turning a row into something the
 * reports module can render is `App\Reports\StoredReport`'s job. Keeping those
 * apart is what lets a definition survive a source gaining a field: the row
 * holds keys, not a frozen copy of the schema.
 */
final class CustomReport
{
    /** Every custom report key starts with this, and no built-in report may. */
    public const KEY_PREFIX = 'custom-';

    private const SELECT = 'SELECT r.*, cu.name AS created_by_name, uu.name AS updated_by_name
                              FROM custom_reports r
                              LEFT JOIN users cu ON cu.id = r.created_by
                              LEFT JOIN users uu ON uu.id = r.updated_by';

    /** @return array<int,array<string,mixed>> */
    public static function all(bool $activeOnly = false): array
    {
        $sql = self::SELECT;

        if ($activeOnly) {
            $sql .= ' WHERE r.is_active = 1';
        }

        return array_map(
            self::decode(...),
            Database::select($sql . ' ORDER BY r.is_active DESC, r.name')
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Database::selectOne(self::SELECT . ' WHERE r.id = ?', [$id]);

        return $row === null ? null : self::decode($row);
    }

    /** @return array<string,mixed>|null */
    public static function findByKey(string $key): ?array
    {
        $row = Database::selectOne(self::SELECT . ' WHERE r.report_key = ?', [$key]);

        return $row === null ? null : self::decode($row);
    }

    /**
     * Turn the JSON columns into arrays.
     *
     * Defensive: a row edited by hand degrades to "no filters, no columns"
     * rather than throwing halfway through rendering somebody's report.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function decode(array $row): array
    {
        $filters = json_decode((string) $row['filters'], true);
        $columns = json_decode((string) $row['columns'], true);

        $row['filters'] = is_array($filters) ? $filters : [];
        $row['columns'] = is_array($columns) ? array_values(array_filter($columns, 'is_string')) : [];

        return $row;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('custom_reports', self::encode($data));
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('custom_reports', self::encode($data), $id);
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM custom_reports WHERE id = ?', [$id]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function encode(array $data): array
    {
        foreach (['filters', 'columns'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                // JSON_FORCE_OBJECT is wrong for `columns`, which is a list, and
                // right for `filters`, which is a map that must not become []
                // when empty — MariaDB's JSON type accepts both, but a filter
                // set that reads back as a list would silently lose its keys.
                $data[$key] = json_encode(
                    $data[$key],
                    JSON_UNESCAPED_UNICODE | ($key === 'filters' && $data[$key] === [] ? JSON_FORCE_OBJECT : 0)
                );
            }
        }

        return $data;
    }

    /**
     * A URL-safe key for a name, unique across saved reports.
     *
     * The `custom-` prefix is not decoration: it is what guarantees a saved
     * report can never take the URL of a built-in one, now or after somebody
     * ships a new built-in called "faulty assets".
     */
    public static function makeKey(string $name, int $ignoreId = 0): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
        $slug = $slug === '' ? 'report' : substr($slug, 0, 60);

        $base      = self::KEY_PREFIX . $slug;
        $candidate = $base;
        $suffix    = 2;

        while (self::keyExists($candidate, $ignoreId)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    public static function keyExists(string $key, int $ignoreId = 0): bool
    {
        $sql    = 'SELECT COUNT(*) FROM custom_reports WHERE report_key = ?';
        $params = [$key];

        if ($ignoreId > 0) {
            $sql     .= ' AND id <> ?';
            $params[] = $ignoreId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    public static function count(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM custom_reports');
    }
}
