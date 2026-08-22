<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class User
{
    private const SELECT = 'SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.is_superuser AS role_is_superuser
                              FROM users u
                              INNER JOIN roles r ON r.id = u.role_id';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE u.id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findActive(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE u.id = ? AND u.is_active = 1', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByEmail(string $email): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE u.email = ?', [mb_strtolower(trim($email))]);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function all(array $filters = []): array
    {
        $sql    = self::SELECT;
        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]          = '(u.name LIKE :search OR u.email LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]             = 'u.is_active = :is_active';
            $params['is_active'] = (int) $filters['is_active'];
        }

        if (!empty($filters['role_id'])) {
            $where[]           = 'u.role_id = :role_id';
            $params['role_id'] = (int) $filters['role_id'];
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY u.is_active DESC, u.name ASC';

        return Database::select($sql, $params);
    }

    public static function create(string $name, string $email, string $password, int $roleId, bool $isActive, ?int $createdBy): int
    {
        return Database::insert('users', [
            'name'          => $name,
            'email'         => mb_strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role_id'       => $roleId,
            'is_active'     => $isActive ? 1 : 0,
            'created_by'    => $createdBy,
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower(trim((string) $data['email']));
        }

        Database::update('users', $data, $id);

        // Deactivating an account has to reach the trusted devices too.
        // `is_active = 0` stops Auth::user() finding them, but a row saying
        // "this browser has already proved itself" surviving a deactivation is
        // the kind of leftover that matters the day somebody is let go.
        if (array_key_exists('is_active', $data) && (int) $data['is_active'] === 0) {
            TrustedDevice::forgetAll($id);
        }
    }

    /**
     * This account's own password policy.
     *
     * Separate from `update()` because the three columns are the only ones on
     * the row where **NULL is a value with a meaning** — "follow whatever the
     * site says" — rather than an omission. Writing them through a method that
     * merges whatever keys it is handed would make it far too easy to clear one
     * by not mentioning it, or to fail to clear one by passing null in an array
     * something else has filtered.
     *
     * @param array{password_expiry_days:?int,password_min_length:?int,password_min_classes:?int} $policy
     */
    public static function updatePasswordPolicy(int $id, array $policy): void
    {
        Database::update('users', [
            'password_expiry_days' => $policy['password_expiry_days'],
            'password_min_length'  => $policy['password_min_length'],
            'password_min_classes' => $policy['password_min_classes'],
        ], $id);
    }
    /**
     * @param bool $isChange False only for a silent re-hash of the *same*
     *                       password, which is not a change and must not have a
     *                       change's consequences.
     */
    public static function updatePassword(int $id, string $password, bool $isChange = true): void
    {
        Database::update('users', [
            'password_hash'      => password_hash($password, PASSWORD_DEFAULT),
            'password_changed_at'=> date('Y-m-d H:i:s'),
        ], $id);

        if (!$isChange) {
            return;
        }

        // Every "do not ask again on this computer" goes with the old password.
        // Somebody changing their password after a scare, or an administrator
        // resetting one for them, means exactly "whoever had access should not
        // have it any more" — and a trusted-device cookie issued before that
        // would walk straight past the second factor.
        TrustedDevice::forgetAll($id);
    }

    public static function touchLogin(int $id, string $ip): void
    {
        Database::update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ], $id);
    }

    public static function countActiveAdmins(): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id
              WHERE r.is_superuser = 1 AND u.is_active = 1'
        );
    }

    /**
     * Active users who hold a permission, optionally narrowed to a set of ids.
     *
     * This is the same rule Auth::can() applies — a superuser role holds
     * everything, everyone else gets their role's permissions plus their own
     * grants and minus their own denies — asked of an arbitrary user rather
     * than the signed-in one. It backs the reminder notify list and the
     * calendar feed, both of which have to answer "may *this* person see PAT
     * dates?" with nobody signed in at all.
     *
     * The condition itself comes from `UserPermission::holdsSql()` rather than
     * being written out here. An access-control rule with two copies is a rule
     * with one copy that will quietly stop matching the other, and this is the
     * copy nobody would notice going stale: it decides who gets *emailed*, not
     * who sees a 403.
     *
     * @param array<int,int>|null $ids null = every user
     * @return array<int,array<string,mixed>>
     */
    public static function withPermission(string $permission, ?array $ids = null): array
    {
        if ($ids !== null && $ids === []) {
            return [];
        }

        $sql = 'SELECT u.id, u.name, u.email, r.slug AS role_slug
                  FROM users u
                  INNER JOIN roles r ON r.id = u.role_id
                 WHERE u.is_active = 1
                   AND u.email <> \'\'
                   AND ' . UserPermission::holdsSql();

        // holdsSql() names the slug three times: the role's grant, the
        // account's grant, and the account's deny.
        $params = [$permission, $permission, $permission];

        if ($ids !== null) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $sql .= ' AND u.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            foreach ($ids as $id) {
                $params[] = $id;
            }
        }

        return Database::select($sql . ' ORDER BY u.name', $params);
    }

    /** Does one user hold a permission? Same rule as Auth::can(). */
    public static function holdsPermission(int $userId, string $permission): bool
    {
        return self::withPermission($permission, [$userId]) !== [];
    }

    /** @return array<string,mixed>|null */
    public static function findByCalendarToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        return Database::selectOne(self::SELECT . ' WHERE u.calendar_token = ? AND u.is_active = 1', [$token]);
    }

    /** Issue (or re-issue) a personal calendar feed token, and return it. */
    public static function regenerateCalendarToken(int $id): string
    {
        $token = bin2hex(random_bytes(32));

        Database::update('users', [
            'calendar_token'            => $token,
            'calendar_token_created_at' => date('Y-m-d H:i:s'),
        ], $id);

        return $token;
    }

    public static function emailExists(string $email, int $ignoreId = 0): bool
    {
        $sql    = 'SELECT COUNT(*) FROM users WHERE email = ?';
        $params = [mb_strtolower(trim($email))];

        if ($ignoreId > 0) {
            $sql     .= ' AND id <> ?';
            $params[] = $ignoreId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }
}
