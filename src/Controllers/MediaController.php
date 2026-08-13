<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\MediaLibrary;
use App\Services\MediaIntake;

/**
 * The shared media library: browsing it, adding to it, attaching an item to an
 * asset and taking it off again.
 *
 * Nothing here touches condition photos. Those belong to one asset and are
 * handled by PhotoController, which is the point of keeping the two apart.
 */
final class MediaController extends Controller
{
    /** The library itself, as a browsable page. */
    public function index(): void
    {
        $type    = self::type(Request::query('type'));
        $keyword = trim((string) Request::query('q', ''));
        $page    = max(1, (int) Request::query('page', 1));

        $this->view('media/index', [
            'pageTitle' => 'Media library',
            'result'    => MediaLibrary::search($type, $keyword, $page, 24),
            'type'      => $type,
            'keyword'   => $keyword,
        ]);
    }

    /**
     * The picker, as JSON.
     *
     * Used by the Add asset form and the template editor, both of which need to
     * search the library without leaving the page they are on.
     */
    public function search(): void
    {
        $result = MediaLibrary::search(
            self::type(Request::query('type')),
            trim((string) Request::query('q', '')),
            max(1, (int) Request::query('page', 1)),
            18
        );

        Response::json([
            'page'  => $result['page'],
            'pages' => $result['pages'],
            'total' => $result['total'],
            'items' => array_map(static fn (array $row): array => [
                'id'          => (int) $row['id'],
                'type'        => $row['media_type'],
                'title'       => $row['title'],
                'description' => $row['description'],
                'filename'    => $row['original_filename'],
                'size'        => Upload::formatBytes((int) $row['file_size_bytes']),
                'assets'      => (int) ($row['asset_count'] ?? 0),
                'url'         => url('/media/' . (int) $row['id']),
                'thumbnail'   => $row['media_type'] === 'photo' ? url('/media/' . (int) $row['id'] . '/thumbnail') : null,
            ], $result['rows']),
        ]);
    }

    /** Add a file to the library on its own, without attaching it to anything. */
    public function store(): void
    {
        $type  = self::type(Request::post('media_type')) ?? 'document';
        $files = Upload::files('files');

        if (!self::mayUpload($type)) {
            $this->notFound('You do not have permission to add that kind of file.');
        }

        if ($files === []) {
            Flash::error('No file was selected.');
            Response::redirect('/media');
        }

        $title       = trim((string) Request::post('title', ''));
        $description = trim((string) Request::post('description', ''));

        [$added, $reused, $errors] = self::intake($files, $type, $title, $description);

        self::report($added, $reused, $errors);
        Response::redirect('/media');
    }

    /** Stream a library file: inline by default, as a download with ?download=1. */
    public function show(string $id): void
    {
        $media = MediaLibrary::find((int) $id);

        if ($media === null) {
            $this->notFound('That file is not in the library.');
        }

        $this->stream(
            (string) $media['file_path'],
            (string) $media['mime_type'],
            (string) ($media['original_filename'] ?: $media['title']),
            Request::query('download') === '1'
        );
    }

    /** The thumbnail of a library photo, falling back to the photo itself. */
    public function thumbnail(string $id): void
    {
        $media = MediaLibrary::find((int) $id);

        if ($media === null || $media['media_type'] !== 'photo') {
            $this->notFound('That file is not in the library.');
        }

        $path = (string) ($media['thumbnail_path'] ?: $media['file_path']);

        $this->stream($path, (string) $media['mime_type'], (string) $media['title'], false);
    }

    /** Attach existing library items to an asset. */
    public function attach(string $assetId): void
    {
        $id    = (int) $assetId;
        $asset = Asset::find($id);

        if ($asset === null) {
            $this->notFound();
        }

        $ids      = array_map('intval', (array) Request::post('media_ids', []));
        $attached = MediaLibrary::attachMany($id, $ids);

        if ($attached > 0) {
            ActivityLog::record(
                'media_attached',
                'asset',
                $id,
                sprintf('Attached %d library item(s) to %s', $attached, $asset['asset_tag'])
            );
            Flash::success(sprintf('%d file%s attached.', $attached, $attached === 1 ? '' : 's'));
        } else {
            Flash::warning('Nothing new to attach — those files are already on this asset.');
        }

        Response::redirect('/assets/' . $id . '#documents');
    }

    /** Upload a new file straight into the library and onto this asset. */
    public function upload(string $assetId): void
    {
        $id    = (int) $assetId;
        $asset = Asset::find($id);

        if ($asset === null) {
            $this->notFound();
        }

        $type  = self::type(Request::post('media_type')) ?? 'document';
        $files = Upload::files('files');

        if (!self::mayUpload($type)) {
            $this->notFound('You do not have permission to add that kind of file.');
        }

        if ($files === []) {
            Flash::error('No file was selected.');
            Response::redirect('/assets/' . $id . '#documents');
        }

        [$added, $reused, $errors] = self::intake(
            $files,
            $type,
            trim((string) Request::post('title', '')),
            trim((string) Request::post('description', '')),
            $id
        );

        if ($added > 0 || $reused > 0) {
            ActivityLog::record(
                'media_attached',
                'asset',
                $id,
                sprintf('Added %d library item(s) to %s', $added + $reused, $asset['asset_tag'])
            );
        }

        self::report($added, $reused, $errors);
        Response::redirect('/assets/' . $id . '#documents');
    }

    /**
     * Take a library item off one asset.
     *
     * The file stays in the library for everything else using it. It is only
     * deleted outright when this was the last thing referencing it, which keeps
     * the library from filling with items nobody can reach.
     */
    public function detach(string $assetId, string $mediaId): void
    {
        $id    = (int) $assetId;
        $asset = Asset::find($id);
        $media = MediaLibrary::find((int) $mediaId);

        if ($asset === null || $media === null) {
            $this->notFound('That file is no longer attached to this asset.');
        }

        MediaLibrary::detach($id, (int) $mediaId);

        $stillUsed = MediaLibrary::assetCount((int) $mediaId) + MediaLibrary::templateCount((int) $mediaId);

        if ($stillUsed === 0 && Request::boolean('forget')) {
            MediaIntake::forget((int) $mediaId);
            Flash::success('“' . $media['title'] . '” has been removed from this asset and deleted from the library.');
        } elseif ($stillUsed === 0) {
            Flash::success('“' . $media['title'] . '” has been removed from this asset. It is still in the library.');
        } else {
            Flash::success(sprintf(
                '“%s” has been removed from this asset. %d other place%s still use it.',
                $media['title'],
                $stillUsed,
                $stillUsed === 1 ? '' : 's'
            ));
        }

        ActivityLog::record('media_detached', 'asset', $id, 'Removed ' . $media['title'] . ' from ' . $asset['asset_tag']);
        Response::redirect('/assets/' . $id . '#documents');
    }

    /** Delete a library item outright, refused while anything still uses it. */
    public function destroy(string $id): void
    {
        $mediaId = (int) $id;
        $media   = MediaLibrary::find($mediaId);

        if ($media === null) {
            $this->notFound('That file is not in the library.');
        }

        $assets    = MediaLibrary::assetCount($mediaId);
        $templates = MediaLibrary::templateCount($mediaId);

        if ($assets + $templates > 0) {
            Flash::error(sprintf(
                '“%s” is still used by %d asset(s) and %d template(s). Remove it from those first.',
                $media['title'],
                $assets,
                $templates
            ));
            Response::redirect('/media');
        }

        MediaIntake::forget($mediaId);
        ActivityLog::record('media_deleted', 'media', $mediaId, 'Deleted ' . $media['title'] . ' from the library');
        Flash::success('“' . $media['title'] . '” has been deleted.');

        Response::redirect('/media');
    }

    /**
     * Run a batch of uploads through the library, attaching each to an asset
     * when one is given.
     *
     * @param array<int,array{name:string,tmp_name:string,error:int,size:int}> $files
     * @return array{0:int,1:int,2:array<int,string>} added, reused, errors
     */
    private static function intake(array $files, string $type, string $title, string $description, ?int $assetId = null): array
    {
        $added  = 0;
        $reused = 0;
        $errors = [];
        $many   = count($files) > 1;

        foreach ($files as $index => $file) {
            // One title for a single upload; for several at once each file
            // keeps its own name so they stay distinguishable.
            $itemTitle = ($title !== '' && !$many)
                ? $title
                : ($title !== '' ? $title . ' (' . ($index + 1) . ')' : '');

            $result = MediaIntake::store($file, $type, $itemTitle, $description);

            if (is_string($result)) {
                $errors[] = $result;
                continue;
            }

            $result['created'] ? $added++ : $reused++;

            if ($assetId !== null) {
                MediaLibrary::attach($assetId, (int) $result['media']['id']);
            }
        }

        return [$added, $reused, $errors];
    }

    private static function report(int $added, int $reused, array $errors): void
    {
        if ($added > 0) {
            Flash::success(sprintf('%d file%s added to the library.', $added, $added === 1 ? '' : 's'));
        }

        if ($reused > 0) {
            Flash::success(sprintf(
                '%d file%s already in the library — attached rather than stored again.',
                $reused,
                $reused === 1 ? ' was' : 's were'
            ));
        }

        foreach ($errors as $error) {
            Flash::error($error);
        }
    }

    /** Uploading a photo and uploading a document are separate permissions. */
    private static function mayUpload(string $type): bool
    {
        return Auth::can($type === 'photo' ? 'media.photo.upload' : 'media.manual.upload');
    }

    private static function type(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, MediaLibrary::TYPES, true) ? $value : null;
    }

    /** Send a stored file, with the same guards the other file routes use. */
    private function stream(string $relativePath, string $mime, string $filename, bool $download): void
    {
        $path = Upload::absolutePath($relativePath);

        if ($path === null) {
            $this->notFound('The file is missing from the server. It may need uploading again.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header(sprintf(
            'Content-Disposition: %s; filename="%s"',
            $download ? 'attachment' : 'inline',
            str_replace('"', '', Upload::displayName($filename))
        ));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=600');
        header_remove('Pragma');

        readfile($path);
        exit;
    }
}
