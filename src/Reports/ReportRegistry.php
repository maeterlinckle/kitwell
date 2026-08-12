<?php

declare(strict_types=1);

namespace App\Reports;

use App\Core\Auth;

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

    /** Extra reports registered at runtime (used by the test suite). */
    private static array $additional = [];

    /**
     * Every report, keyed by its key.
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

        return array_merge(self::$instances, self::$additional);
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
