<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Team;
use App\Models\User;

/**
 * Teams: the groups work can be assigned to.
 *
 * Administrative rather than day-to-day, which is why it sits under Settings
 * behind `teams.manage`. Membership decides who is reminded about a job and who
 * it is expected of, so it is not something the people doing the work should be
 * able to rearrange for themselves.
 *
 * Teams are archived, never deleted from the interface. A team that has been
 * disbanded is still the honest answer to "who was this assigned to last
 * year?", and the schedules pointing at it keep reading properly.
 */
final class TeamController extends Controller
{
    public function index(): void
    {
        $this->view('admin/teams/index', [
            'pageTitle' => 'Teams',
            'teams'     => Team::all(),
        ]);
    }

    public function create(): void
    {
        $this->view('admin/teams/form', [
            'pageTitle'  => 'Add team',
            'team'       => null,
            'members'    => [],
            'candidates' => [],
        ]);
    }

    public function store(): void
    {
        $data = $this->validate([
            'name'        => 'required|max:120',
            'description' => 'max:255',
        ], [
            'name'        => 'Team name',
            'description' => 'Description',
        ], '/admin/teams/create');

        $name = trim((string) $data['name']);

        if (Team::nameExists($name)) {
            $this->failValidation(['name' => 'A team called “' . $name . '” already exists.'], '/admin/teams/create');
        }

        $teamId = Team::create([
            'name'        => $name,
            'description' => trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
            'is_active'   => 1,
            'created_by'  => Auth::id(),
        ]);

        ActivityLog::record('created', 'team', $teamId, 'Created the team ' . $name);

        Flash::success('“' . $name . '” was created. Add its members below.');
        Response::redirect('/admin/teams/' . $teamId . '/edit');
    }

    public function edit(string $id): void
    {
        $teamId = (int) $id;
        $team   = Team::find($teamId);

        if ($team === null) {
            Flash::error('That team no longer exists.');
            Response::redirect('/admin/teams');
        }

        $this->view('admin/teams/form', [
            'pageTitle'  => 'Edit ' . $team['name'],
            'team'       => $team,
            'members'    => Team::members($teamId),
            'candidates' => Team::candidates($teamId),
        ]);
    }

    public function update(string $id): void
    {
        $teamId = (int) $id;
        $team   = Team::find($teamId);

        if ($team === null) {
            Flash::error('That team no longer exists.');
            Response::redirect('/admin/teams');
        }

        $redirect = '/admin/teams/' . $teamId . '/edit';

        $data = $this->validate([
            'name'        => 'required|max:120',
            'description' => 'max:255',
        ], [
            'name'        => 'Team name',
            'description' => 'Description',
        ], $redirect);

        $name = trim((string) $data['name']);

        if (Team::nameExists($name, $teamId)) {
            $this->failValidation(['name' => 'A team called “' . $name . '” already exists.'], $redirect);
        }

        $changes = [
            'name'        => $name,
            'description' => trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
        ];

        Team::update($teamId, $changes);

        ActivityLog::record(
            'updated',
            'team',
            $teamId,
            'Updated the team ' . $name,
            ActivityLog::diff($team, $changes)
        );

        Flash::success('“' . $name . '” has been updated.');
        Response::redirect($redirect);
    }

    /** Archive or bring back. Never a delete — see the class docblock. */
    public function toggleActive(string $id): void
    {
        $teamId = (int) $id;
        $team   = Team::find($teamId);

        if ($team === null) {
            Flash::error('That team no longer exists.');
            Response::redirect('/admin/teams');
        }

        $activate = (int) $team['is_active'] !== 1;

        Team::update($teamId, ['is_active' => $activate ? 1 : 0]);

        ActivityLog::record(
            $activate ? 'activated' : 'archived',
            'team',
            $teamId,
            ($activate ? 'Brought back the team ' : 'Archived the team ') . $team['name']
        );

        // Spelled out, because "archived" could be read as "the work assigned to
        // it has moved somewhere else".
        Flash::success($activate
            ? '“' . $team['name'] . '” is available again.'
            : '“' . $team['name'] . '” has been archived. It keeps the '
                . (int) $team['schedule_count'] . ' schedule(s) already assigned to it, and its members are '
                . 'still reminded about them — it is just not offered for anything new.');

        Response::redirect('/admin/teams');
    }

    public function addMember(string $id): void
    {
        $teamId = (int) $id;
        $team   = Team::find($teamId);

        if ($team === null) {
            Flash::error('That team no longer exists.');
            Response::redirect('/admin/teams');
        }

        $redirect = '/admin/teams/' . $teamId . '/edit';
        $userId   = (int) Request::post('user_id', 0);
        $user     = $userId > 0 ? User::find($userId) : null;

        if ($user === null) {
            Flash::error('Choose somebody to add.');
            Response::redirect($redirect);
        }

        Team::addMember($teamId, $userId, Auth::id());

        ActivityLog::record('member_added', 'team', $teamId, sprintf('Added %s to %s', $user['name'], $team['name']));

        Flash::success($user['name'] . ' was added to “' . $team['name'] . '”.');
        Response::redirect($redirect);
    }

    public function removeMember(string $id, string $userId): void
    {
        $teamId = (int) $id;
        $team   = Team::find($teamId);

        if ($team === null) {
            Flash::error('That team no longer exists.');
            Response::redirect('/admin/teams');
        }

        $redirect = '/admin/teams/' . $teamId . '/edit';
        $user     = User::find((int) $userId);

        Team::removeMember($teamId, (int) $userId);

        ActivityLog::record(
            'member_removed',
            'team',
            $teamId,
            sprintf('Removed %s from %s', $user['name'] ?? ('user #' . (int) $userId), $team['name'])
        );

        Flash::success(($user['name'] ?? 'That user') . ' was removed from “' . $team['name'] . '”.');
        Response::redirect($redirect);
    }
}
