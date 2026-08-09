<?php

declare(strict_types=1);

namespace App\Imports;

/**
 * A CSV importer.
 *
 * Like the report registry, an importer describes itself — its columns, which
 * are required, what a valid value looks like — and the controller, preview
 * screen, template download and commit step are all generic.
 *
 * Column definitions accept:
 *   label       string  the heading used in the template (required)
 *   required    bool    the column must be present in the file
 *   aliases     array   other headings that mean the same thing
 *   description string  shown in the on-screen format documentation
 *   example     string  used in the downloadable template
 */
abstract class Importer
{
    /** Row is valid and will be created. */
    public const STATUS_OK = 'ok';

    /** Row is valid but something is worth knowing about. */
    public const STATUS_WARNING = 'warning';

    /** Row will be skipped. */
    public const STATUS_ERROR = 'error';

    abstract public function key(): string;

    abstract public function name(): string;

    abstract public function description(): string;

    /** Permission required to run this import. */
    abstract public function permission(): string;

    /** @return array<string,array<string,mixed>> */
    abstract public function columns(): array;

    /**
     * Check and normalise one row.
     *
     * Never writes anything. $context is shared across the whole file so an
     * importer can spot duplicates within the upload itself.
     *
     * @param array<string,string> $row
     * @param array<string,mixed>  $context
     * @param array<string,mixed>  $options
     * @return array{status:string,errors:array<int,string>,warnings:array<int,string>,data:array<string,mixed>,summary:string}
     */
    abstract public function validateRow(array $row, array &$context, array $options): array;

    /**
     * Write one validated row. Returns a short description for the log.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $options
     */
    abstract public function commitRow(array $data, array $options): string;

    /** Extra options offered on the upload form. */
    public function optionDefinitions(): array
    {
        return [];
    }

    /** Columns shown in the preview table, in order. */
    public function previewColumns(): array
    {
        return array_slice(array_keys($this->columns()), 0, 6);
    }

    /** Guidance shown above the upload form. */
    public function notes(): array
    {
        return [];
    }

    /**
     * Rows for the downloadable template: one example row built from each
     * column's `example`.
     *
     * @return array<int,array<int,string>>
     */
    public function templateRows(): array
    {
        $row = [];

        foreach ($this->templateColumns() as $definition) {
            $row[] = (string) ($definition['example'] ?? '');
        }

        return [$row];
    }

    /** @return array<int,string> */
    final public function templateHeadings(): array
    {
        return array_values(array_map(
            static fn (array $definition): string => (string) $definition['label'],
            $this->templateColumns()
        ));
    }

    /**
     * Columns worth putting in the template: everything except the ones that
     * exist only so an exported file re-imports without complaint.
     *
     * @return array<string,array<string,mixed>>
     */
    final public function templateColumns(): array
    {
        return array_filter(
            $this->columns(),
            static fn (array $definition): bool => empty($definition['ignore'])
        );
    }

    /** Fresh context for a run. */
    public function newContext(): array
    {
        return [];
    }

    /**
     * Normalise the options posted with the upload against the declarations.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    final public function normaliseOptions(array $input): array
    {
        $options = [];

        foreach ($this->optionDefinitions() as $key => $definition) {
            $value = $input[$key] ?? null;

            $options[$key] = $value === null
                ? (bool) ($definition['default'] ?? false)
                : in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
        }

        return $options;
    }

    /* ---------------------------------------------------------------------
     * Shared value parsing. Spreadsheets are inconsistent; these are the
     * conversions every importer ends up needing.
     * ------------------------------------------------------------------ */

    /** Accept ISO, UK and US-ish date formats; return Y-m-d or null. */
    public static function parseDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d', 'd/m/y', 'd M Y', 'j M Y', 'M j, Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);

            if ($date !== false) {
                $errors = \DateTimeImmutable::getLastErrors();

                if ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0)) {
                    return $date->format('Y-m-d');
                }
            }
        }

        return null;
    }

    /** Strip currency symbols, spaces and thousands separators. */
    public static function parseNumber(string $value): ?float
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $cleaned = preg_replace('/[^0-9.\-]/u', '', str_replace(',', '', $value)) ?? '';

        if ($cleaned === '' || !is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }

    /**
     * Interpret the many ways a spreadsheet says yes or no.
     * Returns null when the value is blank or unrecognised.
     */
    public static function parseBool(string $value): ?bool
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        if (in_array($value, ['1', 'y', 'yes', 'true', 't', 'pass', 'passed', 'ok'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'n', 'no', 'false', 'f', 'fail', 'failed'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Match a free-text value against a list of allowed values, ignoring case
     * and spacing.
     *
     * @param array<int,string> $allowed
     */
    public static function matchOption(string $value, array $allowed): ?string
    {
        $needle = preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value))) ?? '';

        if ($needle === '') {
            return null;
        }

        foreach ($allowed as $option) {
            if ((preg_replace('/[^a-z0-9]+/', '', strtolower($option)) ?? '') === $needle) {
                return $option;
            }
        }

        return null;
    }
}
