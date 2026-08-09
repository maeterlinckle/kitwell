<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetManual;

/**
 * PDF manuals: upload, view in the browser, download, delete.
 *
 * Files live outside the document root and are streamed through PHP, so a
 * manual is only reachable by someone who is signed in and allowed to see the
 * asset it belongs to.
 */
final class ManualController extends Controller
{
    public function store(string $assetId): void
    {
        $id    = (int) $assetId;
        $asset = Asset::find($id);

        if ($asset === null) {
            $this->notFound();
        }

        $files = Upload::files('manuals');

        if ($files === []) {
            Flash::error('No file was selected.');
            Response::redirect('/assets/' . $id . '#manuals');
        }

        $maxBytes   = (int) Config::get('uploads.max_pdf_bytes');
        $mimes      = (array) Config::get('uploads.pdf_mimes');
        $extensions = (array) Config::get('uploads.pdf_extensions');

        $title  = trim((string) Request::post('title', ''));
        $notes  = trim((string) Request::post('notes', ''));
        $stored = 0;
        $errors = [];

        foreach ($files as $index => $file) {
            $error = Upload::validate($file, $mimes, $extensions, $maxBytes);

            if ($error !== null) {
                $errors[] = $error;
                continue;
            }

            $displayName = Upload::displayName($file['name']);

            // One title for a single upload; for several files at once, fall
            // back to each file's own name so they stay distinguishable.
            $manualTitle = ($title !== '' && count($files) === 1)
                ? $title
                : ($title !== '' ? $title . ' (' . ($index + 1) . ')' : pathinfo($displayName, PATHINFO_FILENAME));

            $path = Upload::store($file, 'assets/' . $id . '/manuals', 'pdf');

            AssetManual::create([
                'asset_id'          => $id,
                'title'             => mb_substr($manualTitle, 0, 191),
                'file_path'         => $path,
                'original_filename' => $displayName,
                'mime_type'         => 'application/pdf',
                'file_size_bytes'   => (int) $file['size'],
                'notes'             => $notes !== '' ? mb_substr($notes, 0, 255) : null,
                'uploaded_by'       => Auth::id(),
            ]);

            $stored++;
        }

        if ($stored > 0) {
            ActivityLog::record('manual_uploaded', 'asset', $id, sprintf('Uploaded %d manual(s) to %s', $stored, $asset['asset_tag']));
            Flash::success(sprintf('%d manual%s uploaded.', $stored, $stored === 1 ? '' : 's'));
        }

        foreach ($errors as $error) {
            Flash::error($error);
        }

        Response::redirect('/assets/' . $id . '#manuals');
    }

    /** Stream a manual: inline by default, as a download with ?download=1. */
    public function show(string $assetId, string $manualId): void
    {
        $manual = AssetManual::find((int) $manualId);

        if ($manual === null || (int) $manual['asset_id'] !== (int) $assetId) {
            $this->notFound('That manual is no longer attached to this asset.');
        }

        $path = Upload::absolutePath((string) $manual['file_path']);

        if ($path === null) {
            $this->notFound('The file for this manual is missing from the server. It may need re-uploading.');
        }

        $download = Request::query('download') === '1';
        $filename = Upload::displayName((string) ($manual['original_filename'] ?: $manual['title'] . '.pdf'));

        if (!str_ends_with(strtolower($filename), '.pdf')) {
            $filename .= '.pdf';
        }

        // Clear any buffered output so the PDF is the only thing sent.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Length: ' . (string) filesize($path));
        header(sprintf(
            '%s; filename="%s"',
            'Content-Disposition: ' . ($download ? 'attachment' : 'inline'),
            str_replace('"', '', $filename)
        ));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=600');
        header_remove('Pragma');

        readfile($path);
        exit;
    }

    public function destroy(string $assetId, string $manualId): void
    {
        $id     = (int) $assetId;
        $manual = AssetManual::find((int) $manualId);

        if ($manual === null || (int) $manual['asset_id'] !== $id) {
            $this->notFound('That manual has already been removed.');
        }

        Upload::delete((string) $manual['file_path']);
        AssetManual::delete((int) $manual['id']);

        ActivityLog::record('manual_deleted', 'asset', $id, 'Removed manual: ' . $manual['title']);
        Flash::success('“' . $manual['title'] . '” has been removed.');

        Response::redirect('/assets/' . $id . '#manuals');
    }
}
