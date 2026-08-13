<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetTemplate;
use App\Models\Category;
use App\Models\Location;
use App\Models\MediaLibrary;
use App\Services\MediaIntake;

/**
 * Asset templates: the starting points offered on the Add asset form.
 *
 * Reference data an operation maintains for itself, like categories and
 * locations, so it sits under Settings behind `templates.manage` — held by
 * Administrator and Manager / Staff.
 */
final class AssetTemplateController extends Controller
{
    public function index(): void
    {
        $this->view('admin/templates/index', [
            'pageTitle' => 'Asset templates',
            'templates' => AssetTemplate::all(),
        ]);
    }

    public function create(): void
    {
        $this->form(null);
    }

    public function edit(string $id): void
    {
        $assetTemplate = AssetTemplate::find((int) $id);

        if ($assetTemplate === null) {
            Flash::error('That template no longer exists.');
            Response::redirect('/admin/templates');
        }

        $this->form($assetTemplate);
    }

    public function store(): void
    {
        $data = $this->validated();

        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        $id = AssetTemplate::create($data);

        ActivityLog::record('created', 'asset_template', $id, 'Added the asset template ' . $data['name']);
        Flash::success('“' . $data['name'] . '” has been created. Attach its photos and documents below.');

        Response::redirect('/admin/templates/' . $id . '/edit');
    }

    public function update(string $id): void
    {
        $templateId = (int) $id;
        $assetTemplate   = AssetTemplate::find($templateId);

        if ($assetTemplate === null) {
            Flash::error('That template no longer exists.');
            Response::redirect('/admin/templates');
        }

        $data = $this->validated($templateId);
        $data['updated_by'] = Auth::id();

        AssetTemplate::update($templateId, $data);

        ActivityLog::record(
            'updated',
            'asset_template',
            $templateId,
            'Updated the asset template ' . $data['name'],
            ActivityLog::diff($assetTemplate, $data)
        );

        Flash::success('“' . $data['name'] . '” has been saved.');
        Response::redirect('/admin/templates/' . $templateId . '/edit');
    }

    public function destroy(string $id): void
    {
        $templateId = (int) $id;
        $assetTemplate   = AssetTemplate::find($templateId);

        if ($assetTemplate === null) {
            Flash::error('That template no longer exists.');
            Response::redirect('/admin/templates');
        }

        // Only the template and its attachments go. The library items it
        // pointed at are shared, and assets created from it are ordinary
        // assets that stopped depending on it the moment they were created.
        AssetTemplate::delete($templateId);

        ActivityLog::record('deleted', 'asset_template', $templateId, 'Deleted the asset template ' . $assetTemplate['name']);
        Flash::success('“' . $assetTemplate['name'] . '” has been deleted. Assets already created from it are unaffected.');

        Response::redirect('/admin/templates');
    }

    /** Attach existing library items to a template. */
    public function attach(string $id): void
    {
        $templateId = (int) $id;
        $assetTemplate   = AssetTemplate::find($templateId);

        if ($assetTemplate === null) {
            Flash::error('That template no longer exists.');
            Response::redirect('/admin/templates');
        }

        $attached = 0;
        foreach (array_map('intval', (array) Request::post('media_ids', [])) as $mediaId) {
            if ($mediaId > 0 && MediaLibrary::attachToTemplate($templateId, $mediaId)) {
                $attached++;
            }
        }

        Flash::success($attached > 0
            ? sprintf('%d file%s attached to the template.', $attached, $attached === 1 ? '' : 's')
            : 'Nothing new to attach — those files are already on this template.');

        Response::redirect('/admin/templates/' . $templateId . '/edit#media');
    }

    /** Upload a new file into the library and attach it to this template. */
    public function upload(string $id): void
    {
        $templateId = (int) $id;
        $assetTemplate   = AssetTemplate::find($templateId);

        if ($assetTemplate === null) {
            Flash::error('That template no longer exists.');
            Response::redirect('/admin/templates');
        }

        $type  = Request::post('media_type') === 'photo' ? 'photo' : 'document';
        $files = Upload::files('files');

        if (!Auth::can($type === 'photo' ? 'media.photo.upload' : 'media.manual.upload')) {
            Flash::error('You do not have permission to add that kind of file.');
            Response::redirect('/admin/templates/' . $templateId . '/edit#media');
        }

        if ($files === []) {
            Flash::error('No file was selected.');
            Response::redirect('/admin/templates/' . $templateId . '/edit#media');
        }

        $title  = trim((string) Request::post('title', ''));
        $added  = 0;
        $reused = 0;

        foreach ($files as $index => $file) {
            $result = MediaIntake::store(
                $file,
                $type,
                count($files) === 1 ? $title : ($title !== '' ? $title . ' (' . ($index + 1) . ')' : ''),
                trim((string) Request::post('description', ''))
            );

            if (is_string($result)) {
                Flash::error($result);
                continue;
            }

            $result['created'] ? $added++ : $reused++;
            MediaLibrary::attachToTemplate($templateId, (int) $result['media']['id']);
        }

        if ($added > 0) {
            Flash::success(sprintf('%d file%s added and attached.', $added, $added === 1 ? '' : 's'));
        }

        if ($reused > 0) {
            Flash::success(sprintf(
                '%d file%s already in the library — attached rather than stored again.',
                $reused,
                $reused === 1 ? ' was' : 's were'
            ));
        }

        Response::redirect('/admin/templates/' . $templateId . '/edit#media');
    }

    public function detach(string $id, string $mediaId): void
    {
        $templateId = (int) $id;

        if (AssetTemplate::find($templateId) === null) {
            Flash::error('That template no longer exists.');
            Response::redirect('/admin/templates');
        }

        MediaLibrary::detachFromTemplate($templateId, (int) $mediaId);
        Flash::success('Removed from the template. The file stays in the library.');

        Response::redirect('/admin/templates/' . $templateId . '/edit#media');
    }

    /** @param array<string,mixed>|null $assetTemplate */
    private function form(?array $assetTemplate): void
    {
        $id = $assetTemplate === null ? 0 : (int) $assetTemplate['id'];

        $this->view('admin/templates/form', [
            'pageTitle'  => $assetTemplate === null ? 'Add asset template' : 'Edit ' . $assetTemplate['name'],
            'assetTemplate'   => $assetTemplate,
            'categories' => Category::all(true),
            'locations'  => Location::forSelect(),
            'media'      => $id > 0 ? MediaLibrary::forTemplate($id) : [],
            // What the picker shows before anybody searches.
            'libraryDocuments' => MediaLibrary::search('document', '', 1, 12)['rows'],
            'libraryPhotos'    => MediaLibrary::search('photo', '', 1, 12)['rows'],
        ]);
    }

    /** @return array<string,mixed> */
    private function validated(int $id = 0): array
    {
        $redirect = $id > 0 ? '/admin/templates/' . $id . '/edit' : '/admin/templates/create';

        $data = $this->validate([
            'name'                  => 'required|max:120',
            'description'           => 'max:500',
            'asset_name'            => 'max:191',
            'asset_description'     => 'max:5000',
            'category_id'           => 'integer',
            'location_id'           => 'integer',
            'manufacturer'          => 'max:191',
            'model'                 => 'max:191',
            'manufacturer_url'      => 'url|max:500',
            'supplier'              => 'max:191',
            'condition_rating'      => 'in:' . implode(',', Asset::CONDITIONS),
            'appliance_class'       => 'in:' . implode(',', Asset::APPLIANCE_CLASSES),
            'load_rating_va'        => 'numeric|min_value:0|max_value:9999999',
            'plug_fuse_rating_amps' => 'numeric|min_value:0|max_value:999',
            'cable_csa_mm2'         => 'numeric|min_value:0|max_value:999',
            'pat_interval_months'   => 'integer|min_value:1|max_value:120',
            'notes'                 => 'max:5000',
        ], [
            'name'                  => 'Template name',
            'asset_name'            => 'Asset name',
            'manufacturer_url'      => 'Manufacturer website',
            'condition_rating'      => 'Condition',
            'appliance_class'       => 'Appliance class',
            'load_rating_va'        => 'Load rating (VA)',
            'plug_fuse_rating_amps' => 'Plug fuse rating',
            'cable_csa_mm2'         => 'Cable CSA',
            'pat_interval_months'   => 'PAT interval',
        ], $redirect);

        $name = trim((string) $data['name']);

        if (AssetTemplate::nameExists($name, $id)) {
            $this->failValidation(['name' => 'A template called “' . $name . '” already exists.'], $redirect);
        }

        // The three flags are tri-state: "leave it alone", "yes" or "no". A
        // template that says nothing about PAT must not quietly switch it off
        // on every asset started from it.
        $flag = static function (string $field): ?int {
            $value = Request::post($field);

            return ($value === null || $value === '' || $value === 'inherit') ? null : ((string) $value === '1' ? 1 : 0);
        };

        $fuseRating = self::nullIfBlank($data['plug_fuse_rating_amps']);

        if ($fuseRating !== null && !in_array((string) (float) $fuseRating, Asset::FUSE_RATINGS, true)) {
            $this->failValidation(
                ['plug_fuse_rating_amps' => 'Choose one of the standard plug fuse ratings: '
                    . implode(' A, ', Asset::FUSE_RATINGS) . ' A.'],
                $redirect
            );
        }

        return [
            'name'                  => $name,
            'description'           => self::nullIfBlank($data['description']),
            'asset_name'            => self::nullIfBlank($data['asset_name']),
            'asset_description'     => self::nullIfBlank($data['asset_description']),
            'category_id'           => (int) $data['category_id'] > 0 ? (int) $data['category_id'] : null,
            'location_id'           => (int) $data['location_id'] > 0 ? (int) $data['location_id'] : null,
            'manufacturer'          => self::nullIfBlank($data['manufacturer']),
            'model'                 => self::nullIfBlank($data['model']),
            'manufacturer_url'      => self::nullIfBlank($data['manufacturer_url']),
            'supplier'              => self::nullIfBlank($data['supplier']),
            'condition_rating'      => $data['condition_rating'] !== '' ? $data['condition_rating'] : null,
            'appliance_class'       => $data['appliance_class'] !== '' ? $data['appliance_class'] : null,
            'load_rating_va'        => self::nullIfBlank($data['load_rating_va']),
            'has_fuse'              => $flag('has_fuse'),
            'plug_fuse_rating_amps' => $flag('has_fuse') === 0 ? null : $fuseRating,
            'cable_csa_mm2'         => self::nullIfBlank($data['cable_csa_mm2']),
            'requires_pat'          => $flag('requires_pat'),
            'pat_interval_months'   => self::nullIfBlank($data['pat_interval_months']),
            'is_hireable'           => $flag('is_hireable'),
            'notes'                 => self::nullIfBlank($data['notes']),
            'is_active'             => Request::boolean('is_active') ? 1 : 0,
        ];
    }

    private static function nullIfBlank(mixed $value): mixed
    {
        return ($value === null || $value === '') ? null : $value;
    }
}
