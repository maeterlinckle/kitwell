<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * What one account may do that its role does not say.
 *
 * A role is the baseline. An account may be given a permission on top of it, or
 * have one withheld despite it:
 *
 *     effective = (role's permissions + this account's grants) - this account's denies
 *
 * **Deny beats grant, and both beat the role** — except on a superuser, which
 * ignores the lot. See `holdsSql()` for the one place that rule is written, and
 * `Auth::can()` for the superuser escape.
 *
 * The two effects are stored in one table with one row per (user, permission),
 * so an account cannot hold a grant and a deny for the same permission at once.
 * Making the contradiction unstorable is cheaper than resolving it every time
 * somebody reads it.
 */
final class UserPermission
{
    public const GRANT = 'grant';
    public const DENY  = 'deny';

    /**
     * The rule, as SQL, for a query that already has `users u` and `roles r` in
     * scope.
     *
     * Written once and used by both callers — `Auth::permissions()` asks it of
     * the signed-in user and `User::withPermission()` asks it of everybody at
     * once — because two copies of an access-control rule is one copy that will
     * quietly stop matching the other.
     *
     * Takes the permission slug **three times**, positionally.
     */
    public static function holdsSql(): string
    {
        return "(r.is_superuser = 1 OR (
                    (
                        EXISTS (
                            SELECT 1 FROM role_permissions rp
                              INNER JOIN permissions rpp ON rpp.id = rp.permission_id
                             WHERE rp.role_id = r.id AND rpp.slug = ?
                        )
                        OR EXISTS (
                            SELECT 1 FROM user_permissions ug
                              INNER JOIN permissions ugp ON ugp.id = ug.permission_id
                             WHERE ug.user_id = u.id AND ugp.slug = ? AND ug.effect = 'grant'
                        )
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM user_permissions ud
                          INNER JOIN permissions udp ON udp.id = ud.permission_id
                         WHERE ud.user_id = u.id AND udp.slug = ? AND ud.effect = 'deny'
                    )
                ))";
    }

    /**
     * Every permission slug this account effectively holds.
     *
     * Superusers are not special-cased here: `Auth::can()` short-circuits for
     * them before this is ever consulted, and a list that silently contained
     * everything would make the *displayed* answer on the user page a lie about
     * where those permissions came from.
     *
     * @return array<int,string>
     */
    public static function effectiveSlugs(int $userId): array
    {
        $rows = Database::select(
            "SELECT p.slug
               FROM permissions p
              WHERE (
                        EXISTS (
                            SELECT 1 FROM users u
                              INNER JOIN role_permissions rp ON rp.role_id = u.role_id
                             WHERE u.id = ? AND rp.permission_id = p.id
                        )
                        OR EXISTS (
                            SELECT 1 FROM user_permissions ug
                             WHERE ug.user_id = ? AND ug.permission_id = p.id AND ug.effect = 'grant'
                        )
                    )
                AND NOT EXISTS (
                        SELECT 1 FROM user_permissions ud
                         WHERE ud.user_id = ? AND ud.permission_id = p.id AND ud.effect = 'deny'
                    )
              ORDER BY p.group_name, p.sort_order, p.name",
            [$userId, $userId, $userId]
        );

        return array_map(static fn (array $r): string => (string) $r['slug'], $rows);
    }

    /**
     * This account's overrides, as permission id => 'grant'|'deny'.
     *
     * @return array<int,string>
     */
    public static function forUser(int $userId): array
    {
        $out = [];

        foreach (Database::select('SELECT permission_id, effect FROM user_permissions WHERE user_id = ?', [$userId]) as $row) {
            $out[(int) $row['permission_id']] = (string) $row['effect'];
        }

        return $out;
    }

    /**
     * The overrides with their permission details, for showing what an account
     * differs by without listing all forty.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function describeForUser(int $userId): array
    {
        return Database::select(
            'SELECT up.effect, p.id, p.slug, p.name, p.group_name
               FROM user_permissions up
               INNER JOIN permissions p ON p.id = up.permission_id
              WHERE up.user_id = ?
              ORDER BY up.effect DESC, p.group_name, p.sort_order, p.name',
            [$userId]
        );
    }

    /**
     * Replace this account's overrides wholesale.
     *
     * A whole-set write rather than an add/remove pair, because the form it
     * comes from shows every permission at once: anything not named is
     * "inherit", and inheriting has to be expressible by *not saying* it.
     *
     * @param array<int,string> $effects permission id => 'grant'|'deny'
     */
    public static function replace(int $userId, array $effects, ?int $setBy): void
    {
        Database::beginTransaction();

        try {
            Database::run('DELETE FROM user_permissions WHERE user_id = ?', [$userId]);

            foreach ($effects as $permissionId => $effect) {
                if ($effect !== self::GRANT && $effect !== self::DENY) {
                    continue;
                }

                Database::insert('user_permissions', [
                    'user_id'       => $userId,
                    'permission_id' => (int) $permissionId,
                    'effect'        => $effect,
                    'granted_by'    => $setBy,
                ]);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();

            throw $e;
        }
    }

    /** How many accounts differ from their role at all. */
    public static function accountsWithOverrides(): int
    {
        return (int) Database::scalar('SELECT COUNT(DISTINCT user_id) FROM user_permissions');
    }
}
