<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\MediaLibrary;
use App\Models\Category;
use App\Models\Location;
use App\Services\AssetCopier;
use App\Services\AssetTagger;

/**
 * The two copy workflows: duplicating an asset into N new ones, and applying
 * selected fields from one asset onto a chosen set of existing ones.
 */
final class AssetCopyController extends Controller
{
    /** Step 1 of duplicating: a form pre-filled from the source asset. */
    public function copyForm(string $id): void
    {
        $source = Asset::find((int) $id);

        if ($source === null) {
            $this->notFound();
        }

        $this->view('assets/copy', [
            'pageTitle'    => 'Copy ' . $source['asset_tag'],
            'source'       => $source,
            'fields'       => AssetCopier::COPYABLE_FIELDS,
            'defaults'     => AssetCopier::DUPLICATE_DEFAULTS,
            'categories'   => Category::all(true),
            'locations'    => Location::forSelect(),
            'mediaCount'   => MediaLibrary::countForAsset((int) $source['id']),
            'nextTags'     => AssetTagger::nextBatch(3),
            'parents'      => Asset::parentOptions((int) $source['id']),
        ]);
    }

    /** Step 2 of duplicating: create the copies. */
    public function storeCopies(string $id): void
    {
        $source = Asset::find((int) $id);

        if ($source === null) {
            $this->notFound();
        }

        $sourceId = (int) $source['id'];
        $redirect = '/assets/' . $sourceId . '/copy';

        $input = $this->validate([
            'quantity'          => 'required|integer|min_value:1|max_value:50',
            'name'              => 'required|max:191',
            'serial_number'     => 'max:191',
            'location_id'       => 'integer',
            'parent_asset_id'   => 'integer',
            'relationship_type' => 'in:' . implode(',', Asset::RELATIONSHIPS),
        ], [
            'quantity' => 'Number of copies',
            'name'     => 'Name',
        ], $redirect);

        $fields = (array) ($_POST['fields'] ?? []);
        $fields = array_values(array_intersect(array_map('strval', $fields), array_keys(AssetCopier::COPYABLE_FIELDS)));

        // Values default to the source asset, with anything set on the form
        // taking precedence. array_merge (not +) so the form wins on keys the
        // source also has, such as the location.
        $values = array_merge($source, [
            'name'              => $input['name'],
            // Blank means blank: a serial number identifies one physical item,
            // so it is never inherited from the source.
            'serial_number'     => $input['serial_number'] !== '' ? $input['serial_number'] : null,
            'location_id'       => (int) $input['location_id'] > 0 ? (int) $input['location_id'] : null,
            'parent_asset_id'   => (int) $input['parent_asset_id'] > 0 ? (int) $input['parent_asset_id'] : null,
            'relationship_type' => $input['relationship_type'] !== '' ? $input['relationship_type'] : 'sub-asset',
        ]);

        $quantity = (int) $input['quantity'];

        $newIds = AssetCopier::duplicate(
            $source,
            $values,
            $fields,
            $quantity,
            Request::boolean('copy_media')
        );

        $created = Asset::byIds($newIds);
        $tags    = array_map(static fn (array $a): string => (string) $a['asset_tag'], $created);

        ActivityLog::record(
            'copied',
            'asset',
            $sourceId,
            sprintf('Created %d cop%s of %s: %s', count($newIds), count($newIds) === 1 ? 'y' : 'ies', $source['asset_tag'], implode(', ', $tags))
        );

        foreach ($newIds as $newId) {
            ActivityLog::record('created', 'asset', $newId, 'Created as a copy of ' . $source['asset_tag']);
        }

        Flash::success(sprintf(
            '%d new asset%s created: %s',
            count($newIds),
            count($newIds) === 1 ? '' : 's',
            implode(', ', $tags)
        ));

        if (count($newIds) === 1) {
            Response::redirect('/assets/' . $newIds[0]);
        }

        // For a batch, go straight to the label sheet — the next thing you
        // want after creating five identical items is five labels.
        Response::redirect('/assets/labels?ids=' . implode(',', $newIds));
    }

    /** Step 1 of bulk apply: pick fields and target assets. */
    public function applyForm(string $id): void
    {
        $source = Asset::find((int) $id);

        if ($source === null) {
            $this->notFound();
        }

        $filters = [
            'q'          => (string) Request::query('q', ''),
            'category_id'=> (string) Request::query('category', ''),
            'exclude_id' => (int) $source['id'],
        ];

        // Default the candidate list to the same make and model, which is the
        // usual reason for doing this at all.
        $suggestOnly = Request::query('all') !== '1';
        if ($suggestOnly && $filters['q'] === '' && !empty($source['model'])) {
            $filters['q'] = trim((string) $source['manufacturer'] . ' ' . (string) $source['model']);
        }

        $result = Asset::search($filters, max(1, (int) Request::query('page', 1)), 50);

        $this->view('assets/apply', [
            'pageTitle'   => 'Copy details from ' . $source['asset_tag'],
            'source'      => $source,
            'fields'      => AssetCopier::COPYABLE_FIELDS,
            'result'      => $result,
            'filters'     => $filters,
            'categories'  => Category::all(true),
            'mediaCount'  => MediaLibrary::countForAsset((int) $source['id']),
            'suggestOnly' => $suggestOnly,
        ]);
    }

    /** Step 2 of bulk apply: write the selected fields onto the chosen assets. */
    public function applyStore(string $id): void
    {
        $source = Asset::find((int) $id);

        if ($source === null) {
            $this->notFound();
        }

        $sourceId = (int) $source['id'];
        $redirect = '/assets/' . $sourceId . '/apply';

        $fields = (array) ($_POST['fields'] ?? []);
        $fields = array_values(array_intersect(array_map('strval', $fields), array_keys(AssetCopier::COPYABLE_FIELDS)));

        $targets   = array_map('intval', (array) ($_POST['ids'] ?? []));
        $copyMedia = Request::boolean('copy_media');

        if ($targets === []) {
            $this->failValidation(['ids' => 'Choose at least one asset to copy the details to.'], $redirect);
        }

        if ($fields === [] && !$copyMedia) {
            $this->failValidation(['fields' => 'Choose at least one field (or the photos and documents) to copy.'], $redirect);
        }

        $result = AssetCopier::applyTo($source, $targets, $fields, $copyMedia);

        $labels = array_map(static fn (string $f): string => AssetCopier::COPYABLE_FIELDS[$f], $fields);
        if ($copyMedia) {
            $labels[] = 'photos and documents';
        }

        ActivityLog::record(
            'bulk_applied',
            'asset',
            $sourceId,
            sprintf(
                'Copied %s from %s onto %d asset(s)',
                implode(', ', $labels),
                $source['asset_tag'],
                $result['updated']
            ),
            ['fields' => $fields, 'targets' => $targets, 'media_attached' => $result['media']]
        );

        foreach ($targets as $targetId) {
            if ($targetId !== $sourceId) {
                ActivityLog::record('updated', 'asset', $targetId, 'Details copied from ' . $source['asset_tag']);
            }
        }

        $message = sprintf('Updated %d asset%s', $result['updated'], $result['updated'] === 1 ? '' : 's');
        if ($result['media'] > 0) {
            $message .= sprintf(' and attached %d file%s', $result['media'], $result['media'] === 1 ? '' : 's');
        }
        if ($result['skipped'] > 0) {
            $message .= sprintf(' (%d skipped)', $result['skipped']);
        }

        Flash::success($message . '.');
        Response::redirect('/assets/' . $sourceId);
    }
}
