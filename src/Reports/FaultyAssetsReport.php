<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Asset;
use App\Models\FaultReport;

/**
 * Everything currently marked faulty, worst first.
 *
 * The list behind the dashboard's Faulty tile. Both read FaultReport, so the
 * count on the tile and the number of rows here cannot disagree — the same
 * arrangement the maintenance and PAT tiles already have.
 *
 * Sorted by urgency by default, and filterable by it, because that is the
 * question this list exists to answer: of the eleven broken things, which two
 * matter today?
 */
final class FaultyAssetsReport extends Report
{
    public function key(): string
    {
        return 'faulty-assets';
    }

    public function name(): string
    {
        return 'Faulty equipment';
    }

    public function description(): string
    {
        return 'Every asset currently marked faulty, most urgent first, with what is wrong and who is responsible for it.';
    }

    public function permission(): string
    {
        return 'assets.view';
    }

    public function group(): string
    {
        return 'Maintenance & testing';
    }

    public function columns(): array
    {
        return [
            'urgency'          => ['label' => 'Urgency', 'type' => 'badge', 'badge' => 'urgency-'],
            'asset_tag'        => ['label' => 'Asset tag', 'link' => 'asset'],
            'asset_name'       => ['label' => 'Asset'],
            'faulty_on'        => ['label' => 'Faulty since', 'type' => 'date', 'sub' => 'faulty_for'],
            'description'      => ['label' => 'What is wrong'],
            // Says "(team)" where it is one — a CSV has nowhere to put a badge,
            // and a report is read away from the screen that would have shown
            // one. Same reasoning as the maintenance report's assignee column.
            'responsible'      => ['label' => 'Responsible'],
            'location_name'    => ['label' => 'Location'],
            'condition_rating' => ['label' => 'Condition', 'type' => 'badge', 'badge' => 'condition-'],
            'reported_by_name' => ['label' => 'Reported by'],
        ];
    }

    public function filterDefinitions(): array
    {
        return [
            'urgency' => ['label' => 'Urgency', 'type' => 'select', 'default' => '', 'options' => [
                ''                    => 'Any urgency',
                FaultReport::URGENT   => 'Critical or High',
                'Critical'            => 'Critical only',
                'High'                => 'High only',
                'Medium'              => 'Medium only',
                'Low'                 => 'Low only',
            ]],
            'sort' => ['label' => 'Order by', 'type' => 'select', 'default' => 'urgency', 'options' => [
                'urgency'  => 'Most urgent first',
                'oldest'   => 'Longest faulty first',
                'reported' => 'Most recently reported first',
                'asset'    => 'Asset tag',
            ]],
            'responsible' => ['label' => 'Responsible', 'type' => 'select', 'default' => '', 'options' => self::responsibleOptions()],
            'q' => ['label' => 'Search', 'type' => 'search', 'placeholder' => 'Asset, tag, fault…'],
        ];
    }

    /**
     * People and teams that actually have a faulty asset against them, plus
     * "nobody". Built from the data rather than from the whole user list: a
     * filter offering forty names that all return nothing is not a filter.
     *
     * @return array<string,string>
     */
    private static function responsibleOptions(): array
    {
        $options = ['' => 'Anyone', 'none' => 'Nobody set'];

        foreach (FaultReport::currentFaults() as $row) {
            if (!empty($row['responsible_team_id'])) {
                $options['team:' . (int) $row['responsible_team_id']] = (string) $row['responsible_name'] . ' (team)';
            } elseif (!empty($row['responsible_user_id'])) {
                $options['user:' . (int) $row['responsible_user_id']] = (string) $row['responsible_name'];
            }
        }

        return $options;
    }

    public function rows(array $filters): array
    {
        $rows = FaultReport::currentFaults([
            'urgency'     => $filters['urgency'] ?? '',
            'sort'        => $filters['sort'] ?? 'urgency',
            'responsible' => $filters['responsible'] ?? '',
            'q'           => $filters['q'] ?? '',
        ]);

        foreach ($rows as &$row) {
            // The generic table links an 'asset' column using the row's
            // asset_id, which currentFaults() already selects under that name.
            $row['responsible'] = Asset::responsibleLabel($row, 'Nobody');
            $row['faulty_for']  = self::faultyFor($row['days_faulty']);

            // A status set by hand rather than through the form leaves no
            // report behind. Saying so is more use than an empty cell.
            if ($row['fault_report_id'] === null) {
                $row['urgency']          = 'Unrated';
                $row['description']      = 'No fault report on record — the status was set by hand.';
                $row['reported_by_name'] = '—';
            }
        }

        return $rows;
    }

    public function summary(array $rows, array $filters): array
    {
        $critical = 0;
        $urgent   = 0;
        $orphaned = 0;

        foreach ($rows as $row) {
            if ($row['urgency'] === 'Critical') {
                $critical++;
            }

            if (in_array($row['urgency'], ['Critical', 'High'], true)) {
                $urgent++;
            }

            if ($row['responsible'] === 'Nobody') {
                $orphaned++;
            }
        }

        return [
            ['label' => 'Critical', 'value' => $critical, 'tone' => $critical > 0 ? 'danger' : ''],
            ['label' => 'Critical or High', 'value' => $urgent, 'tone' => $urgent > 0 ? 'warn' : ''],
            ['label' => 'Total faulty', 'value' => count($rows)],
            // Worth its own figure: these are the faults nobody is being told
            // about, by either the immediate email or the digest.
            ['label' => 'Nobody responsible', 'value' => $orphaned, 'tone' => $orphaned > 0 ? 'warn' : ''],
        ];
    }

    public function subtitle(array $rows, array $filters): string
    {
        $count = count($rows);

        return $count === 1
            ? '1 asset is currently marked faulty.'
            : $count . ' assets are currently marked faulty.';
    }

    public function emptyMessage(): string
    {
        return 'Nothing is marked faulty. Assets appear here as soon as somebody reports a fault on one.';
    }

    /** "6 days", "today" — the sub-line under the faulty-since date. */
    private static function faultyFor(mixed $days): string
    {
        if ($days === null || $days === '') {
            return '';
        }

        $days = (int) $days;

        if ($days <= 0) {
            return 'today';
        }

        return $days === 1 ? 'for 1 day' : 'for ' . $days . ' days';
    }
}
