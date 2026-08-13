<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\View;
use App\Services\HelpSettings;
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
            // A page documents configurable values as `{{setting:key}}` so the
            // file stays readable on disk; here they become what this site has
            // actually got set.
            'source'    => HelpSettings::resolve($source),
            'contents'  => self::contents(),
        ]);
    }

    /**
     * The contents panel, grouped and ordered exactly as the index page groups
     * and orders its links — so the sidebar and README.md cannot disagree, and
     * moving a page between the user and Administration halves is one edit.
     *
     * A page the index does not mention is appended to the last group, so a new
     * file is never invisible.
     *
     * @return array<int,array{label:?string,pages:array<int,array{slug:string,title:string}>}>
     */
    private static function contents(): array
    {
        $directory = self::directory();
        $pages     = [];

        foreach (glob($directory . '/*.md') ?: [] as $file) {
            $slug = basename($file, '.md');

            if ($slug === self::INDEX) {
                continue;
            }

            $source = (string) file_get_contents($file);
            $pages[$slug] = ['slug' => $slug, 'title' => Markdown::title($source) ?? ucfirst($slug)];
        }

        $index  = (string) @file_get_contents($directory . '/' . self::INDEX . '.md');
        $groups = [];

        // Each `## Heading` in the index opens a group; the .md links beneath it
        // are its pages. Anything linked before the first heading is ungrouped.
        foreach (preg_split('/^## /m', str_replace("\r\n", "\n", $index)) ?: [] as $position => $section) {
            $label = $position === 0 ? null : trim(strtok($section, "\n") ?: '');
            $found = [];

            if (preg_match_all('/]\(([a-z0-9-]+)\.md(?:#[^)]*)?\)/', $section, $matches) > 0) {
                foreach ($matches[1] as $slug) {
                    if (isset($pages[$slug])) {
                        $found[] = $pages[$slug];
                        unset($pages[$slug]);
                    }
                }
            }

            if ($found !== []) {
                $groups[] = ['label' => $label, 'pages' => $found];
            }
        }

        if ($pages !== []) {
            ksort($pages);

            if ($groups === []) {
                $groups[] = ['label' => null, 'pages' => []];
            }

            $last = array_key_last($groups);
            $groups[$last]['pages'] = array_merge($groups[$last]['pages'], array_values($pages));
        }

        return $groups;
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
