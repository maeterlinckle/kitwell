<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;

/**
 * The whole register, filterable and exportable — the report people reach for
 * at audit time.
 */
final class AllAssetsReport extends Report
{
    public function key(): string
    {
        return 'all-assets';
    }

    public function name(): string
    {
        return 'All assets';
    }

    public function description(): string
    {
        return 'The complete register, with category, location, condition, status and value. Filter it, then export to CSV.';
    }

    public function permission(): string
    {
        return 'assets.view';
    }

    public function exportPermission(): string
    {
        return 'assets.export';
    }

    public function group(): string
    {
        return 'Assets';
    }

    public function columns(): array
    {
        return [
            'asset_tag'        => ['label' => 'Asset tag', 'link' => 'asset'],
            'name'             => ['label' => 'Name', 'sub' => 'manufacturer_model'],
            'category_name'    => ['label' => 'Category'],
            'location_name'    => ['label' => 'Location'],
            'status'           => ['label' => 'Status', 'type' => 'badge', 'badge' => 'status-'],
            'condition_rating' => ['label' => 'Condition', 'type' => 'badge', 'badge' => 'condition-'],
            'serial_number'    => ['label' => 'Serial'],
            'requires_pat'     => ['label' => 'PAT', 'type' => 'bool'],
            'purchase_date'    => ['label' => 'Purchased', 'type' => 'date'],
            'purchase_cost'    => ['label' => 'Cost', 'type' => 'money', 'align' => 'right'],
            'current_value'    => ['label' => 'Value', 'type' => 'money', 'align' => 'right'],
        ];
    }

    public function filterDefinitions(): array
    {
        $categories = ['' => 'All categories'];
        foreach (Category::all(true) as $category) {
            $categories[(string) $category['id']] = (string) $category['name'];
        }

        $locations = ['' => 'All locations'];
        foreach (Location::forSelect() as $location) {
            $locations[(string) $location['id']] = (string) $location['display_name'];
        }

        $statuses = ['' => 'Any status'];
        foreach (Asset::STATUSES as $status) {
            $statuses[$status] = $status;
        }

        $conditions = ['' => 'Any condition'];
        foreach (Asset::CONDITIONS as $condition) {
            $conditions[$condition] = $condition;
        }

        return [
            'q'           => ['label' => 'Search', 'type' => 'search', 'placeholder' => 'Tag, name, serial, manufacturer…'],
            'category'    => ['label' => 'Category', 'type' => 'select', 'options' => $categories],
            'location'    => ['label' => 'Location', 'type' => 'select', 'options' => $locations],
            'status'      => ['label' => 'Status', 'type' => 'select', 'options' => $statuses],
            'condition'   => ['label' => 'Condition', 'type' => 'select', 'options' => $conditions],
            'type'        => ['label' => 'Item type', 'type' => 'select', 'options' => [
                ''    => 'All items',
                'top' => 'Top-level assets only',
                'sub' => 'Sub-assets and accessories only',
            ]],
            'archived'    => ['label' => 'Include retired assets', 'type' => 'checkbox'],
        ];
    }

    public function rows(array $filters): array
    {
        $rows = Asset::searchAll([
            'q'                => $filters['q'] ?? '',
            'category_id'      => $filters['category'] ?? '',
            'location_id'      => $filters['location'] ?? '',
            'status'           => ($filters['status'] ?? '') !== '' ? [$filters['status']] : [],
            'condition'        => ($filters['condition'] ?? '') !== '' ? [$filters['condition']] : [],
            'type'             => $filters['type'] ?? '',
            'include_archived' => !empty($filters['archived']),
        ]);

        foreach ($rows as &$row) {
            $row['manufacturer_model'] = trim((string) $row['manufacturer'] . ' ' . (string) $row['model']);
        }

        return $rows;
    }

    public function summary(array $rows, array $filters): array
    {
        $cost  = 0.0;
        $value = 0.0;
        $pat   = 0;

        foreach ($rows as $row) {
            $cost  += (float) ($row['purchase_cost'] ?? 0);
            $value += (float) ($row['current_value'] ?? 0);
            $pat   += (int) $row['requires_pat'] === 1 ? 1 : 0;
        }

        return [
            ['label' => 'Assets', 'value' => number_format(count($rows))],
            ['label' => 'Requiring PAT', 'value' => number_format($pat)],
            ['label' => 'Total purchase cost', 'value' => format_money($cost)],
            ['label' => 'Total current value', 'value' => format_money($value)],
        ];
    }

    public function emptyMessage(): string
    {
        return 'No assets match these filters. Retired assets are excluded unless you tick “Include retired assets”.';
    }
}
