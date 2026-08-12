<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Auth;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Category;
use App\Models\FaultReport;
use App\Models\Hire;
use App\Models\Hirer;
use App\Models\Location;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceSchedule;
use App\Models\PatRecord;
use App\Models\Team;
use App\Models\User;

/**
 * Everything the API exposes.
 *
 * Two rules run through the whole file.
 *
 * **Reuse, never re-implement.** Every list closure calls the same model method
 * the corresponding screen calls, with the same filter keys, so the API cannot
 * return a row the interface would have hidden or accept a filter the interface
 * does not understand.
 *
 * **Read-only where writing carries a workflow the model does not.** Some
 * resources are GET-only, and each says so in its own description. Checking a
 * hire out moves the asset's status, allocates a reference and refuses a double
 * booking; a `POST /hires` that inserted a row would produce a hire the rest of
 * the application does not believe in. Those resources read, and the interface
 * writes.
 */
final class ResourceRegistry
{
    /** @var array<string,Resource>|null */
    private static ?array $resources = null;

    /** @return array<string,Resource> */
    public static function all(): array
    {
        if (self::$resources === null) {
            self::$resources = [];

            foreach ([
                self::assets(),
                self::categories(),
                self::locations(),
                self::maintenanceSchedules(),
                self::maintenanceLogs(),
                self::patRecords(),
                self::hires(),
                self::hirers(),
                self::teams(),
                self::faults(),
                self::users(),
            ] as $resource) {
                self::$resources[$resource->name] = $resource;
            }
        }

        return self::$resources;
    }

    public static function find(string $name): ?Resource
    {
        return self::all()[$name] ?? null;
    }

    /**
     * Resources the acting user may list.
     *
     * Used only by the index endpoint and the documentation page, so both
     * describe the API *this* caller has rather than one they will be refused.
     *
     * @return array<string,Resource>
     */
    public static function visible(): array
    {
        return array_filter(
            self::all(),
            static fn (Resource $r): bool => Auth::can((string) $r->permission('list'))
        );
    }

    // -- Assets ---------------------------------------------------------------

    private static function assets(): Resource
    {
        return new Resource(
            name: 'assets',
            singular: 'asset',
            description: 'The register. Sub-assets and accessories are assets too, distinguished by parent_id.',
            permissions: [
                'list'   => 'assets.view',
                'read'   => 'assets.view',
                'create' => 'assets.create',
                'update' => 'assets.edit',
                'delete' => 'assets.delete',
            ],
            fields: [
                'id'                  => ['type' => 'integer', 'description' => 'Identifier.'],
                'asset_tag'           => ['type' => 'string', 'writable' => true, 'required' => true, 'label' => 'Asset tag',
                    'description' => 'The printed barcode value. Unique across the register.'],
                'barcode'             => ['type' => 'string', 'writable' => true, 'label' => 'Barcode',
                    'description' => 'An optional second barcode the item already carries. Unique when set.'],
                'name'                => ['type' => 'string', 'writable' => true, 'required' => true, 'label' => 'Name'],
                'description'         => ['type' => 'string', 'writable' => true, 'label' => 'Description'],
                'category_id'         => ['type' => 'integer', 'writable' => true, 'label' => 'Category'],
                'category_name'       => ['type' => 'string', 'description' => 'Read-only; set category_id to change it.'],
                'location_id'         => ['type' => 'integer', 'writable' => true, 'label' => 'Location'],
                'location_name'       => ['type' => 'string', 'description' => 'Read-only; set location_id to change it.'],
                'status'              => ['type' => 'string', 'writable' => true, 'label' => 'Status', 'enum' => Asset::STATUSES, 'default' => 'In Stock'],
                'condition_rating'    => ['type' => 'string', 'writable' => true, 'label' => 'Condition', 'enum' => Asset::CONDITIONS, 'default' => 'Good'],
                'responsible_user_id' => ['type' => 'integer', 'writable' => true, 'label' => 'Responsible user',
                    'description' => 'Mutually exclusive with responsible_team_id; setting one clears the other.'],
                'responsible_team_id' => ['type' => 'integer', 'writable' => true, 'label' => 'Responsible team'],
                'responsible_name'    => ['type' => 'string', 'description' => 'Read-only name of whichever is set.'],
                'serial_number'       => ['type' => 'string', 'writable' => true, 'label' => 'Serial number'],
                'manufacturer'        => ['type' => 'string', 'writable' => true, 'label' => 'Manufacturer'],
                'model'               => ['type' => 'string', 'writable' => true, 'label' => 'Model'],
                'supplier'            => ['type' => 'string', 'writable' => true, 'label' => 'Supplier'],
                'purchase_date'       => ['type' => 'string', 'format' => 'date', 'writable' => true, 'label' => 'Purchase date'],
                'purchase_cost'       => ['type' => 'number', 'writable' => true, 'label' => 'Purchase cost'],
                'current_value'       => ['type' => 'number', 'writable' => true, 'label' => 'Current value'],
                'warranty_expires_on' => ['type' => 'string', 'format' => 'date', 'writable' => true, 'label' => 'Warranty expiry'],
                'requires_pat'        => ['type' => 'boolean', 'writable' => true, 'label' => 'Requires PAT', 'default' => false],
                'pat_interval_months' => ['type' => 'integer', 'writable' => true, 'label' => 'PAT interval (months)'],
                'is_hireable'         => ['type' => 'boolean', 'writable' => true, 'label' => 'Available to hire', 'default' => true],
                'parent_id'           => ['type' => 'integer', 'writable' => true, 'label' => 'Parent asset',
                    'description' => 'Set to make this a sub-asset of another. One level only.'],
                'relationship_type'   => ['type' => 'string', 'writable' => true, 'label' => 'Relationship',
                    'enum' => Asset::RELATIONSHIPS, 'description' => 'Only meaningful when parent_id is set.'],
                'notes'               => ['type' => 'string', 'writable' => true, 'label' => 'Notes'],
                'retired_on'          => ['type' => 'string', 'format' => 'date'],
                'created_at'          => ['type' => 'string', 'format' => 'date-time'],
                'updated_at'          => ['type' => 'string', 'format' => 'date-time'],
            ],
            filters: [
                'q'         => ['description' => 'Multi-term search across tag, name, serial, manufacturer, model, supplier and notes.'],
                'status'    => ['description' => 'Repeatable. Any of the asset statuses.', 'enum' => Asset::STATUSES, 'repeatable' => true, 'model_key' => 'status'],
                'condition' => ['description' => 'Repeatable.', 'enum' => Asset::CONDITIONS, 'repeatable' => true, 'model_key' => 'condition'],
                'category_id' => ['description' => 'Exact category.', 'type' => 'integer'],
                'location_id' => ['description' => 'Exact location.', 'type' => 'integer'],
                'parent_id'   => ['description' => 'Sub-assets of one asset.', 'type' => 'integer', 'model_key' => 'parent_asset_id'],
                'type'        => ['description' => 'top = top-level only, sub = sub-assets only.', 'enum' => ['top', 'sub']],
                'requires_pat' => ['description' => '1 or 0.', 'enum' => ['1', '0']],
                'include_archived' => ['description' => 'Include retired assets, which are otherwise left out.', 'type' => 'boolean'],
            ],
            sorts: [
                'asset_tag'  => 'tag',
                'name'       => 'name',
                'status'     => 'status',
                'condition'  => 'condition',
                'created_at' => 'newest',
                'updated_at' => 'updated',
                'value'      => 'value',
            ],
            list: static function (array $filters): array {
                $rows = Asset::searchAll($filters);

                return array_map(self::presentAsset(...), $rows);
            },
            get: static function (int $id): ?array {
                $row = Asset::find($id);

                return $row === null ? null : self::presentAsset($row);
            },
            create: static function (array $input): int {
                $id = Asset::create(self::assetColumns($input, null) + [
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                ActivityLog::record('created', 'asset', $id, sprintf(
                    'Registered %s — %s (API)',
                    $input['asset_tag'] ?? '',
                    $input['name'] ?? ''
                ));

                return $id;
            },
            update: static function (int $id, array $input, array $existing): void {
                $data = self::assetColumns($input, $existing) + ['updated_by' => Auth::id()];

                Asset::update($id, $data);
                ActivityLog::record('updated', 'asset', $id, sprintf('Updated %s (API)', $existing['asset_tag']));
            },
            delete: static function (int $id, array $existing): void {
                $blocked = array_filter(Asset::historyCounts($id));

                if ($blocked !== []) {
                    $parts = [];
                    foreach ($blocked as $type => $count) {
                        $parts[] = $count . ' ' . $type;
                    }

                    // The same refusal the interface makes, for the same reason:
                    // deleting an asset with history destroys the history.
                    throw Problem::conflict(
                        'This asset cannot be deleted because it has ' . implode(', ', $parts)
                        . '. Set status to "Retired" to archive it instead, which keeps everything.',
                        ['history' => $blocked]
                    );
                }

                Asset::delete($id);
                ActivityLog::record('deleted', 'asset', $id, sprintf('Deleted %s (API)', $existing['asset_tag']));
            },
            validate: static function (array $input, ?array $existing): array {
                $errors = [];
                $id     = $existing === null ? 0 : (int) $existing['id'];

                if (isset($input['asset_tag']) && Asset::tagExists((string) $input['asset_tag'], $id)) {
                    $errors['asset_tag'] = 'That asset tag is already in use.';
                }

                if (!empty($input['barcode']) && Asset::barcodeExists((string) $input['barcode'], $id)) {
                    $errors['barcode'] = 'That barcode is already assigned to another asset.';
                }

                if (!empty($input['category_id']) && Category::find((int) $input['category_id']) === null) {
                    $errors['category_id'] = 'No category with that id.';
                }

                if (!empty($input['location_id']) && Location::find((int) $input['location_id']) === null) {
                    $errors['location_id'] = 'No location with that id.';
                }

                if (!empty($input['parent_id'])) {
                    if ((int) $input['parent_id'] === $id) {
                        $errors['parent_id'] = 'An asset cannot be attached to itself.';
                    } elseif (Asset::find((int) $input['parent_id']) === null) {
                        $errors['parent_id'] = 'No asset with that id.';
                    } elseif ($id > 0 && Asset::childCount($id) > 0) {
                        $errors['parent_id'] = 'This asset already has items attached to it, so it cannot become a sub-asset.';
                    }
                }

                if (!empty($input['responsible_user_id']) && !empty($input['responsible_team_id'])) {
                    $errors['responsible_team_id'] = 'An asset is looked after by a person or a team, not both.';
                }

                if (!empty($input['responsible_user_id']) && User::find((int) $input['responsible_user_id']) === null) {
                    $errors['responsible_user_id'] = 'No user with that id.';
                }

                if (!empty($input['responsible_team_id']) && Team::find((int) $input['responsible_team_id']) === null) {
                    $errors['responsible_team_id'] = 'No team with that id.';
                }

                return $errors;
            },
            defaultSort: 'asset_tag',
        );
    }

    /**
     * The asset shape the API speaks, which is not quite the database's.
     *
     * `parent_asset_id` becomes `parent_id` because that is what a reader
     * expects, and the joined display names come along so a client listing 200
     * assets does not have to fetch 200 categories to show them.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function presentAsset(array $row): array
    {
        $row['parent_id']        = $row['parent_asset_id'] ?? null;
        $row['responsible_name'] = Asset::responsibleLabel($row, '');

        return $row;
    }

    /**
     * Map API field names onto database columns for a write.
     *
     * @param array<string,mixed>      $input
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private static function assetColumns(array $input, ?array $existing): array
    {
        $columns = [];

        $direct = [
            'asset_tag', 'barcode', 'name', 'description', 'category_id', 'location_id',
            'status', 'condition_rating', 'serial_number', 'manufacturer', 'model',
            'supplier', 'purchase_date', 'purchase_cost', 'current_value',
            'warranty_expires_on', 'pat_interval_months', 'notes', 'relationship_type',
            'responsible_user_id', 'responsible_team_id',
        ];

        foreach ($direct as $field) {
            if (array_key_exists($field, $input)) {
                $columns[$field] = $input[$field] === '' ? null : $input[$field];
            }
        }

        foreach (['requires_pat', 'is_hireable'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $columns[$flag] = self::toBool($input[$flag]) ? 1 : 0;
            }
        }

        if (array_key_exists('parent_id', $input)) {
            $parentId = (int) $input['parent_id'];
            $columns['parent_asset_id']   = $parentId > 0 ? $parentId : null;
            $columns['relationship_type'] = $parentId > 0
                ? (string) ($input['relationship_type'] ?? $existing['relationship_type'] ?? 'sub-asset')
                : null;
        }

        // The two responsible columns are mutually exclusive, so setting either
        // clears the other — otherwise a PATCH naming a team would leave the
        // old person in place and the team would win silently.
        if (array_key_exists('responsible_user_id', $input) && !empty($input['responsible_user_id'])) {
            $columns['responsible_team_id'] = null;
        }

        if (array_key_exists('responsible_team_id', $input) && !empty($input['responsible_team_id'])) {
            $columns['responsible_user_id'] = null;
        }

        if (array_key_exists('status', $input)) {
            $columns['retired_on'] = (string) $input['status'] === 'Retired'
                ? ($existing['retired_on'] ?? date('Y-m-d'))
                : null;
        }

        return $columns;
    }

    // -- Reference data --------------------------------------------------------

    private static function categories(): Resource
    {
        return new Resource(
            name: 'categories',
            singular: 'category',
            description: 'The category tree. A category with a parent_id is a child of it.',
            permissions: [
                'list' => 'assets.view', 'read' => 'assets.view',
                'create' => 'categories.manage', 'update' => 'categories.manage', 'delete' => 'categories.manage',
            ],
            fields: [
                'id'          => ['type' => 'integer'],
                'name'        => ['type' => 'string', 'writable' => true, 'required' => true, 'label' => 'Name'],
                'slug'        => ['type' => 'string'],
                'description' => ['type' => 'string', 'writable' => true, 'label' => 'Description'],
                'parent_id'   => ['type' => 'integer', 'writable' => true, 'label' => 'Parent category'],
                'parent_name' => ['type' => 'string'],
                'is_active'   => ['type' => 'boolean', 'writable' => true, 'label' => 'Active', 'default' => true],
                'asset_count' => ['type' => 'integer', 'description' => 'How many assets use it.'],
            ],
            filters: ['active_only' => ['description' => 'Leave out archived categories.', 'type' => 'boolean']],
            sorts: [],
            list: static fn (array $f): array => Category::all(!empty($f['active_only'])),
            get: static fn (int $id): ?array => Category::find($id),
            create: static function (array $input): int {
                $id = Category::create(
                    (string) $input['name'],
                    !empty($input['parent_id']) ? (int) $input['parent_id'] : null,
                    isset($input['description']) ? (string) $input['description'] : null
                );

                ActivityLog::record('created', 'category', $id, 'Added the category "' . $input['name'] . '" (API)');

                return $id;
            },
            update: static function (int $id, array $input, array $existing): void {
                $data = [];

                foreach (['name', 'description'] as $field) {
                    if (array_key_exists($field, $input)) {
                        $data[$field] = $input[$field] === '' ? null : $input[$field];
                    }
                }

                if (array_key_exists('parent_id', $input)) {
                    $data['parent_id'] = !empty($input['parent_id']) ? (int) $input['parent_id'] : null;
                }

                if (array_key_exists('is_active', $input)) {
                    $data['is_active'] = self::toBool($input['is_active']) ? 1 : 0;
                }

                Category::update($id, $data);
                ActivityLog::record('updated', 'category', $id, 'Updated the category "' . $existing['name'] . '" (API)');
            },
            delete: static function (int $id, array $existing): void {
                if (Category::inUse($id)) {
                    throw Problem::conflict('That category is still in use by at least one asset. Set is_active to false to retire it instead.');
                }

                Category::delete($id);
                ActivityLog::record('deleted', 'category', $id, 'Deleted the category "' . $existing['name'] . '" (API)');
            },
            validate: static function (array $input, ?array $existing): array {
                $id = $existing === null ? 0 : (int) $existing['id'];

                if (!empty($input['parent_id'])) {
                    if ((int) $input['parent_id'] === $id) {
                        return ['parent_id' => 'A category cannot be its own parent.'];
                    }

                    if (Category::find((int) $input['parent_id']) === null) {
                        return ['parent_id' => 'No category with that id.'];
                    }

                    if ($id > 0 && in_array((int) $input['parent_id'], Category::descendantIds($id), true)) {
                        return ['parent_id' => 'That would put the category inside one of its own children.'];
                    }
                }

                return [];
            },
        );
    }

    private static function locations(): Resource
    {
        return new Resource(
            name: 'locations',
            singular: 'location',
            description: 'The location tree, in the same shape as categories.',
            permissions: [
                'list' => 'assets.view', 'read' => 'assets.view',
                'create' => 'locations.manage', 'update' => 'locations.manage', 'delete' => 'locations.manage',
            ],
            fields: [
                'id'          => ['type' => 'integer'],
                'name'        => ['type' => 'string', 'writable' => true, 'required' => true, 'label' => 'Name'],
                'code'        => ['type' => 'string', 'writable' => true, 'label' => 'Code'],
                'description' => ['type' => 'string', 'writable' => true, 'label' => 'Description'],
                'parent_id'   => ['type' => 'integer', 'writable' => true, 'label' => 'Parent location'],
                'parent_name' => ['type' => 'string'],
                'is_active'   => ['type' => 'boolean', 'writable' => true, 'label' => 'Active', 'default' => true],
                'asset_count' => ['type' => 'integer'],
            ],
            filters: ['active_only' => ['description' => 'Leave out archived locations.', 'type' => 'boolean']],
            sorts: [],
            list: static fn (array $f): array => Location::all(!empty($f['active_only'])),
            get: static fn (int $id): ?array => Location::find($id),
            create: static function (array $input): int {
                $id = Location::create(
                    (string) $input['name'],
                    isset($input['code']) ? (string) $input['code'] : null,
                    !empty($input['parent_id']) ? (int) $input['parent_id'] : null,
                    isset($input['description']) ? (string) $input['description'] : null
                );

                ActivityLog::record('created', 'location', $id, 'Added the location "' . $input['name'] . '" (API)');

                return $id;
            },
            update: static function (int $id, array $input, array $existing): void {
                $data = [];

                foreach (['name', 'code', 'description'] as $field) {
                    if (array_key_exists($field, $input)) {
                        $data[$field] = $input[$field] === '' ? null : $input[$field];
                    }
                }

                if (array_key_exists('parent_id', $input)) {
                    $data['parent_id'] = !empty($input['parent_id']) ? (int) $input['parent_id'] : null;
                }

                if (array_key_exists('is_active', $input)) {
                    $data['is_active'] = self::toBool($input['is_active']) ? 1 : 0;
                }

                Location::update($id, $data);
                ActivityLog::record('updated', 'location', $id, 'Updated the location "' . $existing['name'] . '" (API)');
            },
            delete: static function (int $id, array $existing): void {
                if (Location::inUse($id)) {
                    throw Problem::conflict('That location is still in use. Set is_active to false to retire it instead.');
                }

                Location::delete($id);
                ActivityLog::record('deleted', 'location', $id, 'Deleted the location "' . $existing['name'] . '" (API)');
            },
            validate: static function (array $input, ?array $existing): array {
                $id = $existing === null ? 0 : (int) $existing['id'];

                if (!empty($input['parent_id'])) {
                    if ((int) $input['parent_id'] === $id) {
                        return ['parent_id' => 'A location cannot be its own parent.'];
                    }

                    if (Location::find((int) $input['parent_id']) === null) {
                        return ['parent_id' => 'No location with that id.'];
                    }

                    if ($id > 0 && in_array((int) $input['parent_id'], Location::descendantIds($id), true)) {
                        return ['parent_id' => 'That would put the location inside one of its own children.'];
                    }
                }

                return [];
            },
        );
    }

    // -- Maintenance -----------------------------------------------------------

    private static function maintenanceSchedules(): Resource
    {
        return new Resource(
            name: 'maintenance-schedules',
            singular: 'maintenance schedule',
            description: 'Planned work: routine, periodic and one-off jobs, with a computed due status.',
            permissions: [
                'list' => 'maintenance.view', 'read' => 'maintenance.view',
                'create' => 'maintenance.manage', 'update' => 'maintenance.manage', 'delete' => 'maintenance.manage',
            ],
            fields: [
                'id'                  => ['type' => 'integer'],
                'asset_id'            => ['type' => 'integer', 'writable' => true, 'required' => true, 'label' => 'Asset'],
                'asset_tag'           => ['type' => 'string'],
                'asset_name'          => ['type' => 'string'],
                'title'               => ['type' => 'string', 'writable' => true, 'required' => true, 'label' => 'Title'],
                'maintenance_type'    => ['type' => 'string', 'writable' => true, 'label' => 'Type', 'enum' => MaintenanceSchedule::TYPES, 'default' => 'periodic'],
                'frequency_interval'  => ['type' => 'integer', 'writable' => true, 'label' => 'Interval'],
                'frequency_unit'      => ['type' => 'string', 'writable' => true, 'label' => 'Interval unit', 'enum' => MaintenanceSchedule::UNITS],
                'next_due_date'       => ['type' => 'string', 'format' => 'date', 'writable' => true, 'label' => 'Next due'],
                'last_completed_date' => ['type' => 'string', 'format' => 'date'],
                'assigned_to_user_id' => ['type' => 'integer', 'writable' => true, 'label' => 'Assigned user'],
                'assigned_to_team_id' => ['type' => 'integer', 'writable' => true, 'label' => 'Assigned team'],
                'assigned_to_name'    => ['type' => 'string'],
                'estimated_minutes'   => ['type' => 'integer', 'writable' => true, 'label' => 'Estimated minutes'],
                'instructions'        => ['type' => 'string', 'writable' => true, 'label' => 'Instructions'],
                'is_active'           => ['type' => 'boolean', 'writable' => true, 'label' => 'Active', 'default' => true],
                'due_status'          => ['type' => 'string', 'description' => 'Computed: Overdue, Due soon, Scheduled, Unscheduled or Inactive.'],
                'days_until_due'      => ['type' => 'integer', 'description' => 'Negative when overdue.'],
            ],
            filters: [
                'q'      => ['description' => 'Search job title, instructions and asset.'],
                'status' => ['description' => 'Repeatable due status.', 'repeatable' => true,
                    'enum' => ['Overdue', 'Due soon', 'Scheduled', 'Unscheduled', 'Inactive'], 'model_key' => 'status'],
                'type'   => ['description' => 'Schedule type.', 'enum' => MaintenanceSchedule::TYPES],
                'asset_id' => ['description' => 'Jobs on one asset.', 'type' => 'integer'],
                'due_within_days' => ['description' => 'Due within this many days.', 'type' => 'integer'],
                'include_inactive' => ['description' => 'Include closed schedules.', 'type' => 'boolean'],
            ],
            sorts: ['next_due_date' => 'due', 'title' => 'title', 'asset_tag' => 'asset'],
            list: static fn (array $f): array => MaintenanceSchedule::searchAll($f),
            get: static fn (int $id): ?array => MaintenanceSchedule::find($id),
            create: static function (array $input): int {
                $id = MaintenanceSchedule::create(self::scheduleColumns($input) + ['created_by' => Auth::id()]);
                ActivityLog::record('created', 'maintenance_schedule', $id, sprintf('Added the schedule "%s" (API)', $input['title']));

                return $id;
            },
            update: static function (int $id, array $input, array $existing): void {
                $data = self::scheduleColumns($input);
                unset($data['asset_id']);   // a schedule stays with its asset

                MaintenanceSchedule::update($id, $data);
                ActivityLog::record('updated', 'maintenance_schedule', $id, sprintf('Updated the schedule "%s" (API)', $existing['title']));
            },
            delete: static function (int $id, array $existing): void {
                MaintenanceSchedule::delete($id);
                ActivityLog::record('deleted', 'maintenance_schedule', $id, sprintf('Deleted the schedule "%s" (API)', $existing['title']));
            },
            validate: static function (array $input, ?array $existing): array {
                $errors = [];

                if (!empty($input['asset_id']) && Asset::find((int) $input['asset_id']) === null) {
                    $errors['asset_id'] = 'No asset with that id.';
                }

                if (!empty($input['assigned_to_user_id']) && !empty($input['assigned_to_team_id'])) {
                    $errors['assigned_to_team_id'] = 'A job is assigned to a person or a team, not both.';
                }

                if (!empty($input['assigned_to_team_id']) && Team::find((int) $input['assigned_to_team_id']) === null) {
                    $errors['assigned_to_team_id'] = 'No team with that id.';
                }

                if (!empty($input['assigned_to_user_id']) && User::find((int) $input['assigned_to_user_id']) === null) {
                    $errors['assigned_to_user_id'] = 'No user with that id.';
                }

                return $errors;
            },
            defaultSort: 'next_due_date',
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private static function scheduleColumns(array $input): array
    {
        $columns = [];

        foreach ([
            'asset_id', 'title', 'maintenance_type', 'frequency_interval', 'frequency_unit',
            'next_due_date', 'assigned_to_user_id', 'assigned_to_team_id', 'estimated_minutes', 'instructions',
        ] as $field) {
            if (array_key_exists($field, $input)) {
                $columns[$field] = $input[$field] === '' ? null : $input[$field];
            }
        }

        if (array_key_exists('is_active', $input)) {
            $columns['is_active'] = self::toBool($input['is_active']) ? 1 : 0;
        }

        // Same exclusivity rule as the form and the API's asset resource.
        if (!empty($input['assigned_to_team_id'])) {
            $columns['assigned_to_user_id'] = null;
        } elseif (!empty($input['assigned_to_user_id'])) {
            $columns['assigned_to_team_id'] = null;
        }

        return $columns;
    }

    private static function maintenanceLogs(): Resource
    {
        return new Resource(
            name: 'maintenance-logs',
            singular: 'maintenance record',
            description: 'Work that has been done. Read-only: recording a completion also rolls the schedule '
                . 'forward, carries the condition onto the asset, may return it to stock and may create a '
                . 'follow-up job — a bare insert would produce a record none of that had happened for.',
            permissions: ['list' => 'maintenance.view', 'read' => 'maintenance.view'],
            fields: [
                'id'                => ['type' => 'integer'],
                'asset_id'          => ['type' => 'integer'],
                'asset_tag'         => ['type' => 'string'],
                'asset_name'        => ['type' => 'string'],
                'schedule_id'       => ['type' => 'integer'],
                'schedule_title'    => ['type' => 'string'],
                'maintenance_type'  => ['type' => 'string'],
                'performed_on'      => ['type' => 'string', 'format' => 'date'],
                'performed_by_name' => ['type' => 'string'],
                'work_done'         => ['type' => 'string'],
                'parts_used'        => ['type' => 'string'],
                'cost'              => ['type' => 'number'],
                'downtime_minutes'  => ['type' => 'integer'],
                'result'            => ['type' => 'string'],
                'condition_after'   => ['type' => 'string'],
                'next_due_date'     => ['type' => 'string', 'format' => 'date'],
                'notes'             => ['type' => 'string'],
                'created_at'        => ['type' => 'string', 'format' => 'date-time'],
            ],
            filters: [
                'q'        => ['description' => 'Search work done, parts and asset.'],
                'asset_id' => ['description' => 'Records for one asset.', 'type' => 'integer'],
                'from'     => ['description' => 'Performed on or after this date.', 'format' => 'date'],
                'to'       => ['description' => 'Performed on or before this date.', 'format' => 'date'],
            ],
            sorts: [],
            list: static fn (array $f): array => MaintenanceLog::search($f, 1, 5000)['rows'],
            get: static fn (int $id): ?array => MaintenanceLog::find($id),
        );
    }

    // -- PAT --------------------------------------------------------------------

    private static function patRecords(): Resource
    {
        return new Resource(
            name: 'pat-records',
            singular: 'PAT record',
            description: 'Portable appliance test results, one row per test. Read-only: a test is entered '
                . 'through a guided flow that records a verdict per electrical step, and a record posted '
                . 'without those would claim a test nobody performed.',
            permissions: ['list' => 'pat.view', 'read' => 'pat.view'],
            fields: [
                'id'               => ['type' => 'integer'],
                'asset_id'         => ['type' => 'integer'],
                'asset_tag'        => ['type' => 'string'],
                'asset_name'       => ['type' => 'string'],
                'test_date'        => ['type' => 'string', 'format' => 'date'],
                'retest_due_date'  => ['type' => 'string', 'format' => 'date'],
                'result'           => ['type' => 'string'],
                'appliance_class'  => ['type' => 'string'],
                'pat_label_serial' => ['type' => 'string'],
                'tester_name'      => ['type' => 'string'],
                'notes'            => ['type' => 'string'],
                'created_at'       => ['type' => 'string', 'format' => 'date-time'],
            ],
            filters: [
                'asset_id' => ['description' => 'Every test on one asset. Required unless you want the whole history.', 'type' => 'integer'],
            ],
            sorts: [],
            list: static function (array $f): array {
                if (!empty($f['asset_id'])) {
                    return PatRecord::forAsset((int) $f['asset_id']);
                }

                // No asset given: the register of assets that need testing, with
                // their current state, which is the more useful whole-estate
                // view and the one the PAT screen shows.
                return PatRecord::assetSearchAll([]);
            },
            get: static fn (int $id): ?array => PatRecord::find($id),
        );
    }

    // -- Hires ------------------------------------------------------------------

    private static function hires(): Resource
    {
        return new Resource(
            name: 'hires',
            singular: 'hire',
            description: 'Equipment out on hire, and everything that has been. Read-only: checking out moves '
                . 'the asset\'s status, allocates a reference and refuses a double booking, and returning '
                . 'records condition and photos — a hire inserted as a row would be one the rest of the '
                . 'application does not believe in.',
            permissions: ['list' => 'hires.view', 'read' => 'hires.view'],
            fields: [
                'id'               => ['type' => 'integer'],
                'reference'        => ['type' => 'string'],
                'asset_id'         => ['type' => 'integer'],
                'asset_tag'        => ['type' => 'string'],
                'asset_name'       => ['type' => 'string'],
                'hirer_id'         => ['type' => 'integer'],
                'hirer_name'       => ['type' => 'string'],
                'company_name'     => ['type' => 'string'],
                'checked_out_at'   => ['type' => 'string', 'format' => 'date-time'],
                'due_back_date'    => ['type' => 'string', 'format' => 'date'],
                'returned_at'      => ['type' => 'string', 'format' => 'date-time'],
                'effective_status' => ['type' => 'string', 'description' => 'Out, Overdue or Returned, computed at read time.'],
                'days_until_due'   => ['type' => 'integer'],
                'purpose'          => ['type' => 'string'],
                'condition_out'    => ['type' => 'string'],
                'condition_in'     => ['type' => 'string'],
            ],
            filters: [
                'q'         => ['description' => 'Search reference, purpose, asset and hirer.'],
                'status'    => ['description' => 'Repeatable.', 'repeatable' => true, 'enum' => Hire::STATUSES, 'model_key' => 'status'],
                'hirer_id'  => ['description' => 'Hires to one hirer.', 'type' => 'integer'],
                'asset_id'  => ['description' => 'Hires of one asset.', 'type' => 'integer'],
                'open_only' => ['description' => 'Only what is currently out.', 'type' => 'boolean'],
                'due_within_days' => ['description' => 'Due back within this many days.', 'type' => 'integer'],
                'from'      => ['description' => 'Taken out on or after.', 'format' => 'date'],
                'to'        => ['description' => 'Taken out on or before.', 'format' => 'date'],
            ],
            sorts: ['due_back_date' => 'due', 'checked_out_at' => 'recent', 'asset_tag' => 'asset', 'hirer_name' => 'hirer'],
            list: static fn (array $f): array => Hire::searchAll($f),
            get: static fn (int $id): ?array => Hire::find($id),
            defaultSort: 'due_back_date',
        );
    }

    private static function hirers(): Resource
    {
        return new Resource(
            name: 'hirers',
            singular: 'hirer',
            description: 'The people and companies equipment goes out to.',
            permissions: [
                'list' => 'hirers.view', 'read' => 'hirers.view',
                'create' => 'hirers.manage', 'update' => 'hirers.manage', 'delete' => 'hirers.manage',
            ],
            fields: [
                'id'           => ['type' => 'integer'],
                'name'         => ['type' => 'string', 'writable' => true, 'required' => true, 'label' => 'Name'],
                'hirer_type'   => ['type' => 'string', 'writable' => true, 'label' => 'Type', 'enum' => Hirer::TYPES, 'default' => 'Person'],
                'company_name' => ['type' => 'string', 'writable' => true, 'label' => 'Company'],
                'reference'    => ['type' => 'string', 'writable' => true, 'label' => 'Reference'],
                'email'        => ['type' => 'string', 'format' => 'email', 'writable' => true, 'label' => 'Email'],
                'phone'        => ['type' => 'string', 'writable' => true, 'label' => 'Phone'],
                'address'      => ['type' => 'string', 'writable' => true, 'label' => 'Address'],
                'notes'        => ['type' => 'string', 'writable' => true, 'label' => 'Notes'],
                'is_active'    => ['type' => 'boolean', 'writable' => true, 'label' => 'Active', 'default' => true],
                'open_hires'   => ['type' => 'integer', 'description' => 'How many items they currently hold.'],
            ],
            filters: [
                'q'         => ['description' => 'Search name, company, reference, email and phone.'],
                'type'      => ['description' => 'Person or Company.', 'enum' => Hirer::TYPES],
                'active_only' => ['description' => 'Leave out archived hirers.', 'type' => 'boolean'],
            ],
            sorts: [],
            // `active_only` is the API's word across every resource; the model
            // has always called it `is_active`, so the translation lives here
            // rather than making one screen's vocabulary the API's.
            list: static function (array $f): array {
                if (!empty($f['active_only'])) {
                    $f['is_active'] = 1;
                }

                unset($f['active_only']);

                return Hirer::all($f);
            },
            get: static fn (int $id): ?array => Hirer::find($id),
            create: static function (array $input): int {
                $id = Hirer::create(self::plain($input, [
                    'name', 'hirer_type', 'company_name', 'reference', 'email', 'phone', 'address', 'notes',
                ]));

                ActivityLog::record('created', 'hirer', $id, 'Added the hirer "' . $input['name'] . '" (API)');

                return $id;
            },
            update: static function (int $id, array $input, array $existing): void {
                $data = self::plain($input, [
                    'name', 'hirer_type', 'company_name', 'reference', 'email', 'phone', 'address', 'notes',
                ]);

                if (array_key_exists('is_active', $input)) {
                    $data['is_active'] = self::toBool($input['is_active']) ? 1 : 0;
                }

                Hirer::update($id, $data);
                ActivityLog::record('updated', 'hirer', $id, 'Updated the hirer "' . $existing['name'] . '" (API)');
            },
            delete: static function (int $id, array $existing): void {
                if (Hirer::hasHires($id)) {
                    throw Problem::conflict('That hirer has hire history, which a delete would destroy. Set is_active to false instead.');
                }

                Hirer::delete($id);
                ActivityLog::record('deleted', 'hirer', $id, 'Deleted the hirer "' . $existing['name'] . '" (API)');
            },
        );
    }

    // -- Teams -------------------------------------------------------------------

    private static function teams(): Resource
    {
        return new Resource(
            name: 'teams',
            singular: 'team',
            description: 'Groups work can be assigned to. Membership is included on a single team read.',
            permissions: [
                'list' => 'maintenance.view', 'read' => 'maintenance.view',
                'create' => 'teams.manage', 'update' => 'teams.manage',
            ],
            fields: [
                'id'             => ['type' => 'integer'],
                'name'           => ['type' => 'string', 'writable' => true, 'required' => true, 'label' => 'Name'],
                'description'    => ['type' => 'string', 'writable' => true, 'label' => 'Description'],
                'is_active'      => ['type' => 'boolean', 'writable' => true, 'label' => 'Active', 'default' => true],
                'member_count'   => ['type' => 'integer'],
                'schedule_count' => ['type' => 'integer', 'description' => 'Live maintenance schedules assigned to it.'],
                'members'        => ['type' => 'array', 'description' => 'Only on a single team read: id, name and email of each member.'],
            ],
            filters: ['active_only' => ['description' => 'Leave out archived teams.', 'type' => 'boolean']],
            sorts: [],
            list: static fn (array $f): array => Team::all(empty($f['active_only'])),
            get: static function (int $id): ?array {
                $team = Team::find($id);

                if ($team === null) {
                    return null;
                }

                $team['members'] = array_map(
                    static fn (array $m): array => [
                        'id'    => (int) $m['id'],
                        'name'  => (string) $m['name'],
                        'email' => (string) $m['email'],
                    ],
                    Team::members($id)
                );

                return $team;
            },
            create: static function (array $input): int {
                $id = Team::create([
                    'name'        => $input['name'],
                    'description' => $input['description'] ?? null,
                    'is_active'   => 1,
                    'created_by'  => Auth::id(),
                ]);

                ActivityLog::record('created', 'team', $id, 'Created the team "' . $input['name'] . '" (API)');

                return $id;
            },
            update: static function (int $id, array $input, array $existing): void {
                $data = self::plain($input, ['name', 'description']);

                if (array_key_exists('is_active', $input)) {
                    $data['is_active'] = self::toBool($input['is_active']) ? 1 : 0;
                }

                Team::update($id, $data);
                ActivityLog::record('updated', 'team', $id, 'Updated the team "' . $existing['name'] . '" (API)');
            },
            validate: static function (array $input, ?array $existing): array {
                $id = $existing === null ? 0 : (int) $existing['id'];

                if (isset($input['name']) && Team::nameExists((string) $input['name'], $id)) {
                    return ['name' => 'A team with that name already exists.'];
                }

                return [];
            },
        );
    }

    // -- Faults -------------------------------------------------------------------

    private static function faults(): Resource
    {
        return new Resource(
            name: 'faults',
            singular: 'faulty asset',
            description: 'Assets currently marked faulty, with the fault report describing each. Read-only: '
                . 'reporting a fault requires a photograph, which this interface does not accept.',
            permissions: ['list' => 'assets.view', 'read' => 'assets.view'],
            fields: [
                'fault_report_id'  => ['type' => 'integer'],
                'asset_id'         => ['type' => 'integer'],
                'asset_tag'        => ['type' => 'string'],
                'asset_name'       => ['type' => 'string'],
                'urgency'          => ['type' => 'string', 'enum' => FaultReport::URGENCIES],
                'faulty_on'        => ['type' => 'string', 'format' => 'date'],
                'days_faulty'      => ['type' => 'integer'],
                'description'      => ['type' => 'string'],
                'condition_rating' => ['type' => 'string'],
                'responsible_name' => ['type' => 'string'],
                'location_name'    => ['type' => 'string'],
                'reported_by_name' => ['type' => 'string'],
                'reported_at'      => ['type' => 'string', 'format' => 'date-time'],
            ],
            filters: [
                'q'       => ['description' => 'Search tag, asset name and fault description.'],
                'urgency' => ['description' => 'One level, or "urgent" for Critical or High.',
                    'enum' => array_merge([FaultReport::URGENT], FaultReport::URGENCIES)],
            ],
            sorts: [],
            list: static fn (array $f): array => FaultReport::currentFaults($f),
        );
    }

    // -- Users --------------------------------------------------------------------

    private static function users(): Resource
    {
        return new Resource(
            name: 'users',
            singular: 'user',
            description: 'Accounts, for resolving the ids that appear on other resources. Read-only, and '
                . 'behind users.view: an account is created by invitation so that the person chooses their '
                . 'own password, which cannot happen over an API. No password material is ever returned.',
            permissions: ['list' => 'users.view', 'read' => 'users.view'],
            fields: [
                'id'            => ['type' => 'integer'],
                'name'          => ['type' => 'string'],
                'email'         => ['type' => 'string', 'format' => 'email'],
                'role_name'     => ['type' => 'string'],
                'role_slug'     => ['type' => 'string'],
                'is_active'     => ['type' => 'boolean'],
                'last_login_at' => ['type' => 'string', 'format' => 'date-time'],
                'created_at'    => ['type' => 'string', 'format' => 'date-time'],
            ],
            filters: [
                'q'           => ['description' => 'Search name and email.'],
                'active_only' => ['description' => 'Leave out deactivated accounts.', 'type' => 'boolean'],
            ],
            sorts: [],
            // User::all() has spoken `search` and `is_active` since stage 1 and
            // is used by the admin screen; the API's own names are translated
            // here rather than by renaming a filter three other callers use.
            list: static fn (array $f): array => User::all(array_filter([
                'search'    => $f['q'] ?? '',
                'is_active' => !empty($f['active_only']) ? '1' : '',
            ], static fn (string $v): bool => $v !== '')),
            get: static fn (int $id): ?array => User::find($id),
        );
    }

    // -- Helpers --------------------------------------------------------------------

    /**
     * @param array<string,mixed> $input
     * @param array<int,string>   $fields
     * @return array<string,mixed>
     */
    private static function plain(array $input, array $fields): array
    {
        $out = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $out[$field] = $input[$field] === '' ? null : $input[$field];
            }
        }

        return $out;
    }

    /** JSON true, the string "1", and the number 1 all mean the same thing. */
    private static function toBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }
}
