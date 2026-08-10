<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Hire;
use App\Models\Setting;

/**
 * The chase list: what is overdue, and what is about to be.
 */
final class HiresDueBackReport extends Report
{
    public function key(): string
    {
        return 'hires-due-back';
    }

    public function name(): string
    {
        return 'Assets due back';
    }

    public function description(): string
    {
        return 'Hires that are overdue or coming due, with hirer contact details so they can be chased.';
    }

    public function permission(): string
    {
        return 'hires.view';
    }

    public function group(): string
    {
        return 'Hires & hire';
    }

    public function columns(): array
    {
        return [
            'effective_status' => ['label' => 'Status', 'type' => 'badge', 'badge' => 'hire-'],
            'due_back_date'    => ['label' => 'Due back', 'type' => 'date', 'sub' => 'due_in_words'],
            'asset_tag'        => ['label' => 'Asset tag', 'link' => 'asset'],
            'asset_name'       => ['label' => 'Asset'],
            'hirer_name'    => ['label' => 'Hirer', 'link' => 'hirer', 'sub' => 'company_name'],
            'hirer_phone'   => ['label' => 'Phone'],
            'hirer_email'   => ['label' => 'Email'],
            'reference'        => ['label' => 'Reference', 'link' => 'hire'],
            'checked_out_at'   => ['label' => 'Out since', 'type' => 'date'],
        ];
    }

    public function filterDefinitions(): array
    {
        return [
            'window' => ['label' => 'Include', 'type' => 'select', 'default' => 'due', 'options' => [
                'due'     => 'Overdue and due soon',
                'overdue' => 'Overdue only',
                'soon'    => 'Due soon only',
                'all'     => 'All open hires',
            ]],
            'days' => ['label' => 'Days ahead', 'type' => 'select', 'default' => '', 'options' => [
                ''   => 'Site default',
                '1'  => 'Due tomorrow',
                '3'  => 'Next 3 days',
                '7'  => 'Next 7 days',
                '14' => 'Next 14 days',
                '30' => 'Next 30 days',
            ]],
            'q' => ['label' => 'Search', 'type' => 'search', 'placeholder' => 'Asset, hirer, reference…'],
        ];
    }

    public function rows(array $filters): array
    {
        $window = (string) ($filters['window'] ?? 'due');
        $days   = ($filters['days'] ?? '') !== ''
            ? (int) $filters['days']
            : self::defaultWindowDays();

        $modelFilters = [
            'q'         => $filters['q'] ?? '',
            'open_only' => true,
            'sort'      => 'due',
        ];

        if ($window === 'overdue') {
            $modelFilters['status'] = ['Overdue'];
        } elseif ($window === 'soon') {
            $modelFilters['status']          = ['Out'];
            $modelFilters['due_within_days'] = $days;
        } elseif ($window !== 'all') {
            // Overdue plus anything falling due inside the window.
            $modelFilters['due_within_days'] = $days;
        }

        $rows = Hire::searchAll($modelFilters);

        foreach ($rows as &$row) {
            $row['due_in_words'] = MaintenanceDueReport::dueInWords($row['days_until_due']);
        }

        return $rows;
    }

    public function summary(array $rows, array $filters): array
    {
        $overdue = 0;
        $today   = 0;

        foreach ($rows as $row) {
            if ($row['effective_status'] === 'Overdue') {
                $overdue++;
            } elseif ((int) $row['days_until_due'] === 0) {
                $today++;
            }
        }

        return [
            ['label' => 'Overdue', 'value' => $overdue, 'tone' => $overdue > 0 ? 'danger' : ''],
            ['label' => 'Due today', 'value' => $today, 'tone' => $today > 0 ? 'warn' : ''],
            ['label' => 'Total listed', 'value' => count($rows)],
            ['label' => '“Due soon” window', 'value' => self::defaultWindowDays() . ' days'],
        ];
    }

    public function subtitle(array $rows, array $filters): string
    {
        return count($rows) . ' hire' . (count($rows) === 1 ? '' : 's')
            . ' overdue or due back within ' . self::defaultWindowDays() . ' days.';
    }

    public function emptyMessage(): string
    {
        return 'Nothing is overdue or due back soon. Widen the window to look further ahead.';
    }

    private static function defaultWindowDays(): int
    {
        return max(0, min(90, Setting::int('hire_due_soon_days', 2)));
    }
}
