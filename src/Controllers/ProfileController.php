<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Response;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\PasswordPolicy;

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
            'password'              => 'required|max:255|' . PasswordPolicy::rule($user),
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

    /**
     * The wall an expired password puts up.
     *
     * `AuthMiddleware` sends every other request here until a new password is
     * set, so this pair of actions is the only way out besides signing out.
     * They live on the signed-out layout deliberately: a navigation bar on a
     * page whose whole purpose is that there is no way past it invites people
     * to look for one.
     */
    public function expired(): void
    {
        $user = Auth::user();

        // Reached directly by somebody whose password is perfectly current.
        // Nothing to do here, and pretending otherwise would be alarming.
        if ($user === null || !PasswordPolicy::hasExpired($user)) {
            Response::redirect('/profile');
        }

        $this->view('auth/password-expired', [
            'pageTitle' => 'Your password has expired',
            'user'      => $user,
            'days'      => PasswordPolicy::forUser($user)['expiry_days'],
        ], 'layouts/auth');
    }

    public function updateExpired(): void
    {
        $user = Auth::user();
        $id   = (int) $user['id'];

        $this->validate([
            'current_password'      => 'required',
            'password'              => 'required|max:255|' . PasswordPolicy::rule($user),
            'password_confirmation' => 'required|matches:password',
        ], [
            'current_password'      => 'Current password',
            'password'              => 'New password',
            'password_confirmation' => 'Confirmation',
        ], '/password/expired');

        if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $user['password_hash'])) {
            Flash::errors(['current_password' => 'That is not your current password.']);
            Flash::error('Your current password was not correct.');
            Response::redirect('/password/expired');
        }

        // The new password must actually be a new one. Re-entering the expired
        // one would satisfy every rule here and reset the clock on it, which
        // would make the whole policy ceremonial.
        if (password_verify((string) $_POST['password'], (string) $user['password_hash'])) {
            Flash::errors(['password' => 'Choose a password you have not been using.']);
            Flash::error('That is the password that has just expired.');
            Response::redirect('/password/expired');
        }

        User::updatePassword($id, (string) $_POST['password']);
        ActivityLog::record('password_changed', 'user', $id, 'Changed an expired password');
        Flash::success('Your password has been changed.');

        Response::redirect(Session::pull('__intended_url') ?? '/');
    }
}
