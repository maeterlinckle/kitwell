<?php

use App\Core\Barcode;

/**
 * A sheet of Code 128 barcode labels.
 *
 * @var array<int,array<string,mixed>> $assets
 * @var string $size
 * @var bool   $showName
 * @var bool   $showLocation
 * @var string|null $organisation
 */

// Module width and bar height per preset, in millimetres. Narrower modules fit
// more data but need a better printer — 0.33mm is the safe default for a
// 300dpi laser, which is what most offices have.
$presets = [
    'small'  => ['module' => 0.26, 'height' => 8.0],
    'medium' => ['module' => 0.33, 'height' => 12.0],
    'large'  => ['module' => 0.42, 'height' => 16.0],
];

$preset = $presets[$size] ?? $presets['medium'];

// Usable label width in mm, less the padding, per preset.
$labelWidths = ['small' => 46.0, 'medium' => 58.0, 'large' => 72.0];
$usableWidth = $labelWidths[$size] ?? $labelWidths['medium'];

/** Printed width of a Code 128 barcode: 11 modules per symbol, 13 for stop, plus quiet zones. */
$barcodeWidth = static function (string $value) use ($preset): float {
    $symbols = 0;
    foreach (App\Core\Barcode::encode($value) as $pattern) {
        $symbols += array_sum(array_map('intval', str_split($pattern)));
    }

    return ($symbols + 20) * $preset['module'];
};

$oversized = 0;
foreach ($assets as $labelAsset) {
    if (App\Core\Barcode::isEncodable((string) $labelAsset['asset_tag'])
        && $barcodeWidth((string) $labelAsset['asset_tag']) > $usableWidth) {
        $oversized++;
    }
}
?>
<div class="label-sheet label-<?= e($size) ?>">
    <?php foreach ($assets as $asset): ?>
        <?php
        $tag        = (string) $asset['asset_tag'];
        $encodable  = Barcode::isEncodable($tag);
        $locationText = trim((string) ($asset['location_parent_name'] ?? '') . ' ' . (string) ($asset['location_name'] ?? ''));
        ?>
        <div class="label">
            <?php if ($organisation !== null && $organisation !== ''): ?>
                <div class="label-org"><?= e($organisation) ?></div>
            <?php endif; ?>

            <div class="label-barcode">
                <?php if ($encodable): ?>
                    <?= Barcode::svg($tag, $preset['module'], $preset['height']) ?>
                <?php else: ?>
                    <span class="label-tag-fallback"><?= e($tag) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($showName): ?>
                <div class="label-name"><?= e(str_limit((string) $asset['name'], 40)) ?></div>
            <?php endif; ?>

            <?php if ($showLocation && $locationText !== ''): ?>
                <div class="label-location"><?= e($locationText) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="print-note no-print">
    <p class="muted">
        <?= count($assets) ?> label<?= count($assets) === 1 ? '' : 's' ?> · Code 128 ·
        size:
        <?php foreach (['small', 'medium', 'large'] as $option): ?>
            <?php if ($option === $size): ?>
                <strong><?= e($option) ?></strong>
            <?php else: ?>
                <a href="?<?= e(http_build_query(array_merge($_GET, ['size' => $option]))) ?>"><?= e($option) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </p>
    <p class="muted">
        Print at 100% scale (no “fit to page”) or the barcodes will not scan.
        Check one label with a scanner before running a full sheet.
    </p>

    <?php if ($oversized > 0): ?>
        <p class="print-warning">
            <?= (int) $oversized ?> barcode<?= $oversized === 1 ? ' is' : 's are' ?> wider than the
            <?= e($size) ?> label and <?= $oversized === 1 ? 'has' : 'have' ?> been scaled down to fit.
            The bars stay in proportion so they should still scan, but a
            <?= $size === 'large' ? 'shorter asset tag' : 'larger label size' ?> will be more reliable —
            long tags are the usual cause.
        </p>
    <?php endif; ?>
</div>
