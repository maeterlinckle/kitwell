<?php

declare(strict_types=1);

namespace App\Core;

/**
 * CSV download.
 *
 * Written straight to the output stream rather than built in memory, so a
 * large export does not depend on the PHP memory limit.
 */
final class Csv
{
    /**
     * @param array<int,string>              $headings
     * @param array<int,array<int,mixed>>    $rows
     */
    public static function download(string $filename, array $headings, array $rows): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!str_ends_with(strtolower($filename), '.csv')) {
            $filename .= '.csv';
        }

        // Strip anything awkward from the filename rather than trusting it.
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?? 'export.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header_remove('Pragma');

        $handle = fopen('php://output', 'wb');

        if ($handle === false) {
            exit;
        }

        // Byte-order mark so Excel opens UTF-8 correctly rather than mangling
        // pounds, ohms and degree signs.
        fwrite($handle, "\xEF\xBB\xBF");

        // The $escape argument is passed explicitly: PHP 8.4 deprecates
        // relying on its default, and '' is both the future default and the
        // RFC 4180 behaviour every spreadsheet expects.
        fputcsv($handle, $headings, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::cell(...), $row), ',', '"', '');
        }

        fclose($handle);
        exit;
    }

    /**
     * Neutralise spreadsheet formula injection.
     *
     * A cell beginning =, +, - or @ is treated as a formula by Excel and
     * friends. Asset data is user-entered, so it gets a leading apostrophe.
     */
    private static function cell(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value === true) {
            return 'Yes';
        }

        $text = (string) $value;

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $text;
        }

        return $text;
    }
}
