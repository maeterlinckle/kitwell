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
use App\Models\Hirer;
use App\Models\Hire;
use App\Models\Setting;

final class HireController extends Controller
{
    public function index(): void
    {
        // Keep the stored status column honest for anything querying the
        // database directly; display uses the derived status regardless.
        Hire::refreshOverdue();

        $filters = self::filtersFromRequest();

        $this->view('hires/index', [
            'pageTitle'   => 'Hires',
            'result'      => Hire::search($filters, max(1, (int) Request::query('page', 1)), 25),
            'filters'     => $filters,
            'summary'     => Hire::summary(),
            'hirers'   => Hirer::forSelect(),
            'queryString' => self::queryString($filters),
        ]);
    }

    public function show(string $id): void
    {
        $hire = Hire::find((int) $id);

        if ($hire === null) {
            $this->notFound();
        }

        $this->view('hires/show', [
            'pageTitle' => 'Hire ' . ($hire['reference'] ?? '#' . $hire['id']),
            'hire'      => $hire,
            'photosOut' => Hire::photos((int) $hire['id'], 'out'),
            'photosIn'  => Hire::photos((int) $hire['id'], 'in'),
        ]);
    }

    /** Step 1 of checkout: an asset (possibly pre-filled from a scan). */
    public function checkoutForm(): void
    {
        $assetId = (int) Request::query('asset', 0);
        $asset   = $assetId > 0 ? Asset::find($assetId) : null;
        $blocked = $asset === null ? null : Hire::blockedReason($asset);

        $defaultDays = max(1, min(365, Setting::int('hire_default_days', 7)));

        $this->view('hires/checkout', [
            'pageTitle'   => $asset !== null ? 'Check out ' . $asset['asset_tag'] : 'Check out an asset',
            'asset'       => $asset,
            'blocked'     => $blocked,
            'hirers'   => Hirer::forSelect(),
            'defaultDue'  => date('Y-m-d', strtotime('+' . $defaultDays . ' days')),
            'defaultDays' => $defaultDays,
        ]);
    }

    public function checkout(): void
    {
        $data = $this->validate([
            'asset_id'      => 'required|integer|exists:assets,id',
            'hirer_id'   => 'required|integer|exists:hirers,id',
            'due_back_date' => 'required|date',
            'condition_out' => 'in:' . implode(',', Asset::CONDITIONS),
            'purpose'       => 'max:255',
            'hire_charge'   => 'numeric|min_value:0|max_value:9999999',
            'notes'         => 'max:5000',
        ], [
            'asset_id'      => 'Asset',
            'hirer_id'   => 'Hirer',
            'due_back_date' => 'Due back date',
        ], '/hires/checkout');

        $assetId  = (int) $data['asset_id'];
        $redirect = '/hires/checkout?asset=' . $assetId;
        $asset    = Asset::find($assetId);

        if ($asset === null) {
            $this->notFound();
        }

        if ($data['due_back_date'] < date('Y-m-d')) {
            $this->failValidation(['due_back_date' => 'The due-back date cannot be in the past.'], $redirect);
        }

        $blocked = Hire::blockedReason($asset);
        if ($blocked !== null) {
            Flash::error($blocked);
            Response::redirect('/assets/' . $assetId);
        }

        try {
            $hireId = Hire::checkout($assetId, (int) $data['hirer_id'], [
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

        $this->attachPhotos($hireId, 'out');

        $hire     = Hire::find($hireId);
        $hirer = Hirer::find((int) $data['hirer_id']);

        ActivityLog::record(
            'checked_out',
            'asset',
            $assetId,
            sprintf('%s checked out to %s, due back %s (%s)',
                $asset['asset_tag'], $hirer['name'] ?? '', format_date($data['due_back_date']), $hire['reference'] ?? '')
        );

        Flash::success(sprintf(
            '%s is now with %s until %s.',
            $asset['asset_tag'],
            Hirer::label($hirer ?? []),
            format_date($data['due_back_date'])
        ));

        // Straight back to scanning if that is how they got here.
        if (Request::post('and_scan_next') !== null) {
            Response::redirect('/scan?mode=checkout');
        }

        Response::redirect('/hires/' . $hireId);
    }

    /** Step 1 of return: confirm condition and add photos. */
    public function returnForm(string $id): void
    {
        $hire = Hire::find((int) $id);

        if ($hire === null) {
            $this->notFound();
        }

        if ($hire['returned_at'] !== null) {
            Flash::info('That hire was already booked back in on ' . format_date($hire['returned_at']) . '.');
            Response::redirect('/hires/' . (int) $id);
        }

        $this->view('hires/return', [
            'pageTitle' => 'Book in ' . $hire['asset_tag'],
            'hire'      => $hire,
        ]);
    }

    public function returnHire(string $id): void
    {
        $hireId = (int) $id;
        $hire   = Hire::find($hireId);

        if ($hire === null) {
            $this->notFound();
        }

        $redirect = '/hires/' . $hireId . '/return';

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

        if ($data['returned_on'] < substr((string) $hire['checked_out_at'], 0, 10)) {
            $this->failValidation(
                ['returned_on' => 'The return date cannot be before the item was checked out (' . format_date($hire['checked_out_at']) . ').'],
                $redirect
            );
        }

        // Preserve the time of day when booking in today, so same-day hires
        // read sensibly in the history.
        $returnedAt = $data['returned_on'] === date('Y-m-d')
            ? date('Y-m-d H:i:s')
            : $data['returned_on'] . ' 12:00:00';

        try {
            Hire::markReturned($hireId, [
                'returned_at'              => $returnedAt,
                'condition_in'             => $data['condition_in'] !== '' ? $data['condition_in'] : null,
                'returned_condition_notes' => $data['returned_condition_notes'] !== '' ? $data['returned_condition_notes'] : null,
                'asset_status'             => $data['asset_status'] !== '' ? $data['asset_status'] : 'In Stock',
            ]);
        } catch (\RuntimeException $e) {
            Flash::error($e->getMessage());
            Response::redirect('/hires/' . $hireId);
        }

        $this->attachPhotos($hireId, 'in');

        ActivityLog::record(
            'checked_in',
            'asset',
            (int) $hire['asset_id'],
            sprintf('%s returned by %s%s',
                $hire['asset_tag'],
                $hire['hirer_name'],
                $data['condition_in'] !== '' ? ' in ' . strtolower((string) $data['condition_in']) . ' condition' : '')
        );

        Flash::success($hire['asset_tag'] . ' has been booked back in.');

        if (Request::post('and_scan_next') !== null) {
            Response::redirect('/scan?mode=return');
        }

        Response::redirect('/hires/' . $hireId);
    }

    /** Push an open hire's due date back. */
    public function extend(string $id): void
    {
        $hireId = (int) $id;
        $hire   = Hire::find($hireId);

        if ($hire === null) {
            $this->notFound();
        }

        if ($hire['returned_at'] !== null) {
            Flash::error('That hire has already been returned.');
            Response::redirect('/hires/' . $hireId);
        }

        $data = $this->validate([
            'due_back_date' => 'required|date',
        ], ['due_back_date' => 'New due-back date'], '/hires/' . $hireId);

        if ($data['due_back_date'] < date('Y-m-d')) {
            $this->failValidation(['due_back_date' => 'The new due date must be today or later.'], '/hires/' . $hireId);
        }

        Hire::extend($hireId, $data['due_back_date']);

        ActivityLog::record(
            'hire_extended',
            'asset',
            (int) $hire['asset_id'],
            sprintf('Hire %s extended from %s to %s',
                $hire['reference'] ?? '#' . $hireId,
                format_date($hire['due_back_date']),
                format_date($data['due_back_date']))
        );

        Flash::success('Due back date moved to ' . format_date($data['due_back_date']) . '.');
        Response::redirect('/hires/' . $hireId);
    }

    /** Stream a photo taken at checkout or return. */
    public function photo(string $hireId, string $photoId): void
    {
        $photo = Hire::findPhoto((int) $photoId);

        if ($photo === null || (int) $photo['hire_id'] !== (int) $hireId) {
            $this->notFound('That photo is no longer attached to this hire.');
        }

        self::streamImage($photo);
    }

    /**
     * Shared image streaming for hire photos.
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

    private function attachPhotos(int $hireId, string $stage): void
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

            $path     = Upload::store($file, 'hires/' . $hireId, $extension);
            $absolute = Upload::absolutePath($path);

            if ($absolute !== null) {
                Image::normalise($absolute, $mime);
            }

            Hire::addPhoto([
                'hire_id'           => $hireId,
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
            'hirer_id' => (string) Request::query('hirer', ''),
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
            'hirer' => $filters['hirer_id'] ?? '',
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
