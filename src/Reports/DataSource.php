<?php

declare(strict_types=1);

namespace App\Reports;

use Closure;

/**
 * One thing a custom report can be built on.
 *
 * A data source declares three lists and owns one closure:
 *
 *   - **filters** — a subset of the filter keys the underlying model *already*
 *     accepts, each with a label and an input type. These are not a new
 *     filtering language: `App\Models\Asset::searchAll()` has understood
 *     `category_id` and `status` since stage 2, and a custom report simply
 *     supplies values for them. That is the whole reason there is no SQL
 *     anywhere in the custom-report code, and no user-supplied string ever
 *     reaches a query.
 *   - **columns** — the fields that may be shown, in `App\Reports\Report`'s own
 *     column vocabulary (label, type, link, badge, sub), so the generic table,
 *     print view and CSV render a custom report exactly as they render a
 *     built-in one.
 *   - **fetch** — hands the assembled filter array to the model method the list
 *     page uses, and returns its rows.
 *
 * Nothing here is stored. A definition names a source by key; if a source is
 * later removed from the registry, its reports stop appearing rather than
 * failing halfway through rendering.
 */
final class DataSource
{
    /**
     * @param string                            $key        Stored in custom_reports.data_source
     * @param string                            $permission Required to open a report built on this
     * @param array<string,array<string,mixed>> $filters    filterKey => definition
     * @param array<string,array<string,mixed>> $columns    columnKey => Report column definition
     * @param Closure(array<string,mixed>):array<int,array<string,mixed>> $fetch
     * @param array<int,string>                 $defaultColumns Ticked when a new report is started
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly string $permission,
        public readonly string $group,
        private readonly array $filters,
        private readonly array $columns,
        private readonly Closure $fetch,
        public readonly array $defaultColumns = [],
    ) {
    }

    /** @return array<string,array<string,mixed>> */
    public function filters(): array
    {
        return $this->filters;
    }

    /** @return array<string,array<string,mixed>> */
    public function columns(): array
    {
        return $this->columns;
    }

    public function hasColumn(string $key): bool
    {
        return isset($this->columns[$key]);
    }

    /**
     * Rows for a set of stored filter values.
     *
     * The values are cleaned against the declared filters first, so a key the
     * source never offered — from a hand-edited row, or from a source that has
     * changed since the report was saved — is dropped rather than passed
     * through to the model.
     *
     * @param array<string,mixed> $stored
     * @return array<int,array<string,mixed>>
     */
    public function rows(array $stored): array
    {
        return ($this->fetch)($this->cleanFilters($stored));
    }

    /**
     * Keep only values this source declares, coerced to the declared type.
     *
     * @param array<string,mixed> $stored
     * @return array<string,mixed>
     */
    public function cleanFilters(array $stored): array
    {
        $clean = [];

        foreach ($this->filters as $key => $definition) {
            if (!array_key_exists($key, $stored)) {
                continue;
            }

            $value = $stored[$key];
            $type  = (string) ($definition['type'] ?? 'text');

            $value = match ($type) {
                'multi' => self::allowedList($value, (array) ($definition['options'] ?? [])),
                'select' => array_key_exists((string) $value, (array) ($definition['options'] ?? []))
                    ? (string) $value
                    : '',
                'date'   => (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) ? $value : '',
                'number' => is_numeric($value) ? (string) (int) $value : '',
                'bool'   => in_array($value, [true, 1, '1', 'on', 'yes'], true) ? '1' : '',
                default  => is_string($value) ? trim($value) : '',
            };

            if ($value === '' || $value === []) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * @param array<string,string> $options
     * @return array<int,string>
     */
    private static function allowedList(mixed $value, array $options): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_intersect(
            array_map('strval', $value),
            array_keys($options)
        ));
    }

    /**
     * Describe the stored filters in words, for the report's subtitle.
     *
     * @param array<string,mixed> $stored
     * @return array<int,string>
     */
    public function describeFilters(array $stored): array
    {
        $parts = [];

        foreach ($this->cleanFilters($stored) as $key => $value) {
            $definition = $this->filters[$key];
            $label      = (string) $definition['label'];
            $options    = (array) ($definition['options'] ?? []);

            $shown = match ((string) ($definition['type'] ?? 'text')) {
                'multi'  => implode(', ', array_map(
                    static fn (string $v): string => (string) ($options[$v] ?? $v),
                    (array) $value
                )),
                'select' => (string) ($options[(string) $value] ?? $value),
                'bool'   => 'yes',
                default  => (string) $value,
            };

            $parts[] = $label . ': ' . $shown;
        }

        return $parts;
    }
}
