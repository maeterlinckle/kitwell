<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * A starting point for an asset that gets registered often.
 *
 * A template holds defaults for the fields that describe a *model* of thing —
 * make, model, electrical properties, category — and the library media that
 * goes with it. Everything it supplies is a default: the Add asset form fills
 * the fields in and the person creating the asset can change any of them.
 *
 * It cannot supply an asset tag or a barcode. Those identify one physical item,
 * so they are generated or typed per asset no matter which template was used.
 */
final class AssetTemplate
{
    /**
     * The asset fields a template can pre-fill, mapped to the template column
     * that holds them. Anything outside this list is not templatable, and
     * `asset_tag`, `barcode` and `serial_number` are absent on purpose.
     *
     * @var array<string,string> asset field => template column
     */
    public const PREFILL_FIELDS = [
        'name'                  => 'asset_name',
        'description'           => 'asset_description',
        'category_id'           => 'category_id',
        'location_id'           => 'location_id',
        'manufacturer'          => 'manufacturer',
        'model'                 => 'model',
        'manufacturer_url'      => 'manufacturer_url',
        'supplier'              => 'supplier',
        'condition_rating'      => 'condition_rating',
        'appliance_class'       => 'appliance_class',
        'load_rating_va'        => 'load_rating_va',
        'has_fuse'              => 'has_fuse',
        'plug_fuse_rating_amps' => 'plug_fuse_rating_amps',
        'cable_csa_mm2'         => 'cable_csa_mm2',
        'requires_pat'          => 'requires_pat',
        'pat_interval_months'   => 'pat_interval_months',
        'is_hireable'           => 'is_hireable',
        'notes'                 => 'notes',
    ];

    /**
     * Fields a template must never carry, checked by tests/media-library.php.
     *
     * @var array<int,string>
     */
    public const NEVER_TEMPLATED = ['asset_tag', 'barcode', 'serial_number', 'status'];

    /** @return array<int,array<string,mixed>> */
    public static function all(bool $activeOnly = false): array
    {
        $whereSql = $activeOnly ? 'WHERE t.is_active = 1' : '';

        return Database::select(
            'SELECT t.*, c.name AS category_name, l.name AS location_name,
                    (SELECT COUNT(*) FROM template_media tm WHERE tm.template_id = t.id) AS media_count
               FROM asset_templates t
               LEFT JOIN categories c ON c.id = t.category_id
               LEFT JOIN locations  l ON l.id = t.location_id
               ' . $whereSql . '
              ORDER BY t.name'
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            'SELECT t.*, c.name AS category_name, l.name AS location_name
               FROM asset_templates t
               LEFT JOIN categories c ON c.id = t.category_id
               LEFT JOIN locations  l ON l.id = t.location_id
              WHERE t.id = ?',
            [$id]
        );
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('asset_templates', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('asset_templates', $data, $id);
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM asset_templates WHERE id = ?', [$id]);
    }

    public static function nameExists(string $name, int $exceptId = 0): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM asset_templates WHERE name = ? AND id <> ?',
            [$name, $exceptId]
        ) > 0;
    }

    /**
     * The template's values as Add-asset form fields.
     *
     * Only what the template actually sets: a NULL column means it has nothing
     * to say about that field, and the form keeps its own default. That is why
     * the three flags are nullable — "leave it alone" and "switch it off" are
     * different instructions.
     *
     * @param array<string,mixed> $template
     * @return array<string,mixed>
     */
    public static function prefill(array $template): array
    {
        $values = [];

        foreach (self::PREFILL_FIELDS as $field => $column) {
            $value = $template[$column] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $values[$field] = $value;
        }

        return $values;
    }
}
