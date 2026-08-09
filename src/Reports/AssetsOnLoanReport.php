<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Borrower;
use App\Models\Loan;

/**
 * Everything that is out right now, and who has it.
 */
final class AssetsOnLoanReport extends Report
{
    public function key(): string
    {
        return 'assets-on-loan';
    }

    public function name(): string
    {
        return 'Assets currently on loan';
    }

    public function description(): string
    {
        return 'Every item out on loan or hire right now, who has it, and when it is due back.';
    }

    public function permission(): string
    {
        return 'loans.view';
    }

    public function group(): string
    {
        return 'Loans & hire';
    }

    public function columns(): array
    {
        return [
            'effective_status' => ['label' => 'Status', 'type' => 'badge', 'badge' => 'loan-'],
            'asset_tag'        => ['label' => 'Asset tag', 'link' => 'asset'],
            'asset_name'       => ['label' => 'Asset'],
            'borrower_name'    => ['label' => 'Borrower', 'link' => 'borrower', 'sub' => 'company_name'],
            'reference'        => ['label' => 'Reference', 'link' => 'loan'],
            'checked_out_at'   => ['label' => 'Out since', 'type' => 'date'],
            'due_back_date'    => ['label' => 'Due back', 'type' => 'date', 'sub' => 'due_in_words'],
            'condition_out'    => ['label' => 'Condition out'],
            'purpose'          => ['label' => 'Purpose'],
            'hire_charge'      => ['label' => 'Charge', 'type' => 'money', 'align' => 'right'],
        ];
    }

    public function filterDefinitions(): array
    {
        $borrowers = ['' => 'Anyone'];
        foreach (Borrower::forSelect() as $borrower) {
            $borrowers[(string) $borrower['id']] = Borrower::label($borrower);
        }

        return [
            'borrower' => ['label' => 'Borrower', 'type' => 'select', 'options' => $borrowers],
            'q'        => ['label' => 'Search', 'type' => 'search', 'placeholder' => 'Asset, borrower, reference…'],
        ];
    }

    public function rows(array $filters): array
    {
        $rows = Loan::searchAll([
            'q'           => $filters['q'] ?? '',
            'borrower_id' => $filters['borrower'] ?? '',
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
        $borrowers = [];
        $charges   = 0.0;

        foreach ($rows as $row) {
            if ($row['effective_status'] === 'Overdue') {
                $overdue++;
            }

            $borrowers[(int) $row['borrower_id']] = true;
            $charges += (float) ($row['hire_charge'] ?? 0);
        }

        return [
            ['label' => 'Items out', 'value' => count($rows)],
            ['label' => 'Overdue', 'value' => $overdue, 'tone' => $overdue > 0 ? 'danger' : ''],
            ['label' => 'Borrowers', 'value' => count($borrowers)],
            ['label' => 'Hire charges', 'value' => format_money($charges)],
        ];
    }

    public function subtitle(array $rows, array $filters): string
    {
        return count($rows) . ' item' . (count($rows) === 1 ? '' : 's') . ' out on loan right now.';
    }

    public function emptyMessage(): string
    {
        return 'Nothing is out on loan at the moment.';
    }
}
