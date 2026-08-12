<?php

declare(strict_types=1);

namespace App\Reports;

use App\Core\Auth;
use App\Models\Asset;
use App\Models\Category;
use App\Models\FaultReport;
use App\Models\Hire;
use App\Models\Hirer;
use App\Models\Location;
use App\Models\MaintenanceSchedule;
use App\Models\PatRecord;

/**
 * The things a custom report can be built on.
 *
 * Each entry wires a source's declared filters and columns to the model method
 * the corresponding list page already calls — `Asset::searchAll()`,
 * `MaintenanceSchedule::searchAll()`, and so on. That is deliberate and is the
 * single most important property of this feature: a custom report cannot reach
 * data, or express a condition, that the equivalent screen could not. There is
 * no query builder here, and the word "custom" never touches SQL.
 *
 * Adding a source means adding one entry. Adding a *field* to an existing
 * source means adding one line to its filter or column list — and it becomes
 * available to every report already built on it, because definitions store keys
 * rather than a frozen copy of the schema.
 */
final class DataSourceRegistry
{
    /** @var array<string,DataSource>|null */
    private static ?array $sources = null;

    /** @return array<string,DataSource> */
    public static function all(): array
    {
        if (self::$sources === null) {
            self::$sources = [];

            foreach ([self::assets(), self::maintenance(), self::pat(), self::hires(), self::faults()] as $source) {
                self::$sources[$source->key] = $source;
            }
        }

        return self::$sources;
    }

    public static function find(string $key): ?DataSource
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Sources the signed-in user could actually build a report on.
     *
     * Offering a source somebody cannot read would produce a report they cannot
     * open — a definition that is refused the moment it is saved.
     *
     * @return array<string,DataSource>
     */
    public static function available(): array
    {
        return array_filter(self::all(), static fn (DataSource $s): bool => Auth::can($s->permission));
    }

    /** Reset the memoised list — the test suite changes permissions underneath it. */
    public static function forget(): void
    {
        self::$sources = null;
    }

    // -- Assets ---------------------------------------------------------------

    private static function assets(): DataSource
    {
        return new DataSource(
            key: 'assets',
            label: 'Assets',
            description: 'The register itself — every asset, sub-asset and accessory.',
            permission: 'assets.view',
            group: 'Assets',
            filters: [
                'q' => ['label' => 'Search', 'type' => 'text',
                    'hint' => 'Tag, name, serial, manufacturer, model, supplier, notes.'],
                'status' => ['label' => 'Status is any of', 'type' => 'multi',
                    'options' => self::asKeyedOptions(Asset::STATUSES)],
                'condition' => ['label' => 'Condition is any of', 'type' => 'multi',
                    'options' => self::asKeyedOptions(Asset::CONDITIONS)],
                'category_id' => ['label' => 'Category', 'type' => 'select', 'options' => self::categoryOptions()],
                'location_id' => ['label' => 'Location', 'type' => 'select', 'options' => self::locationOptions()],
                'requires_pat' => ['label' => 'Requires PAT', 'type' => 'select',
                    'options' => ['' => 'Either', '1' => 'Yes', '0' => 'No']],
                'type' => ['label' => 'Item type', 'type' => 'select', 'options' => [
                    ''    => 'All items',
                    'top' => 'Top-level assets only',
                    'sub' => 'Sub-assets and accessories only',
                ]],
                'include_archived' => ['label' => 'Include retired assets', 'type' => 'bool',
                    'hint' => 'Retired assets are left out unless this is ticked, as everywhere else.'],
            ],
            columns: [
                'asset_tag'        => ['label' => 'Asset tag', 'link' => 'asset'],
                'name'             => ['label' => 'Name'],
                'description'      => ['label' => 'Description'],
                'category_name'    => ['label' => 'Category'],
                'location_name'    => ['label' => 'Location'],
                'responsible'      => ['label' => 'Responsible'],
                'status'           => ['label' => 'Status', 'type' => 'badge', 'badge' => 'status-'],
                'condition_rating' => ['label' => 'Condition', 'type' => 'badge', 'badge' => 'condition-'],
                'serial_number'    => ['label' => 'Serial number'],
                'manufacturer'     => ['label' => 'Manufacturer'],
                'model'            => ['label' => 'Model'],
                'supplier'         => ['label' => 'Supplier'],
                'requires_pat'     => ['label' => 'Requires PAT', 'type' => 'bool'],
                'purchase_date'    => ['label' => 'Purchased', 'type' => 'date'],
                'purchase_cost'    => ['label' => 'Purchase cost', 'type' => 'money', 'align' => 'right'],
                'current_value'    => ['label' => 'Current value', 'type' => 'money', 'align' => 'right'],
                'warranty_expires_on' => ['label' => 'Warranty expires', 'type' => 'date'],
                'parent_tag'       => ['label' => 'Part of'],
                'notes'            => ['label' => 'Notes'],
                'created_at'       => ['label' => 'Added', 'type' => 'date'],
                'updated_at'       => ['label' => 'Last updated', 'type' => 'datetime'],
            ],
            fetch: static function (array $filters): array {
                $rows = Asset::searchAll($filters);

                foreach ($rows as &$row) {
                    $row['responsible'] = Asset::responsibleLabel($row, '');
                }

                return $rows;
            },
            defaultColumns: ['asset_tag', 'name', 'category_name', 'location_name', 'status', 'condition_rating'],
        );
    }

    // -- Maintenance ----------------------------------------------------------

    private static function maintenance(): DataSource
    {
        return new DataSource(
            key: 'maintenance',
            label: 'Maintenance schedules',
            description: 'Scheduled and one-off jobs, with their due status and who owns them.',
            permission: 'maintenance.view',
            group: 'Maintenance & testing',
            filters: [
                'q' => ['label' => 'Search', 'type' => 'text', 'hint' => 'Job title, instructions, asset.'],
                'status' => ['label' => 'Due status is any of', 'type' => 'multi', 'options' => self::asKeyedOptions(
                    ['Overdue', 'Due soon', 'Scheduled', 'Unscheduled', 'Inactive']
                )],
                'type' => ['label' => 'Schedule type', 'type' => 'select', 'options' =>
                    ['' => 'Any type'] + self::asKeyedOptions(MaintenanceSchedule::TYPES)],
                'due_within_days' => ['label' => 'Due within (days)', 'type' => 'number',
                    'hint' => 'Leave blank to use the site’s own “due soon” window.'],
                'category_id' => ['label' => 'Asset category', 'type' => 'select', 'options' => self::categoryOptions()],
                'location_id' => ['label' => 'Asset location', 'type' => 'select', 'options' => self::locationOptions()],
                'include_inactive' => ['label' => 'Include closed schedules', 'type' => 'bool'],
            ],
            columns: [
                'due_status'      => ['label' => 'Due status', 'type' => 'badge', 'badge' => 'due-'],
                'title'           => ['label' => 'Job', 'link' => 'maintenance'],
                'asset_tag'       => ['label' => 'Asset tag', 'link' => 'asset'],
                'asset_name'      => ['label' => 'Asset'],
                'maintenance_type'=> ['label' => 'Type'],
                'frequency'       => ['label' => 'Repeats'],
                'next_due_date'   => ['label' => 'Next due', 'type' => 'date'],
                'last_completed_date' => ['label' => 'Last done', 'type' => 'date'],
                'assignee'        => ['label' => 'Assigned to'],
                'location_name'   => ['label' => 'Location'],
                'category_name'   => ['label' => 'Category'],
                'estimated_minutes' => ['label' => 'Estimated minutes', 'type' => 'number', 'align' => 'right'],
                'instructions'    => ['label' => 'Instructions'],
            ],
            fetch: static function (array $filters): array {
                $rows = MaintenanceSchedule::searchAll($filters);

                foreach ($rows as &$row) {
                    $row['frequency'] = MaintenanceSchedule::describeFrequency($row);
                    $row['assignee']  = MaintenanceSchedule::assigneeLabel($row, '');
                    // The generic table links a 'maintenance' column using
                    // schedule_id or id; searchAll returns the schedule as `id`.
                    $row['schedule_id'] = $row['id'];
                }

                return $rows;
            },
            defaultColumns: ['due_status', 'next_due_date', 'title', 'asset_tag', 'assignee'],
        );
    }

    // -- PAT ------------------------------------------------------------------

    private static function pat(): DataSource
    {
        return new DataSource(
            key: 'pat',
            label: 'PAT testing',
            description: 'Assets that require portable appliance testing, with their current test state.',
            permission: 'pat.view',
            group: 'Maintenance & testing',
            filters: [
                'q' => ['label' => 'Search', 'type' => 'text', 'hint' => 'Tag, name, serial, manufacturer, label serial.'],
                'status' => ['label' => 'PAT status is any of', 'type' => 'multi', 'options' => self::asKeyedOptions(
                    ['Never tested', 'Failed', 'Overdue', 'Due soon', 'Current', 'No retest date', 'Retired']
                )],
                'category_id' => ['label' => 'Category', 'type' => 'select', 'options' => self::categoryOptions()],
                'location_id' => ['label' => 'Location', 'type' => 'select', 'options' => self::locationOptions()],
                'include_retired' => ['label' => 'Include retired assets', 'type' => 'bool'],
            ],
            columns: [
                'pat_status'       => ['label' => 'PAT status', 'type' => 'badge', 'badge' => 'pat-status-'],
                'asset_tag'        => ['label' => 'Asset tag', 'link' => 'asset'],
                'name'             => ['label' => 'Asset'],
                'retest_due_date'  => ['label' => 'Retest due', 'type' => 'date'],
                'test_date'        => ['label' => 'Last tested', 'type' => 'date'],
                'result'           => ['label' => 'Last result'],
                'appliance_class'  => ['label' => 'Class'],
                'pat_label_serial' => ['label' => 'Label serial'],
                'location_name'    => ['label' => 'Location'],
                'category_name'    => ['label' => 'Category'],
                'condition_rating' => ['label' => 'Condition', 'type' => 'badge', 'badge' => 'condition-'],
                'asset_status'     => ['label' => 'Asset status', 'type' => 'badge', 'badge' => 'status-'],
                'test_count'       => ['label' => 'Tests on record', 'type' => 'number', 'align' => 'right'],
            ],
            fetch: static fn (array $filters): array => PatRecord::assetSearchAll($filters),
            defaultColumns: ['pat_status', 'asset_tag', 'name', 'retest_due_date', 'test_date'],
        );
    }

    // -- Hires ----------------------------------------------------------------

    private static function hires(): DataSource
    {
        $hirers = ['' => 'Any hirer'];
        foreach (Hirer::all() as $hirer) {
            $hirers[(string) $hirer['id']] = Hirer::label($hirer);
        }

        return new DataSource(
            key: 'hires',
            label: 'Hires',
            description: 'Equipment out on hire and the history of everything that has been.',
            permission: 'hires.view',
            group: 'Hires',
            filters: [
                'q' => ['label' => 'Search', 'type' => 'text', 'hint' => 'Reference, purpose, asset, hirer.'],
                'status' => ['label' => 'Status is any of', 'type' => 'multi',
                    'options' => self::asKeyedOptions(Hire::STATUSES)],
                'hirer_id' => ['label' => 'Hirer', 'type' => 'select', 'options' => $hirers],
                'open_only' => ['label' => 'Currently out only', 'type' => 'bool'],
                'due_within_days' => ['label' => 'Due back within (days)', 'type' => 'number'],
                'from' => ['label' => 'Taken out on or after', 'type' => 'date'],
                'to'   => ['label' => 'Taken out on or before', 'type' => 'date'],
            ],
            columns: [
                'reference'        => ['label' => 'Reference', 'link' => 'hire'],
                'effective_status' => ['label' => 'Status', 'type' => 'badge', 'badge' => 'hire-'],
                'asset_tag'        => ['label' => 'Asset tag', 'link' => 'asset'],
                'asset_name'       => ['label' => 'Asset'],
                'hirer_label'      => ['label' => 'Hirer'],
                'checked_out_at'   => ['label' => 'Taken out', 'type' => 'datetime'],
                'due_back_date'    => ['label' => 'Due back', 'type' => 'date'],
                'returned_at'      => ['label' => 'Returned', 'type' => 'datetime'],
                'purpose'          => ['label' => 'Purpose'],
                'condition_out'    => ['label' => 'Condition out'],
                'condition_in'     => ['label' => 'Condition in'],
            ],
            fetch: static function (array $filters): array {
                $rows = Hire::searchAll($filters);

                foreach ($rows as &$row) {
                    $row['hirer_label'] = Hirer::label([
                        'name'         => $row['hirer_name'] ?? '',
                        'company_name' => $row['company_name'] ?? null,
                    ]);
                    $row['hire_id'] = $row['id'];
                }

                return $rows;
            },
            defaultColumns: ['reference', 'effective_status', 'asset_tag', 'hirer_label', 'due_back_date'],
        );
    }

    // -- Faults ---------------------------------------------------------------

    private static function faults(): DataSource
    {
        return new DataSource(
            key: 'faults',
            label: 'Faulty equipment',
            description: 'Assets currently marked faulty, with the fault that describes them.',
            permission: 'assets.view',
            group: 'Maintenance & testing',
            filters: [
                'q' => ['label' => 'Search', 'type' => 'text', 'hint' => 'Tag, asset name, fault description.'],
                'urgency' => ['label' => 'Urgency', 'type' => 'select', 'options' =>
                    ['' => 'Any urgency', FaultReport::URGENT => 'Critical or High']
                    + self::asKeyedOptions(FaultReport::URGENCIES)],
                'responsible' => ['label' => 'Responsible', 'type' => 'select', 'options' =>
                    ['' => 'Anyone', 'none' => 'Nobody set']],
            ],
            columns: [
                'urgency'          => ['label' => 'Urgency', 'type' => 'badge', 'badge' => 'urgency-'],
                'asset_tag'        => ['label' => 'Asset tag', 'link' => 'asset'],
                'asset_name'       => ['label' => 'Asset'],
                'faulty_on'        => ['label' => 'Faulty since', 'type' => 'date'],
                'days_faulty'      => ['label' => 'Days faulty', 'type' => 'number', 'align' => 'right'],
                'description'      => ['label' => 'What is wrong'],
                'responsible_name' => ['label' => 'Responsible'],
                'location_name'    => ['label' => 'Location'],
                'category_name'    => ['label' => 'Category'],
                'condition_rating' => ['label' => 'Condition', 'type' => 'badge', 'badge' => 'condition-'],
                'reported_by_name' => ['label' => 'Reported by'],
                'reported_at'      => ['label' => 'Reported', 'type' => 'datetime'],
            ],
            fetch: static fn (array $filters): array => FaultReport::currentFaults($filters),
            defaultColumns: ['urgency', 'asset_tag', 'asset_name', 'faulty_on', 'description'],
        );
    }

    // -- Shared option lists ---------------------------------------------------

    /**
     * @param array<int,string> $values
     * @return array<string,string>
     */
    private static function asKeyedOptions(array $values): array
    {
        $options = [];

        foreach ($values as $value) {
            $options[(string) $value] = (string) $value;
        }

        return $options;
    }

    /** @return array<string,string> */
    private static function categoryOptions(): array
    {
        $options = ['' => 'Any category'];

        foreach (Category::all(true) as $category) {
            $options[(string) $category['id']] = (string) ($category['parent_name'] !== null
                ? $category['parent_name'] . ' → ' . $category['name']
                : $category['name']);
        }

        return $options;
    }

    /** @return array<string,string> */
    private static function locationOptions(): array
    {
        $options = ['' => 'Any location'];

        foreach (Location::forSelect() as $location) {
            $options[(string) $location['id']] = (string) $location['display_name'];
        }

        return $options;
    }
}
