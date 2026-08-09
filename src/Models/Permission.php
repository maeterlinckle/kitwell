<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Permission
{
    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Database::select('SELECT * FROM permissions ORDER BY group_name ASC, sort_order ASC, name ASC');
    }

    /**
     * Permissions keyed by their display group, for rendering checkbox groups.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::all() as $permission) {
            $grouped[(string) $permission['group_name']][] = $permission;
        }

        return $grouped;
    }
}
