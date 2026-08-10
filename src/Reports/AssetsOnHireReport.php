<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Hirer;
use App\Models\Hire;

/**
 * Everything that is out right now, and who has it.
 */
final class AssetsOnHireReport extends Report
{
    public function key(): string
    {
        return 'assets-on-hire';
    }

    public function name(): string
    {
        return 'Assets currently on hire';
    }

    public function description(): string
    {
        return 'Every item out on hire or hire right now, who has it, and when it is due back.';
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
            'asset_tag'        => ['label' => 'Asset tag', 'link' => 'asset'],
            'asset_name'       => ['label' => 'Asset'],
            'hirer_name'    => ['label' => 'Hirer', 'link' => 'hirer', 'sub' => 'company_name'],
            'reference'        => ['label' => 'Reference', 'link' => 'hire'],
            'checked_out_at'   => ['label' => 'Out since', 'type' => 'date'],
            'due_back_date'    => ['label' => 'Due back', 'type' => 'date', 'sub' => 'due_in_words'],
            'condition_out'    => ['label' => 'Condition out'],
            'purpose'          => ['label' => 'Purpose'],
            'hire_charge'      => ['label' => 'Charge', 'type' => 'money', 'align' => 'right'],
        ];
    }

    public function filterDefinitions(): array
    {
        $hirers = ['' => 'Anyone'];
        foreach (Hirer::forSelect() as $hirer) {
            $hirers[(string) $hirer['id']] = Hirer::label($hirer);
        }

        return [
            'hirer' => ['label' => 'Hirer', 'type' => 'select', 'options' => $hirers],
            'q'        => ['label' => 'Search', 'type' => 'search', 'placeholder' => 'Asset, hirer, reference…'],
        ];
    }

    public function rows(array $filters): array
    {
        $rows = Hire::searchAll([
            'q'           => $filters['q'] ?? '',
            'hirer_id' => $filters['hirer'] ?? '',
            'open_only'   => true,
            'sort'        => 'due',
        ]);

        foreach ($rows as &$row) {
            $row['due_in_words'] = MaintenanceDueReport::dueInWords($row['days_until_due']);
        }

        return $rows;
    }

    public function summary(array $rows, array $filters): array
    {
        $overdue   = 0;
        $hirers = [];
        $charges   = 0.0;

        foreach ($rows as $row) {
            if ($row['effective_status'] === 'Overdue') {
                $overdue++;
            }

            $hirers[(int) $row['hirer_id']] = true;
            $charges += (float) ($row['hire_charge'] ?? 0);
        }

        return [
            ['label' => 'Items out', 'value' => count($rows)],
            ['label' => 'Overdue', 'value' => $overdue, 'tone' => $overdue > 0 ? 'danger' : ''],
            ['label' => 'Hirers', 'value' => count($hirers)],
            ['label' => 'Hire charges', 'value' => format_money($charges)],
        ];
    }

    public function subtitle(array $rows, array $filters): string
    {
        return count($rows) . ' item' . (count($rows) === 1 ? '' : 's') . ' out on hire right now.';
    }

    public function emptyMessage(): string
    {
        return 'Nothing is out on hire at the moment.';
    }
}
