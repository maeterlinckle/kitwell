<?php

use App\Services\Branding;

/**
 * The logo (or the fallback mark) plus the wordmark.
 *
 * Both variants are rendered when both exist and CSS picks by theme, rather
 * than the server choosing one: the theme lives in a `data-theme` attribute the
 * user can flip without a page load, so a server-side choice would show the
 * wrong logo until the next navigation.
 *
 * The wordmark stays whatever happens — a logo replaces the icon box, not the
 * name of the thing you are looking at.
 *
 * @var string $appName
 */
$light = Branding::url('light');
$dark  = Branding::url('dark');
$name  = (string) ($appName ?? config('app.name', 'Asset Register'));
?>
<?php if ($light !== null || $dark !== null): ?>
    <span class="brand-logo-wrap">
        <?php if ($light !== null): ?>
            <img class="brand-logo brand-logo-light" src="<?= e($light) ?>" alt="<?= e($name) ?>">
        <?php endif; ?>
        <?php if ($dark !== null && $dark !== $light): ?>
            <img class="brand-logo brand-logo-dark" src="<?= e($dark) ?>" alt="<?= e($name) ?>">
        <?php endif; ?>
    </span>
<?php else: ?>
    <span class="brand-mark" aria-hidden="true"><?= e(config('app.mark', 'KW')) ?></span>
<?php endif; ?>
<span class="brand-name"><?= e($name) ?></span>
