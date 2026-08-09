<?php
/**
 * Bare layout for print views (barcode labels). No navigation, no footer —
 * what you see is what comes out of the printer.
 *
 * @var string $content
 * @var string $pageTitle
 * @var string $appName
 * @var string $backUrl
 */
?>
<!doctype html>
<html lang="en-GB" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Print') ?> · <?= e($appName ?? '') ?></title>
    <link rel="icon" href="<?= e(asset_url('favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset_url('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('css/print.css')) ?>">
</head>
<body class="print-body">

<div class="print-toolbar no-print">
    <a class="btn btn-ghost" href="<?= e(url($backUrl ?? '/assets')) ?>">&larr; Back</a>
    <span class="muted"><?= e($pageTitle ?? '') ?></span>
    <button type="button" class="btn btn-primary" data-print>Print</button>
</div>

<?= $content ?>

<script src="<?= e(asset_url('js/app.js')) ?>" defer></script>
</body>
</html>
