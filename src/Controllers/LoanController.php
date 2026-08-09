<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Image;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Setting;

final class LoanController extends Controller
{
    public function index(): void
    {
        // Keep the stored status column honest for anything querying the
        // database directly; display uses the derived status regardless.
        Loan::refreshOverdue();

        $filters = self::filtersFromRequest();

        $this->view('loans/index', [
            'pageTitle'   => 'Loans',
            'result'      => Loan::search($filters, max(1, (int) Request::query('page', 1)), 25),
            'filters'     => $filters,
            'summary'     => Loan::summary(),
            'borrowers'   => Borrower::forSelect(),
            'queryString' => self::queryString($filters),
        ]);
    }

    public function show(string $id): void
    {
        $loan = Loan::find((int) $id);

        if ($loan === null) {
            $this->notFound();
        }

        $this->view('loans/show', [
            'pageTitle' => 'Loan ' . ($loan['reference'] ?? '#' . $loan['id']),
            'loan'      => $loan,
            'photosOut' => Loan::photos((int) $loan['id'], 'out'),
            'photosIn'  => Loan::photos((int) $loan['id'], 'in'),
        ]);
    }

    /** Step 1 of checkout: an asset (possibly pre-filled from a scan). */
    public function checkoutForm(): void
    {
        $assetId = (int) Request::query('asset', 0);
        $asset   = $assetId > 0 ? Asset::find($assetId) : null;
        $blocked = $asset === null ? null : Loan::blockedReason($asset);

        $defaultDays = max(1, min(365, Setting::int('loan_default_days', 7)));

        $this->view('loans/checkout', [
            'pageTitle'   => $asset !== null ? 'Check out ' . $asset['asset_tag'] : 'Check out an asset',
            'asset'       => $asset,
            'blocked'     => $blocked,
            'borrowers'   => Borrower::forSelect(),
            'defaultDue'  => date('Y-m-d', strtotime('+' . $defaultDays . ' days')),
            'defaultDays' => $defaultDays,
        ]);
    }

    public function checkout(): void
    {
        $data = $this->validate([
            'asset_id'      => 'required|integer|exists:assets,id',
            'borrower_id'   => 'required|integer|exists:borrowers,id',
            'due_back_date' => 'required|date',
            'condition_out' => 'in:' . implode(',', Asset::CONDITIONS),
            'purpose'       => 'max:255',
            'hire_charge'   => 'numeric|min_value:0|max_value:9999999',
            'notes'         => 'max:5000',
        ], [
            'asset_id'      => 'Asset',
            'borrower_id'   => 'Borrower',
            'due_back_date' => 'Due back date',
        ], '/loans/checkout');

        $assetId  = (int) $data['asset_id'];
        $redirect = '/loans/checkout?asset=' . $assetId;
        $asset    = Asset::find($assetId);

        if ($asset === null) {
            $this->notFound();
        }

        if ($data['due_back_date'] < date('Y-m-d')) {
            $this->failValidation(['due_back_date' => 'The due-back date cannot be in the past.'], $redirect);
        }

        $blocked = Loan::blockedReason($asset);
        if ($blocked !== null) {
            Flash::error($blocked);
            Response::redirect('/assets/' . $assetId);
        }

        try {
            $loanId = Loan::checkout($assetId, (int) $data['borrower_id'], [
                'checked_out_at' => date('Y-m-d H:i:s'),
                'due_back_date'  => $data['due_back_date'],
                'condition_out'  => $data['condition_out'] !== '' ? $data['condition_out'] : $asset['condition_rating'],
                'purpose'        => $data['purpose'] !== '' ? $data['purpose'] : null,
                'hire_charge'    => $data['hire_charge'] !== '' ? $data['hire_charge'] : null,
                'notes'          => $data['notes'] !== '' ? $data['notes'] : null,
            ]);
        } catch (\RuntimeException $e) {
            Flash::error($e->getMessage());
            Response::redirect('/assets/' . $assetId);
        }

        $this->attachPhotos($loanId, 'out');

        $loan     = Loan::find($loanId);
        $borrower = Borrower::find((int) $data['borrower_id']);

        ActivityLog::record(
            'checked_out',
            'asset',
            $assetId,
            sprintf('%s checked out to %s, due back %s (%s)',
                $asset['asset_tag'], $borrower['name'] ?? '', format_date($data['due_back_date']), $loan['reference'] ?? '')
        );

        Flash::success(sprintf(
            '%s is now with %s until %s.',
            $asset['asset_tag'],
            Borrower::label($borrower ?? []),
            format_date($data['due_back_date'])
        ));

        // Straight back to scanning if that is how they got here.
        if (Request::post('and_scan_next') !== null) {
            Response::redirect('/scan?mode=checkout');
        }

        Response::redirect('/loans/' . $loanId);
    }

    /** Step 1 of return: confirm condition and add photos. */
    public function returnForm(string $id): void
    {
        $loan = Loan::find((int) $id);

        if ($loan === null) {
            $this->notFound();
        }

        if ($loan['returned_at'] !== null) {
            Flash::info('That loan was already booked back in on ' . format_date($loan['returned_at']) . '.');
            Response::redirect('/loans/' . (int) $id);
        }

        $this->view('loans/return', [
            'pageTitle' => 'Book in ' . $loan['asset_tag'],
            'loan'      => $loan,
        ]);
    }

    public function returnLoan(string $id): void
    {
        $loanId = (int) $id;
        $loan   = Loan::find($loanId);

        if ($loan === null) {
            $this->notFound();
        }

        $redirect = '/loans/' . $loanId . '/return';

        $data = $this->validate([
            'returned_on'              => 'required|date',
            'condition_in'             => 'in:' . implode(',', Asset::CONDITIONS),
            'returned_condition_notes' => 'max:5000',
            'asset_status'             => 'in:In Stock,In Maintenance',
        ], [
            'returned_on'  => 'Return date',
            'condition_in' => 'Condition on return',
        ], $redirect);

        if ($data['returned_on'] > date('Y-m-d')) {
            $this->failValidation(['returned_on' => 'The return date cannot be in the future.'], $redirect);
        }

        if ($data['returned_on'] < substr((string) $loan['checked_out_at'], 0, 10)) {
            $this->failValidation(
                ['returned_on' => 'The return date cannot be before the item was checked out (' . format_date($loan['checked_out_at']) . ').'],
                $redirect
            );
        }

        // Preserve the time of day when booking in today, so same-day loans
        // read sensibly in the history.
        $returnedAt = $data['returned_on'] === date('Y-m-d')
            ? date('Y-m-d H:i:s')
            : $data['returned_on'] . ' 12:00:00';

        try {
            Loan::markReturned($loanId, [
                'returned_at'              => $returnedAt,
                'condition_in'             => $data['condition_in'] !== '' ? $data['condition_in'] : null,
                'returned_condition_notes' => $data['returned_condition_notes'] !== '' ? $data['returned_condition_notes'] : null,
                'asset_status'             => $data['asset_status'] !== '' ? $data['asset_status'] : 'In Stock',
            ]);
        } catch (\RuntimeException $e) {
            Flash::error($e->getMessage());
            Response::redirect('/loans/' . $loanId);
        }

        $this->attachPhotos($loanId, 'in');

        ActivityLog::record(
            'checked_in',
            'asset',
            (int) $loan['asset_id'],
            sprintf('%s returned by %s%s',
                $loan['asset_tag'],
                $loan['borrower_name'],
                $data['condition_in'] !== '' ? ' in ' . strtolower((string) $data['condition_in']) . ' condition' : '')
        );

        Flash::success($loan['asset_tag'] . ' has been booked back in.');

        if (Request::post('and_scan_next') !== null) {
            Response::redirect('/scan?mode=return');
        }

        Response::redirect('/loans/' . $loanId);
    }

    /** Push an open loan's due date back. */
    public function extend(string $id): void
    {
        $loanId = (int) $id;
        $loan   = Loan::find($loanId);

        if ($loan === null) {
            $this->notFound();
        }

        if ($loan['returned_at'] !== null) {
            Flash::error('That loan has already been returned.');
            Response::redirect('/loans/' . $loanId);
        }

        $data = $this->validate([
            'due_back_date' => 'required|date',
        ], ['due_back_date' => 'New due-back date'], '/loans/' . $loanId);

        if ($data['due_back_date'] < date('Y-m-d')) {
            $this->failValidation(['due_back_date' => 'The new due date must be today or later.'], '/loans/' . $loanId);
        }

        Loan::extend($loanId, $data['due_back_date']);

        ActivityLog::record(
            'loan_extended',
            'asset',
            (int) $loan['asset_id'],
            sprintf('Loan %s extended from %s to %s',
                $loan['reference'] ?? '#' . $loanId,
                format_date($loan['due_back_date']),
                format_date($data['due_back_date']))
        );

        Flash::success('Due back date moved to ' . format_date($data['due_back_date']) . '.');
        Response::redirect('/loans/' . $loanId);
    }

    /** Stream a photo taken at checkout or return. */
    public function photo(string $loanId, string $photoId): void
    {
        $photo = Loan::findPhoto((int) $photoId);

        if ($photo === null || (int) $photo['loan_id'] !== (int) $loanId) {
            $this->notFound('That photo is no longer attached to this loan.');
        }

        self::streamImage($photo);
    }

    /**
     * Shared image streaming for loan photos.
     *
     * @param array<string,mixed> $photo
     */
    public static function streamImage(array $photo): never
    {
        $path = Upload::absolutePath((string) $photo['file_path']);

        if ($path === null) {
            http_response_code(404);
            exit;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . (string) $photo['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', Upload::displayName((string) $photo['original_filename'])) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=2592000, immutable');
        header_remove('Pragma');

        readfile($path);
        exit;
    }

    private function attachPhotos(int $loanId, string $stage): void
    {
        $files = Upload::files('photos');

        if ($files === []) {
            return;
        }

        $maxBytes   = (int) Config::get('uploads.max_photo_bytes');
        $mimes      = (array) Config::get('uploads.photo_mimes');
        $extensions = (array) Config::get('uploads.photo_extensions');

        foreach ($files as $file) {
            $error = Upload::validate($file, $mimes, $extensions, $maxBytes);

            if ($error !== null) {
                Flash::error($error);
                continue;
            }

            $mime      = (string) Upload::detectMime($file['tmp_name']);
            $extension = match ($mime) {
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/heic' => 'heic',
                'image/heif' => 'heif',
                default      => 'jpg',
            };

            $path     = Upload::store($file, 'loans/' . $loanId, $extension);
            $absolute = Upload::absolutePath($path);

            if ($absolute !== null) {
                Image::normalise($absolute, $mime);
            }

            Loan::addPhoto([
                'loan_id'           => $loanId,
                'stage'             => $stage,
                'file_path'         => $path,
                'original_filename' => Upload::displayName($file['name']),
                'mime_type'         => $mime,
                'file_size_bytes'   => $absolute !== null ? (int) filesize($absolute) : (int) $file['size'],
                'caption'           => null,
                'uploaded_by'       => Auth::id(),
            ]);
        }
    }

    /** @return array<string,mixed> */
    public static function filtersFromRequest(): array
    {
        return [
            'q'           => (string) Request::query('q', ''),
            'status'      => array_values(array_filter((array) (Request::query('status', []) ?? []), 'is_string')),
            'borrower_id' => (string) Request::query('borrower', ''),
            'from'        => (string) Request::query('from', ''),
            'to'          => (string) Request::query('to', ''),
            'sort'        => (string) Request::query('sort', 'due'),
        ];
    }

    /** @param array<string,mixed> $filters */
    public static function queryString(array $filters): string
    {
        $params = array_filter([
            'q'        => $filters['q'] ?? '',
            'borrower' => $filters['borrower_id'] ?? '',
            'from'     => $filters['from'] ?? '',
            'to'       => $filters['to'] ?? '',
            'sort'     => ($filters['sort'] ?? 'due') !== 'due' ? $filters['sort'] : '',
        ], static fn ($v): bool => $v !== '' && $v !== null);

        $query = http_build_query($params);

        foreach ((array) ($filters['status'] ?? []) as $status) {
            $query .= '&status%5B%5D=' . rawurlencode((string) $status);
        }

        return trim($query, '&');
    }
}
