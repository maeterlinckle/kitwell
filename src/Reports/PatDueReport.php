<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\PatRecord;

/**
 * PAT that is overdue, coming up, failed or never done.
 *
 * Shares PatRecord's status rules with the PAT register and the dashboard.
 */
final class PatDueReport extends Report
{
    public function key(): string
    {
        return 'pat-due';
    }

    public function name(): string
    {
        return 'Assets needing PAT';
    }

    public function description(): string
    {
        return 'Items whose retest is overdue or due soon, plus anything that failed its last test or has never been tested.';
    }

    public function permission(): string
    {
        return 'pat.view';
    }

    public function group(): string
    {
        return 'Maintenance & testing';
    }

    public function columns(): array
    {
        return [
            'pat_status'       => ['label' => 'Status', 'type' => 'badge', 'badge' => 'pat-status-'],
            'retest_due_date'  => ['label' => 'Retest due', 'type' => 'date', 'sub' => 'due_in_words'],
            'asset_tag'        => ['label' => 'Asset tag', 'link' => 'asset'],
            'name'             => ['label' => 'Asset'],
            'location_name'    => ['label' => 'Location'],
            'test_date'        => ['label' => 'Last tested', 'type' => 'date'],
            'overall_result'   => ['label' => 'Last result'],
            'appliance_class'  => ['label' => 'Class'],
            'pat_label_serial' => ['label' => 'PAT label'],
            'test_count'       => ['label' => 'Tests', 'type' => 'number', 'align' => 'right'],
        ];
    }

    public function filterDefinitions(): array
    {
        return [
            'window' => ['label' => 'Include', 'type' => 'select', 'default' => 'attention', 'options' => [
                'attention' => 'Needs attention (overdue, failed, never tested)',
                'due'       => 'Overdue and due soon',
                'overdue'   => 'Overdue only',
                'soon'      => 'Due soon only',
                'failed'    => 'Failed last test',
                'never'     => 'Never tested',
                'all'       => 'Every asset requiring PAT',
            ]],
            'q' => ['label' => 'Search', 'type' => 'search', 'placeholder' => 'Tag, name, serial, PAT label…'],
            'retired' => ['label' => 'Include retired assets', 'type' => 'checkbox'],
        ];
    }

    public function rows(array $filters): array
    {
        $window = (string) ($filters['window'] ?? 'attention');

        $statuses = match ($window) {
            'due'     => ['Overdue', 'Due soon'],
            'overdue' => ['Overdue'],
            'soon'    => ['Due soon'],
            'failed'  => ['Failed'],
            'never'   => ['Never tested'],
            'all'     => [],
            default   => ['Overdue', 'Failed', 'Never tested'],
        };

        $rows = PatRecord::assetSearchAll([
            'q'               => $filters['q'] ?? '',
            'status'          => $statuses,
            'include_retired' => !empty($filters['retired']),
        ]);

        foreach ($rows as &$row) {
            $row['due_in_words'] = MaintenanceDueReport::dueInWords($row['days_until_due']);
        }

        return $rows;
    }

    public function summary(array $rows, array $filters): array
    {
        $counts = ['Overdue' => 0, 'Failed' => 0, 'Never tested' => 0, 'Due soon' => 0];

        foreach ($rows as $row) {
            $status = (string) $row['pat_status'];

            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return [
            ['label' => 'Overdue', 'value' => $counts['Overdue'], 'tone' => $counts['Overdue'] > 0 ? 'danger' : ''],
            ['label' => 'Failed', 'value' => $counts['Failed'], 'tone' => $counts['Failed'] > 0 ? 'danger' : ''],
            ['label' => 'Never tested', 'value' => $counts['Never tested'], 'tone' => $counts['Never tested'] > 0 ? 'warn' : ''],
            ['label' => 'Due soon', 'value' => $counts['Due soon'], 'tone' => $counts['Due soon'] > 0 ? 'warn' : ''],
        ];
    }

    public function subtitle(array $rows, array $filters): string
    {
        return count($rows) . ' item' . (count($rows) === 1 ? '' : 's')
            . ', using a ' . PatRecord::dueDays() . '-day “due soon” window.';
    }

    public function emptyMessage(): string
    {
        return 'Nothing needs testing. Widen the selection above to see everything on the PAT register.';
    }
}
