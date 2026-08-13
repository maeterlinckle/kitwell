<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Image;
use App\Core\Upload;
use App\Models\MediaLibrary;

/**
 * The one way a file gets into the shared media library.
 *
 * Every upload is validated by its contents, hashed, and only then stored. If
 * the hash matches something already held, the existing item is returned and
 * nothing is written to disk — so uploading the same manual against a second
 * asset costs a join row.
 *
 * Library files live under `library/`, not under any one asset's directory:
 * an item belongs to no single asset, and a path saying otherwise would be a
 * lie the moment it were attached to a second one.
 */
final class MediaIntake
{
    private const DIRECTORY = 'library';

    /**
     * Take one uploaded file into the library.
     *
     * @param array{name:string,tmp_name:string,error:int,size:int} $file
     * @param 'photo'|'document' $type
     * @return array{media:array<string,mixed>,created:bool}|string  The item and
     *         whether it is new, or an error message.
     */
    public static function store(array $file, string $type, string $title = '', string $description = ''): array|string
    {
        $error = self::validate($file, $type);

        if ($error !== null) {
            return $error;
        }

        // Hash before the file moves: an identical upload is an attachment, not
        // a second copy.
        $hash     = hash_file('sha256', $file['tmp_name']);
        $existing = $hash === false ? null : MediaLibrary::findByHash($hash);

        if ($existing !== null) {
            return ['media' => $existing, 'created' => false];
        }

        $displayName = Upload::displayName($file['name']);
        $extension   = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));
        $path        = Upload::store($file, self::DIRECTORY, $extension !== '' ? $extension : 'bin');
        $mime        = (string) (Upload::detectMime((string) Upload::absolutePath($path)) ?: 'application/octet-stream');

        $width     = null;
        $height    = null;
        $thumbnail = null;

        if ($type === 'photo') {
            $absolute = Upload::absolutePath($path);

            if ($absolute !== null) {
                $normalised = Image::normalise($absolute, $mime);
                $width      = $normalised['width'];
                $height     = $normalised['height'];
                $thumbnail  = Image::thumbnail($path, $mime);

                // Normalising rewrites the pixels, so the hash taken before the
                // move no longer describes what is on disk.
                $rehashed = hash_file('sha256', $absolute);
                $hash     = $rehashed === false ? $hash : $rehashed;

                // A second upload of the same photo now lands on the same
                // normalised bytes, which may already be held.
                $duplicate = MediaLibrary::findByHash((string) $hash);

                if ($duplicate !== null) {
                    Upload::delete($path);

                    if ($thumbnail !== null) {
                        Upload::delete($thumbnail);
                    }

                    return ['media' => $duplicate, 'created' => false];
                }
            }
        }

        $size = Upload::absolutePath($path) !== null
            ? (int) filesize((string) Upload::absolutePath($path))
            : (int) $file['size'];

        $id = MediaLibrary::create([
            'media_type'        => $type,
            'title'             => mb_substr(self::title($title, $displayName), 0, 191),
            'description'       => $description !== '' ? mb_substr($description, 0, 500) : null,
            'file_path'         => $path,
            'original_filename' => $displayName,
            'mime_type'         => $mime,
            'file_size_bytes'   => $size,
            'file_hash'         => $hash === false ? null : $hash,
            'thumbnail_path'    => $thumbnail,
            'width_px'          => $width,
            'height_px'         => $height,
            'uploaded_by'       => Auth::id(),
        ]);

        $media = MediaLibrary::find($id);

        return $media === null
            ? 'The file was stored but could not be read back.'
            : ['media' => $media, 'created' => true];
    }

    /**
     * Validate an upload against the limits for its type.
     *
     * @param array{name:string,tmp_name:string,error:int,size:int} $file
     */
    public static function validate(array $file, string $type): ?string
    {
        if ($type === 'photo') {
            return Upload::validate(
                $file,
                (array) Config::get('uploads.photo_mimes'),
                (array) Config::get('uploads.photo_extensions'),
                (int) Config::get('uploads.max_photo_bytes')
            );
        }

        return Upload::validate(
            $file,
            (array) Config::get('uploads.pdf_mimes'),
            (array) Config::get('uploads.pdf_extensions'),
            (int) Config::get('uploads.max_pdf_bytes')
        );
    }

    /**
     * Remove an item and its files, but only once nothing references it.
     *
     * @return bool False when it is still attached somewhere.
     */
    public static function forget(int $mediaId): bool
    {
        $media = MediaLibrary::find($mediaId);

        if ($media === null) {
            return false;
        }

        if (MediaLibrary::assetCount($mediaId) > 0 || MediaLibrary::templateCount($mediaId) > 0) {
            return false;
        }

        Upload::delete((string) $media['file_path']);

        if (!empty($media['thumbnail_path'])) {
            Upload::delete((string) $media['thumbnail_path']);
        }

        MediaLibrary::delete($mediaId);

        return true;
    }

    /** Fill in the hash of anything stored before one was recorded. */
    public static function backfillHashes(): int
    {
        $filled = 0;

        foreach (MediaLibrary::withoutHash() as $row) {
            $absolute = Upload::absolutePath((string) $row['file_path']);

            if ($absolute === null) {
                continue;
            }

            $hash = hash_file('sha256', $absolute);

            if ($hash === false || MediaLibrary::findByHash($hash) !== null) {
                continue; // an identical file is already recorded under its hash
            }

            MediaLibrary::update((int) $row['id'], ['file_hash' => $hash]);
            $filled++;
        }

        return $filled;
    }

    private static function title(string $given, string $filename): string
    {
        $given = trim($given);

        if ($given !== '') {
            return $given;
        }

        $base = pathinfo($filename, PATHINFO_FILENAME);

        return $base !== '' ? str_replace(['_', '-'], ' ', $base) : $filename;
    }
}
