<?php
/**
 * Main application layout.
 *
 * @var string $content
 * @var string $pageTitle
 * @var string $appName
 */
$pageTitle = $pageTitle ?? '';
?>
<!doctype html>
<html lang="en-GB" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#0f172a">
    <title><?= e($pageTitle !== '' ? $pageTitle . ' · ' . $appName : $appName) ?></title>
    <link rel="icon" href="<?= e(asset_url('favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset_url('css/app.css')) ?>">
    <script>
        // Applied before first paint so the page never flashes the wrong theme.
        (function () {
            try {
                var stored = localStorage.getItem('theme');
                if (!stored) {
                    var match = document.cookie.match(/(?:^|;\s*)theme=(light|dark)/);
                    stored = match ? match[1] : null;
                }
                if (!stored) {
                    stored = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                document.documentElement.setAttribute('data-theme', stored);
            } catch (e) { /* theme stays light */ }
        })();
    </script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<?= partial('partials/nav') ?>

<main id="main" class="container">
    <?= partial('partials/flash') ?>
    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="container footer-inner">
        <span><?= e($appName) ?></span>
        <?php if (auth_user() !== null): ?>
            <span class="muted">Signed in as <?= e(auth_user()['name']) ?> · <?= e(auth_user()['role_name']) ?></span>
        <?php endif; ?>
    </div>
</footer>

<script src="<?= e(asset_url('js/app.js')) ?>" defer></script>
</body>
</html>
