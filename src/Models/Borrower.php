<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Borrower
{
    public const TYPES = ['Person', 'Company'];

    private const SELECT = 'SELECT b.*,
                                   u.name AS user_name, u.email AS user_email, u.is_active AS user_is_active,
                                   r.slug AS user_role,
                                   (SELECT COUNT(*) FROM loans l WHERE l.borrower_id = b.id AND l.returned_at IS NULL) AS open_loans,
                                   (SELECT COUNT(*) FROM loans l WHERE l.borrower_id = b.id AND l.returned_at IS NULL AND l.due_back_date < CURDATE()) AS overdue_loans,
                                   (SELECT COUNT(*) FROM loans l WHERE l.borrower_id = b.id) AS total_loans
                              FROM borrowers b
                              LEFT JOIN users u ON u.id = b.user_id
                              LEFT JOIN roles r ON r.id = u.role_id';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE b.id = ?', [$id]);
    }

    /**
     * The borrower record linked to a login.
     *
     * This is the single hinge of the borrower portal: no link, no loans.
     */
    public static function findByUserId(int $userId): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE b.user_id = ?', [$userId]);
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

        $keywords = trim((string) ($filters['q'] ?? ''));
        if ($keywords !== '') {
            $like    = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keywords) . '%';
            $columns = ['b.name', 'b.company_name', 'b.email', 'b.phone', 'b.reference'];

            $clauses = [];
            foreach ($columns as $column) {
                $clauses[] = $column . " LIKE ? ESCAPE '!'";
                $params[]  = $like;
            }

            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        if (!empty($filters['type'])) {
            $where[]  = 'b.borrower_type = ?';
            $params[] = (string) $filters['type'];
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = 'b.is_active = ?';
            $params[] = (int) $filters['is_active'];
        }

        if (!empty($filters['with_open_loans'])) {
            $where[] = 'EXISTS (SELECT 1 FROM loans l WHERE l.borrower_id = b.id AND l.returned_at IS NULL)';
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        return Database::select($sql . ' ORDER BY b.is_active DESC, b.name ASC', $params);
    }

    /** Active borrowers, for the checkout picker. */
    public static function forSelect(): array
    {
        return Database::select(
            'SELECT id, name, company_name, borrower_type, reference
               FROM borrowers WHERE is_active = 1 ORDER BY name'
        );
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('borrowers', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('borrowers', $data, $id);
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM borrowers WHERE id = ?', [$id]);
    }

    public static function hasLoans(int $id): bool
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM loans WHERE borrower_id = ?', [$id]) > 0;
    }

    /**
     * Users who could be linked to a borrower record: anyone not already
     * linked to a different one.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function linkableUsers(int $currentBorrowerId = 0): array
    {
        return Database::select(
            'SELECT u.id, u.name, u.email, r.name AS role_name, r.slug AS role_slug
               FROM users u
               INNER JOIN roles r ON r.id = u.role_id
              WHERE u.is_active = 1
                AND (u.id NOT IN (SELECT user_id FROM borrowers WHERE user_id IS NOT NULL) OR u.id = (
                        SELECT user_id FROM borrowers WHERE id = ?
                    ))
              ORDER BY r.sort_order, u.name',
            [$currentBorrowerId]
        );
    }

    /** Display label: "Jo Bloggs (Northfield Electrical)". */
    public static function label(array $borrower): string
    {
        $name = (string) $borrower['name'];

        if (!empty($borrower['company_name']) && $borrower['company_name'] !== $name) {
            return $name . ' (' . $borrower['company_name'] . ')';
        }

        return $name;
    }
}
