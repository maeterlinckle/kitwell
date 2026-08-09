<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class AssetPhoto
{
    private const SELECT = 'SELECT p.*, u.name AS uploaded_by_name,
                                   COALESCE(p.taken_at, p.created_at) AS recorded_at
                              FROM asset_photos p
                              LEFT JOIN users u ON u.id = p.uploaded_by';

    /**
     * Newest first — the most recent condition is what people want to see.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forAsset(int $assetId, ?int $limit = null): array
    {
        $sql = self::SELECT . ' WHERE p.asset_id = ? ORDER BY recorded_at DESC, p.id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, min(200, $limit));
        }

        return Database::select($sql, [$assetId]);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE p.id = ?', [$id]);
    }

    /** The photo shown as the asset's thumbnail in listings. */
    public static function primaryFor(int $assetId): ?array
    {
        $primary = Database::selectOne(
            self::SELECT . ' WHERE p.asset_id = ? AND p.is_primary = 1 LIMIT 1',
            [$assetId]
        );

        if ($primary !== null) {
            return $primary;
        }

        // Fall back to the most recent photo, so a gallery always has a face.
        return Database::selectOne(
            self::SELECT . ' WHERE p.asset_id = ? ORDER BY recorded_at DESC, p.id DESC LIMIT 1',
            [$assetId]
        );
    }

    /**
     * Primary (or latest) photo for a set of assets, keyed by asset id — one
     * query for a whole page of the register rather than one per row.
     *
     * @param array<int,int> $assetIds
     * @return array<int,array<string,mixed>>
     */
    public static function primaryForMany(array $assetIds): array
    {
        $assetIds = array_values(array_unique(array_filter(array_map('intval', $assetIds))));

        if ($assetIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($assetIds), '?'));

        $rows = Database::select(
            'SELECT p.asset_id, p.id, p.file_path, p.thumbnail_path, p.caption, p.is_primary,
                    COALESCE(p.taken_at, p.created_at) AS recorded_at
               FROM asset_photos p
              WHERE p.asset_id IN (' . $placeholders . ')
              ORDER BY p.asset_id, p.is_primary DESC, COALESCE(p.taken_at, p.created_at) DESC, p.id DESC',
            $assetIds
        );

        $byAsset = [];
        foreach ($rows as $row) {
            $assetId = (int) $row['asset_id'];

            // The ordering above puts the best candidate first for each asset.
            if (!isset($byAsset[$assetId])) {
                $byAsset[$assetId] = $row;
            }
        }

        return $byAsset;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('asset_photos', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('asset_photos', $data, $id);
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM asset_photos WHERE id = ?', [$id]);
    }

    public static function countForAsset(int $assetId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM asset_photos WHERE asset_id = ?', [$assetId]);
    }

    /** Make one photo the asset's primary, clearing any previous choice. */
    public static function makePrimary(int $assetId, int $photoId): void
    {
        Database::beginTransaction();

        try {
            Database::run('UPDATE asset_photos SET is_primary = 0 WHERE asset_id = ?', [$assetId]);
            Database::run('UPDATE asset_photos SET is_primary = 1 WHERE id = ? AND asset_id = ?', [$photoId, $assetId]);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * Group photos by month for the timeline view.
     *
     * @param array<int,array<string,mixed>> $photos
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function groupByMonth(array $photos): array
    {
        $grouped = [];

        foreach ($photos as $photo) {
            $timestamp = strtotime((string) $photo['recorded_at']);
            $key       = $timestamp === false ? 'Undated' : date('F Y', $timestamp);

            $grouped[$key][] = $photo;
        }

        return $grouped;
    }
}
