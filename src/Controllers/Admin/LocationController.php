<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Location;

/**
 * Locations: the same shape as categories, shown the same way — a tree with the
 * form on its own page. See CategoryController for why.
 */
final class LocationController extends Controller
{
    public function index(): void
    {
        $this->view('admin/locations/index', [
            'pageTitle' => 'Locations',
            'locations' => Location::all(),
        ]);
    }

    public function create(): void
    {
        $this->view('admin/locations/form', [
            'pageTitle' => 'Add location',
            'location'  => null,
            'parents'   => Location::parentOptions(),
            'parentId'  => max(0, (int) Request::query('parent', 0)),
        ]);
    }

    public function store(): void
    {
        $data = $this->validate([
            'name'        => 'required|max:120',
            'code'        => 'max:40',
            'parent_id'   => 'integer',
            'description' => 'max:255',
        ], ['name' => 'Location name', 'code' => 'Short code'], '/admin/locations/create');

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

    public function edit(string $id): void
    {
        $location = Location::find((int) $id);

        if ($location === null) {
            $this->notFound();
        }

        $this->view('admin/locations/form', [
            'pageTitle' => 'Edit ' . $location['name'],
            'location'  => $location,
            'parents'   => Location::parentOptions((int) $id),
            'parentId'  => 0,
        ]);
    }

    public function update(string $id): void
    {
        $locationId = (int) $id;
        $location   = Location::find($locationId);

        if ($location === null) {
            $this->notFound();
        }

        $redirect = '/admin/locations/' . $locationId . '/edit';

        $data = $this->validate([
            'name'        => 'required|max:120',
            'code'        => 'max:40',
            'parent_id'   => 'integer',
            'description' => 'max:255',
        ], ['name' => 'Location name'], $redirect);

        $parentId = (int) $data['parent_id'];

        if ($parentId === $locationId) {
            $this->failValidation(['parent_id' => 'A location cannot be inside itself.'], $redirect);
        }

        // Checked server-side for the same reason as categories: a cycle here
        // is a tree walk that never ends, not a message somebody reads later.
        if ($parentId > 0 && in_array($parentId, Location::descendantIds($locationId), true)) {
            $this->failValidation(
                ['parent_id' => 'A location cannot be moved inside one of its own sub-locations.'],
                $redirect
            );
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
