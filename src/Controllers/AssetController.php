<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetManual;
use App\Models\AssetPhoto;
use App\Models\Category;
use App\Models\Hire;
use App\Models\Location;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceSchedule;
use App\Models\PatRecord;
use App\Services\AssetTagger;

final class AssetController extends Controller
{
    public function index(): void
    {
        $filters = self::filtersFromRequest();
        $page    = max(1, (int) Request::query('page', 1));
        $result  = Asset::search($filters, $page, 25);

        // One query for the whole page's thumbnails rather than one per row.
        $photos = AssetPhoto::primaryForMany(
            array_map(static fn (array $row): int => (int) $row['id'], $result['rows'])
        );

        $this->view('assets/index', [
            'pageTitle'  => 'Assets',
            'result'     => $result,
            'filters'    => $filters,
            'categories' => Category::all(true),
            'locations'  => Location::forSelect(),
            'photos'     => $photos,
            'queryString'=> self::queryString($filters),
        ]);
    }

    public function show(string $id): void
    {
        $asset = Asset::find((int) $id);

        if ($asset === null) {
            $this->notFound();
        }

        $assetId = (int) $asset['id'];

        $this->view('assets/show', [
            'pageTitle'  => $asset['asset_tag'] . ' · ' . $asset['name'],
            'asset'      => $asset,
            'children'   => Asset::children($assetId),
            'manuals'    => AssetManual::forAsset($assetId),
            // The 12 most recent photos inline; the rest on the history page.
            'photos'     => AssetPhoto::forAsset($assetId, 12),
            'photoCount' => AssetPhoto::countForAsset($assetId),
            'schedules'  => Auth::can('maintenance.view') ? MaintenanceSchedule::forAsset($assetId) : [],
            'maintenanceLogs' => Auth::can('maintenance.view') ? MaintenanceLog::forAsset($assetId, 5) : [],
            'patStatus'  => Auth::can('pat.view') ? PatRecord::statusForAsset($assetId) : null,
            'patRecords' => Auth::can('pat.view') ? PatRecord::forAsset($assetId, 3) : [],
            'openHire'   => Hire::openForAsset($assetId),
            'hireBlocked'=> Hire::blockedReason($asset),
            'hireHistory'=> Auth::can('hires.view') ? Hire::forAsset($assetId, 10) : [],
            'history'    => Auth::can('audit.view') ? ActivityLog::recent(15, ['entity_type' => 'asset', 'entity_id' => $assetId]) : [],
        ]);
    }

    public function create(): void
    {
        $parentId = (int) Request::query('parent', 0);
        $parent   = $parentId > 0 ? Asset::find($parentId) : null;

        $this->view('assets/form', [
            'pageTitle'   => $parent !== null ? 'Add item to ' . $parent['asset_tag'] : 'Add asset',
            'asset'       => null,
            'parent'      => $parent,
            'suggestedTag'=> AssetTagger::next(),
            'categories'  => Category::all(true),
            'locations'   => Location::forSelect(),
            'parents'     => Asset::parentOptions(),
        ]);
    }

    public function store(): void
    {
        $data = $this->validateAsset();

        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        $id = Asset::create($data);

        ActivityLog::record('created', 'asset', $id, sprintf('Registered %s — %s', $data['asset_tag'], $data['name']));
        Flash::success($data['asset_tag'] . ' has been registered.');

        // "Save and add another" keeps the workflow going when tagging a batch.
        if (Request::post('save_and_new') !== null) {
            Response::redirect('/assets/create' . ($data['parent_asset_id'] !== null ? '?parent=' . (int) $data['parent_asset_id'] : ''));
        }

        Response::redirect('/assets/' . $id);
    }

    public function edit(string $id): void
    {
        $asset = Asset::find((int) $id);

        if ($asset === null) {
            $this->notFound();
        }

        $this->view('assets/form', [
            'pageTitle'  => 'Edit ' . $asset['asset_tag'],
            'asset'      => $asset,
            'parent'     => $asset['parent_asset_id'] !== null ? Asset::find((int) $asset['parent_asset_id']) : null,
            'suggestedTag' => null,
            'categories' => Category::all(true),
            'locations'  => Location::forSelect(),
            'parents'    => Asset::parentOptions((int) $asset['id']),
        ]);
    }

    public function update(string $id): void
    {
        $assetId = (int) $id;
        $asset   = Asset::find($assetId);

        if ($asset === null) {
            $this->notFound();
        }

        $data = $this->validateAsset($asset);
        $data['updated_by'] = Auth::id();

        Asset::update($assetId, $data);

        ActivityLog::record(
            'updated',
            'asset',
            $assetId,
            sprintf('Updated %s — %s', $data['asset_tag'], $data['name']),
            ActivityLog::diff($asset, $data)
        );

        Flash::success($data['asset_tag'] . ' has been saved.');
        Response::redirect('/assets/' . $assetId);
    }

    public function archive(string $id): void
    {
        $assetId = (int) $id;
        $asset   = Asset::find($assetId);

        if ($asset === null) {
            $this->notFound();
        }

        if ($asset['status'] === 'On Hire') {
            Flash::error('This asset is out on hire. Check it back in before archiving it.');
            Response::redirect('/assets/' . $assetId);
        }

        $children = Asset::childCount($assetId);
        Asset::archive($assetId);

        ActivityLog::record('archived', 'asset', $assetId, sprintf('Archived %s — %s', $asset['asset_tag'], $asset['name']));

        Flash::success(sprintf(
            '%s has been archived%s. Its history is kept and it can be restored at any time.',
            $asset['asset_tag'],
            $children > 0 ? ", along with {$children} attached item(s)" : ''
        ));

        Response::redirect('/assets/' . $assetId);
    }

    public function restore(string $id): void
    {
        $assetId = (int) $id;
        $asset   = Asset::find($assetId);

        if ($asset === null) {
            $this->notFound();
        }

        Asset::restore($assetId);
        ActivityLog::record('restored', 'asset', $assetId, sprintf('Restored %s from the archive', $asset['asset_tag']));

        Flash::success($asset['asset_tag'] . ' is back in stock.');
        Response::redirect('/assets/' . $assetId);
    }

    /**
     * Permanent deletion, deliberately narrow: only for records with no
     * history worth keeping. Anything else must be archived instead.
     */
    public function destroy(string $id): void
    {
        $assetId = (int) $id;
        $asset   = Asset::find($assetId);

        if ($asset === null) {
            $this->notFound();
        }

        $counts = Asset::historyCounts($assetId);
        $blocked = array_filter($counts);

        if ($blocked !== []) {
            $parts = [];
            foreach ($blocked as $type => $count) {
                $parts[] = $count . ' ' . $type;
            }

            Flash::error('This asset cannot be deleted because it has ' . implode(', ', $parts) . '. Archive it instead — that keeps the history.');
            Response::redirect('/assets/' . $assetId);
        }

        // Remove the files too — the database rows go with the asset via the
        // foreign key, but the uploads would otherwise be orphaned on disk.
        foreach (AssetManual::forAsset($assetId) as $manual) {
            \App\Core\Upload::delete((string) $manual['file_path']);
        }

        foreach (AssetPhoto::forAsset($assetId) as $photo) {
            \App\Core\Upload::delete((string) $photo['file_path']);

            if (!empty($photo['thumbnail_path'])) {
                \App\Core\Upload::delete((string) $photo['thumbnail_path']);
            }
        }

        Asset::delete($assetId);
        ActivityLog::record('deleted', 'asset', $assetId, sprintf('Deleted %s — %s', $asset['asset_tag'], $asset['name']));

        Flash::success($asset['asset_tag'] . ' has been deleted.');
        Response::redirect('/assets');
    }

    /**
     * Validate and normalise the asset form.
     *
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function validateAsset(?array $existing = null): array
    {
        $id       = $existing === null ? 0 : (int) $existing['id'];
        $redirect = $existing === null ? '/assets/create' : '/assets/' . $id . '/edit';

        $data = $this->validate([
            'asset_tag'             => 'required|max:64',
            'barcode'               => 'max:64',
            'name'                  => 'required|max:191',
            'description'           => 'max:5000',
            'category_id'           => 'integer',
            'location_id'           => 'integer',
            'condition_rating'      => 'required|in:' . implode(',', Asset::CONDITIONS),
            'status'                => 'required|in:' . implode(',', Asset::STATUSES),
            'purchase_date'         => 'date',
            'purchase_cost'         => 'numeric|min_value:0|max_value:99999999',
            'current_value'         => 'numeric|min_value:0|max_value:99999999',
            'supplier'              => 'max:191',
            'warranty_expires_on'   => 'date',
            'serial_number'         => 'max:191',
            'manufacturer'          => 'max:191',
            'model'                 => 'max:191',
            'manufacturer_url'      => 'url|max:500',
            'plug_fuse_rating_amps' => 'numeric|min_value:0|max_value:999',
            'cable_csa_mm2'         => 'numeric|min_value:0|max_value:999',
            'appliance_class'       => 'in:' . implode(',', Asset::APPLIANCE_CLASSES),
            'load_rating_va'        => 'numeric|min_value:0|max_value:9999999',
            'pat_interval_months'   => 'integer|min_value:1|max_value:120',
            'parent_asset_id'       => 'integer',
            'relationship_type'     => 'in:' . implode(',', Asset::RELATIONSHIPS),
            'notes'                 => 'max:5000',
        ], [
            'asset_tag'             => 'Asset tag',
            'name'                  => 'Name',
            'condition_rating'      => 'Condition',
            'manufacturer_url'      => 'Manufacturer website',
            'plug_fuse_rating_amps' => 'Plug fuse rating',
            'cable_csa_mm2'         => 'Cable CSA',
            'appliance_class'       => 'Appliance class',
            'load_rating_va'        => 'Load rating (VA)',
            'pat_interval_months'   => 'PAT interval',
        ], $redirect);

        $tag = (string) $data['asset_tag'];

        // Uniqueness is checked here rather than with the generic rule so the
        // message can point at the clash directly.
        if (Asset::tagExists($tag, $id)) {
            $this->failValidation(['asset_tag' => 'Asset tag ' . $tag . ' is already in use.'], $redirect);
        }

        $barcode = trim((string) $data['barcode']);
        if ($barcode !== '' && Asset::barcodeExists($barcode, $id)) {
            $this->failValidation(['barcode' => 'That barcode is already assigned to another asset.'], $redirect);
        }

        $parentId = (int) $data['parent_asset_id'];
        if ($parentId > 0) {
            if ($parentId === $id) {
                $this->failValidation(['parent_asset_id' => 'An asset cannot be attached to itself.'], $redirect);
            }

            if (Asset::find($parentId) === null) {
                $this->failValidation(['parent_asset_id' => 'That parent asset no longer exists.'], $redirect);
            }

            // One level of nesting: attaching a parent to its own child would
            // make the tree impossible to display sensibly.
            if ($id > 0 && Asset::childCount($id) > 0) {
                $this->failValidation(['parent_asset_id' => 'This asset already has items attached to it, so it cannot become a sub-asset itself.'], $redirect);
            }
        }

        $requiresPat = Request::boolean('requires_pat');
        $hasFuse     = Request::boolean('has_fuse');

        // The fuse rating is a four-way choice, not free numeric entry. An
        // existing non-standard value is allowed through so that editing an
        // unrelated field on an old record does not silently destroy it; the
        // form shows it flagged for correction.
        $fuseRating = self::nullIfBlank($data['plug_fuse_rating_amps']);
        if (!$hasFuse) {
            $fuseRating = null;
        } elseif ($fuseRating !== null
            && !in_array((string) (float) $fuseRating, Asset::FUSE_RATINGS, true)
            && (float) ($existing['plug_fuse_rating_amps'] ?? -1) !== (float) $fuseRating) {
            $this->failValidation(
                ['plug_fuse_rating_amps' => 'Choose one of the standard plug fuse ratings: '
                    . implode(' A, ', Asset::FUSE_RATINGS) . ' A.'],
                $redirect
            );
        }

        return [
            'asset_tag'             => $tag,
            'barcode'               => $barcode !== '' ? $barcode : null,
            'name'                  => $data['name'],
            'description'           => self::nullIfBlank($data['description']),
            'category_id'           => (int) $data['category_id'] > 0 ? (int) $data['category_id'] : null,
            'location_id'           => (int) $data['location_id'] > 0 ? (int) $data['location_id'] : null,
            'condition_rating'      => $data['condition_rating'],
            'status'                => $data['status'],
            'purchase_date'         => self::nullIfBlank($data['purchase_date']),
            'purchase_cost'         => self::nullIfBlank($data['purchase_cost']),
            'current_value'         => self::nullIfBlank($data['current_value']),
            'supplier'              => self::nullIfBlank($data['supplier']),
            'warranty_expires_on'   => self::nullIfBlank($data['warranty_expires_on']),
            'serial_number'         => self::nullIfBlank($data['serial_number']),
            'manufacturer'          => self::nullIfBlank($data['manufacturer']),
            'model'                 => self::nullIfBlank($data['model']),
            'manufacturer_url'      => self::nullIfBlank($data['manufacturer_url']),
            'has_fuse'              => $hasFuse ? 1 : 0,
            'plug_fuse_rating_amps' => $fuseRating,
            'cable_csa_mm2'         => self::nullIfBlank($data['cable_csa_mm2']),
            'appliance_class'       => $data['appliance_class'] !== '' ? $data['appliance_class'] : null,
            'load_rating_va'        => self::nullIfBlank($data['load_rating_va']),
            'requires_pat'          => $requiresPat ? 1 : 0,
            'pat_interval_months'   => $requiresPat ? self::nullIfBlank($data['pat_interval_months']) : null,
            'parent_asset_id'       => $parentId > 0 ? $parentId : null,
            'relationship_type'     => $parentId > 0 ? ($data['relationship_type'] !== '' ? $data['relationship_type'] : 'sub-asset') : null,
            'is_hireable'           => Request::boolean('is_hireable') ? 1 : 0,
            'notes'                 => self::nullIfBlank($data['notes']),
            'retired_on'            => $data['status'] === 'Retired' ? ($existing['retired_on'] ?? date('Y-m-d')) : null,
        ];
    }

    /** @return array<string,mixed> */
    public static function filtersFromRequest(): array
    {
        return [
            'q'                => (string) Request::query('q', ''),
            'category_id'      => (string) Request::query('category', ''),
            'location_id'      => (string) Request::query('location', ''),
            'status'           => array_values(array_filter((array) (Request::query('status', []) ?? []), 'is_string')),
            'condition'        => array_values(array_filter((array) (Request::query('condition', []) ?? []), 'is_string')),
            'requires_pat'     => (string) Request::query('pat', ''),
            'type'             => (string) Request::query('type', ''),
            'include_archived' => Request::query('archived') === '1',
            'sort'             => (string) Request::query('sort', 'tag'),
            // Not a filter as such: carried through so the "Export CSV" link
            // keeps whatever extra columns were ticked.
            'extras'           => array_values(array_filter((array) (Request::query('extras', []) ?? []), 'is_string')),
        ];
    }

    /** Rebuild the current filters as a query string, for links and pagination. */
    public static function queryString(array $filters, array $extra = []): string
    {
        $params = array_filter([
            'q'        => $filters['q'] ?? '',
            'category' => $filters['category_id'] ?? '',
            'location' => $filters['location_id'] ?? '',
            'pat'      => $filters['requires_pat'] ?? '',
            'type'     => $filters['type'] ?? '',
            'archived' => !empty($filters['include_archived']) ? '1' : '',
            'sort'     => ($filters['sort'] ?? 'tag') !== 'tag' ? $filters['sort'] : '',
        ], static fn ($v): bool => $v !== '' && $v !== null);

        $query = http_build_query($params + $extra);

        foreach ((array) ($filters['status'] ?? []) as $status) {
            $query .= '&status%5B%5D=' . rawurlencode((string) $status);
        }

        foreach ((array) ($filters['condition'] ?? []) as $condition) {
            $query .= '&condition%5B%5D=' . rawurlencode((string) $condition);
        }

        foreach ((array) ($filters['extras'] ?? []) as $extra) {
            $query .= '&extras%5B%5D=' . rawurlencode((string) $extra);
        }

        return trim($query, '&');
    }

    private static function nullIfBlank(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $value = is_string($value) ? trim($value) : $value;

        return $value === '' ? null : $value;
    }
}
