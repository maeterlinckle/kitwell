<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Borrower;
use App\Models\Loan;

final class BorrowerController extends Controller
{
    public function index(): void
    {
        $filters = [
            'q'               => (string) Request::query('q', ''),
            'type'            => (string) Request::query('type', ''),
            'is_active'       => (string) Request::query('active', ''),
            'with_open_loans' => Request::query('out') === '1',
        ];

        $this->view('borrowers/index', [
            'pageTitle' => 'Borrowers',
            'borrowers' => Borrower::all($filters),
            'filters'   => $filters,
        ]);
    }

    /** Everything currently with one borrower, plus their history. */
    public function show(string $id): void
    {
        $borrower = Borrower::find((int) $id);

        if ($borrower === null) {
            $this->notFound();
        }

        $loans = Loan::forBorrower((int) $borrower['id']);

        $this->view('borrowers/show', [
            'pageTitle' => Borrower::label($borrower),
            'borrower'  => $borrower,
            'openLoans' => array_values(array_filter($loans, static fn (array $l): bool => $l['returned_at'] === null)),
            'pastLoans' => array_values(array_filter($loans, static fn (array $l): bool => $l['returned_at'] !== null)),
        ]);
    }

    public function create(): void
    {
        $this->view('borrowers/form', [
            'pageTitle' => 'Add borrower',
            'borrower'  => null,
            'users'     => Borrower::linkableUsers(),
        ]);
    }

    public function store(): void
    {
        $data = $this->validateBorrower();
        $data['created_by'] = Auth::id();

        $id = Borrower::create($data);

        ActivityLog::record('created', 'borrower', $id, 'Added borrower ' . $data['name']);
        Flash::success($data['name'] . ' has been added.');

        Response::redirect('/borrowers/' . $id);
    }

    public function edit(string $id): void
    {
        $borrower = Borrower::find((int) $id);

        if ($borrower === null) {
            $this->notFound();
        }

        $this->view('borrowers/form', [
            'pageTitle' => 'Edit ' . $borrower['name'],
            'borrower'  => $borrower,
            'users'     => Borrower::linkableUsers((int) $borrower['id']),
        ]);
    }

    public function update(string $id): void
    {
        $borrowerId = (int) $id;
        $borrower   = Borrower::find($borrowerId);

        if ($borrower === null) {
            $this->notFound();
        }

        $data = $this->validateBorrower($borrower);
        $data['is_active'] = Request::boolean('is_active') ? 1 : 0;

        Borrower::update($borrowerId, $data);

        ActivityLog::record(
            'updated',
            'borrower',
            $borrowerId,
            'Updated borrower ' . $data['name'],
            ActivityLog::diff($borrower, $data)
        );

        Flash::success('Borrower updated.');
        Response::redirect('/borrowers/' . $borrowerId);
    }

    public function destroy(string $id): void
    {
        $borrowerId = (int) $id;
        $borrower   = Borrower::find($borrowerId);

        if ($borrower === null) {
            $this->notFound();
        }

        if (Borrower::hasLoans($borrowerId)) {
            Flash::error('This borrower has loan history, which must be kept. Deactivate them instead — they will stop appearing in the checkout list.');
            Response::redirect('/borrowers/' . $borrowerId);
        }

        Borrower::delete($borrowerId);

        ActivityLog::record('deleted', 'borrower', $borrowerId, 'Deleted borrower ' . $borrower['name']);
        Flash::success('Borrower deleted.');

        Response::redirect('/borrowers');
    }

    /**
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function validateBorrower(?array $existing = null): array
    {
        $redirect = $existing === null
            ? '/borrowers/create'
            : '/borrowers/' . (int) $existing['id'] . '/edit';

        $data = $this->validate([
            'borrower_type' => 'required|in:' . implode(',', Borrower::TYPES),
            'name'          => 'required|max:191',
            'company_name'  => 'max:191',
            'reference'     => 'max:64',
            'email'         => 'email|max:190',
            'phone'         => 'max:50',
            'address'       => 'max:5000',
            'user_id'       => 'integer',
            'notes'         => 'max:5000',
        ], [
            'borrower_type' => 'Borrower type',
            'name'          => 'Name',
            'company_name'  => 'Company',
            'user_id'       => 'Linked login',
        ], $redirect);

        $userId = (int) $data['user_id'];

        // One login links to at most one borrower record — that link is what
        // scopes the self-service portal.
        if ($userId > 0) {
            $linked = \App\Core\Database::selectOne(
                'SELECT id, name FROM borrowers WHERE user_id = ? AND id <> ?',
                [$userId, $existing === null ? 0 : (int) $existing['id']]
            );

            if ($linked !== null) {
                $this->failValidation(
                    ['user_id' => 'That login is already linked to the borrower “' . $linked['name'] . '”.'],
                    $redirect
                );
            }
        }

        return [
            'borrower_type' => $data['borrower_type'],
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
