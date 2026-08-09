<?php

declare(strict_types=1);

namespace App\Core;

use GdImage;

/**
 * Image processing for condition photos.
 *
 * Everything here degrades gracefully: if the GD extension is missing the
 * upload still succeeds and the original is served as-is. That matters on
 * modest shared hosting, where GD is usual but not guaranteed.
 *
 * Two jobs on upload:
 *   1. Straighten the picture. Phones record orientation in EXIF rather than
 *      rotating the pixels, so an un-processed photo often appears sideways.
 *   2. Bring the size down. A modern phone camera produces 4–12 MB files;
 *      re-saving at a sane maximum keeps the gallery usable over 4G in a
 *      workshop without throwing away detail that matters for condition.
 */
final class Image
{
    /** Longest edge kept for the stored original, in pixels. */
    public const MAX_DIMENSION = 2400;

    /** Longest edge of a generated thumbnail, in pixels. */
    public const THUMB_DIMENSION = 480;

    public static function isSupported(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /** Mime types this build of PHP can actually process. */
    public static function processableMimes(): array
    {
        if (!self::isSupported()) {
            return [];
        }

        $mimes = [];

        if (function_exists('imagecreatefromjpeg')) {
            $mimes[] = 'image/jpeg';
        }
        if (function_exists('imagecreatefrompng')) {
            $mimes[] = 'image/png';
        }
        if (function_exists('imagecreatefromwebp')) {
            $mimes[] = 'image/webp';
        }

        return $mimes;
    }

    public static function canProcess(string $mime): bool
    {
        return in_array($mime, self::processableMimes(), true);
    }

    /**
     * Straighten and (if needed) shrink an image in place.
     *
     * @return array{width:int|null,height:int|null,taken_at:string|null}
     */
    public static function normalise(string $path, string $mime): array
    {
        $takenAt = self::takenAt($path, $mime);

        if (!self::canProcess($mime)) {
            $size = @getimagesize($path);

            return [
                'width'    => $size === false ? null : (int) $size[0],
                'height'   => $size === false ? null : (int) $size[1],
                'taken_at' => $takenAt,
            ];
        }

        $image = self::load($path, $mime);

        if ($image === null) {
            return ['width' => null, 'height' => null, 'taken_at' => $takenAt];
        }

        $image = self::applyExifOrientation($image, $path, $mime);

        $width  = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest > self::MAX_DIMENSION) {
            $scale  = self::MAX_DIMENSION / $longest;
            $resized = self::resample($image, (int) round($width * $scale), (int) round($height * $scale));

            imagedestroy($image);
            $image  = $resized;
            $width  = imagesx($image);
            $height = imagesy($image);
        }

        $saved = self::save($image, $path, $mime);
        imagedestroy($image);

        if (!$saved) {
            // The file on disk is still the untouched upload, so report its
            // real dimensions rather than the ones we intended to write.
            $size = @getimagesize($path);

            return [
                'width'    => $size === false ? null : (int) $size[0],
                'height'   => $size === false ? null : (int) $size[1],
                'taken_at' => $takenAt,
            ];
        }

        return ['width' => $width, 'height' => $height, 'taken_at' => $takenAt];
    }

    /**
     * Write a thumbnail alongside the original.
     *
     * @return string|null The thumbnail path relative to the uploads root, or
     *                     null when one could not be produced.
     */
    public static function thumbnail(string $relativePath, string $mime): ?string
    {
        if (!self::canProcess($mime)) {
            return null;
        }

        $source = Upload::absolutePath($relativePath);
        if ($source === null) {
            return null;
        }

        $image = self::load($source, $mime);
        if ($image === null) {
            return null;
        }

        // A thumbnail is a nicety; never let it break an upload.
        $width   = imagesx($image);
        $height  = imagesy($image);
        $longest = max($width, $height);
        $scale   = $longest > self::THUMB_DIMENSION ? self::THUMB_DIMENSION / $longest : 1.0;

        $thumb = self::resample($image, (int) round($width * $scale), (int) round($height * $scale));
        imagedestroy($image);

        // storage/uploads/assets/12/photos/x.jpg -> .../photos/thumbs/x.jpg
        $directory = dirname($relativePath) . '/thumbs';
        $absolute  = (string) Config::get('storage.uploads') . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $directory);

        try {
            Upload::ensureDirectory($absolute);
        } catch (\Throwable $e) {
            error_log('Image: could not create the thumbnail directory: ' . $e->getMessage());
            imagedestroy($thumb);

            return null;
        }

        $target = $absolute . DIRECTORY_SEPARATOR . basename($relativePath);
        $ok     = self::save($thumb, $target, $mime, 78);
        imagedestroy($thumb);

        if (!$ok) {
            return null;
        }

        @chmod($target, 0640);

        return $directory . '/' . basename($relativePath);
    }

    /** Read the capture date from EXIF, so the timeline reflects reality. */
    public static function takenAt(string $path, string $mime): ?string
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return null;
        }

        $exif = @exif_read_data($path);
        if ($exif === false) {
            return null;
        }

        foreach (['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'] as $key) {
            if (empty($exif[$key])) {
                continue;
            }

            $date = \DateTimeImmutable::createFromFormat('Y:m:d H:i:s', (string) $exif[$key]);

            // Ignore nonsense: a camera with a flat clock often reports 1970.
            if ($date instanceof \DateTimeImmutable
                && $date->format('Y') > '2000'
                && $date->getTimestamp() <= time() + 86400) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    private static function load(string $path, string $mime): ?GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => false,
        };

        return $image === false ? null : $image;
    }

    /**
     * Write an image to disk.
     *
     * Warnings are suppressed and turned into a false return: a failure here
     * (full disk, awkward permissions, a host with a broken GD build) must not
     * cost the user the photo they just took. The original upload is already
     * safely on disk by this point, so the caller keeps it as-is.
     */
    private static function save(GdImage $image, string $path, string $mime, int $quality = 82): bool
    {
        $ok = match ($mime) {
            'image/jpeg' => @imagejpeg($image, $path, $quality),
            'image/png'  => @imagepng($image, $path, 6),
            'image/webp' => @imagewebp($image, $path, $quality),
            default      => false,
        };

        if (!$ok) {
            error_log(sprintf('Image: could not write %s (%s). Keeping the original as uploaded.', $path, $mime));
        }

        return $ok;
    }

    private static function resample(GdImage $source, int $width, int $height): GdImage
    {
        $width  = max(1, $width);
        $height = max(1, $height);

        $target = imagecreatetruecolor($width, $height);

        // Keep transparency for PNG/WebP rather than turning it black.
        imagealphablending($target, false);
        imagesavealpha($target, true);

        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $target;
    }

    /**
     * Rotate/flip according to the EXIF orientation tag, then the pixels match
     * what the photographer saw.
     */
    private static function applyExifOrientation(GdImage $image, string $path, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        if ($exif === false || empty($exif['Orientation'])) {
            return $image;
        }

        $orientation = (int) $exif['Orientation'];

        // imagerotate() turns anti-clockwise.
        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, 270, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($rotated !== $image && $rotated !== false) {
            imagedestroy($image);
            $image = $rotated;
        }

        // The mirrored orientations (2, 4, 5, 7) are rare but cheap to fix.
        if (in_array($orientation, [2, 4, 5, 7], true) && function_exists('imageflip')) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }
}
