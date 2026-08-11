<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Flash;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;

final class RoleController extends Controller
{
    public function index(): void
    {
        $grouped = Permission::grouped();

        $this->view('admin/roles/index', [
            'pageTitle'   => 'Roles & permissions',
            'roles'       => Role::all(),
            'permissions' => $grouped,
            // "12 / 32" says more about a role than "12" does.
            'permissionTotal' => array_sum(array_map('count', $grouped)),
        ]);
    }

    public function create(): void
    {
        $this->view('admin/roles/form', [
            'pageTitle'   => 'Add role',
            'role'        => null,
            'permissions' => Permission::grouped(),
            'assigned'    => [],
        ]);
    }

    public function store(): void
    {
        $data = $this->validate([
            'name'        => 'required|max:100',
            'description' => 'max:255',
        ], [
            'name'        => 'Role name',
            'description' => 'Description',
        ], '/admin/roles/create');

        $name = trim((string) $data['name']);

        // Two roles called the same thing would be indistinguishable in every
        // user-facing list, which is a support problem rather than a data one.
        foreach (Role::all() as $existing) {
            if (strcasecmp((string) $existing['name'], $name) === 0) {
                $this->failValidation(['name' => 'A role called “' . $name . '” already exists.'], '/admin/roles/create');
            }
        }

        $selected = $_POST['permissions'] ?? [];
        $selected = is_array($selected) ? array_map('intval', $selected) : [];

        $roleId = Role::create($name, trim((string) $data['description']));
        Role::syncPermissions($roleId, $selected);

        ActivityLog::record('created', 'role', $roleId, 'Created the role ' . $name, [
            'permissions' => $selected,
        ]);

        Flash::success('The role “' . $name . '” was created.');
        Response::redirect('/admin/roles');
    }

    public function edit(string $id): void
    {
        $role = Role::find((int) $id);

        if ($role === null) {
            Flash::error('That role no longer exists.');
            Response::redirect('/admin/roles');
        }

        $this->view('admin/roles/form', [
            'pageTitle'   => 'Edit ' . $role['name'],
            'role'        => $role,
            'permissions' => Permission::grouped(),
            'assigned'    => Role::permissionIds((int) $role['id']),
        ]);
    }

    public function update(string $id): void
    {
        $roleId = (int) $id;
        $role   = Role::find($roleId);

        if ($role === null) {
            Flash::error('That role no longer exists.');
            Response::redirect('/admin/roles');
        }

        if ((int) $role['is_superuser'] === 1) {
            Flash::warning('The Administrator role always holds every permission, so it cannot be edited.');
            Response::redirect('/admin/roles');
        }

        $selected = $_POST['permissions'] ?? [];
        $selected = is_array($selected) ? array_map('intval', $selected) : [];

        $before = Role::permissionIds($roleId);
        Role::syncPermissions($roleId, $selected);

        // A role created here can be renamed here; the ones that ship with the
        // application keep their names, because the documentation refers to them.
        if ((int) $role['is_system'] !== 1) {
            $data = $this->validate([
                'name'        => 'required|max:100',
                'description' => 'max:255',
            ], [
                'name'        => 'Role name',
                'description' => 'Description',
            ], '/admin/roles/' . $roleId . '/edit');

            Role::update($roleId, trim((string) $data['name']), trim((string) $data['description']));
        }

        ActivityLog::record('permissions_changed', 'role', $roleId, 'Changed permissions for ' . $role['name'], [
            'from' => $before,
            'to'   => $selected,
        ]);

        Flash::success('Permissions updated for ' . $role['name'] . '.');
        Response::redirect('/admin/roles');
    }
}
