<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csv;
use App\Core\Database;
use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\Asset;

/**
 * CSV export of the asset register.
 *
 * Takes either an explicit set of ids (ticked in the register) or the register's
 * own filters, so what you export is exactly what you were looking at.
 *
 * The core columns match the import format, so an export can be edited and fed
 * straight back in. The optional extras (latest PAT, current loan, next
 * maintenance) are appended after them, and are deliberately not part of the
 * import format — they are derived data, not asset fields.
 */
final class AssetExportController extends Controller
{
    /** Core columns: the same shape the importer accepts. */
    private const CORE = [
        'asset_tag'             => 'Asset tag',
        'name'                  => 'Name',
        'description'           => 'Description',
        'category_name'         => 'Category',
        'location_name'         => 'Location',
        'condition_rating'      => 'Condition',
        'status'                => 'Status',
        'purchase_date'         => 'Purchase date',
        'purchase_cost'         => 'Purchase cost',
        'current_value'         => 'Current value',
        'supplier'              => 'Supplier',
        'serial_number'         => 'Serial number',
        'manufacturer'          => 'Manufacturer',
        'model'                 => 'Model',
        'manufacturer_url'      => 'Manufacturer URL',
        'plug_fuse_rating_amps' => 'Plug fuse rating (A)',
        'cable_csa_mm2'         => 'Cable CSA (mm2)',
        'requires_pat'          => 'Requires PAT',
        'pat_interval_months'   => 'PAT interval (months)',
        'is_loanable'           => 'Available for loan',
        'notes'                 => 'Notes',
        'barcode'               => 'Secondary barcode',
        'warranty_expires_on'   => 'Warranty expires',
        'parent_tag'            => 'Part of',
        'relationship_type'     => 'Relationship',
        'created_at'            => 'Added',
    ];

    /** Optional extra column groups. */
    private const EXTRAS = [
        'pat' => [
            'label'   => 'Latest PAT result',
            'columns' => [
                'pat_status'      => 'PAT status',
                'pat_test_date'   => 'PAT last tested',
                'pat_result'      => 'PAT result',
                'pat_retest_due'  => 'PAT retest due',
                'pat_label'       => 'PAT label',
            ],
        ],
        'loan' => [
            'label'   => 'Current loan',
            'columns' => [
                'loan_status'    => 'Loan status',
                'loan_borrower'  => 'On loan to',
                'loan_out_since' => 'Out since',
                'loan_due_back'  => 'Due back',
            ],
        ],
        'maintenance' => [
            'label'   => 'Next maintenance',
            'columns' => [
                'maintenance_title'    => 'Next maintenance job',
                'maintenance_due'      => 'Next maintenance due',
                'maintenance_last_done'=> 'Maintenance last done',
            ],
        ],
    ];

    /** @return array<string,array<string,mixed>> */
    public static function extraGroups(): array
    {
        return self::EXTRAS;
    }

    public function export(): void
    {
        Auth::authorize('assets.export');

        $ids     = self::requestedIds();
        $filters = AssetController::filtersFromRequest();

        $rows = $ids !== []
            ? Asset::byIds($ids)
            : Asset::searchAll($filters);

        $extras = array_values(array_intersect(
            array_map('strval', (array) (Request::input('extras', []) ?: [])),
            array_keys(self::EXTRAS)
        ));

        if ($extras !== []) {
            $rows = $this->appendExtras($rows, $extras);
        }

        $headings = array_values(self::CORE);

        foreach ($extras as $extra) {
            foreach (self::EXTRAS[$extra]['columns'] as $label) {
                $headings[] = $label;
            }
        }

        $lines = [];

        foreach ($rows as $row) {
            $line = [];

            foreach (array_keys(self::CORE) as $field) {
                $line[] = self::value($row[$field] ?? null, $field);
            }

            foreach ($extras as $extra) {
                foreach (array_keys(self::EXTRAS[$extra]['columns']) as $field) {
                    $line[] = self::value($row[$field] ?? null, $field);
                }
            }

            $lines[] = $line;
        }

        ActivityLog::record(
            'exported',
            'asset',
            null,
            sprintf(
                'Exported %d asset(s) to CSV%s%s',
                count($rows),
                $ids !== [] ? ' (selected rows)' : '',
                $extras !== [] ? ' with ' . implode(', ', $extras) : ''
            )
        );

        Csv::download('assets-' . date('Y-m-d'), $headings, $lines);
    }

    /**
     * Attach the optional derived columns in as few queries as possible: one
     * per extra group for the whole export, not one per asset.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string> $extras
     * @return array<int,array<string,mixed>>
     */
    private function appendExtras(array $rows, array $extras): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        if ($ids === []) {
            return $rows;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $pat = [];
        if (in_array('pat', $extras, true)) {
            $records = Database::select(
                'SELECT p.asset_id, p.test_date, p.retest_due_date, p.overall_result, p.pat_label_serial
                   FROM pat_records p
                  WHERE p.asset_id IN (' . $placeholders . ')
                  ORDER BY p.asset_id, p.test_date DESC, p.id DESC',
                $ids
            );

            foreach ($records as $record) {
                $pat[(int) $record['asset_id']] ??= $record;
            }
        }

        $loans = [];
        if (in_array('loan', $extras, true)) {
            $records = Database::select(
                "SELECT l.asset_id, l.checked_out_at, l.due_back_date, b.name AS borrower_name,
                        CASE WHEN l.due_back_date < CURDATE() THEN 'Overdue' ELSE 'Out' END AS loan_status
                   FROM loans l
                   INNER JOIN borrowers b ON b.id = l.borrower_id
                  WHERE l.asset_id IN (" . $placeholders . ') AND l.returned_at IS NULL
                  ORDER BY l.asset_id, l.id DESC',
                $ids
            );

            foreach ($records as $record) {
                $loans[(int) $record['asset_id']] ??= $record;
            }
        }

        $maintenance = [];
        if (in_array('maintenance', $extras, true)) {
            $records = Database::select(
                'SELECT s.asset_id, s.title, s.next_due_date, s.last_completed_date
                   FROM maintenance_schedules s
                  WHERE s.asset_id IN (' . $placeholders . ') AND s.is_active = 1
                  ORDER BY s.asset_id, s.next_due_date IS NULL, s.next_due_date ASC',
                $ids
            );

            foreach ($records as $record) {
                $maintenance[(int) $record['asset_id']] ??= $record;
            }
        }

        foreach ($rows as &$row) {
            $assetId = (int) $row['id'];

            if (in_array('pat', $extras, true)) {
                $record = $pat[$assetId] ?? null;

                $row['pat_test_date']  = $record['test_date'] ?? null;
                $row['pat_result']     = $record['overall_result'] ?? null;
                $row['pat_retest_due'] = $record['retest_due_date'] ?? null;
                $row['pat_label']      = $record['pat_label_serial'] ?? null;
                $row['pat_status']     = self::patStatus($row, $record);
            }

            if (in_array('loan', $extras, true)) {
                $record = $loans[$assetId] ?? null;

                $row['loan_status']    = $record['loan_status'] ?? 'Not on loan';
                $row['loan_borrower']  = $record['borrower_name'] ?? null;
                $row['loan_out_since'] = $record['checked_out_at'] ?? null;
                $row['loan_due_back']  = $record['due_back_date'] ?? null;
            }

            if (in_array('maintenance', $extras, true)) {
                $record = $maintenance[$assetId] ?? null;

                $row['maintenance_title']     = $record['title'] ?? null;
                $row['maintenance_due']       = $record['next_due_date'] ?? null;
                $row['maintenance_last_done'] = $record['last_completed_date'] ?? null;
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $asset
     * @param array<string,mixed>|null $record
     */
    private static function patStatus(array $asset, ?array $record): string
    {
        if ((int) $asset['requires_pat'] !== 1) {
            return 'Not required';
        }

        if ($record === null) {
            return 'Never tested';
        }

        if ($record['overall_result'] === 'Fail') {
            return 'Failed';
        }

        if ($record['retest_due_date'] === null) {
            return 'No retest date';
        }

        return $record['retest_due_date'] < date('Y-m-d') ? 'Overdue' : 'Current';
    }

    /** Dates as ISO, booleans as words, money plain. */
    private static function value(mixed $value, string $field): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (in_array($field, ['requires_pat', 'is_loanable'], true)) {
            return ((int) $value === 1) ? 'Yes' : 'No';
        }

        if (in_array($field, ['created_at', 'loan_out_since'], true)) {
            return date('Y-m-d', (int) strtotime((string) $value));
        }

        return (string) $value;
    }

    /** @return array<int,int> */
    private static function requestedIds(): array
    {
        $raw = Request::input('ids', '');

        $ids = is_array($raw)
            ? array_map('intval', $raw)
            : array_map('intval', array_filter(explode(',', (string) $raw)));

        return array_values(array_unique(array_filter($ids)));
    }
}
