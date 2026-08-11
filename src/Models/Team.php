<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * A group of people work can be assigned to.
 *
 * The point of a team is that the work does not belong to one person. Assigning
 * a maintenance schedule to a team means every member is reminded about it and
 * every member may record its completion — so a job does not sit untouched
 * because the one name on it is away.
 *
 * Teams are archived rather than deleted (`is_active`), for the usual reason:
 * a team that no longer exists is still the right answer to "who was this
 * assigned to last year?".
 */
final class Team
{
    private const SELECT = 'SELECT t.*,
                                   (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) AS member_count,
                                   (SELECT COUNT(*) FROM maintenance_schedules s
                                     WHERE s.assigned_to_team_id = t.id AND s.is_active = 1) AS schedule_count,
                                   cu.name AS created_by_name
                              FROM teams t
                              LEFT JOIN users cu ON cu.id = t.created_by';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE t.id = ?', [$id]);
    }

    /**
     * Every team, archived ones last.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(bool $includeArchived = true): array
    {
        $sql = self::SELECT;

        if (!$includeArchived) {
            $sql .= ' WHERE t.is_active = 1';
        }

        return Database::select($sql . ' ORDER BY t.is_active DESC, t.name ASC');
    }

    /**
     * Teams that may be assigned new work.
     *
     * An archived team keeps whatever it already holds — the schedules still
     * name it, and the reminders still reach its members — but it is not
     * offered for anything new.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function assignable(): array
    {
        return Database::select('SELECT id, name FROM teams WHERE is_active = 1 ORDER BY name');
    }

    /**
     * The members of a team, with enough about each to show a list.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function members(int $teamId): array
    {
        return Database::select(
            'SELECT u.id, u.name, u.email, u.is_active, r.name AS role_name, tm.created_at AS joined_at
               FROM team_members tm
               INNER JOIN users u ON u.id = tm.user_id
               INNER JOIN roles r ON r.id = u.role_id
              WHERE tm.team_id = ?
              ORDER BY u.is_active DESC, u.name',
            [$teamId]
        );
    }

    /**
     * Active members of one or more teams who hold a permission.
     *
     * This is the reminder path, and it goes through User::withPermission for
     * the same reason the notify list does: membership says who is *expected*
     * to do the work, but a reminder that lists overdue maintenance is still a
     * view of maintenance data, and someone whose role no longer allows that
     * must stop receiving it.
     *
     * @param array<int,int> $teamIds
     * @return array<int,array<string,mixed>> Keyed by user id
     */
    public static function membersWithPermission(array $teamIds, string $permission): array
    {
        $teamIds = array_values(array_unique(array_filter(array_map('intval', $teamIds), static fn (int $id): bool => $id > 0)));

        if ($teamIds === []) {
            return [];
        }

        $ids = Database::select(
            'SELECT DISTINCT tm.user_id, tm.team_id
               FROM team_members tm
               INNER JOIN users u ON u.id = tm.user_id AND u.is_active = 1
              WHERE tm.team_id IN (' . implode(', ', array_fill(0, count($teamIds), '?')) . ')',
            $teamIds
        );

        if ($ids === []) {
            return [];
        }

        // Which teams each user is in, so a digest can be narrowed to the work
        // that is actually theirs rather than every team's.
        $teamsByUser = [];
        foreach ($ids as $row) {
            $teamsByUser[(int) $row['user_id']][] = (int) $row['team_id'];
        }

        $allowed = [];

        foreach (User::withPermission($permission, array_keys($teamsByUser)) as $user) {
            $userId           = (int) $user['id'];
            $user['team_ids'] = $teamsByUser[$userId];
            $allowed[$userId] = $user;
        }

        return $allowed;
    }

    /** @return array<int,int> */
    public static function memberIds(int $teamId): array
    {
        return array_map('intval', array_column(
            Database::select('SELECT user_id FROM team_members WHERE team_id = ?', [$teamId]),
            'user_id'
        ));
    }

    /** The teams one user belongs to — shown on their own account page. */
    public static function forUser(int $userId): array
    {
        return Database::select(
            'SELECT t.id, t.name, t.is_active
               FROM team_members tm
               INNER JOIN teams t ON t.id = tm.team_id
              WHERE tm.user_id = ?
              ORDER BY t.is_active DESC, t.name',
            [$userId]
        );
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('teams', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('teams', $data, $id);
    }

    public static function addMember(int $teamId, int $userId, ?int $addedBy): void
    {
        // INSERT IGNORE rather than a check-then-insert: the primary key is
        // (team_id, user_id), so adding someone twice is a no-op at the
        // database rather than a race in PHP.
        Database::run(
            'INSERT IGNORE INTO team_members (team_id, user_id, added_by) VALUES (?, ?, ?)',
            [$teamId, $userId, $addedBy]
        );
    }

    public static function removeMember(int $teamId, int $userId): void
    {
        Database::run('DELETE FROM team_members WHERE team_id = ? AND user_id = ?', [$teamId, $userId]);
    }

    public static function nameExists(string $name, int $ignoreId = 0): bool
    {
        $sql    = 'SELECT COUNT(*) FROM teams WHERE name = ?';
        $params = [trim($name)];

        if ($ignoreId > 0) {
            $sql     .= ' AND id <> ?';
            $params[] = $ignoreId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    /** Users who could be added to a team: anyone with an active account. */
    public static function candidates(int $teamId): array
    {
        return Database::select(
            'SELECT u.id, u.name, r.name AS role_name
               FROM users u
               INNER JOIN roles r ON r.id = u.role_id
              WHERE u.is_active = 1
                AND u.id NOT IN (SELECT user_id FROM team_members WHERE team_id = ?)
              ORDER BY u.name',
            [$teamId]
        );
    }
}
