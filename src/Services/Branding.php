<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Upload;
use App\Models\Setting;

/**
 * The uploaded logo, and everything that needs to reach it.
 *
 * One place, because the same two files have to appear in four very different
 * contexts: the site header (an <img> over HTTP), the login page (before anyone
 * has signed in), printed paperwork (light variant, because paper is white) and
 * outbound email (embedded in the message — a mail client cannot fetch a URL
 * from a server that is usually not on the public internet).
 *
 * Both variants are independently optional. Where the variant for the current
 * theme is missing the other stands in: one logo is better than none, and a
 * workshop that has only ever made a light one should not lose it in dark mode.
 */
final class Branding
{
    public const VARIANTS = ['light', 'dark'];

    private const DIRECTORY = 'branding';

    /** Uploads are small: a logo is a logo, not a photograph. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Raster only, deliberately. An SVG is a document that can carry script, so
     * serving one from this origin would hand an administrator an XSS vector;
     * and fileinfo identifies SVGs inconsistently (text/plain, text/xml,
     * image/svg+xml depending on the file), which would make the upload fail
     * for reasons nobody could act on. PNG at twice the display height looks
     * identical in the header and on paper.
     */
    public const MIMES      = ['image/png', 'image/jpeg', 'image/webp'];
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /** The stored relative path for a variant, or null if none is set. */
    public static function path(string $variant): ?string
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            return null;
        }

        $path = Setting::get('logo_' . $variant . '_path');

        if ($path === null || $path === '') {
            return null;
        }

        // A setting can outlive the file it names — a restore from a database
        // dump without the uploads directory, say. Treat a missing file as no
        // logo rather than rendering a broken image on every page.
        return Upload::absolutePath($path) === null ? null : $path;
    }

    /** The variant actually used for a theme, falling back to the other. */
    public static function resolve(string $variant): ?string
    {
        $other = $variant === 'light' ? 'dark' : 'light';

        return self::path($variant) ?? self::path($other);
    }

    public static function mime(string $variant): string
    {
        return (string) (Setting::get('logo_' . $variant . '_mime') ?? 'image/png');
    }

    /**
     * A URL for the header. Carries a fingerprint of the stored path so a
     * replaced logo is not served from cache for a month.
     */
    public static function url(string $variant): ?string
    {
        $path = self::resolve($variant);

        if ($path === null) {
            return null;
        }

        return url('/branding/logo/' . $variant . '?v=' . substr(md5($path), 0, 8));
    }

    /** The absolute path of the light logo, for print and email. */
    public static function printablePath(): ?string
    {
        $path = self::resolve('light');

        return $path === null ? null : Upload::absolutePath($path);
    }

    public static function hasAny(): bool
    {
        return self::path('light') !== null || self::path('dark') !== null;
    }

    /**
     * Take one variant from the request, if a file was chosen for it.
     *
     * Receiving the upload and checking it live in the same place on purpose:
     * a controller that pulls files out of the request but leaves the checking
     * to somebody else is exactly the shape that hides a missing check, and
     * tests/security-audit.php will not accept it either.
     *
     * @return array{provided:bool,error:?string}
     */
    public static function acceptUpload(string $variant): array
    {
        $files = Upload::files('logo_' . $variant);

        if ($files === []) {
            return ['provided' => false, 'error' => null];
        }

        return ['provided' => true, 'error' => self::store($variant, $files[0])];
    }

    /**
     * Replace one variant. Returns an error string, or null on success.
     *
     * @param array<string,mixed> $file A single entry from Upload::files()
     */
    private static function store(string $variant, array $file): ?string
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            return 'Unknown logo variant.';
        }

        $problem = Upload::validate($file, self::MIMES, self::EXTENSIONS, self::MAX_BYTES);

        if ($problem !== null) {
            return $problem;
        }

        $extension = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $stored = Upload::store($file, self::DIRECTORY, $extension);

        // Delete the file being replaced only once the new one is safely on
        // disk, so a failed write cannot leave the site with no logo at all.
        $previous = Setting::get('logo_' . $variant . '_path');

        Setting::put('logo_' . $variant . '_path', $stored);
        Setting::put('logo_' . $variant . '_mime', (string) (Upload::detectMime(
            (string) Upload::absolutePath($stored)
        ) ?? 'application/octet-stream'));

        if ($previous !== null && $previous !== '' && $previous !== $stored) {
            Upload::delete($previous);
        }

        return null;
    }

    public static function remove(string $variant): void
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            return;
        }

        $previous = Setting::get('logo_' . $variant . '_path');

        Setting::put('logo_' . $variant . '_path', null);
        Setting::put('logo_' . $variant . '_mime', null);

        if ($previous !== null && $previous !== '') {
            Upload::delete($previous);
        }
    }
}
