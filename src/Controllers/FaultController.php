<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Image;
use App\Core\Response;
use App\Core\Upload;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\FaultReport;
use App\Services\FaultNotifier;

/**
 * Reporting a fault, and reading the reports back.
 *
 * A page of its own rather than a dialog on the asset screen. Somebody filling
 * this in is standing next to a broken machine with a phone, and the form asks
 * for a photograph — a modal that can be dismissed by a stray tap, over a page
 * that has already scrolled somewhere else, is the wrong container for that.
 * It also means the form has a URL, so "report a fault on AST-0004" is a link
 * that can be sent to somebody.
 *
 * The write is deliberately ordered: the report row, then the photos, then the
 * asset's status, then the notification. Each step depends on the one before —
 * the email says how many photos there are, and there is no point telling
 * anyone about a report that failed to save.
 */
final class FaultController extends Controller
{
    /** The form. */
    public function create(string $assetId): void
    {
        $asset = Asset::find((int) $assetId);

        if ($asset === null) {
            $this->notFound();
        }

        if ($asset['status'] === 'Retired') {
            Flash::error('This asset is archived. Restore it before reporting a fault on it.');
            Response::redirect('/assets/' . (int) $asset['id']);
        }

        $this->view('faults/form', [
            'pageTitle' => 'Report a fault — ' . $asset['asset_tag'],
            'asset'     => $asset,
            'previous'  => FaultReport::latestForAsset((int) $asset['id']),
            'faultCount'=> FaultReport::countForAsset((int) $asset['id']),
        ]);
    }

    public function store(string $assetId): void
    {
        $id    = (int) $assetId;
        $asset = Asset::find($id);

        if ($asset === null) {
            $this->notFound();
        }

        $redirect = '/assets/' . $id . '/faults/report';

        $data = $this->validate([
            'description'      => 'required|max:5000',
            'faulty_on'        => 'required|date',
            'urgency'          => 'required|in:' . implode(',', FaultReport::URGENCIES),
            'condition_rating' => 'required|in:' . implode(',', Asset::CONDITIONS),
        ], [
            'description'      => 'Fault description',
            'faulty_on'        => 'Faulty date',
            'urgency'          => 'Urgency',
            'condition_rating' => 'Condition',
        ], $redirect);

        if ($data['faulty_on'] > date('Y-m-d')) {
            $this->failValidation(['faulty_on' => 'The date the fault was noticed cannot be in the future.'], $redirect);
        }

        // At least one photograph, checked before anything is written. A fault
        // report without one is a sentence somebody has to interpret; with one
        // it is a thing the fitter can look at before walking over.
        $files = Upload::files('photos');

        if ($files === []) {
            $this->failValidation(
                ['photos' => 'Add at least one photo of the fault. Use “Take photo” on a phone or tablet.'],
                $redirect
            );
        }

        // Validated up front for the same reason: a report saved with every
        // photo rejected would satisfy the rule above on a technicality.
        $maxBytes   = (int) Config::get('uploads.max_photo_bytes');
        $mimes      = (array) Config::get('uploads.photo_mimes');
        $extensions = (array) Config::get('uploads.photo_extensions');

        $accepted = [];
        $rejected = [];

        foreach ($files as $file) {
            $error = Upload::validate($file, $mimes, $extensions, $maxBytes);

            if ($error === null) {
                $accepted[] = $file;
            } else {
                $rejected[] = $error;
            }
        }

        if ($accepted === []) {
            $this->failValidation(
                ['photos' => $rejected === [] ? 'That photo could not be read.' : (string) $rejected[0]],
                $redirect
            );
        }

        $reportId = FaultReport::create([
            'asset_id'         => $id,
            'description'      => $data['description'],
            'faulty_on'        => $data['faulty_on'],
            'urgency'          => $data['urgency'],
            'condition_rating' => $data['condition_rating'],
            'reported_by'      => Auth::id(),
            'reported_by_name' => Auth::name() ?? 'Unknown',
        ]);

        $photoCount = $this->attachPhotos($reportId, $accepted);

        foreach ($rejected as $message) {
            Flash::error($message);
        }

        // The condition recorded on the report is a judgement about the asset
        // as it stands, so it is carried onto the asset as well — the same
        // thing recording maintenance does with condition_after.
        Asset::update($id, [
            'status'           => 'Faulty',
            'condition_rating' => $data['condition_rating'],
            'updated_by'       => Auth::id(),
        ]);

        ActivityLog::record(
            'fault_reported',
            'asset',
            $id,
            sprintf(
                '%s fault reported on %s: %s',
                $data['urgency'],
                $asset['asset_tag'],
                str_limit((string) $data['description'], 120)
            ),
            [
                'fault_report_id' => $reportId,
                'urgency'         => $data['urgency'],
                'faulty_on'       => $data['faulty_on'],
                'status_before'   => $asset['status'],
                'photos'          => $photoCount,
            ]
        );

        // Re-read: the notification quotes the asset's status and condition,
        // which the update above has just changed.
        $notice = FaultNotifier::notify(
            Asset::find($id) ?? $asset,
            FaultReport::find($reportId) ?? [],
            $photoCount
        );

        Flash::success(sprintf(
            '%s is marked faulty. %s',
            $asset['asset_tag'],
            self::describeNotice($notice)
        ));

        Response::redirect('/assets/' . $id);
    }

    /**
     * Say plainly what happened to the email.
     *
     * Not "the responsible party has been notified" regardless — an asset with
     * nobody set emails nobody, and that is worth knowing at the moment the
     * report is filed rather than discovering a week later that nothing ever
     * went out.
     *
     * @param array{sent:int,failed:int,skipped:bool,reason:string,recipients:array<int,string>} $notice
     */
    private static function describeNotice(array $notice): string
    {
        if ($notice['skipped']) {
            return $notice['reason'];
        }

        if ($notice['sent'] === 0) {
            return 'The notification could not be sent — see the email log for the reason.';
        }

        $names = implode(', ', $notice['recipients']);

        $sent = $notice['sent'] === 1
            ? 'Notified ' . $names . '.'
            : sprintf('Notified %d people: %s.', $notice['sent'], $names);

        return $notice['failed'] > 0
            ? $sent . sprintf(' %d message(s) failed — see the email log.', $notice['failed'])
            : $sent;
    }

    /**
     * The full history for one asset.
     *
     * The asset page shows the current fault and a count; this is where the
     * rest live, the same split as the PAT history page.
     */
    public function history(string $assetId): void
    {
        $asset = Asset::find((int) $assetId);

        if ($asset === null) {
            $this->notFound();
        }

        $reports = FaultReport::forAsset((int) $asset['id']);

        $this->view('faults/history', [
            'pageTitle' => 'Fault history — ' . $asset['asset_tag'],
            'asset'     => $asset,
            'reports'   => $reports,
            'photos'    => FaultReport::photosForMany(array_map(
                static fn (array $r): int => (int) $r['id'],
                $reports
            )),
        ]);
    }

    /**
     * Stream one photo attached to a fault report.
     *
     * Uploads live outside the document root, so every image is served through
     * PHP — and the report id in the path is checked against the photo's own
     * parent, so a guessed photo id cannot be pulled out of a different report.
     */
    public function photo(string $reportId, string $photoId): void
    {
        $photo = FaultReport::findPhoto((int) $photoId);

        if ($photo === null || (int) $photo['fault_report_id'] !== (int) $reportId) {
            $this->notFound('That photo is no longer attached to this report.');
        }

        $path = Upload::absolutePath((string) $photo['file_path']);

        if ($path === null || !is_file($path)) {
            $this->notFound('That photo is missing from the server.');
        }

        header('Content-Type: ' . (string) $photo['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="'
            . str_replace('"', '', Upload::displayName((string) $photo['original_filename'])) . '"');
        header('Cache-Control: private, max-age=604800');

        readfile($path);
        exit;
    }

    /**
     * Move the accepted uploads into place.
     *
     * @param array<int,array{name:string,tmp_name:string,error:int,size:int}> $files
     * @return int How many were stored
     */
    private function attachPhotos(int $reportId, array $files): int
    {
        $stored = 0;

        foreach ($files as $file) {
            $mime      = (string) Upload::detectMime($file['tmp_name']);
            $extension = match ($mime) {
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/heic' => 'heic',
                'image/heif' => 'heif',
                default      => 'jpg',
            };

            $path     = Upload::store($file, 'faults/' . $reportId, $extension);
            $absolute = Upload::absolutePath($path);

            if ($absolute !== null) {
                // Rotates by the EXIF orientation and scales down: a photo
                // taken sideways on a phone is otherwise unreadable in the
                // email that quotes it.
                Image::normalise($absolute, $mime);
            }

            FaultReport::addPhoto([
                'fault_report_id'   => $reportId,
                'file_path'         => $path,
                'original_filename' => Upload::displayName($file['name']),
                'mime_type'         => $mime,
                'file_size_bytes'   => $absolute !== null ? (int) filesize($absolute) : (int) $file['size'],
                'caption'           => null,
                'uploaded_by'       => Auth::id(),
            ]);

            $stored++;
        }

        return $stored;
    }
}
