<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class AssetManual
{
    /** @return array<int,array<string,mixed>> */
    public static function forAsset(int $assetId): array
    {
        return Database::select(
            'SELECT m.*, u.name AS uploaded_by_name
               FROM asset_manuals m
               LEFT JOIN users u ON u.id = m.uploaded_by
              WHERE m.asset_id = ?
              ORDER BY m.title, m.created_at',
            [$assetId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM asset_manuals WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('asset_manuals', $data);
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM asset_manuals WHERE id = ?', [$id]);
    }

    public static function countForAsset(int $assetId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM asset_manuals WHERE asset_id = ?', [$assetId]);
    }
}
