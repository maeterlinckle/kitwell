<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\CustomReport;

/**
 * A saved definition, wearing the same coat as a built-in report.
 *
 * This is the whole of the "custom reports are not a parallel system" claim: a
 * StoredReport is a `Report`, so `ReportController` opens it, the generic table
 * renders it, the print view prints it and the CSV export exports it, none of
 * them knowing or caring that its columns came out of a database row. The
 * registry hands it back from `find()` like any other.
 *
 * Two differences from a built-in, both deliberate:
 *
 * 1. **Its filters are fixed at save time, not offered on the page.** A saved
 *    report is somebody's considered question — "the Fluke testers in bay 2
 *    that are overdue" — and re-offering every filter would turn it back into
 *    the list page it was made to replace. The stored criteria are shown as
 *    text under the title so a reader can see what they are looking at, and
 *    `filterDefinitions()` stays empty so the generic filter bar renders
 *    nothing.
 *
 * 2. **Its sort is applied in PHP, after the rows come back.** The models sort
 *    by their own named orderings; letting a definition name an arbitrary
 *    column would mean building a column name into SQL, which is the one thing
 *    this feature is designed never to do. Sorting a bounded result set in
 *    memory costs nothing worth measuring and keeps every query parameterised.
 */
final class StoredReport extends Report
{
    /** @param array<string,mixed> $definition A decoded `custom_reports` row */
    public function __construct(
        private readonly array $definition,
        private readonly DataSource $source,
    ) {
    }

    /** @param array<string,mixed> $definition */
    public static function fromRow(array $definition): ?self
    {
        $source = DataSourceRegistry::find((string) $definition['data_source']);

        // A definition naming a source that no longer exists simply stops being
        // a report. Better a missing entry on the index than a page that throws
        // when somebody opens it.
        return $source === null ? null : new self($definition, $source);
    }

    public function id(): int
    {
        return (int) $this->definition['id'];
    }

    public function key(): string
    {
        return (string) $this->definition['report_key'];
    }

    public function name(): string
    {
        return (string) $this->definition['name'];
    }

    public function description(): string
    {
        $description = trim((string) ($this->definition['description'] ?? ''));

        if ($description !== '') {
            return $description;
        }

        $filters = $this->source->describeFilters($this->definition['filters']);

        return $filters === []
            ? 'Every row from ' . lcfirst($this->source->label) . '.'
            : $this->source->label . ' where ' . implode('; ', $filters) . '.';
    }

    public function permission(): string
    {
        return $this->source->permission;
    }

    public function group(): string
    {
        return 'Saved reports';
    }

    public function isCustom(): bool
    {
        return true;
    }

    public function sourceLabel(): string
    {
        return $this->source->label;
    }

    public function isActive(): bool
    {
        return (int) ($this->definition['is_active'] ?? 1) === 1;
    }

    /**
     * The chosen columns, in the chosen order.
     *
     * Filtered against the source's own list on the way out, so a column that
     * has since been removed from a source disappears from the report rather
     * than rendering an empty cell under a heading for a field that is gone.
     */
    public function columns(): array
    {
        $available = $this->source->columns();
        $columns   = [];

        foreach ((array) $this->definition['columns'] as $key) {
            if (isset($available[$key])) {
                $columns[$key] = $available[$key];
            }
        }

        // A definition with nothing left standing still has to render something
        // rather than an empty <table>.
        return $columns === [] ? array_slice($available, 0, 4, true) : $columns;
    }

    public function rows(array $filters): array
    {
        $rows = $this->source->rows((array) $this->definition['filters']);

        return $this->sort($rows);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function sort(array $rows): array
    {
        $column = (string) ($this->definition['sort_column'] ?? '');

        if ($column === '' || !$this->source->hasColumn($column)) {
            return $rows;
        }

        $descending = (string) ($this->definition['sort_direction'] ?? 'asc') === 'desc';
        $type       = (string) ($this->source->columns()[$column]['type'] ?? 'text');

        // usort is not stable before PHP 8.0 and the ordering of equal rows is
        // whatever the model returned, which is itself deliberate — so ties keep
        // the source's own order rather than being shuffled.
        usort($rows, static function (array $a, array $b) use ($column, $type, $descending): int {
            $left  = $a[$column] ?? null;
            $right = $b[$column] ?? null;

            // Empty always sorts last, whichever direction is asked for: a row
            // with no due date is not "the earliest", it is unanswered.
            $leftEmpty  = $left === null || $left === '';
            $rightEmpty = $right === null || $right === '';

            if ($leftEmpty || $rightEmpty) {
                return $leftEmpty === $rightEmpty ? 0 : ($leftEmpty ? 1 : -1);
            }

            $result = match ($type) {
                'date', 'datetime' => (strtotime((string) $left) ?: 0) <=> (strtotime((string) $right) ?: 0),
                'money', 'number'  => (float) $left <=> (float) $right,
                'bool'             => (int) $left <=> (int) $right,
                default            => strnatcasecmp((string) $left, (string) $right),
            };

            return $descending ? -$result : $result;
        });

        return $rows;
    }

    public function subtitle(array $rows, array $filters): string
    {
        $count  = count($rows);
        $line   = $count === 1 ? '1 row' : number_format($count) . ' rows';
        $line  .= ' from ' . lcfirst($this->source->label);

        $described = $this->source->describeFilters((array) $this->definition['filters']);

        return $described === []
            ? $line . ', unfiltered.'
            : $line . ', filtered by ' . implode('; ', $described) . '.';
    }

    public function emptyMessage(): string
    {
        return 'Nothing matches this report’s saved criteria. Edit it to widen them.';
    }

    public function filename(): string
    {
        return $this->key() . '-' . date('Y-m-d');
    }
}
