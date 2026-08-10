<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use DateTimeImmutable;

/**
 * Maintenance schedules.
 *
 * Three types, all sharing one table:
 *   routine  — a standard cadence (weekly, monthly, quarterly, annual)
 *   periodic — any custom interval the site needs
 *   ad-hoc   — a one-off planned job, which closes itself once completed
 *
 * The "due status" is computed in SQL rather than PHP so it can be filtered,
 * sorted and counted by the database — which is what lets the reports module
 * (stage 7) query this directly rather than re-implementing the rules.
 */
final class MaintenanceSchedule
{
    public const TYPES = ['routine', 'periodic', 'ad-hoc'];
    public const UNITS = ['days', 'weeks', 'months', 'years'];

    /** Cadences offered for a "routine" schedule. */
    public const ROUTINE_PRESETS = [
        'weekly'      => ['label' => 'Weekly',        'interval' => 1,  'unit' => 'weeks'],
        'fortnightly' => ['label' => 'Every 2 weeks', 'interval' => 2,  'unit' => 'weeks'],
        'monthly'     => ['label' => 'Monthly',       'interval' => 1,  'unit' => 'months'],
        'quarterly'   => ['label' => 'Quarterly',     'interval' => 3,  'unit' => 'months'],
        'biannual'    => ['label' => 'Every 6 months','interval' => 6,  'unit' => 'months'],
        'annual'      => ['label' => 'Annually',      'interval' => 1,  'unit' => 'years'],
    ];

    /**
     * Due status, computed by the database.
     *
     * The `?` is the "due soon" horizon in days. Because it sits in the SELECT
     * list, its parameter must always be bound *before* any WHERE parameters.
     */
    private const STATUS_SQL = "CASE
            WHEN s.is_active = 0 THEN 'Inactive'
            WHEN s.next_due_date IS NULL THEN 'Unscheduled'
            WHEN s.next_due_date < CURDATE() THEN 'Overdue'
            WHEN s.next_due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 'Due soon'
            ELSE 'Scheduled'
        END";

    private static function selectSql(): string
    {
        return 'SELECT s.*,
                       ' . self::STATUS_SQL . ' AS due_status,
                       DATEDIFF(s.next_due_date, CURDATE()) AS days_until_due,
                       a.asset_tag, a.name AS asset_name, a.status AS asset_status,
                       a.condition_rating AS asset_condition,
                       c.name AS category_name,
                       l.name AS location_name,
                       u.name AS assigned_to_name,
                       cu.name AS created_by_name,
                       (SELECT COUNT(*) FROM maintenance_logs ml WHERE ml.schedule_id = s.id) AS completion_count
                  FROM maintenance_schedules s
                  INNER JOIN assets a ON a.id = s.asset_id
                  LEFT JOIN categories c ON c.id = a.category_id
                  LEFT JOIN locations l ON l.id = a.location_id
                  LEFT JOIN users u ON u.id = s.assigned_to_user_id
                  LEFT JOIN users cu ON cu.id = s.created_by';
    }

    public static function dueDays(): int
    {
        return max(1, min(365, Setting::int('maintenance_due_days', 30)));
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            self::selectSql() . ' WHERE s.id = ?',
            [self::dueDays(), $id]
        );
    }

    /**
     * Schedules attached to one asset, soonest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forAsset(int $assetId): array
    {
        return Database::select(
            self::selectSql() . ' WHERE s.asset_id = ?
              ORDER BY s.is_active DESC, s.next_due_date IS NULL, s.next_due_date ASC',
            [self::dueDays(), $assetId]
        );
    }

    /**
     * The one query behind the maintenance list, the dashboard and (from stage
     * 7) the reports module.
     *
     * Filters: q, status (Overdue|Due soon|Scheduled|Unscheduled|Inactive),
     *          type, asset_id, assigned_to, category_id, location_id,
     *          include_inactive, due_within_days, sort.
     *
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function search(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $dueDays = isset($filters['due_within_days'])
            ? max(0, (int) $filters['due_within_days'])
            : self::dueDays();

        [$where, $params] = self::buildFilters($filters, $dueDays);

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        // Same joins as the main query, because filters reach into assets.
        // The WHERE clause inlines the horizon, so the only parameter the count
        // does not need is the one belonging to the SELECT's status expression.
        $total = (int) Database::scalar(
            'SELECT COUNT(*)
               FROM maintenance_schedules s
               INNER JOIN assets a ON a.id = s.asset_id
               LEFT JOIN categories c ON c.id = a.category_id
               LEFT JOIN locations l ON l.id = a.location_id' . $whereSql,
            array_slice($params, 1)
        );

        $perPage = max(5, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $sorts = [
            'due'      => 's.next_due_date IS NULL, s.next_due_date ASC',
            'due_desc' => 's.next_due_date IS NULL, s.next_due_date DESC',
            'asset'    => 'a.asset_tag ASC',
            'title'    => 's.title ASC',
            'recent'   => 's.last_completed_date IS NULL, s.last_completed_date DESC',
        ];
        $orderBy = $sorts[(string) ($filters['sort'] ?? 'due')] ?? $sorts['due'];

        $rows = Database::select(
            self::selectSql() . $whereSql . ' ORDER BY ' . $orderBy . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
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
     * Every matching schedule, ignoring pagination — for reports and exports.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function searchAll(array $filters = [], int $limit = 5000): array
    {
        $dueDays = isset($filters['due_within_days'])
            ? max(0, (int) $filters['due_within_days'])
            : self::dueDays();

        [$where, $params] = self::buildFilters($filters, $dueDays);

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $orderBy  = 's.next_due_date IS NULL, s.next_due_date ASC';

        return Database::select(
            self::selectSql() . $whereSql . ' ORDER BY ' . $orderBy . ' LIMIT ' . max(1, min(20000, $limit)),
            $params
        );
    }

    /**
     * Counts by due status — the dashboard summary, and cheap enough to call
     * on every page load.
     *
     * @return array{overdue:int,due_soon:int,scheduled:int,unscheduled:int,due_days:int}
     */
    public static function summary(?int $dueDays = null): array
    {
        $dueDays = $dueDays ?? self::dueDays();

        $row = Database::selectOne(
            "SELECT
                SUM(CASE WHEN s.next_due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN s.next_due_date >= CURDATE()
                          AND s.next_due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS due_soon,
                SUM(CASE WHEN s.next_due_date > DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS scheduled,
                SUM(CASE WHEN s.next_due_date IS NULL THEN 1 ELSE 0 END) AS unscheduled
               FROM maintenance_schedules s
               INNER JOIN assets a ON a.id = s.asset_id
              WHERE s.is_active = 1 AND a.status <> 'Retired'",
            [$dueDays, $dueDays]
        );

        return [
            'overdue'     => (int) ($row['overdue'] ?? 0),
            'due_soon'    => (int) ($row['due_soon'] ?? 0),
            'scheduled'   => (int) ($row['scheduled'] ?? 0),
            'unscheduled' => (int) ($row['unscheduled'] ?? 0),
            'due_days'    => $dueDays,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:array<int,string>,1:array<int,mixed>}
     */
    private static function buildFilters(array $filters, int $dueDays): array
    {
        // The status expression in the SELECT binds first.
        $params = [$dueDays];
        $where  = [];

        $keywords = trim((string) ($filters['q'] ?? ''));
        if ($keywords !== '') {
            $like    = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keywords) . '%';
            $columns = ['s.title', 's.instructions', 'a.asset_tag', 'a.name', 'a.serial_number'];

            $clauses = [];
            foreach ($columns as $column) {
                $clauses[] = $column . " LIKE ? ESCAPE '!'";
                $params[]  = $like;
            }

            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        if (!empty($filters['status'])) {
            $statuses = array_values(array_intersect(
                (array) $filters['status'],
                ['Overdue', 'Due soon', 'Scheduled', 'Unscheduled', 'Inactive']
            ));

            if ($statuses !== []) {
                // Repeat the expression in WHERE: MariaDB cannot filter on a
                // SELECT alias, and repeating it keeps one definition of the rules.
                $where[] = self::statusSqlLiteral($dueDays)
                    . ' IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';

                foreach ($statuses as $status) {
                    $params[] = $status;
                }
            }
        }

        if (!empty($filters['type'])) {
            $types = array_values(array_intersect((array) $filters['type'], self::TYPES));

            if ($types !== []) {
                $where[] = 's.maintenance_type IN (' . implode(', ', array_fill(0, count($types), '?')) . ')';
                foreach ($types as $type) {
                    $params[] = $type;
                }
            }
        }

        if (!empty($filters['asset_id'])) {
            $where[]  = 's.asset_id = ?';
            $params[] = (int) $filters['asset_id'];
        }

        if (!empty($filters['assigned_to'])) {
            $where[]  = 's.assigned_to_user_id = ?';
            $params[] = (int) $filters['assigned_to'];
        }

        if (!empty($filters['category_id'])) {
            $where[]  = 'a.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }

        if (!empty($filters['location_id'])) {
            $where[]  = 'a.location_id = ?';
            $params[] = (int) $filters['location_id'];
        }

        if (empty($filters['include_inactive'])) {
            $where[] = 's.is_active = 1';
        }

        if (empty($filters['include_retired_assets'])) {
            $where[] = "a.status <> 'Retired'";
        }

        return [$where, $params];
    }

    /** The status expression with the horizon inlined (an int, never user text). */
    private static function statusSqlLiteral(int $dueDays): string
    {
        return str_replace('INTERVAL ? DAY', 'INTERVAL ' . (int) $dueDays . ' DAY', self::STATUS_SQL);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('maintenance_schedules', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('maintenance_schedules', $data, $id);
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM maintenance_schedules WHERE id = ?', [$id]);
    }

    /**
     * Work out the next due date after a completion.
     *
     * Recurring schedules count forward from the date the work was actually
     * done, which is what a workshop expects: a six-monthly service completed
     * two weeks late is next due six months from that day, not from the date
     * it was originally meant to happen. Ad-hoc jobs do not recur at all.
     */
    public static function nextDueAfter(array $schedule, string $performedOn): ?string
    {
        if ($schedule['maintenance_type'] === 'ad-hoc') {
            return null;
        }

        $interval = (int) ($schedule['frequency_interval'] ?? 0);
        $unit     = (string) ($schedule['frequency_unit'] ?? '');

        if ($interval < 1 || !in_array($unit, self::UNITS, true)) {
            return null;
        }

        $from = DateTimeImmutable::createFromFormat('Y-m-d', $performedOn);
        if ($from === false) {
            return null;
        }

        return $from->modify(sprintf('+%d %s', $interval, $unit))->format('Y-m-d');
    }

    /** Human-readable cadence, e.g. "Every 3 months". */
    public static function describeFrequency(array $schedule): string
    {
        if ($schedule['maintenance_type'] === 'ad-hoc') {
            return 'One-off';
        }

        $interval = (int) ($schedule['frequency_interval'] ?? 0);
        $unit     = (string) ($schedule['frequency_unit'] ?? '');

        if ($interval < 1 || $unit === '') {
            return 'No recurrence set';
        }

        if ($interval === 1) {
            return match ($unit) {
                'days'   => 'Daily',
                'weeks'  => 'Weekly',
                'months' => 'Monthly',
                'years'  => 'Annually',
                default  => 'Every ' . $unit,
            };
        }

        return 'Every ' . $interval . ' ' . $unit;
    }

    /**
     * A date this far ahead of a starting point, or null if the interval makes
     * no sense.
     *
     * Shared by the follow-up check on the completion form and anything else
     * that has to turn "3 weeks" into a date.
     */
    public static function dateAfter(string $from, int $interval, string $unit): ?string
    {
        if ($interval < 1 || !in_array($unit, self::UNITS, true)) {
            return null;
        }

        $start = DateTimeImmutable::createFromFormat('Y-m-d', $from);

        return $start === false ? null : $start->modify(sprintf('+%d %s', $interval, $unit))->format('Y-m-d');
    }

    /**
     * Schedule a follow-up check.
     *
     * A one-off (`ad-hoc`) schedule: it appears in the maintenance list and the
     * reminders like anything else, and closes itself once completed, so a
     * "check the belt again in three weeks" cannot quietly become a recurring
     * job nobody meant to create.
     *
     * @param array<string,mixed> $data title, due_date, instructions, assigned_to_user_id
     */
    public static function createFollowUp(int $assetId, array $data): int
    {
        return self::create([
            'asset_id'            => $assetId,
            'title'               => $data['title'],
            'maintenance_type'    => 'ad-hoc',
            'frequency_interval'  => null,
            'frequency_unit'      => null,
            'next_due_date'       => $data['due_date'],
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
            'instructions'        => $data['instructions'] ?? null,
            'is_active'           => 1,
            'created_by'          => Auth::id(),
        ]);
    }

    /** Record a completion against the schedule itself. */
    public static function applyCompletion(int $id, string $performedOn, ?string $nextDue, bool $closeAdHoc): void
    {
        $data = [
            'last_completed_date' => $performedOn,
            'next_due_date'       => $nextDue,
        ];

        if ($closeAdHoc) {
            $data['is_active'] = 0;
        }

        Database::update('maintenance_schedules', $data, $id);
    }

    /** @return array<int,array<string,mixed>> */
    public static function assignableUsers(): array
    {
        return Database::select(
            'SELECT id, name FROM users WHERE is_active = 1 ORDER BY name'
        );
    }
}
