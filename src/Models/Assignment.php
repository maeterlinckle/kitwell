<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * "A person, or a team, or nobody" — the shape, in one place.
 *
 * Two things in the application are owned this way: a maintenance schedule is
 * *assigned to* somebody, and an asset has somebody *responsible* for it. They
 * are different relationships with different words on screen, but the mechanics
 * are identical — one form control carrying "user:7" or "team:2", unpacked into
 * two mutually exclusive foreign keys.
 *
 * One parser serves both, so a malformed value cannot be read two different
 * ways and quietly send a notification to the wrong half of the workshop.
 *
 * @see MaintenanceSchedule::parseAssignee()  the maintenance wording
 * @see Asset::parseResponsible()             the asset wording
 */
final class Assignment
{
    /**
     * Split "user:7" / "team:2" into its parts.
     *
     * Anything unrecognised comes back as [null, 0], which every caller treats
     * as "nobody" — a stale or hand-edited value must not become a filter on id
     * 0 or, worse, a silently different assignment.
     *
     * @return array{0:?string,1:int}
     */
    public static function parse(string $value): array
    {
        if (preg_match('/^(user|team):(\d+)$/', trim($value), $m) !== 1) {
            return [null, 0];
        }

        $id = (int) $m[2];

        return $id > 0 ? [$m[1], $id] : [null, 0];
    }

    /**
     * The form value for a row as it stands, for re-selecting the option.
     *
     * @param array<string,mixed>|null $row
     */
    public static function value(?array $row, string $userColumn, string $teamColumn): string
    {
        if ($row === null) {
            return '';
        }

        if (!empty($row[$teamColumn])) {
            return 'team:' . (int) $row[$teamColumn];
        }

        if (!empty($row[$userColumn])) {
            return 'user:' . (int) $row[$userColumn];
        }

        return '';
    }

    /**
     * The assignment as one line of text.
     *
     * Says which kind it is, because "Bench fitters" and "Ben Fitter" are not
     * distinguishable from the name alone. Screens with room render a badge
     * instead; this is for report columns, the calendar feed and the emails,
     * which have only text.
     */
    public static function label(?string $name, ?string $kind, string $none): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return $none;
        }

        return $kind === 'team' ? $name . ' (team)' : $name;
    }

    /** Users who may be given something to look after: anyone with an account. */
    public static function assignableUsers(): array
    {
        return \App\Core\Database::select('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');
    }

    /** @return array<int,array<string,mixed>> */
    public static function assignableTeams(): array
    {
        return Team::assignable();
    }
}
