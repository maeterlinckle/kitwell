<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Reads an uploaded CSV into rows keyed by our own column names.
 *
 * Real-world spreadsheet exports are untidy: a UTF-8 byte-order mark from
 * Excel, semicolons instead of commas from a European locale, headings with
 * different capitalisation and spacing, stray blank lines. This handles all of
 * that so the importers can deal with data rather than formatting.
 */
final class CsvReader
{
    /** Hard ceiling on rows per import, to keep a runaway file from stalling. */
    public const MAX_ROWS = 2000;

    /** @var array<int,array<string,string>> */
    private array $rows = [];

    /** @var array<int,string> Headings as they appeared in the file. */
    private array $headings = [];

    /** @var array<int,string> Headings we could not match to a known column. */
    private array $unknownHeadings = [];

    /** @var array<int,string> */
    private array $missingRequired = [];

    private bool $truncated = false;

    /**
     * @param array<string,array<string,mixed>> $columns Column definitions, keyed by our name.
     */
    public function __construct(string $path, array $columns)
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        try {
            $delimiter = self::sniffDelimiter($path);
            $map       = null;
            $lineNo    = 0;

            // The $escape argument is passed explicitly: PHP 8.4 deprecates
            // relying on its default.
            while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $lineNo++;

                // Skip blank lines, including ones that are only separators.
                if ($values === [null] || implode('', array_map(static fn ($v): string => (string) $v, $values)) === '') {
                    continue;
                }

                if ($map === null) {
                    $values[0]      = self::stripBom((string) ($values[0] ?? ''));
                    $this->headings = array_map(static fn ($v): string => trim((string) $v), $values);
                    $map            = $this->mapHeadings($this->headings, $columns);

                    continue;
                }

                if (count($this->rows) >= self::MAX_ROWS) {
                    $this->truncated = true;
                    break;
                }

                $row = [];
                foreach ($map as $index => $columnKey) {
                    $row[$columnKey] = trim((string) ($values[$index] ?? ''));
                }

                // Ignore a row that is entirely empty after mapping.
                if (implode('', $row) === '') {
                    continue;
                }

                $row['__line'] = (string) $lineNo;
                $this->rows[]  = $row;
            }
        } finally {
            fclose($handle);
        }

        foreach ($columns as $key => $definition) {
            if (!empty($definition['required']) && !in_array($key, $map ?? [], true)) {
                $this->missingRequired[] = (string) $definition['label'];
            }
        }
    }

    /**
     * Match the file's headings to our column keys.
     *
     * Matching ignores case, spaces, underscores and punctuation, and each
     * column may declare aliases — so "Asset Tag", "asset_tag" and "Tag" all
     * land in the same place.
     *
     * @param array<int,string> $headings
     * @param array<string,array<string,mixed>> $columns
     * @return array<int,string> Column index => our column key
     */
    private function mapHeadings(array $headings, array $columns): array
    {
        $lookup = [];

        foreach ($columns as $key => $definition) {
            $lookup[self::normalise($key)] = $key;
            $lookup[self::normalise((string) $definition['label'])] = $key;

            foreach ((array) ($definition['aliases'] ?? []) as $alias) {
                $lookup[self::normalise((string) $alias)] = $key;
            }
        }

        $map = [];

        foreach ($headings as $index => $heading) {
            $normalised = self::normalise($heading);

            if ($normalised === '') {
                continue;
            }

            if (isset($lookup[$normalised])) {
                $map[$index] = $lookup[$normalised];
            } else {
                $this->unknownHeadings[] = $heading;
            }
        }

        return $map;
    }

    private static function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\(.*?\)/', '', $value) ?? $value;   // drop "(Ω)" style hints
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;

        return $value;
    }

    private static function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    /** Guess the delimiter from the header line. */
    private static function sniffDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ',';
        }

        $line = (string) fgets($handle, 65535);
        fclose($handle);

        $counts = [
            ','  => substr_count($line, ','),
            ';'  => substr_count($line, ';'),
            "\t" => substr_count($line, "\t"),
            '|'  => substr_count($line, '|'),
        ];

        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? (string) $best : ',';
    }

    /** @return array<int,array<string,string>> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return array<int,string> */
    public function headings(): array
    {
        return $this->headings;
    }

    /** @return array<int,string> */
    public function unknownHeadings(): array
    {
        return array_values(array_unique($this->unknownHeadings));
    }

    /** @return array<int,string> */
    public function missingRequiredColumns(): array
    {
        return $this->missingRequired;
    }

    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
