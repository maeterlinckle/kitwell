<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;

final class UserController extends Controller
{
    public function index(): void
    {
        $filters = [
            'search'    => (string) Request::query('q', ''),
            'role_id'   => (string) Request::query('role', ''),
            'is_active' => (string) Request::query('active', ''),
        ];

        $this->view('admin/users/index', [
            'pageTitle' => 'Users',
            'users'     => User::all($filters),
            'roles'     => Role::all(),
            'filters'   => $filters,
        ]);
    }

    public function create(): void
    {
        $this->view('admin/users/form', [
            'pageTitle' => 'Add user',
            'user'      => null,
            'roles'     => Role::all(),
        ]);
    }

    public function store(): void
    {
        $data = $this->validate([
            'name'                  => 'required|max:150',
            'email'                 => 'required|email|max:190|unique:users,email',
            'role_id'               => 'required|integer|exists:roles,id',
            'password'              => 'required|min:12|max:255',
            'password_confirmation' => 'required|matches:password',
            'job_title'             => 'max:150',
            'phone'                 => 'max:50',
        ], [
            'name'                  => 'Full name',
            'email'                 => 'Email address',
            'role_id'               => 'Role',
            'password'              => 'Password',
            'password_confirmation' => 'Confirmation',
        ], '/admin/users/create');

        $id = User::create(
            $data['name'],
            $data['email'],
            (string) $data['password'],
            (int) $data['role_id'],
            Request::boolean('is_active'),
            Auth::id()
        );

        User::update($id, [
            'job_title' => $data['job_title'] !== '' ? $data['job_title'] : null,
            'phone'     => $data['phone'] !== '' ? $data['phone'] : null,
        ]);

        $role = Role::find((int) $data['role_id']);
        ActivityLog::record('created', 'user', $id, sprintf('Created user %s (%s)', $data['name'], $role['name'] ?? 'unknown role'));
        Flash::success($data['name'] . ' has been added.');

        Response::redirect('/admin/users');
    }

    public function edit(string $id): void
    {
        $user = User::find((int) $id);

        if ($user === null) {
            Flash::error('That user no longer exists.');
            Response::redirect('/admin/users');
        }

        $this->view('admin/users/form', [
            'pageTitle' => 'Edit ' . $user['name'],
            'user'      => $user,
            'roles'     => Role::all(),
        ]);
    }

    public function update(string $id): void
    {
        $userId = (int) $id;
        $user   = User::find($userId);

        if ($user === null) {
            Flash::error('That user no longer exists.');
            Response::redirect('/admin/users');
        }

        $data = $this->validate([
            'name'      => 'required|max:150',
            'email'     => 'required|email|max:190|unique:users,email,' . $userId,
            'role_id'   => 'required|integer|exists:roles,id',
            'job_title' => 'max:150',
            'phone'     => 'max:50',
        ], [
            'name'    => 'Full name',
            'email'   => 'Email address',
            'role_id' => 'Role',
        ], '/admin/users/' . $userId . '/edit');

        $isActive = Request::boolean('is_active');

        // Guard rails: never let an administrator lock everyone out.
        if ($this->wouldRemoveLastAdmin($user, (int) $data['role_id'], $isActive)) {
            Flash::error('This is the last active administrator — change another user to Administrator first.');
            Response::redirect('/admin/users/' . $userId . '/edit');
        }

        if ($userId === Auth::id() && !$isActive) {
            Flash::error('You cannot deactivate your own account.');
            Response::redirect('/admin/users/' . $userId . '/edit');
        }

        $changes = [
            'name'      => $data['name'],
            'email'     => $data['email'],
            'role_id'   => (int) $data['role_id'],
            'is_active' => $isActive ? 1 : 0,
            'job_title' => $data['job_title'] !== '' ? $data['job_title'] : null,
            'phone'     => $data['phone'] !== '' ? $data['phone'] : null,
        ];

        User::update($userId, $changes);

        ActivityLog::record(
            'updated',
            'user',
            $userId,
            'Updated user ' . $data['name'],
            ActivityLog::diff($user, $changes)
        );

        Flash::success($data['name'] . ' has been updated.');
        Response::redirect('/admin/users');
    }

    public function resetPassword(string $id): void
    {
        $userId = (int) $id;
        $user   = User::find($userId);

        if ($user === null) {
            Flash::error('That user no longer exists.');
            Response::redirect('/admin/users');
        }

        $this->validate([
            'password'              => 'required|min:12|max:255',
            'password_confirmation' => 'required|matches:password',
        ], [
            'password'              => 'New password',
            'password_confirmation' => 'Confirmation',
        ], '/admin/users/' . $userId . '/edit');

        User::updatePassword($userId, (string) $_POST['password']);
        ActivityLog::record('password_reset', 'user', $userId, 'Reset the password for ' . $user['name']);

        Flash::success('Password reset for ' . $user['name'] . '. Ask them to change it after signing in.');
        Response::redirect('/admin/users/' . $userId . '/edit');
    }

    public function toggleActive(string $id): void
    {
        $userId = (int) $id;
        $user   = User::find($userId);

        if ($user === null) {
            Flash::error('That user no longer exists.');
            Response::redirect('/admin/users');
        }

        if ($userId === Auth::id()) {
            Flash::error('You cannot deactivate your own account.');
            Response::redirect('/admin/users');
        }

        $activate = (int) $user['is_active'] !== 1;

        if (!$activate && $this->wouldRemoveLastAdmin($user, (int) $user['role_id'], false)) {
            Flash::error('This is the last active administrator, so they cannot be deactivated.');
            Response::redirect('/admin/users');
        }

        User::update($userId, ['is_active' => $activate ? 1 : 0]);
        ActivityLog::record(
            $activate ? 'activated' : 'deactivated',
            'user',
            $userId,
            ($activate ? 'Reactivated ' : 'Deactivated ') . $user['name']
        );

        Flash::success($user['name'] . ' has been ' . ($activate ? 'reactivated' : 'deactivated') . '.');
        Response::redirect('/admin/users');
    }

    /** @param array<string,mixed> $user */
    private function wouldRemoveLastAdmin(array $user, int $newRoleId, bool $newIsActive): bool
    {
        $wasSuperuser = (int) ($user['role_is_superuser'] ?? 0) === 1;
        $wasActive    = (int) $user['is_active'] === 1;

        if (!$wasSuperuser || !$wasActive) {
            return false;
        }

        $newRole         = Role::find($newRoleId);
        $staysSuperuser  = $newRole !== null && (int) $newRole['is_superuser'] === 1;

        if ($staysSuperuser && $newIsActive) {
            return false;
        }

        return User::countActiveAdmins() <= 1;
    }
}
