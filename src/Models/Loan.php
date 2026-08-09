<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;

/**
 * Loans and hires.
 *
 * "Overdue" is derived from the due date in SQL, so it is always right without
 * anything having to run on a schedule. The stored `status` column is kept in
 * step by refreshOverdue() purely so that anything querying the database
 * directly sees the same thing.
 */
final class Loan
{
    public const STATUSES = ['Out', 'Overdue', 'Returned'];

    private const STATUS_SQL = "CASE
            WHEN l.returned_at IS NOT NULL THEN 'Returned'
            WHEN l.due_back_date < CURDATE() THEN 'Overdue'
            ELSE 'Out'
        END";

    private static function selectSql(): string
    {
        return 'SELECT l.*,
                       ' . self::STATUS_SQL . ' AS effective_status,
                       DATEDIFF(l.due_back_date, CURDATE()) AS days_until_due,
                       a.asset_tag, a.name AS asset_name, a.status AS asset_status,
                       a.condition_rating AS asset_condition, a.requires_pat,
                       b.name AS borrower_name, b.borrower_type, b.company_name,
                       b.email AS borrower_email, b.phone AS borrower_phone,
                       ou.name AS checked_out_by_name,
                       ru.name AS returned_to_name,
                       (SELECT COUNT(*) FROM loan_photos lp WHERE lp.loan_id = l.id) AS photo_count
                  FROM loans l
                  INNER JOIN assets a ON a.id = l.asset_id
                  INNER JOIN borrowers b ON b.id = l.borrower_id
                  LEFT JOIN users ou ON ou.id = l.checked_out_by_user_id
                  LEFT JOIN users ru ON ru.id = l.returned_to_user_id';
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::selectSql() . ' WHERE l.id = ?', [$id]);
    }

    /** The open loan for an asset, if it has one. */
    public static function openForAsset(int $assetId): ?array
    {
        return Database::selectOne(
            self::selectSql() . ' WHERE l.asset_id = ? AND l.returned_at IS NULL ORDER BY l.id DESC LIMIT 1',
            [$assetId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forAsset(int $assetId, ?int $limit = null): array
    {
        $sql = self::selectSql() . ' WHERE l.asset_id = ? ORDER BY l.checked_out_at DESC, l.id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, min(200, $limit));
        }

        return Database::select($sql, [$assetId]);
    }

    /**
     * Loans belonging to one borrower.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forBorrower(int $borrowerId, bool $openOnly = false): array
    {
        $sql = self::selectSql() . ' WHERE l.borrower_id = ?';

        if ($openOnly) {
            $sql .= ' AND l.returned_at IS NULL';
        }

        $sql .= ' ORDER BY l.returned_at IS NOT NULL, l.due_back_date ASC, l.id DESC';

        return Database::select($sql, [$borrowerId]);
    }

    /**
     * A single loan, but only if it belongs to this borrower.
     *
     * The scoping lives here rather than in the controller so the borrower
     * portal cannot accidentally be given an unscoped lookup.
     */
    public static function findForBorrower(int $loanId, int $borrowerId): ?array
    {
        return Database::selectOne(
            self::selectSql() . ' WHERE l.id = ? AND l.borrower_id = ?',
            [$loanId, $borrowerId]
        );
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function search(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = self::buildFilters($filters);

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM loans l
               INNER JOIN assets a ON a.id = l.asset_id
               INNER JOIN borrowers b ON b.id = l.borrower_id' . $whereSql,
            $params
        );

        $perPage = max(5, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            self::selectSql() . $whereSql . ' ORDER BY ' . self::orderBy($filters)
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Every matching loan, ignoring pagination — for reports and exports.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function searchAll(array $filters = [], int $limit = 5000): array
    {
        [$where, $params] = self::buildFilters($filters);

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        return Database::select(
            self::selectSql() . $whereSql . ' ORDER BY ' . self::orderBy($filters)
            . ' LIMIT ' . max(1, min(20000, $limit)),
            $params
        );
    }

    /** @param array<string,mixed> $filters */
    private static function orderBy(array $filters): string
    {
        $sorts = [
            'due'      => 'l.returned_at IS NOT NULL, l.due_back_date ASC',
            'recent'   => 'l.checked_out_at DESC',
            'asset'    => 'a.asset_tag ASC',
            'borrower' => 'b.name ASC',
        ];

        return $sorts[(string) ($filters['sort'] ?? 'due')] ?? $sorts['due'];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:array<int,string>,1:array<int,mixed>}
     */
    private static function buildFilters(array $filters): array
    {
        $where  = [];
        $params = [];

        $keywords = trim((string) ($filters['q'] ?? ''));
        if ($keywords !== '') {
            $like    = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keywords) . '%';
            $columns = ['l.reference', 'l.purpose', 'a.asset_tag', 'a.name', 'b.name', 'b.company_name', 'b.reference'];

            $clauses = [];
            foreach ($columns as $column) {
                $clauses[] = $column . " LIKE ? ESCAPE '!'";
                $params[]  = $like;
            }

            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        if (!empty($filters['status'])) {
            $statuses = array_values(array_intersect((array) $filters['status'], self::STATUSES));

            if ($statuses !== []) {
                $where[] = self::STATUS_SQL . ' IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
                foreach ($statuses as $status) {
                    $params[] = $status;
                }
            }
        }

        if (!empty($filters['borrower_id'])) {
            $where[]  = 'l.borrower_id = ?';
            $params[] = (int) $filters['borrower_id'];
        }

        if (!empty($filters['asset_id'])) {
            $where[]  = 'l.asset_id = ?';
            $params[] = (int) $filters['asset_id'];
        }

        if (!empty($filters['from'])) {
            $where[]  = 'l.checked_out_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }

        if (!empty($filters['to'])) {
            $where[]  = 'l.checked_out_at <= ?';
            $params[] = $filters['to'] . ' 23:59:59';
        }

        // Open loans only — used by the "on loan" and "due back" reports.
        if (!empty($filters['open_only'])) {
            $where[] = 'l.returned_at IS NULL';
        }

        // Due back within N days (negative days are already overdue).
        if (isset($filters['due_within_days']) && $filters['due_within_days'] !== '') {
            $where[]  = 'l.due_back_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)';
            $params[] = (int) $filters['due_within_days'];
        }

        return [$where, $params];
    }

    /**
     * @return array{out:int,overdue:int,due_soon:int,returned_30:int,due_days:int}
     */
    public static function summary(): array
    {
        $dueSoon = max(0, min(90, Setting::int('loan_due_soon_days', 2)));

        $row = Database::selectOne(
            'SELECT
                SUM(CASE WHEN l.returned_at IS NULL AND l.due_back_date >= CURDATE() THEN 1 ELSE 0 END) AS out_now,
                SUM(CASE WHEN l.returned_at IS NULL AND l.due_back_date < CURDATE() THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN l.returned_at IS NULL AND l.due_back_date >= CURDATE()
                          AND l.due_back_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS due_soon,
                SUM(CASE WHEN l.returned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS returned_30
               FROM loans l',
            [$dueSoon]
        );

        return [
            'out'         => (int) ($row['out_now'] ?? 0),
            'overdue'     => (int) ($row['overdue'] ?? 0),
            'due_soon'    => (int) ($row['due_soon'] ?? 0),
            'returned_30' => (int) ($row['returned_30'] ?? 0),
            'due_days'    => $dueSoon,
        ];
    }

    /**
     * Bring the stored status column into line with the dates.
     *
     * Display and filtering never rely on this — they use the derived status —
     * but keeping the column honest matters for anyone reporting straight off
     * the database. Two cheap, indexed updates.
     */
    public static function refreshOverdue(): int
    {
        $flagged = Database::run(
            "UPDATE loans SET status = 'Overdue'
              WHERE returned_at IS NULL AND due_back_date < CURDATE() AND status <> 'Overdue'"
        )->rowCount();

        // A loan whose due date was extended is no longer overdue.
        $cleared = Database::run(
            "UPDATE loans SET status = 'Out'
              WHERE returned_at IS NULL AND due_back_date >= CURDATE() AND status <> 'Out'"
        )->rowCount();

        return $flagged + $cleared;
    }

    /**
     * Why an asset cannot go out right now, or null when it can.
     *
     * @param array<string,mixed> $asset
     */
    public static function blockedReason(array $asset): ?string
    {
        if ($asset['status'] === 'Retired') {
            return 'This asset has been archived, so it cannot be loaned out.';
        }

        if ((int) $asset['is_loanable'] !== 1) {
            return 'This asset is marked as not available for loan (fixed plant, or must not leave site).';
        }

        if ($asset['status'] === 'In Maintenance') {
            return 'This asset is in maintenance. Complete or close the maintenance before loaning it out.';
        }

        $open = self::openForAsset((int) $asset['id']);
        if ($open !== null) {
            return sprintf(
                'This asset is already out with %s until %s (%s).',
                $open['borrower_name'],
                format_date($open['due_back_date']),
                $open['reference'] ?? 'no reference'
            );
        }

        return null;
    }

    /**
     * Check an asset out.
     *
     * The availability check is repeated inside the transaction with the asset
     * row locked, so two people scanning the same item at the same moment
     * cannot both succeed.
     *
     * @param array<string,mixed> $data
     */
    public static function checkout(int $assetId, int $borrowerId, array $data): int
    {
        Database::beginTransaction();

        try {
            $asset = Database::selectOne('SELECT * FROM assets WHERE id = ? FOR UPDATE', [$assetId]);

            if ($asset === null) {
                throw new RuntimeException('That asset no longer exists.');
            }

            $openLoan = Database::selectOne(
                'SELECT id FROM loans WHERE asset_id = ? AND returned_at IS NULL LIMIT 1',
                [$assetId]
            );

            if ($openLoan !== null) {
                throw new RuntimeException('That asset was checked out by someone else a moment ago.');
            }

            if ($asset['status'] === 'Retired' || (int) $asset['is_loanable'] !== 1 || $asset['status'] === 'In Maintenance') {
                throw new RuntimeException('That asset is not available to loan.');
            }

            $loanId = Database::insert('loans', [
                'reference'              => self::nextReference(),
                'asset_id'               => $assetId,
                'borrower_id'            => $borrowerId,
                'checked_out_at'         => $data['checked_out_at'],
                'due_back_date'          => $data['due_back_date'],
                'checked_out_by_user_id' => Auth::id(),
                'condition_out'          => $data['condition_out'],
                'status'                 => $data['due_back_date'] < date('Y-m-d') ? 'Overdue' : 'Out',
                'purpose'                => $data['purpose'],
                'hire_charge'            => $data['hire_charge'],
                'notes'                  => $data['notes'],
            ]);

            Database::update('assets', [
                'status'     => 'On Loan',
                'updated_by' => Auth::id(),
            ], $assetId);

            Database::commit();

            return $loanId;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * Book an asset back in.
     *
     * @param array<string,mixed> $data
     */
    public static function markReturned(int $loanId, array $data): void
    {
        Database::beginTransaction();

        try {
            $loan = Database::selectOne('SELECT * FROM loans WHERE id = ? FOR UPDATE', [$loanId]);

            if ($loan === null) {
                throw new RuntimeException('That loan no longer exists.');
            }

            if ($loan['returned_at'] !== null) {
                throw new RuntimeException('That loan has already been booked back in.');
            }

            Database::update('loans', [
                'returned_at'              => $data['returned_at'],
                'returned_to_user_id'      => Auth::id(),
                'condition_in'             => $data['condition_in'],
                'returned_condition_notes' => $data['returned_condition_notes'],
                'status'                   => 'Returned',
            ], $loanId);

            $assetChanges = [
                'status'     => $data['asset_status'],
                'updated_by' => Auth::id(),
            ];

            if ($data['condition_in'] !== null) {
                $assetChanges['condition_rating'] = $data['condition_in'];
            }

            Database::update('assets', $assetChanges, (int) $loan['asset_id']);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** Extend an open loan's due date. */
    public static function extend(int $loanId, string $newDueDate): void
    {
        Database::update('loans', [
            'due_back_date' => $newDueDate,
            'status'        => $newDueDate < date('Y-m-d') ? 'Overdue' : 'Out',
        ], $loanId);
    }

    /** Human-friendly loan reference, e.g. LN-2026-0001. */
    public static function nextReference(): string
    {
        $prefix = (string) Setting::get('loan_reference_prefix', 'LN-');
        $year   = date('Y');
        $stem   = $prefix . $year . '-';

        $max = Database::scalar(
            "SELECT MAX(CAST(SUBSTRING(reference, ?) AS UNSIGNED))
               FROM loans
              WHERE reference LIKE ? ESCAPE '!'",
            [strlen($stem) + 1, str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $stem) . '%']
        );

        $number = ($max === null ? 0 : (int) $max) + 1;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = $stem . str_pad((string) ($number + $attempt), 4, '0', STR_PAD_LEFT);

            $exists = (int) Database::scalar('SELECT COUNT(*) FROM loans WHERE reference = ?', [$candidate]);

            if ($exists === 0) {
                return $candidate;
            }
        }

        return $stem . strtoupper(bin2hex(random_bytes(3)));
    }

    /** @return array<int,array<string,mixed>> */
    public static function photos(int $loanId, ?string $stage = null): array
    {
        $sql    = 'SELECT p.*, u.name AS uploaded_by_name
                     FROM loan_photos p
                     LEFT JOIN users u ON u.id = p.uploaded_by
                    WHERE p.loan_id = ?';
        $params = [$loanId];

        if ($stage !== null) {
            $sql     .= ' AND p.stage = ?';
            $params[] = $stage;
        }

        return Database::select($sql . ' ORDER BY p.id', $params);
    }

    /** @return array<string,mixed>|null */
    public static function findPhoto(int $photoId): ?array
    {
        return Database::selectOne('SELECT * FROM loan_photos WHERE id = ?', [$photoId]);
    }

    /** @param array<string,mixed> $data */
    public static function addPhoto(array $data): int
    {
        return Database::insert('loan_photos', $data);
    }
}
