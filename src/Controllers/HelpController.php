<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\View;
use App\Services\Markdown;


/**
 * Serves the documentation in /docs inside the application, so Help in the
 * menu opens the same pages that ship with the source.
 */
final class HelpController extends Controller
{
    /** The index page, and the slug used when none is given. */
    private const INDEX = 'README';

    public function index(): void
    {
        $this->show(self::INDEX);
    }

    public function show(string $page): void
    {
        $slug = $page === self::INDEX ? self::INDEX : strtolower($page);
        $path = self::path($slug);

        if ($path === null) {
            $this->notFound('There is no help page by that name. Try Settings → Help for the index.');
        }

        $source = (string) file_get_contents($path);
        $title  = Markdown::title($source) ?? 'Help';

        View::render('help/show', [
            'pageTitle' => $title . ' · Help',
            'title'     => $title,
            'slug'      => $slug,
            'source'    => $source,
            'contents'  => self::contents(),
        ]);
    }

    /**
     * Every page in /docs, in the order the index lists them, with anything the
     * index does not mention appended alphabetically.
     *
     * @return array<int,array{slug:string,title:string}>
     */
    private static function contents(): array
    {
        $directory = self::directory();
        $files     = glob($directory . '/*.md') ?: [];
        $pages     = [];

        foreach ($files as $file) {
            $slug = basename($file, '.md');

            if ($slug === self::INDEX) {
                continue;
            }

            $source = (string) file_get_contents($file);
            $pages[$slug] = ['slug' => $slug, 'title' => Markdown::title($source) ?? ucfirst($slug)];
        }

        $index  = (string) @file_get_contents($directory . '/' . self::INDEX . '.md');
        $sorted = [];

        if (preg_match_all('/]\(([a-z0-9-]+)\.md\)/', $index, $matches) > 0) {
            foreach ($matches[1] as $slug) {
                if (isset($pages[$slug])) {
                    $sorted[] = $pages[$slug];
                    unset($pages[$slug]);
                }
            }
        }

        ksort($pages);

        return array_merge($sorted, array_values($pages));
    }

    /** The file for a slug, or null when there is no such page. */
    private static function path(string $slug): ?string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $slug) !== 1) {
            return null;
        }

        $path = self::directory() . '/' . $slug . '.md';

        return is_file($path) ? $path : null;
    }

    private static function directory(): string
    {
        return (string) Config::get('app.root') . '/docs';
    }
}
