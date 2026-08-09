<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Location;

final class LocationController extends Controller
{
    public function index(): void
    {
        $this->view('admin/locations/index', [
            'pageTitle' => 'Locations',
            'locations' => Location::all(),
        ]);
    }

    public function store(): void
    {
        $data = $this->validate([
            'name'        => 'required|max:120',
            'code'        => 'max:40',
            'parent_id'   => 'integer',
            'description' => 'max:255',
        ], ['name' => 'Location name', 'code' => 'Short code'], '/admin/locations');

        $id = Location::create(
            $data['name'],
            $data['code'] !== '' ? $data['code'] : null,
            (int) $data['parent_id'] > 0 ? (int) $data['parent_id'] : null,
            $data['description'] !== '' ? $data['description'] : null
        );

        ActivityLog::record('created', 'location', $id, 'Added location ' . $data['name']);
        Flash::success('Location “' . $data['name'] . '” added.');

        Response::redirect('/admin/locations');
    }

    public function update(string $id): void
    {
        $locationId = (int) $id;
        $location   = Location::find($locationId);

        if ($location === null) {
            $this->notFound();
        }

        $data = $this->validate([
            'name'        => 'required|max:120',
            'code'        => 'max:40',
            'parent_id'   => 'integer',
            'description' => 'max:255',
        ], ['name' => 'Location name'], '/admin/locations');

        $parentId = (int) $data['parent_id'];
        if ($parentId === $locationId) {
            $this->failValidation(['parent_id' => 'A location cannot be inside itself.'], '/admin/locations');
        }

        Location::update($locationId, [
            'name'        => $data['name'],
            'code'        => $data['code'] !== '' ? $data['code'] : null,
            'parent_id'   => $parentId > 0 ? $parentId : null,
            'description' => $data['description'] !== '' ? $data['description'] : null,
            'is_active'   => Request::boolean('is_active') ? 1 : 0,
        ]);

        ActivityLog::record('updated', 'location', $locationId, 'Updated location ' . $data['name']);
        Flash::success('Location updated.');

        Response::redirect('/admin/locations');
    }

    public function destroy(string $id): void
    {
        $locationId = (int) $id;
        $location   = Location::find($locationId);

        if ($location === null) {
            $this->notFound();
        }

        if (Location::inUse($locationId)) {
            Flash::error('“' . $location['name'] . '” is still in use by assets or sub-locations. Move those first, or deactivate it instead.');
            Response::redirect('/admin/locations');
        }

        Location::delete($locationId);
        ActivityLog::record('deleted', 'location', $locationId, 'Deleted location ' . $location['name']);
        Flash::success('Location deleted.');

        Response::redirect('/admin/locations');
    }
}
