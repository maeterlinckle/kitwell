<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csv;
use App\Core\Request;
use App\Core\View;
use App\Models\ActivityLog;
use App\Reports\Report;
use App\Reports\ReportRegistry;

/**
 * One controller for every report.
 *
 * Nothing here knows about any particular report: the registry supplies them,
 * and each report describes its own columns, filters and rows. Adding a report
 * needs no change to this file.
 */
final class ReportController extends Controller
{
    public function index(): void
    {
        $this->view('reports/index', [
            'pageTitle' => 'Reports',
            'grouped'   => ReportRegistry::grouped(),
        ]);
    }

    public function show(string $key): void
    {
        $report  = $this->resolve($key);
        $filters = $report->normaliseFilters(Request::all());
        $rows    = $report->rows($filters);

        $format = (string) Request::query('format', 'html');

        if ($format === 'csv') {
            $this->export($report, $filters, $rows);
        }

        $data = [
            'pageTitle'   => $report->name(),
            'report'      => $report,
            'rows'        => $rows,
            'filters'     => $filters,
            'summary'     => $report->summary($rows, $filters),
            'subtitle'    => $report->subtitle($rows, $filters),
            'queryString' => self::queryString($filters),
        ];

        if ($format === 'print') {
            View::render('reports/print', $data + ['backUrl' => '/reports/' . $report->key()], 'layouts/print');

            return;
        }

        $this->view('reports/show', $data);
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<int,array<string,mixed>> $rows
     */
    private function export(Report $report, array $filters, array $rows): never
    {
        if (!Auth::can($report->exportPermission())) {
            Auth::authorize($report->exportPermission());
        }

        $columns  = $report->columns();
        $headings = [];

        foreach ($columns as $definition) {
            if (($definition['csv'] ?? true) !== false) {
                $headings[] = (string) $definition['label'];
            }
        }

        $lines = [];

        foreach ($rows as $row) {
            $line = [];

            foreach ($columns as $columnKey => $definition) {
                if (($definition['csv'] ?? true) === false) {
                    continue;
                }

                $line[] = self::csvValue($row[$columnKey] ?? null, (string) ($definition['type'] ?? 'text'));
            }

            $lines[] = $line;
        }

        ActivityLog::record(
            'exported',
            'report',
            null,
            sprintf('Exported the "%s" report (%d rows)', $report->name(), count($rows))
        );

        Csv::download($report->filename(), $headings, $lines);
    }

    /** Values in a CSV stay raw-ish: dates ISO, money plain, booleans words. */
    private static function csvValue(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'bool'   => ((int) $value === 1 ? 'Yes' : 'No'),
            'date'   => date('Y-m-d', (int) strtotime((string) $value)),
            'datetime' => date('Y-m-d H:i', (int) strtotime((string) $value)),
            'money'  => number_format((float) $value, 2, '.', ''),
            default  => (string) $value,
        };
    }

    private function resolve(string $key): Report
    {
        $report = ReportRegistry::find($key);

        if ($report === null) {
            $this->notFound('That report does not exist.');
        }

        // Two gates: the reports section, and the data the report is built on.
        Auth::authorize('reports.view');
        Auth::authorize($report->permission());

        return $report;
    }

    /** @param array<string,mixed> $filters */
    public static function queryString(array $filters): string
    {
        $params = [];

        foreach ($filters as $key => $value) {
            if ($value === '' || $value === false || $value === null) {
                continue;
            }

            $params[$key] = $value === true ? '1' : (string) $value;
        }

        return http_build_query($params);
    }
}
