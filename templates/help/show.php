<?php

/**
 * One documentation page, with the contents list beside it.
 *
 * The Markdown comes from /docs, the same files that ship with the source, so
 * the help in the application and the help in the repository cannot drift.
 *
 * @var string $title
 * @var string $slug
 * @var string $source
 * @var array<int,array{label:?string,pages:array<int,array{slug:string,title:string}>}> $contents
 */

/* Links between documents are written as `installation.md` so they work when
   the files are read on disk; here they point at the matching route. */
$rewrite = static function (string $target): string {
    if (preg_match('#^(https?:)?//|^mailto:#i', $target) === 1) {
        return $target;
    }

    if (str_starts_with($target, '#')) {
        return $target;
    }

    [$path, $fragment] = array_pad(explode('#', $target, 2), 2, null);
    $path = preg_replace('/\.md$/i', '', (string) $path) ?? (string) $path;

    if ($path === '' || strcasecmp($path, 'README') === 0) {
        $path = '/help';
    } else {
        $path = '/help/' . basename($path);
    }

    return url($path) . ($fragment !== null && $fragment !== '' ? '#' . $fragment : '');
};
?>
<div class="page-head">
    <div>
        <h1>Help</h1>
        <p class="muted">Documentation for <?= e(config('app.full_name', 'Kitwell by Junction')) ?>.</p>
    </div>
</div>

<div class="help-layout">
    <nav class="help-contents card" aria-label="Documentation contents">
        <h2>Contents</h2>
        <ul>
            <li<?= $slug === 'README' ? ' class="current"' : '' ?>>
                <a href="<?= e(url('/help')) ?>"<?= $slug === 'README' ? ' aria-current="page"' : '' ?>>Overview</a>
            </li>
        </ul>

        <?php foreach ($contents as $group): ?>
            <?php if ($group['label'] !== null): ?>
                <h3 class="help-contents-group"><?= e($group['label']) ?></h3>
            <?php endif; ?>
            <ul>
                <?php foreach ($group['pages'] as $page): ?>
                    <li<?= $slug === $page['slug'] ? ' class="current"' : '' ?>>
                        <a href="<?= e(url('/help/' . $page['slug'])) ?>"<?= $slug === $page['slug'] ? ' aria-current="page"' : '' ?>>
                            <?= e($page['title']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </nav>

    <article class="help-page card prose">
        <?= markdown($source, $rewrite) ?>
    </article>
</div>
