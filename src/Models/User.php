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
    }

    public static function updatePassword(int $id, string $password): void
    {
        Database::update('users', [
            'password_hash'      => password_hash($password, PASSWORD_DEFAULT),
            'password_changed_at'=> date('Y-m-d H:i:s'),
        ], $id);
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
