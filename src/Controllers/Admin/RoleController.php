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
        $this->view('admin/roles/index', [
            'pageTitle'   => 'Roles & permissions',
            'roles'       => Role::all(),
            'permissions' => Permission::grouped(),
        ]);
    }

    public function edit(string $id): void
    {
        $role = Role::find((int) $id);

        if ($role === null) {
            Flash::error('That role no longer exists.');
            Response::redirect('/admin/roles');
        }

        $this->view('admin/roles/edit', [
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

        ActivityLog::record('permissions_changed', 'role', $roleId, 'Changed permissions for ' . $role['name'], [
            'from' => $before,
            'to'   => $selected,
        ]);

        Flash::success('Permissions updated for ' . $role['name'] . '.');
        Response::redirect('/admin/roles');
    }
}
