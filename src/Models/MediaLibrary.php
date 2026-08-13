<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;

/**
 * The shared media library: a photo or document held once and attached to as
 * many assets — and templates — as need it.
 *
 * This is for media that is a property of the *model*: a manufacturer's stock
 * photo, a manual. Attaching an item to a second asset writes a row in
 * `asset_media` and touches neither the file nor the library record, so ten
 * assets built from one template share one manual.
 *
 * Condition photos (`asset_photos`) and the evidence hanging off PAT records,
 * fault reports and maintenance logs are not library items and never route
 * through here: each of those records what one physical item looked like at one
 * moment, and belongs to that record alone.
 */
final class MediaLibrary
{
    public const TYPES = ['photo', 'document'];

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            'SELECT m.*, u.name AS uploaded_by_name
               FROM media_library m
               LEFT JOIN users u ON u.id = m.uploaded_by
              WHERE m.id = ?',
            [$id]
        );
    }

    /**
     * The item holding an identical file, if there is one.
     *
     * This is what makes uploading the same manual twice attach the copy that
     * is already there instead of storing it again.
     *
     * @return array<string,mixed>|null
     */
    public static function findByHash(string $hash): ?array
    {
        if ($hash === '') {
            return null;
        }

        return Database::selectOne('SELECT * FROM media_library WHERE file_hash = ?', [$hash]);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('media_library', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('media_library', $data, $id);
    }

    /**
     * Remove a library item entirely. Every attachment goes with it, so the
     * caller is expected to have said how many assets that affects.
     */
    public static function delete(int $id): void
    {
        Database::run('DELETE FROM media_library WHERE id = ?', [$id]);
    }

    /**
     * Browse the library, optionally narrowed to one type or a keyword.
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int}
     */
    public static function search(?string $type = null, string $keyword = '', int $page = 1, int $perPage = 24): array
    {
        [$whereSql, $params] = self::conditions($type, $keyword);

        $total = (int) Database::scalar('SELECT COUNT(*) FROM media_library m ' . $whereSql, $params);
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page  = max(1, min($page, $pages));

        $limit  = max(1, min(100, $perPage));
        $offset = ($page - 1) * $limit;

        $rows = Database::select(
            'SELECT m.*, u.name AS uploaded_by_name,
                    (SELECT COUNT(*) FROM asset_media am WHERE am.media_id = m.id) AS asset_count
               FROM media_library m
               LEFT JOIN users u ON u.id = m.uploaded_by
               ' . $whereSql . '
              ORDER BY m.title, m.id
              LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function conditions(?string $type, string $keyword): array
    {
        $clauses = [];
        $params  = [];

        if ($type !== null && in_array($type, self::TYPES, true)) {
            $clauses[] = 'm.media_type = ?';
            $params[]  = $type;
        }

        $keyword = trim($keyword);
        if ($keyword !== '') {
            // Every word must appear somewhere, the same rule the register uses.
            foreach (preg_split('/\s+/', $keyword) ?: [] as $word) {
                $clauses[] = '(m.title LIKE ? OR m.description LIKE ? OR m.original_filename LIKE ?)';
                $like      = '%' . $word . '%';
                $params[]  = $like;
                $params[]  = $like;
                $params[]  = $like;
            }
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * Everything attached to one asset.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forAsset(int $assetId, ?string $type = null): array
    {
        $params   = [$assetId];
        $whereSql = 'WHERE am.asset_id = ?';

        // The only fragment that varies is a literal this method wrote; the
        // type itself is bound like every other value.
        if ($type !== null && in_array($type, self::TYPES, true)) {
            $whereSql .= ' AND m.media_type = ?';
            $params[]  = $type;
        }

        return Database::select(
            'SELECT m.*, am.sort_order, am.created_at AS attached_at, u.name AS uploaded_by_name
               FROM asset_media am
               JOIN media_library m ON m.id = am.media_id
               LEFT JOIN users u ON u.id = m.uploaded_by
              ' . $whereSql . '
              ORDER BY m.media_type, am.sort_order, m.title',
            $params
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forTemplate(int $templateId): array
    {
        return Database::select(
            'SELECT m.*, tm.sort_order
               FROM template_media tm
               JOIN media_library m ON m.id = tm.media_id
              WHERE tm.template_id = ?
              ORDER BY m.media_type, tm.sort_order, m.title',
            [$templateId]
        );
    }

    /** @return array<int,int> The library ids attached to a template. */
    public static function templateMediaIds(int $templateId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['media_id'],
            Database::select('SELECT media_id FROM template_media WHERE template_id = ?', [$templateId])
        );
    }

    /** @return array<int,int> The library ids attached to an asset. */
    public static function assetMediaIds(int $assetId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['media_id'],
            Database::select('SELECT media_id FROM asset_media WHERE asset_id = ?', [$assetId])
        );
    }

    /**
     * Attach a library item to an asset.
     *
     * `INSERT IGNORE` against the composite primary key, so attaching something
     * already attached is a no-op rather than an error — which is what makes
     * copy, bulk-apply and template creation safe to run twice.
     *
     * @return bool True when this created a new attachment.
     */
    public static function attach(int $assetId, int $mediaId): bool
    {
        $affected = Database::run(
            'INSERT IGNORE INTO asset_media (asset_id, media_id, attached_by) VALUES (?, ?, ?)',
            [$assetId, $mediaId, Auth::id()]
        )->rowCount();

        return $affected > 0;
    }

    /**
     * @param array<int,int> $mediaIds
     * @return int How many attachments were new.
     */
    public static function attachMany(int $assetId, array $mediaIds): int
    {
        $attached = 0;

        foreach (array_unique(array_filter(array_map('intval', $mediaIds))) as $mediaId) {
            if (self::attach($assetId, $mediaId)) {
                $attached++;
            }
        }

        return $attached;
    }

    public static function detach(int $assetId, int $mediaId): void
    {
        Database::run('DELETE FROM asset_media WHERE asset_id = ? AND media_id = ?', [$assetId, $mediaId]);
    }

    public static function attachToTemplate(int $templateId, int $mediaId): bool
    {
        return Database::run(
            'INSERT IGNORE INTO template_media (template_id, media_id) VALUES (?, ?)',
            [$templateId, $mediaId]
        )->rowCount() > 0;
    }

    public static function detachFromTemplate(int $templateId, int $mediaId): void
    {
        Database::run('DELETE FROM template_media WHERE template_id = ? AND media_id = ?', [$templateId, $mediaId]);
    }

    /** How many assets currently hold this item. */
    public static function assetCount(int $mediaId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM asset_media WHERE media_id = ?', [$mediaId]);
    }

    /** How many templates currently hold this item. */
    public static function templateCount(int $mediaId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM template_media WHERE media_id = ?', [$mediaId]);
    }

    public static function countForAsset(int $assetId, ?string $type = null): int
    {
        if ($type === null) {
            return (int) Database::scalar('SELECT COUNT(*) FROM asset_media WHERE asset_id = ?', [$assetId]);
        }

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM asset_media am JOIN media_library m ON m.id = am.media_id
              WHERE am.asset_id = ? AND m.media_type = ?',
            [$assetId, $type]
        );
    }

    /**
     * The library items for several assets at once, keyed by asset id.
     *
     * @param array<int,int> $assetIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function forAssets(array $assetIds, ?string $type = null): array
    {
        $assetIds = array_values(array_unique(array_filter(array_map('intval', $assetIds))));

        if ($assetIds === []) {
            return [];
        }

        $params   = $assetIds;
        $whereSql = 'WHERE am.asset_id IN (' . implode(',', array_fill(0, count($assetIds), '?')) . ')';

        if ($type !== null && in_array($type, self::TYPES, true)) {
            $whereSql .= ' AND m.media_type = ?';
            $params[]  = $type;
        }

        $rows = Database::select(
            'SELECT am.asset_id, m.*
               FROM asset_media am
               JOIN media_library m ON m.id = am.media_id
              ' . $whereSql . '
              ORDER BY am.sort_order, m.title',
            $params
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['asset_id']][] = $row;
        }

        return $grouped;
    }

    /** @return array<int,array<string,mixed>> Items nothing references. */
    public static function orphans(): array
    {
        return Database::select(
            'SELECT m.* FROM media_library m
              WHERE NOT EXISTS (SELECT 1 FROM asset_media am WHERE am.media_id = m.id)
                AND NOT EXISTS (SELECT 1 FROM template_media tm WHERE tm.media_id = m.id)
              ORDER BY m.created_at'
        );
    }

    /** @return array<int,array<string,mixed>> Items with no stored hash yet. */
    public static function withoutHash(): array
    {
        return Database::select('SELECT id, file_path FROM media_library WHERE file_hash IS NULL ORDER BY id');
    }
}
