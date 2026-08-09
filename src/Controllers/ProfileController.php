<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\User;

final class ProfileController extends Controller
{
    public function edit(): void
    {
        $this->view('profile/edit', [
            'pageTitle' => 'My account',
            'user'      => Auth::user(),
        ]);
    }

    public function update(): void
    {
        $user = Auth::user();
        $id   = (int) $user['id'];

        $data = $this->validate([
            'name'      => 'required|max:150',
            'email'     => 'required|email|max:190|unique:users,email,' . $id,
            'phone'     => 'max:50',
            'job_title' => 'max:150',
        ], [
            'name'      => 'Full name',
            'email'     => 'Email address',
            'phone'     => 'Phone number',
            'job_title' => 'Job title',
        ], '/profile');

        User::update($id, [
            'name'      => $data['name'],
            'email'     => $data['email'],
            'phone'     => $data['phone'] !== '' ? $data['phone'] : null,
            'job_title' => $data['job_title'] !== '' ? $data['job_title'] : null,
        ]);

        ActivityLog::record('updated', 'user', $id, 'Updated their own profile');
        Flash::success('Your details have been saved.');

        Response::redirect('/profile');
    }

    public function updatePassword(): void
    {
        $user = Auth::user();
        $id   = (int) $user['id'];

        $this->validate([
            'current_password'      => 'required',
            'password'              => 'required|min:12|max:255',
            'password_confirmation' => 'required|matches:password',
        ], [
            'current_password'      => 'Current password',
            'password'              => 'New password',
            'password_confirmation' => 'Confirmation',
        ], '/profile');

        if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $user['password_hash'])) {
            Flash::errors(['current_password' => 'That is not your current password.']);
            Flash::error('Your current password was not correct.');
            Response::redirect('/profile');
        }

        User::updatePassword($id, (string) $_POST['password']);
        ActivityLog::record('password_changed', 'user', $id, 'Changed their own password');
        Flash::success('Your password has been changed.');

        Response::redirect('/profile');
    }
}
