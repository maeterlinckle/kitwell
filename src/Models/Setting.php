<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;

/**
 * Key/value application settings, cached for the life of the request.
 */
final class Setting
{
    /** @var array<string,string|null>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();

        $value = self::$cache[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        return $value === null ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string,string|null> */
    public static function all(): array
    {
        self::load();

        return self::$cache ?? [];
    }

    public static function put(string $key, ?string $value): void
    {
        Database::run(
            'INSERT INTO settings (setting_key, setting_value, updated_by)
             VALUES (:k, :v, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
            ['k' => $key, 'v' => $value, 'u' => Auth::id()]
        );

        self::$cache[$key] = $value;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];

        foreach (Database::select('SELECT setting_key, setting_value FROM settings') as $row) {
            self::$cache[(string) $row['setting_key']] = $row['setting_value'] === null ? null : (string) $row['setting_value'];
        }
    }
}
