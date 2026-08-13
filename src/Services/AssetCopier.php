<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Asset;
use App\Models\MediaLibrary;

/**
 * The two copy workflows:
 *
 *   1. Duplicate — create N new assets from an existing one, each with its own
 *      generated tag. Item-specific details (serial number, photos, PAT and
 *      hire history) are never carried over.
 *   2. Apply — push selected fields from one asset onto other existing assets,
 *      field by field, so nothing the user did not tick is touched.
 */
final class AssetCopier
{
    /**
     * Fields that can be copied, with the label shown in the UI. The order here
     * is the order they appear on the copy screens.
     *
     * @var array<string,string>
     */
    public const COPYABLE_FIELDS = [
        'name'                  => 'Name',
        'description'           => 'Description',
        'category_id'           => 'Category',
        'location_id'           => 'Location',
        'manufacturer'          => 'Manufacturer',
        'model'                 => 'Model',
        'manufacturer_url'      => 'Manufacturer website',
        'supplier'              => 'Supplier',
        'purchase_date'         => 'Purchase date',
        'purchase_cost'         => 'Purchase cost',
        'current_value'         => 'Current value',
        'warranty_expires_on'   => 'Warranty expiry',
        'condition_rating'      => 'Condition',
        'plug_fuse_rating_amps' => 'Plug fuse rating (A)',
        'cable_csa_mm2'         => 'Cable CSA (mm²)',
        'requires_pat'          => 'Requires PAT',
        'pat_interval_months'   => 'PAT interval (months)',
        'is_hireable'           => 'Available for hire',
        'notes'                 => 'Notes',
    ];

    /** Fields ticked by default when duplicating an asset. */
    public const DUPLICATE_DEFAULTS = [
        'name', 'description', 'category_id', 'manufacturer', 'model', 'manufacturer_url',
        'supplier', 'plug_fuse_rating_amps', 'cable_csa_mm2', 'requires_pat',
        'pat_interval_months', 'is_hireable',
    ];

    /**
     * Never carried over by either workflow: these identify one physical item.
     *
     * @var array<int,string>
     */
    public const NEVER_COPIED = ['asset_tag', 'barcode', 'serial_number', 'status', 'retired_on', 'parent_asset_id'];

    /**
     * Create one or more copies of an asset.
     *
     * @param array<string,mixed> $source     The asset being copied.
     * @param array<string,mixed> $values     Field values from the copy form (already validated).
     * @param array<int,string>   $fields     Which fields to take from the form.
     * @return array<int,int> The new asset ids.
     */
    public static function duplicate(array $source, array $values, array $fields, int $quantity, bool $copyMedia): array
    {
        $quantity = max(1, min(50, $quantity));
        $fields   = array_values(array_intersect($fields, array_keys(self::COPYABLE_FIELDS)));
        $tags     = AssetTagger::nextBatch($quantity);
        $newIds   = [];

        Database::beginTransaction();

        try {
            foreach ($tags as $tag) {
                $data = [
                    'asset_tag'  => $tag,
                    'status'     => 'In Stock',
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ];

                foreach ($fields as $field) {
                    $data[$field] = $values[$field] ?? null;
                }

                // Serial numbers are per-item, so only a single copy may carry
                // one through from the form; batches leave it blank.
                if ($quantity === 1 && !empty($values['serial_number'])) {
                    $data['serial_number'] = $values['serial_number'];
                }

                if (!empty($values['location_id'])) {
                    $data['location_id'] = (int) $values['location_id'];
                }

                if (!empty($values['parent_asset_id'])) {
                    $data['parent_asset_id']   = (int) $values['parent_asset_id'];
                    $data['relationship_type'] = $values['relationship_type'] ?? 'sub-asset';
                }

                if (!isset($data['condition_rating']) || $data['condition_rating'] === null) {
                    $data['condition_rating'] = 'Good';
                }

                $newId    = Asset::create($data);
                $newIds[] = $newId;

                if ($copyMedia) {
                    self::copyMedia((int) $source['id'], $newId);
                }
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return $newIds;
    }

    /**
     * Apply selected fields from one asset onto others.
     *
     * @param array<string,mixed> $source
     * @param array<int,int>      $targetIds
     * @param array<int,string>   $fields
     * @return array{updated:int,media:int,skipped:int}
     */
    public static function applyTo(array $source, array $targetIds, array $fields, bool $copyMedia): array
    {
        $fields    = array_values(array_intersect($fields, array_keys(self::COPYABLE_FIELDS)));
        $targetIds = array_values(array_unique(array_filter(array_map('intval', $targetIds))));

        $updated = 0;
        $media = 0;
        $skipped = 0;

        if ($targetIds === [] || ($fields === [] && !$copyMedia)) {
            return ['updated' => 0, 'media' => 0, 'skipped' => count($targetIds)];
        }

        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $source[$field] ?? null;
        }

        Database::beginTransaction();

        try {
            foreach ($targetIds as $targetId) {
                if ($targetId === (int) $source['id']) {
                    $skipped++;
                    continue;
                }

                $target = Asset::find($targetId);
                if ($target === null) {
                    $skipped++;
                    continue;
                }

                if ($data !== []) {
                    Asset::update($targetId, $data + ['updated_by' => Auth::id()]);
                    $updated++;
                }

                if ($copyMedia) {
                    $media += self::copyMedia((int) $source['id'], $targetId);
                }
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return ['updated' => $updated, 'media' => $media, 'skipped' => $skipped];
    }

    /**
     * Give the target asset everything in the source's library.
     *
     * Nothing is copied: each item gains one more join row. Ten assets built
     * from one drill share one manual, one file on disk, one library record.
     * Anything already attached is left alone, so this is safe to run twice.
     *
     * Condition photos are not here and never will be — they record what one
     * physical item looked like on one day, so copying them onto a different
     * item would be a false history.
     *
     * @return int How many attachments were new.
     */
    public static function copyMedia(int $fromAssetId, int $toAssetId): int
    {
        return MediaLibrary::attachMany($toAssetId, MediaLibrary::assetMediaIds($fromAssetId));
    }
}
