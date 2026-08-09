<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Setting;

/**
 * Generates the next free asset tag, e.g. AST-0001.
 *
 * The next number is derived from the tags already in the database rather than
 * from a stored counter, so it is self-healing: importing older records or
 * changing the prefix cannot leave the sequence stranded.
 */
final class AssetTagger
{
    public static function prefix(): string
    {
        return (string) Setting::get('asset_tag_prefix', 'AST-');
    }

    public static function padding(): int
    {
        return max(1, min(12, Setting::int('asset_tag_pad', 4)));
    }

    /** The next available tag. */
    public static function next(): string
    {
        return self::nextBatch(1)[0];
    }

    /**
     * A run of consecutive free tags, for "create 5 copies".
     *
     * @return array<int,string>
     */
    public static function nextBatch(int $count): array
    {
        $count   = max(1, min(200, $count));
        $prefix  = self::prefix();
        $padding = self::padding();

        $number = self::highestNumber($prefix);
        $tags   = [];
        $guard  = 0;

        while (count($tags) < $count) {
            $number++;

            if (++$guard > 10000) {
                // Fall back to something unmistakably unique rather than loop.
                $tags[] = $prefix . strtoupper(bin2hex(random_bytes(4)));
                continue;
            }

            $candidate = $prefix . str_pad((string) $number, $padding, '0', STR_PAD_LEFT);

            if (!self::taken($candidate)) {
                $tags[] = $candidate;
            }
        }

        return $tags;
    }

    /** Highest numeric suffix currently used with this prefix. */
    private static function highestNumber(string $prefix): int
    {
        $offset = strlen($prefix) + 1;
        $like   = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix) . '%';

        $max = Database::scalar(
            "SELECT MAX(CAST(SUBSTRING(asset_tag, ?) AS UNSIGNED))
               FROM assets
              WHERE asset_tag LIKE ? ESCAPE '!'",
            [$offset, $like]
        );

        return $max === null ? 0 : (int) $max;
    }

    private static function taken(string $tag): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM assets WHERE asset_tag = ? OR barcode = ?',
            [$tag, $tag]
        ) > 0;
    }
}
