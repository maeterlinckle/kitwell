<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Hirer;
use App\Models\Hire;

final class HirerController extends Controller
{
    public function index(): void
    {
        $filters = [
            'q'               => (string) Request::query('q', ''),
            'type'            => (string) Request::query('type', ''),
            'is_active'       => (string) Request::query('active', ''),
            'with_open_hires' => Request::query('out') === '1',
        ];

        $this->view('hirers/index', [
            'pageTitle' => 'Hirers',
            'hirers' => Hirer::all($filters),
            'filters'   => $filters,
        ]);
    }

    /** Everything currently with one hirer, plus their history. */
    public function show(string $id): void
    {
        $hirer = Hirer::find((int) $id);

        if ($hirer === null) {
            $this->notFound();
        }

        $hires = Hire::forHirer((int) $hirer['id']);

        $this->view('hirers/show', [
            'pageTitle' => Hirer::label($hirer),
            'hirer'  => $hirer,
            'openHires' => array_values(array_filter($hires, static fn (array $l): bool => $l['returned_at'] === null)),
            'pastHires' => array_values(array_filter($hires, static fn (array $l): bool => $l['returned_at'] !== null)),
        ]);
    }

    public function create(): void
    {
        $this->view('hirers/form', [
            'pageTitle' => 'Add hirer',
            'hirer'  => null,
            'users'     => Hirer::linkableUsers(),
        ]);
    }

    public function store(): void
    {
        $data = $this->validateHirer();
        $data['created_by'] = Auth::id();

        $id = Hirer::create($data);

        ActivityLog::record('created', 'hirer', $id, 'Added hirer ' . $data['name']);
        Flash::success($data['name'] . ' has been added.');

        Response::redirect('/hirers/' . $id);
    }

    public function edit(string $id): void
    {
        $hirer = Hirer::find((int) $id);

        if ($hirer === null) {
            $this->notFound();
        }

        $this->view('hirers/form', [
            'pageTitle' => 'Edit ' . $hirer['name'],
            'hirer'  => $hirer,
            'users'     => Hirer::linkableUsers((int) $hirer['id']),
        ]);
    }

    public function update(string $id): void
    {
        $hirerId = (int) $id;
        $hirer   = Hirer::find($hirerId);

        if ($hirer === null) {
            $this->notFound();
        }

        $data = $this->validateHirer($hirer);
        $data['is_active'] = Request::boolean('is_active') ? 1 : 0;

        Hirer::update($hirerId, $data);

        ActivityLog::record(
            'updated',
            'hirer',
            $hirerId,
            'Updated hirer ' . $data['name'],
            ActivityLog::diff($hirer, $data)
        );

        Flash::success('Hirer updated.');
        Response::redirect('/hirers/' . $hirerId);
    }

    public function destroy(string $id): void
    {
        $hirerId = (int) $id;
        $hirer   = Hirer::find($hirerId);

        if ($hirer === null) {
            $this->notFound();
        }

        if (Hirer::hasHires($hirerId)) {
            Flash::error('This hirer has hire history, which must be kept. Deactivate them instead — they will stop appearing in the checkout list.');
            Response::redirect('/hirers/' . $hirerId);
        }

        Hirer::delete($hirerId);

        ActivityLog::record('deleted', 'hirer', $hirerId, 'Deleted hirer ' . $hirer['name']);
        Flash::success('Hirer deleted.');

        Response::redirect('/hirers');
    }

    /**
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function validateHirer(?array $existing = null): array
    {
        $redirect = $existing === null
            ? '/hirers/create'
            : '/hirers/' . (int) $existing['id'] . '/edit';

        $data = $this->validate([
            'hirer_type' => 'required|in:' . implode(',', Hirer::TYPES),
            'name'          => 'required|max:191',
            'company_name'  => 'max:191',
            'reference'     => 'max:64',
            'email'         => 'email|max:190',
            'phone'         => 'max:50',
            'address'       => 'max:5000',
            'user_id'       => 'integer',
            'notes'         => 'max:5000',
        ], [
            'hirer_type' => 'Hirer type',
            'name'          => 'Name',
            'company_name'  => 'Company',
            'user_id'       => 'Linked login',
        ], $redirect);

        $userId = (int) $data['user_id'];

        // One login links to at most one hirer record — that link is what
        // scopes the self-service portal.
        if ($userId > 0) {
            $linked = \App\Core\Database::selectOne(
                'SELECT id, name FROM hirers WHERE user_id = ? AND id <> ?',
                [$userId, $existing === null ? 0 : (int) $existing['id']]
            );

            if ($linked !== null) {
                $this->failValidation(
                    ['user_id' => 'That login is already linked to the hirer “' . $linked['name'] . '”.'],
                    $redirect
                );
            }
        }

        return [
            'hirer_type' => $data['hirer_type'],
            'name'          => $data['name'],
            'company_name'  => $data['company_name'] !== '' ? $data['company_name'] : null,
            'reference'     => $data['reference'] !== '' ? $data['reference'] : null,
            'email'         => $data['email'] !== '' ? $data['email'] : null,
            'phone'         => $data['phone'] !== '' ? $data['phone'] : null,
            'address'       => $data['address'] !== '' ? $data['address'] : null,
            'user_id'       => $userId > 0 ? $userId : null,
            'notes'         => $data['notes'] !== '' ? $data['notes'] : null,
        ];
    }
}
