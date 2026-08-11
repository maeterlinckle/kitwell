<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Upload;
use App\Services\Branding;

/**
 * Serves the uploaded logo.
 *
 * Deliberately public: the sign-in page carries the logo, and nobody has a
 * session yet when it is drawn. A logo is the least secret thing an
 * organisation owns — it is on their van — and the route exposes nothing else:
 * it takes no id, reads one of two settings, and can only ever return an image
 * an administrator chose to publish.
 */
final class BrandingController extends Controller
{
    public function logo(string $variant): void
    {
        $path = Branding::resolve($variant);

        if ($path === null) {
            $this->notFound('No logo has been uploaded.');
        }

        $absolute = Upload::absolutePath($path);

        if ($absolute === null) {
            $this->notFound('The logo file is missing from the server.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . Branding::mime($variant === 'dark' ? 'dark' : 'light'));
        header('Content-Length: ' . (string) filesize($absolute));
        header('X-Content-Type-Options: nosniff');

        // The URL carries a fingerprint of the stored path, so a replaced logo
        // arrives under a new URL and this can be cached hard.
        header('Cache-Control: public, max-age=2592000, immutable');
        header_remove('Pragma');

        readfile($absolute);
        exit;
    }
}
