<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Validated file uploads.
 *
 * Files are written under storage/uploads (outside the document root) with a
 * generated name — the original filename is kept in the database for display
 * only, so a hostile name can never reach the filesystem.
 */
final class Upload
{
    /**
     * Normalise PHP's $_FILES into a flat list, handling both single and
     * multiple (name="x[]") inputs.
     *
     * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public static function files(string $field): array
    {
        if (!isset($_FILES[$field])) {
            return [];
        }

        $file = $_FILES[$field];

        if (!is_array($file['name'])) {
            return $file['error'] === UPLOAD_ERR_NO_FILE ? [] : [$file];
        }

        $files = [];
        foreach (array_keys($file['name']) as $i) {
            if ((int) $file['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $files[] = [
                'name'     => (string) $file['name'][$i],
                'type'     => (string) $file['type'][$i],
                'tmp_name' => (string) $file['tmp_name'][$i],
                'error'    => (int) $file['error'][$i],
                'size'     => (int) $file['size'][$i],
            ];
        }

        return $files;
    }

    /**
     * Validate one uploaded file. Returns an error message, or null when it is
     * acceptable.
     *
     * @param array{name:string,tmp_name:string,error:int,size:int} $file
     * @param array<int,string> $allowedMimes
     * @param array<int,string> $allowedExtensions
     */
    public static function validate(array $file, array $allowedMimes, array $allowedExtensions, int $maxBytes): ?string
    {
        $name = self::displayName($file['name']);

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $name . ': ' . self::errorMessage($file['error']);
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            return $name . ': the upload could not be verified.';
        }

        if ($file['size'] <= 0) {
            return $name . ': the file is empty.';
        }

        if ($file['size'] > $maxBytes) {
            return sprintf('%s is %s — the limit is %s.', $name, self::formatBytes($file['size']), self::formatBytes($maxBytes));
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            return sprintf('%s: %s files are not allowed here.', $name, $extension === '' ? 'unnamed' : strtoupper($extension));
        }

        // Trust the file's contents, not the browser-supplied Content-Type.
        $detected = self::detectMime($file['tmp_name']);
        if ($detected === null || !in_array($detected, $allowedMimes, true)) {
            return sprintf('%s does not look like a valid %s file.', $name, strtoupper($extension));
        }

        return null;
    }

    /**
     * Move an uploaded file into place and return its path relative to the
     * uploads root (which is what gets stored in the database).
     *
     * @param array{name:string,tmp_name:string} $file
     */
    public static function store(array $file, string $relativeDirectory, string $extension): string
    {
        $root      = (string) Config::get('storage.uploads');
        $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim($relativeDirectory, '/'));

        self::ensureDirectory($directory);

        $filename = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . strtolower($extension);
        $target   = $directory . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new RuntimeException('Could not save the uploaded file. Check the permissions on the storage directory.');
        }

        @chmod($target, 0640);

        return trim($relativeDirectory, '/') . '/' . $filename;
    }

    /** Copy an already-stored file to another asset's directory. */
    public static function copy(string $relativePath, string $newRelativeDirectory): string
    {
        $root   = (string) Config::get('storage.uploads');
        $source = self::absolutePath($relativePath);

        if ($source === null) {
            throw new RuntimeException('The original file is missing.');
        }

        $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim($newRelativeDirectory, '/'));
        self::ensureDirectory($directory);

        $extension = pathinfo($source, PATHINFO_EXTENSION);
        $filename  = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . ($extension !== '' ? '.' . strtolower($extension) : '');
        $target    = $directory . DIRECTORY_SEPARATOR . $filename;

        if (!copy($source, $target)) {
            throw new RuntimeException('Could not copy the file.');
        }

        @chmod($target, 0640);

        return trim($newRelativeDirectory, '/') . '/' . $filename;
    }

    /**
     * Resolve a stored relative path to a real file inside the uploads root.
     * Returns null if it does not exist or escapes the root.
     */
    public static function absolutePath(string $relativePath): ?string
    {
        $root = realpath((string) Config::get('storage.uploads'));
        if ($root === false) {
            return null;
        }

        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));
        $real      = realpath($candidate);

        if ($real === false || !is_file($real)) {
            return null;
        }

        // Path traversal guard: the resolved file must sit inside the root.
        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    public static function delete(string $relativePath): void
    {
        $path = self::absolutePath($relativePath);

        if ($path !== null) {
            @unlink($path);
        }
    }

    public static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the upload directory: ' . $directory);
        }
    }

    public static function detectMime(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime === false ? null : $mime;
    }

    /** A safe version of the original filename, for display and downloads. */
    public static function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w \-.()\[\]]+/u', '_', $name) ?? 'file';

        return mb_substr($name, 0, 200);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return $bytes . ' bytes';
    }

    private static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'the file is larger than the server allows.',
            UPLOAD_ERR_PARTIAL                        => 'the upload was interrupted — please try again.',
            UPLOAD_ERR_NO_FILE                        => 'no file was received.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'the server has no temporary directory configured.',
            UPLOAD_ERR_CANT_WRITE                     => 'the server could not write the file to disk.',
            UPLOAD_ERR_EXTENSION                      => 'a server extension blocked the upload.',
            default                                   => 'the upload failed.',
        };
    }
}
