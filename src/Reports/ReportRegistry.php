<?php

declare(strict_types=1);

namespace App\Reports;

use App\Core\Auth;
use App\Models\CustomReport;

/**
 * The report registry.
 *
 * To add a report: write a class extending Report, then add it to the list
 * below. That is the whole change — routing, filtering, the table, the CSV
 * export and the print view are all generic.
 */
final class ReportRegistry
{
    /**
     * Registered report classes, in the order they appear on the index.
     *
     * @var array<int,class-string<Report>>
     */
    private const REPORTS = [
        AllAssetsReport::class,
        FaultyAssetsReport::class,
        MaintenanceDueReport::class,
        PatDueReport::class,
        AssetsOnHireReport::class,
        HiresDueBackReport::class,
    ];

    /** @var array<string,Report>|null */
    private static ?array $instances = null;

    /** @var array<string,Report>|null */
    private static ?array $stored = null;

    /** Extra reports registered at runtime (used by the test suite). */
    private static array $additional = [];

    /**
     * Every report, keyed by its key.
     *
     * Built-ins first, then the saved definitions — which arrive here as
     * ordinary `Report` objects, so nothing downstream has to know the
     * difference. A definition whose data source no longer exists is dropped by
     * `StoredReport::fromRow()` rather than appearing and then failing to open.
     *
     * @return array<string,Report>
     */
    public static function all(): array
    {
        if (self::$instances === null) {
            self::$instances = [];

            foreach (self::REPORTS as $class) {
                /** @var Report $report */
                $report = new $class();
                self::$instances[$report->key()] = $report;
            }
        }

        return array_merge(self::$instances, self::storedReports(), self::$additional);
    }

    /**
     * The saved definitions, read once per request.
     *
     * @return array<string,Report>
     */
    private static function storedReports(): array
    {
        if (self::$stored !== null) {
            return self::$stored;
        }

        self::$stored = [];

        // Guarded because the registry is reachable from the reports index on a
        // database that has not been migrated to 024 yet. A missing table
        // should cost the saved reports, not the whole page.
        try {
            $rows = CustomReport::all(true);
        } catch (\Throwable) {
            return self::$stored;
        }

        foreach ($rows as $row) {
            $report = StoredReport::fromRow($row);

            if ($report !== null) {
                self::$stored[$report->key()] = $report;
            }
        }

        return self::$stored;
    }

    /** Drop the memoised definitions — after one is saved, edited or deleted. */
    public static function forgetStored(): void
    {
        self::$stored = null;
    }

    /**
     * Reports the signed-in user may actually see.
     *
     * @return array<string,Report>
     */
    public static function available(): array
    {
        if (!Auth::can('reports.view')) {
            return [];
        }

        return array_filter(
            self::all(),
            static fn (Report $report): bool => Auth::can($report->permission())
        );
    }

    /**
     * Available reports grouped for display.
     *
     * @return array<string,array<string,Report>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::available() as $key => $report) {
            $grouped[$report->group()][$key] = $report;
        }

        return $grouped;
    }

    public static function find(string $key): ?Report
    {
        return self::all()[$key] ?? null;
    }

    /** Register a report at runtime. */
    public static function register(Report $report): void
    {
        self::$additional[$report->key()] = $report;
    }

    public static function forget(string $key): void
    {
        unset(self::$additional[$key]);
    }
}
