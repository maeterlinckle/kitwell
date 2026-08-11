<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\MaintenanceSchedule;

/**
 * Maintenance that is overdue or coming up.
 *
 * Uses the same MaintenanceSchedule query the maintenance screens and the
 * dashboard use, so the numbers here always agree with the numbers there.
 */
final class MaintenanceDueReport extends Report
{
    public function key(): string
    {
        return 'maintenance-due';
    }

    public function name(): string
    {
        return 'Assets needing maintenance';
    }

    public function description(): string
    {
        return 'Everything overdue or falling due within the site’s “due soon” window, soonest first.';
    }

    public function permission(): string
    {
        return 'maintenance.view';
    }

    public function group(): string
    {
        return 'Maintenance & testing';
    }

    public function columns(): array
    {
        return [
            'due_status'      => ['label' => 'Status', 'type' => 'badge', 'badge' => 'due-'],
            'next_due_date'   => ['label' => 'Due', 'type' => 'date', 'sub' => 'due_in_words'],
            'asset_tag'       => ['label' => 'Asset tag', 'link' => 'asset'],
            'asset_name'      => ['label' => 'Asset'],
            'title'           => ['label' => 'Job', 'link' => 'maintenance'],
            'frequency'       => ['label' => 'Repeats'],
            // Says "(team)" where it is one — see rows(). A report is read away
            // from the screen that would have shown the badge, and a CSV has
            // nowhere to put one at all.
            'assignee'        => ['label' => 'Assigned to'],
            'location_name'   => ['label' => 'Location'],
            'last_completed_date' => ['label' => 'Last done', 'type' => 'date'],
        ];
    }

    public function filterDefinitions(): array
    {
        return [
            'window' => ['label' => 'Include', 'type' => 'select', 'default' => 'due', 'options' => [
                'due'     => 'Overdue and due soon',
                'overdue' => 'Overdue only',
                'soon'    => 'Due soon only',
                'all'     => 'Everything scheduled',
            ]],
            'days' => ['label' => 'Days ahead', 'type' => 'select', 'default' => '', 'options' => [
                ''    => 'Site default',
                '7'   => 'Next 7 days',
                '14'  => 'Next 14 days',
                '30'  => 'Next 30 days',
                '90'  => 'Next 90 days',
                '365' => 'Next year',
            ]],
            'q' => ['label' => 'Search', 'type' => 'search', 'placeholder' => 'Job title, asset…'],
        ];
    }

    public function rows(array $filters): array
    {
        $window = (string) ($filters['window'] ?? 'due');

        $statuses = match ($window) {
            'overdue' => ['Overdue'],
            'soon'    => ['Due soon'],
            'all'     => [],
            default   => ['Overdue', 'Due soon'],
        };

        $modelFilters = [
            'q'      => $filters['q'] ?? '',
            'status' => $statuses,
        ];

        if (($filters['days'] ?? '') !== '') {
            $modelFilters['due_within_days'] = (int) $filters['days'];
        }

        $rows = MaintenanceSchedule::searchAll($modelFilters);

        foreach ($rows as &$row) {
            $row['frequency']    = MaintenanceSchedule::describeFrequency($row);
            $row['due_in_words'] = self::dueInWords($row['days_until_due']);
            $row['assignee']     = MaintenanceSchedule::assigneeLabel($row, '');
        }

        return $rows;
    }

    public function summary(array $rows, array $filters): array
    {
        $overdue = 0;
        $soon    = 0;

        foreach ($rows as $row) {
            if ($row['due_status'] === 'Overdue') {
                $overdue++;
            } elseif ($row['due_status'] === 'Due soon') {
                $soon++;
            }
        }

        return [
            ['label' => 'Overdue', 'value' => $overdue, 'tone' => $overdue > 0 ? 'danger' : ''],
            ['label' => 'Due soon', 'value' => $soon, 'tone' => $soon > 0 ? 'warn' : ''],
            ['label' => 'Total listed', 'value' => count($rows)],
            ['label' => '“Due soon” window', 'value' => MaintenanceSchedule::dueDays() . ' days'],
        ];
    }

    public function subtitle(array $rows, array $filters): string
    {
        return count($rows) . ' scheduled job' . (count($rows) === 1 ? '' : 's')
            . ', using a ' . MaintenanceSchedule::dueDays() . '-day “due soon” window.';
    }

    public function emptyMessage(): string
    {
        return 'Nothing is overdue or due soon. Widen the window to look further ahead.';
    }

    public static function dueInWords(mixed $days): string
    {
        if ($days === null || $days === '') {
            return 'no date set';
        }

        $days = (int) $days;

        if ($days < 0) {
            return abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' late';
        }

        return $days === 0 ? 'today' : 'in ' . $days . ' day' . ($days === 1 ? '' : 's');
    }
}
