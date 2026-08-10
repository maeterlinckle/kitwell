<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use DateTimeImmutable;

/**
 * Portable Appliance Testing records.
 *
 * One row per test, so an asset keeps its full testing history rather than
 * just the latest result — which is the point of a PAT register.
 *
 * As with maintenance, the "is it in date?" question is answered in SQL so it
 * can be filtered, sorted and counted by the database, and so the reports
 * module (stage 7) can query it without re-implementing the rules.
 *
 * Units are explicit everywhere: ohms, megohms, milliamps, amps, volt-amps.
 */
final class PatRecord
{
    public const CLASSES = ['Class I', 'Class II', 'Class III', 'Not Applicable'];
    public const RESULTS = ['Pass', 'Fail'];

    /**
     * The visual checks, in the order a tester actually performs them.
     * `fuse` is only asked when the asset records has_fuse = 1.
     *
     * column => [label, help]
     */
    public const VISUAL_CHECKS = [
        'visual_plug_pass' => [
            'Plug',
            'Correct wiring, secure cord grip, pins undamaged, no scorching or cracks.',
        ],
        'visual_cable_pass' => [
            'Cable',
            'No cuts, fraying, kinks, crushing or taped repairs along the full length.',
        ],
        'visual_case_pass' => [
            'Case / enclosure',
            'No cracks or damage, guards present, nothing loose inside when handled.',
        ],
        'visual_fuse_pass' => [
            'Fuse',
            'The fuse actually fitted matches the rating recorded for this asset.',
        ],
    ];

    /**
     * The electrical tests. Which of these are asked depends on the asset's
     * appliance class — see Asset::CLASS_TESTS.
     *
     * key => [value column, verdict column, label, unit, decimal step]
     */
    public const ELECTRICAL_TESTS = [
        'earth_continuity' => [
            'earth_continuity_ohms', 'earth_continuity_pass',
            'Earth continuity', 'Ω', '0.001',
        ],
        'insulation_resistance' => [
            'insulation_resistance_mohms', 'insulation_resistance_pass',
            'Insulation resistance', 'MΩ', '0.01',
        ],
        'leakage_current' => [
            'leakage_current_ma', 'leakage_current_pass',
            'Leakage current', 'mA', '0.001',
        ],
    ];

    /**
     * Status of an asset's PAT position.
     *
     * A failed item is called out separately: it is not "in date" just because
     * the retest date has not arrived, and it should not quietly look fine.
     */
    private const STATUS_SQL = "CASE
            WHEN a.requires_pat = 0 THEN 'Not required'
            WHEN a.status = 'Retired' THEN 'Retired'
            WHEN p.id IS NULL THEN 'Never tested'
            WHEN p.overall_result = 'Fail' THEN 'Failed'
            WHEN p.retest_due_date IS NULL THEN 'No retest date'
            WHEN p.retest_due_date < CURDATE() THEN 'Overdue'
            WHEN p.retest_due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 'Due soon'
            ELSE 'Current'
        END";

    /** Joins each asset to its most recent test. */
    private const LATEST_JOIN = 'LEFT JOIN pat_records p ON p.id = (
            SELECT p2.id FROM pat_records p2
             WHERE p2.asset_id = a.id
             ORDER BY p2.test_date DESC, p2.id DESC
             LIMIT 1
        )';

    public static function dueDays(): int
    {
        return max(1, min(365, Setting::int('pat_due_days', 30)));
    }

    public static function defaultIntervalMonths(): int
    {
        return max(1, min(120, Setting::int('pat_default_interval_months', 12)));
    }

    /**
     * The guideline pass ranges, from settings.
     *
     * These are shown to the tester as helper text and are GUIDANCE ONLY —
     * nothing compares a reading against them to decide a result. Acceptable
     * values vary by appliance, so the tester's own verdict is what counts.
     * They live in settings so a workshop can tune them without a code change.
     *
     * @return array<string,float>
     */
    public static function guidelines(): array
    {
        return [
            'insulation_mohm'   => self::settingFloat('pat_guide_insulation_mohm', 1.0),
            'earth_base_ohm'    => self::settingFloat('pat_guide_earth_base_ohm', 0.1),
            'earth_lead_ohm'    => self::settingFloat('pat_guide_earth_lead_ohm', 0.1),
            'earth_lead_metres' => max(0.1, self::settingFloat('pat_guide_earth_lead_metres', 7.5)),
            'leakage_class1_ma' => self::settingFloat('pat_guide_leakage_class1_ma', 3.5),
            'leakage_class2_ma' => self::settingFloat('pat_guide_leakage_class2_ma', 0.25),
        ];
    }

    private static function settingFloat(string $key, float $default): float
    {
        $value = Setting::get($key);

        return ($value === null || !is_numeric($value)) ? $default : (float) $value;
    }

    /**
     * The earth continuity guideline for a given length of extension lead.
     * Defaults allow 0.1 Ω for the appliance plus 0.1 Ω per 7.5 m of extra lead.
     */
    public static function earthGuideline(float $leadMetres = 0.0): float
    {
        $g = self::guidelines();

        return $g['earth_base_ohm'] + (max(0.0, $leadMetres) / $g['earth_lead_metres']) * $g['earth_lead_ohm'];
    }

    /** The leakage guideline for an appliance class, in milliamps. */
    public static function leakageGuideline(string $applianceClass): float
    {
        $g = self::guidelines();

        return $applianceClass === 'Class II' ? $g['leakage_class2_ma'] : $g['leakage_class1_ma'];
    }

    /**
     * Guideline helper text for one electrical test, ready to print.
     * Deliberately worded as guidance, never as a verdict.
     */
    public static function guidelineText(string $test, string $applianceClass, float $leadMetres = 0.0): string
    {
        $g = self::guidelines();

        return match ($test) {
            'insulation_resistance' => sprintf(
                'Typically %s MΩ or more.',
                self::trimNumber($g['insulation_mohm'])
            ),
            'earth_continuity' => $leadMetres > 0
                ? sprintf(
                    'Typically under %s Ω — %s Ω for the appliance plus %s Ω per %s m of lead, for %s m of lead.',
                    self::trimNumber(self::earthGuideline($leadMetres)),
                    self::trimNumber($g['earth_base_ohm']),
                    self::trimNumber($g['earth_lead_ohm']),
                    self::trimNumber($g['earth_lead_metres']),
                    self::trimNumber($leadMetres)
                )
                : sprintf(
                    'Typically under %s Ω for the appliance or lead alone, plus about %s Ω per %s m of any extension lead.',
                    self::trimNumber($g['earth_base_ohm']),
                    self::trimNumber($g['earth_lead_ohm']),
                    self::trimNumber($g['earth_lead_metres'])
                ),
            'leakage_current' => sprintf(
                'Typically under %s mA for %s.',
                self::trimNumber(self::leakageGuideline($applianceClass)),
                $applianceClass === 'Class II' ? 'Class II' : 'Class I'
            ),
            default => '',
        };
    }

    /** 3.50 -> "3.5", 1.00 -> "1". Guidance reads badly with trailing zeros. */
    private static function trimNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    /** The retest interval that applies to one asset, in months. */
    public static function intervalForAsset(?array $asset): int
    {
        $assetInterval = (int) ($asset['pat_interval_months'] ?? 0);

        return $assetInterval > 0 ? $assetInterval : self::defaultIntervalMonths();
    }

    /** Suggested retest date for a test performed on the given date. */
    public static function suggestRetestDate(string $testDate, ?array $asset): ?string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $testDate);

        if ($date === false) {
            return null;
        }

        return $date->modify('+' . self::intervalForAsset($asset) . ' months')->format('Y-m-d');
    }

    /** Every test for one asset, newest first. */
    public static function forAsset(int $assetId, ?int $limit = null): array
    {
        $sql = 'SELECT r.*, u.name AS tester_user_name, cu.name AS created_by_name
                  FROM pat_records r
                  LEFT JOIN users u ON u.id = r.tester_user_id
                  LEFT JOIN users cu ON cu.id = r.created_by
                 WHERE r.asset_id = ?
                 ORDER BY r.test_date DESC, r.id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, min(200, $limit));
        }

        return Database::select($sql, [$assetId]);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            'SELECT r.*, u.name AS tester_user_name, cu.name AS created_by_name,
                    a.asset_tag, a.name AS asset_name, a.requires_pat, a.pat_interval_months
               FROM pat_records r
               INNER JOIN assets a ON a.id = r.asset_id
               LEFT JOIN users u ON u.id = r.tester_user_id
               LEFT JOIN users cu ON cu.id = r.created_by
              WHERE r.id = ?',
            [$id]
        );
    }

    /**
     * The PAT position of one asset: its latest test plus the computed status.
     *
     * @return array<string,mixed>|null
     */
    public static function statusForAsset(int $assetId): ?array
    {
        return Database::selectOne(
            'SELECT ' . self::STATUS_SQL . ' AS pat_status,
                    DATEDIFF(p.retest_due_date, CURDATE()) AS days_until_due,
                    p.id AS latest_record_id, p.test_date, p.retest_due_date,
                    p.overall_result, p.appliance_class, p.pat_label_serial,
                    p.tester_name, p.visual_inspection_pass, p.functional_check_pass,
                    a.requires_pat, a.pat_interval_months,
                    (SELECT COUNT(*) FROM pat_records p3 WHERE p3.asset_id = a.id) AS test_count
               FROM assets a ' . self::LATEST_JOIN . '
              WHERE a.id = ?',
            [self::dueDays(), $assetId]
        );
    }

    /**
     * The PAT register: every asset that needs testing, with its latest result.
     *
     * Filters: q, status[], category_id, location_id, include_retired, sort.
     *
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function assetSearch(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $dueDays = self::dueDays();
        [$where, $params] = self::buildFilters($filters, $dueDays);

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM assets a ' . self::LATEST_JOIN
            . ' LEFT JOIN categories c ON c.id = a.category_id
                LEFT JOIN locations l ON l.id = a.location_id' . $whereSql,
            array_slice($params, 1)
        );

        $perPage = max(5, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $sorts = [
            'due'    => 'p.retest_due_date IS NULL, p.retest_due_date ASC',
            'tested' => 'p.test_date IS NULL, p.test_date DESC',
            'asset'  => 'a.asset_tag ASC',
            'name'   => 'a.name ASC',
        ];
        $orderBy = $sorts[(string) ($filters['sort'] ?? 'due')] ?? $sorts['due'];

        $rows = Database::select(
            'SELECT a.id, a.asset_tag, a.name, a.status AS asset_status, a.condition_rating,
                    a.requires_pat, a.pat_interval_months,
                    a.plug_fuse_rating_amps, a.cable_csa_mm2,
                    c.name AS category_name, l.name AS location_name,
                    ' . self::STATUS_SQL . ' AS pat_status,
                    DATEDIFF(p.retest_due_date, CURDATE()) AS days_until_due,
                    p.id AS latest_record_id, p.test_date, p.retest_due_date,
                    p.overall_result, p.appliance_class, p.pat_label_serial, p.tester_name,
                    (SELECT COUNT(*) FROM pat_records p3 WHERE p3.asset_id = a.id) AS test_count
               FROM assets a ' . self::LATEST_JOIN . '
               LEFT JOIN categories c ON c.id = a.category_id
               LEFT JOIN locations l ON l.id = a.location_id'
            . $whereSql . ' ORDER BY ' . $orderBy . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
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
     * Every matching asset, ignoring pagination — for reports and exports.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function assetSearchAll(array $filters = [], int $limit = 5000): array
    {
        $dueDays = self::dueDays();
        [$where, $params] = self::buildFilters($filters, $dueDays);

        return Database::select(
            'SELECT a.id, a.asset_tag, a.name, a.status AS asset_status, a.condition_rating,
                    a.requires_pat, a.pat_interval_months,
                    a.plug_fuse_rating_amps, a.cable_csa_mm2,
                    c.name AS category_name, l.name AS location_name,
                    ' . self::STATUS_SQL . ' AS pat_status,
                    DATEDIFF(p.retest_due_date, CURDATE()) AS days_until_due,
                    p.id AS latest_record_id, p.test_date, p.retest_due_date,
                    p.overall_result, p.appliance_class, p.pat_label_serial, p.tester_name,
                    (SELECT COUNT(*) FROM pat_records p3 WHERE p3.asset_id = a.id) AS test_count
               FROM assets a ' . self::LATEST_JOIN . '
               LEFT JOIN categories c ON c.id = a.category_id
               LEFT JOIN locations l ON l.id = a.location_id
              WHERE ' . implode(' AND ', $where)
            . ' ORDER BY p.retest_due_date IS NULL, p.retest_due_date ASC LIMIT ' . max(1, min(20000, $limit)),
            $params
        );
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:array<int,string>,1:array<int,mixed>}
     */
    private static function buildFilters(array $filters, int $dueDays): array
    {
        $params = [$dueDays];      // belongs to the status expression in the SELECT
        $where  = ['a.requires_pat = 1'];

        $keywords = trim((string) ($filters['q'] ?? ''));
        if ($keywords !== '') {
            $like    = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keywords) . '%';
            $columns = ['a.asset_tag', 'a.name', 'a.serial_number', 'a.manufacturer', 'a.model', 'p.pat_label_serial'];

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
                ['Never tested', 'Failed', 'Overdue', 'Due soon', 'Current', 'No retest date', 'Retired']
            ));

            if ($statuses !== []) {
                $where[] = self::statusSqlLiteral($dueDays)
                    . ' IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';

                foreach ($statuses as $status) {
                    $params[] = $status;
                }
            }
        }

        if (!empty($filters['category_id'])) {
            $where[]  = 'a.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }

        if (!empty($filters['location_id'])) {
            $where[]  = 'a.location_id = ?';
            $params[] = (int) $filters['location_id'];
        }

        if (empty($filters['include_retired'])) {
            $where[] = "a.status <> 'Retired'";
        }

        return [$where, $params];
    }

    private static function statusSqlLiteral(int $dueDays): string
    {
        return str_replace('INTERVAL ? DAY', 'INTERVAL ' . (int) $dueDays . ' DAY', self::STATUS_SQL);
    }

    /**
     * Counts by PAT status, for the dashboard and reports.
     *
     * @return array{never_tested:int,failed:int,overdue:int,due_soon:int,current:int,no_date:int,total:int,due_days:int}
     */
    public static function summary(): array
    {
        $dueDays = self::dueDays();
        $status  = self::statusSqlLiteral($dueDays);

        $rows = Database::select(
            'SELECT ' . $status . ' AS pat_status, COUNT(*) AS n
               FROM assets a ' . self::LATEST_JOIN . "
              WHERE a.requires_pat = 1 AND a.status <> 'Retired'
              GROUP BY pat_status"
        );

        $counts = [];
        $total  = 0;

        foreach ($rows as $row) {
            $counts[(string) $row['pat_status']] = (int) $row['n'];
            $total += (int) $row['n'];
        }

        return [
            'never_tested' => $counts['Never tested'] ?? 0,
            'failed'       => $counts['Failed'] ?? 0,
            'overdue'      => $counts['Overdue'] ?? 0,
            'due_soon'     => $counts['Due soon'] ?? 0,
            'current'      => $counts['Current'] ?? 0,
            'no_date'      => $counts['No retest date'] ?? 0,
            'total'        => $total,
            'due_days'     => $dueDays,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('pat_records', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('pat_records', $data, $id);
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM pat_records WHERE id = ?', [$id]);
    }

    /** Assets that need testing, for the "add a test" asset picker. */
    public static function testableAssets(): array
    {
        return Database::select(
            "SELECT id, asset_tag, name, appliance_class FROM assets
              WHERE requires_pat = 1 AND status <> 'Retired'
              ORDER BY asset_tag"
        );
    }

    /**
     * Format a measurement with its unit, trimming pointless trailing zeros.
     * Returns an em dash when the reading was not taken.
     */
    public static function measurement(mixed $value, string $unit): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $number = rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');

        return $number . ' ' . $unit;
    }
}
