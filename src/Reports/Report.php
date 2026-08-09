<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * A report.
 *
 * Everything a report needs to describe itself lives here: its name, who may
 * see it, what columns it has, what filters it offers and how to fetch its
 * rows. The controller, the HTML table, the CSV export and the print view are
 * all generic and driven from these declarations — so adding a new report
 * means writing one class and adding one line to ReportRegistry, with no
 * changes to routes, controllers or templates.
 *
 * Column definitions accept:
 *   label   string  heading text (required)
 *   type    string  text|date|datetime|money|number|badge|bool  (default text)
 *   align   string  left|right
 *   link    string  'asset' | 'loan' | 'maintenance' | 'borrower' — makes the
 *                   cell a link, using the row's <link>_id (or id) value
 *   badge   string  CSS class prefix for a badge cell, e.g. 'due-'
 *   csv     bool    include in the CSV export (default true)
 *   sub     string  another row key rendered small underneath
 */
abstract class Report
{
    /** Stable identifier used in the URL. */
    abstract public function key(): string;

    abstract public function name(): string;

    abstract public function description(): string;

    /** Permission required to see this report, on top of `reports.view`. */
    abstract public function permission(): string;

    /** @return array<string,array<string,mixed>> */
    abstract public function columns(): array;

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    abstract public function rows(array $filters): array;

    /** Grouping on the reports index. */
    public function group(): string
    {
        return 'General';
    }

    /** Permission required to export. Defaults to the view permission. */
    public function exportPermission(): string
    {
        return $this->permission();
    }

    /**
     * Filters this report offers, rendered generically.
     *
     * Each is: key => ['label' => …, 'type' => 'search|select|checkbox|date',
     *                  'options' => [value => label], 'default' => …]
     *
     * @return array<string,array<string,mixed>>
     */
    public function filterDefinitions(): array
    {
        return [];
    }

    /**
     * Headline figures shown above the table.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $filters
     * @return array<int,array{label:string,value:string|int,tone?:string}>
     */
    public function summary(array $rows, array $filters): array
    {
        return [];
    }

    public function emptyMessage(): string
    {
        return 'Nothing matched this report.';
    }

    /** Sensible sentence describing what the reader is looking at. */
    public function subtitle(array $rows, array $filters): string
    {
        $count = count($rows);

        return $count === 1 ? '1 row' : number_format($count) . ' rows';
    }

    /**
     * Normalise raw query input against the declared filters, so a report
     * never has to trust or re-parse the request.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    final public function normaliseFilters(array $input): array
    {
        $filters = [];

        foreach ($this->filterDefinitions() as $key => $definition) {
            $type  = (string) ($definition['type'] ?? 'search');
            $value = $input[$key] ?? null;

            $filters[$key] = match ($type) {
                'checkbox' => in_array((string) $value, ['1', 'true', 'on', 'yes'], true),
                'select'   => (is_string($value) && array_key_exists($value, (array) ($definition['options'] ?? [])))
                    ? $value
                    : (string) ($definition['default'] ?? ''),
                'date'     => (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) ? $value : '',
                default    => is_string($value) ? trim($value) : '',
            };

            if ($type === 'checkbox' && $value === null && array_key_exists('default', $definition)) {
                $filters[$key] = (bool) $definition['default'];
            }
        }

        return $filters;
    }

    /** Filename stem for exports. */
    public function filename(): string
    {
        return $this->key() . '-' . date('Y-m-d');
    }
}
