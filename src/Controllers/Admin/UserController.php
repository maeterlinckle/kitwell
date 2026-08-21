<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Mail\AccountMail;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Models\UserToken;
use App\Models\PasswordPolicy;

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
            'pageTitle'    => 'Users',
            'users'        => User::all($filters),
            'roles'        => Role::all(),
            'filters'      => $filters,
            'inviteStates' => UserToken::inviteStates(),
            'canInvite'    => AccountMail::isAvailable(),
        ]);
    }

    public function create(): void
    {
        $this->view('admin/users/form', [
            'pageTitle' => 'Add user',
            'user'      => null,
            'roles'     => Role::all(),
            // Which half of the form to show. With email working the new user
            // chooses their own password; without it, somebody has to type one
            // in and pass it on, which is the pre-email behaviour and still the
            // only thing that works on an install with no SMTP.
            'canInvite'    => AccountMail::isAvailable(),
            'inviteExpiry' => UserToken::describeExpiry(UserToken::expiryHours(UserToken::INVITE)),
            'invite'       => null,
            'inviteState'  => null,
        ]);
    }

    public function store(): void
    {
        $canInvite = AccountMail::isAvailable();

        $rules = [
            'name'      => 'required|max:150',
            'email'     => 'required|email|max:190|unique:users,email',
            'role_id'   => 'required|integer|exists:roles,id',
            'job_title' => 'max:150',
            'phone'     => 'max:50',
        ];

        if (!$canInvite) {
            // No user yet, so the application policy is the one that applies.
            // An override set on the account afterwards governs the *next*
            // password, not this one.
            $rules['password']              = 'required|max:255|' . PasswordPolicy::rule();
            $rules['password_confirmation'] = 'required|matches:password';
        }

        $data = $this->validate($rules, [
            'name'                  => 'Full name',
            'email'                 => 'Email address',
            'role_id'               => 'Role',
            'password'              => 'Password',
            'password_confirmation' => 'Confirmation',
        ], '/admin/users/create');

        // An invited account is created with a password nobody knows and nobody
        // can guess — 64 random hex characters, hashed like any other. It is
        // never shown, never sent, and replaced the moment the invite is
        // accepted. The alternative, a nullable password_hash, would mean every
        // sign-in path had to remember to check for it.
        $password = $canInvite ? bin2hex(random_bytes(32)) : (string) $data['password'];

        $id = User::create(
            $data['name'],
            $data['email'],
            $password,
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

        if (!$canInvite) {
            Flash::success($data['name'] . ' has been added. Tell them the password you set.');
            Response::redirect('/admin/users');
        }

        $this->issueInvite((int) $id, 'created');
        Response::redirect('/admin/users');
    }

    /** Send (or re-send) the invitation, and say plainly what happened. */
    public function invite(string $id): void
    {
        $userId = (int) $id;
        $user   = User::find($userId);

        if ($user === null) {
            Flash::error('That user no longer exists.');
            Response::redirect('/admin/users');
        }

        if (!AccountMail::isAvailable()) {
            Flash::error('Email is not configured, so an invitation cannot be sent. Set a password for them instead.');
            Response::redirect('/admin/users/' . $userId . '/edit');
        }

        $this->issueInvite($userId, 'resent');
        Response::redirect('/admin/users/' . $userId . '/edit');
    }

    /**
     * Issue an invite and report the outcome.
     *
     * A failed send is not hidden. The account exists either way — refusing to
     * create it because the mail server was down would be worse — but the
     * administrator has to know that nothing arrived, or they will wait for a
     * user who is waiting for them.
     */
    private function issueInvite(int $userId, string $context): void
    {
        $user = User::find($userId);

        if ($user === null) {
            return;
        }

        $sent = AccountMail::sendInvite($user);

        ActivityLog::record(
            'invited',
            'user',
            $userId,
            sprintf('%s an invitation to %s (%s)', $context === 'resent' ? 'Re-sent' : 'Sent', $user['name'], $sent ? 'sent' : 'failed')
        );

        if ($sent) {
            Flash::success(sprintf(
                '%s has been emailed a link to set their own password. It expires in %s.',
                $user['name'],
                UserToken::describeExpiry(UserToken::expiryHours(UserToken::INVITE))
            ));

            return;
        }

        Flash::error(sprintf(
            'The account exists, but the invitation to %s could not be sent — see Settings → Email → Log. '
            . 'Send it again once that is fixed, or set a password for them here.',
            $user['email']
        ));
    }

    public function edit(string $id): void
    {
        $user = User::find((int) $id);

        if ($user === null) {
            Flash::error('That user no longer exists.');
            Response::redirect('/admin/users');
        }

        $invite = UserToken::latest((int) $id, UserToken::INVITE);

        $this->view('admin/users/form', [
            'pageTitle'    => 'Edit ' . $user['name'],
            'user'         => $user,
            'roles'        => Role::all(),
            'canInvite'    => AccountMail::isAvailable(),
            'inviteExpiry' => UserToken::describeExpiry(UserToken::expiryHours(UserToken::INVITE)),
            'invite'       => $invite,
            'inviteState'  => UserToken::inviteStates()[(int) $id] ?? null,
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

    /**
     * This account's own password policy, overriding the site's.
     *
     * Three values on the row, all nullable, and NULL is a real answer: it
     * means "whatever the site says", which has to stay true when the site
     * changes its mind. 0 days means never. Collapsing either into the other
     * would break the one case the feature exists for — a shared account that
     * must stay exempt after somebody tightens the site-wide rule.
     */
    public function updatePasswordPolicy(string $id): void
    {
        $userId = (int) $id;
        $user   = User::find($userId);

        if ($user === null) {
            Flash::error('That user no longer exists.');
            Response::redirect('/admin/users');
        }

        $back = '/admin/users/' . $userId . '/edit';

        $data = $this->validate([
            'password_expiry_mode'     => 'required|in:inherit,never,custom',
            'password_complexity_mode' => 'required|in:inherit,custom',
            'password_expiry_days'     => 'integer|min_value:1|max_value:3650',
            'password_min_length'      => 'integer|min_value:8|max_value:64',
            'password_min_classes'     => 'integer|min_value:1|max_value:4',
        ], [
            'password_expiry_mode'     => 'Password expiry',
            'password_complexity_mode' => 'Minimum complexity',
            'password_expiry_days'     => 'Expiry in days',
            'password_min_length'      => 'Minimum length',
            'password_min_classes'     => 'Character types required',
        ], $back);

        // A number is only required by the mode that uses one, which is why it
        // is not `required` above — the field is hidden in the other two modes
        // and a hidden required field is a form nobody can submit.
        if ($data['password_expiry_mode'] === 'custom' && (string) $data['password_expiry_days'] === '') {
            Flash::errors(['password_expiry_days' => 'Say how many days.']);
            Flash::error('Choose the number of days, or pick one of the other two options.');
            Response::redirect($back);
        }

        if ($data['password_complexity_mode'] === 'custom'
            && ((string) $data['password_min_length'] === '' || (string) $data['password_min_classes'] === '')) {
            Flash::errors(['password_min_length' => 'Set both a length and a number of character types.']);
            Flash::error('Set both a length and a number of character types, or follow the site policy.');
            Response::redirect($back);
        }

        $expiry = match ($data['password_expiry_mode']) {
            'never'  => 0,
            'custom' => (int) $data['password_expiry_days'],
            default  => null,
        };

        $custom = $data['password_complexity_mode'] === 'custom';

        User::updatePasswordPolicy($userId, [
            'password_expiry_days' => $expiry,
            'password_min_length'  => $custom ? (int) $data['password_min_length'] : null,
            'password_min_classes' => $custom ? (int) $data['password_min_classes'] : null,
        ]);

        ActivityLog::record(
            'updated',
            'user',
            $userId,
            'Set the password policy for ' . $user['name'] . ': '
            . match (true) {
                $expiry === null => 'expiry follows the site policy',
                $expiry === 0    => 'password never expires',
                default          => 'password expires after ' . $expiry . ' days',
            }
            . ', complexity ' . ($custom
                ? $data['password_min_length'] . ' characters and ' . $data['password_min_classes'] . ' of 4 types'
                : 'follows the site policy')
        );

        Flash::success('Password policy saved for ' . $user['name'] . '.');
        Response::redirect($back);
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
            'password'              => 'required|max:255|' . PasswordPolicy::rule($user),
            'password_confirmation' => 'required|matches:password',
        ], [
            'password'              => 'New password',
            'password_confirmation' => 'Confirmation',
        ], '/admin/users/' . $userId . '/edit');

        User::updatePassword($userId, (string) $_POST['password']);

        // An outstanding invitation is a second way into this account, and the
        // password just set is the one the administrator is about to pass on.
        // Leaving the link live would mean two different passwords could end up
        // on the account depending on which was used last.
        UserToken::revokeAll($userId, UserToken::INVITE);
        UserToken::revokeAll($userId, UserToken::RESET);

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
