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
use App\Models\AssetPhoto;

/**
 * Condition photos: a dated visual history for any asset, added at any point
 * in its life — not just at registration.
 *
 * Files live outside the document root and are streamed through PHP, so a
 * photo is only reachable by someone signed in who can view the asset.
 */
final class PhotoController extends Controller
{
    /** The full photo history for one asset, grouped by month. */
    public function index(string $assetId): void
    {
        $id    = (int) $assetId;
        $asset = Asset::find($id);

        if ($asset === null) {
            $this->notFound();
        }

        $photos = AssetPhoto::forAsset($id);

        $this->view('assets/photos', [
            'pageTitle' => 'Photos · ' . $asset['asset_tag'],
            'asset'     => $asset,
            'photos'    => $photos,
            'byMonth'   => AssetPhoto::groupByMonth($photos),
        ]);
    }

    /**
     * Store condition photos against one asset.
     *
     * Static and public because the Add asset form needs exactly this, not
     * something almost like it: a photo taken while registering an item is the
     * same kind of record as one taken next week. It never touches the shared
     * media library — a condition photo describes one physical unit at one
     * moment and belongs to that asset alone.
     *
     * @param array<int,array{name:string,tmp_name:string,error:int,size:int}> $files
     * @return array{0:int,1:array<int,string>} stored, errors
     */
    public static function intake(int $assetId, array $files, string $caption = '', string $takenOn = ''): array
    {
        $maxBytes   = (int) Config::get('uploads.max_photo_bytes');
        $mimes      = (array) Config::get('uploads.photo_mimes');
        $extensions = (array) Config::get('uploads.photo_extensions');

        $stored = 0;
        $errors = [];

        foreach ($files as $file) {
            $error = Upload::validate($file, $mimes, $extensions, $maxBytes);

            if ($error !== null) {
                $errors[] = $error;
                continue;
            }

            $displayName = Upload::displayName($file['name']);
            $mime        = (string) Upload::detectMime($file['tmp_name']);
            $extension   = self::extensionFor($mime, $displayName);

            $path = Upload::store($file, 'assets/' . $assetId . '/photos', $extension);

            // Straighten and shrink, then make a thumbnail. Both steps are
            // no-ops without GD, and the photo still uploads.
            $absolute = Upload::absolutePath($path);
            $meta     = $absolute === null
                ? ['width' => null, 'height' => null, 'taken_at' => null]
                : Image::normalise($absolute, $mime);

            $thumbnail = Image::thumbnail($path, $mime);

            // Date precedence: what the user typed, then the camera's EXIF
            // capture date, then now.
            $takenAt = null;
            if ($takenOn !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $takenOn) === 1) {
                $takenAt = $takenOn . ' ' . date('H:i:s');
            } elseif ($meta['taken_at'] !== null) {
                $takenAt = $meta['taken_at'];
            }

            AssetPhoto::create([
                'asset_id'          => $assetId,
                'file_path'         => $path,
                'thumbnail_path'    => $thumbnail,
                'original_filename' => $displayName,
                'mime_type'         => $mime,
                'file_size_bytes'   => $absolute !== null ? (int) filesize($absolute) : (int) $file['size'],
                'width_px'          => $meta['width'],
                'height_px'         => $meta['height'],
                'caption'           => $caption !== '' ? mb_substr($caption, 0, 255) : null,
                'taken_at'          => $takenAt,
                'is_primary'        => AssetPhoto::countForAsset($assetId) === 0 ? 1 : 0,
                'uploaded_by'       => Auth::id(),
            ]);

            $stored++;
        }

        return [$stored, $errors];
    }

    public function store(string $assetId): void
    {
        $id    = (int) $assetId;
        $asset = Asset::find($id);

        if ($asset === null) {
            $this->notFound();
        }

        $files = Upload::files('photos');

        if ($files === []) {
            Flash::error('No photo was selected.');
            Response::redirect('/assets/' . $id . '#photos');
        }

        [$stored, $errors] = self::intake(
            $id,
            $files,
            trim((string) Request::post('caption', '')),
            trim((string) Request::post('taken_on', ''))
        );

        if ($stored > 0) {
            ActivityLog::record(
                'photo_uploaded',
                'asset',
                $id,
                sprintf('Added %d condition photo%s to %s', $stored, $stored === 1 ? '' : 's', $asset['asset_tag'])
            );

            Flash::success(sprintf('%d photo%s added.', $stored, $stored === 1 ? '' : 's'));
        }

        foreach ($errors as $error) {
            Flash::error($error);
        }

        Response::redirect('/assets/' . $id . '#photos');
    }

    /** Stream a photo. `?size=thumb` serves the thumbnail where one exists. */
    public function show(string $assetId, string $photoId): void
    {
        $photo = AssetPhoto::find((int) $photoId);

        if ($photo === null || (int) $photo['asset_id'] !== (int) $assetId) {
            $this->notFound('That photo is no longer attached to this asset.');
        }

        $wantsThumb = Request::query('size') === 'thumb';
        $relative   = ($wantsThumb && !empty($photo['thumbnail_path']))
            ? (string) $photo['thumbnail_path']
            : (string) $photo['file_path'];

        $path = Upload::absolutePath($relative);

        // A missing thumbnail should never break the gallery — fall back.
        if ($path === null && $wantsThumb) {
            $path = Upload::absolutePath((string) $photo['file_path']);
        }

        if ($path === null) {
            $this->notFound('The image file is missing from the server.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . (string) $photo['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', Upload::displayName((string) $photo['original_filename'])) . '"');
        header('X-Content-Type-Options: nosniff');

        // Uploaded images never change, so they can be cached hard. Private,
        // because they are only for signed-in users.
        header('Cache-Control: private, max-age=2592000, immutable');
        header_remove('Pragma');

        readfile($path);
        exit;
    }

    /** Edit a caption or correct the date the photo represents. */
    public function update(string $assetId, string $photoId): void
    {
        $id    = (int) $assetId;
        $photo = AssetPhoto::find((int) $photoId);

        if ($photo === null || (int) $photo['asset_id'] !== $id) {
            $this->notFound('That photo is no longer attached to this asset.');
        }

        $data = $this->validate([
            'caption'  => 'max:255',
            'taken_on' => 'date',
        ], ['caption' => 'Caption', 'taken_on' => 'Date taken'], '/assets/' . $id . '/photos');

        $takenAt = $photo['taken_at'];
        if ($data['taken_on'] !== '') {
            $existingTime = $photo['taken_at'] !== null ? date('H:i:s', (int) strtotime((string) $photo['taken_at'])) : '12:00:00';
            $takenAt      = $data['taken_on'] . ' ' . $existingTime;
        }

        AssetPhoto::update((int) $photo['id'], [
            'caption'  => $data['caption'] !== '' ? $data['caption'] : null,
            'taken_at' => $takenAt,
        ]);

        ActivityLog::record('photo_updated', 'asset', $id, 'Updated a photo caption/date');
        Flash::success('Photo details saved.');

        Response::redirect('/assets/' . $id . '/photos');
    }

    public function makePrimary(string $assetId, string $photoId): void
    {
        $id    = (int) $assetId;
        $photo = AssetPhoto::find((int) $photoId);

        if ($photo === null || (int) $photo['asset_id'] !== $id) {
            $this->notFound('That photo is no longer attached to this asset.');
        }

        AssetPhoto::makePrimary($id, (int) $photo['id']);

        ActivityLog::record('photo_primary', 'asset', $id, 'Changed the main photo');
        Flash::success('That photo is now the main image for this asset.');

        Response::back('/assets/' . $id . '#photos');
    }

    public function destroy(string $assetId, string $photoId): void
    {
        $id    = (int) $assetId;
        $photo = AssetPhoto::find((int) $photoId);

        if ($photo === null || (int) $photo['asset_id'] !== $id) {
            $this->notFound('That photo has already been removed.');
        }

        Upload::delete((string) $photo['file_path']);

        if (!empty($photo['thumbnail_path'])) {
            Upload::delete((string) $photo['thumbnail_path']);
        }

        $wasPrimary = (int) $photo['is_primary'] === 1;
        AssetPhoto::delete((int) $photo['id']);

        // Promote the next most recent photo so listings keep a thumbnail.
        if ($wasPrimary) {
            $next = AssetPhoto::primaryFor($id);
            if ($next !== null) {
                AssetPhoto::makePrimary($id, (int) $next['id']);
            }
        }

        ActivityLog::record('photo_deleted', 'asset', $id, 'Removed a condition photo');
        Flash::success('Photo removed.');

        Response::back('/assets/' . $id . '#photos');
    }

    /** Pick a file extension from the sniffed type, not the supplied name. */
    private static function extensionFor(string $mime, string $filename): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            default      => strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'jpg',
        };
    }
}
