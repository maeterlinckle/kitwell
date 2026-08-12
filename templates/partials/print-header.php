<?php

use App\Models\Setting;
use App\Services\Branding;

/**
 * The masthead on printed documents.
 *
 * Always the light logo: paper is white, and a logo drawn for a dark header
 * would come out as a white shape on a white page.
 *
 * @var string $title
 * @var string $subtitle
 */
$logo         = Branding::url('light');
$organisation = (string) (Setting::get('organisation_name') ?? '');
?>
<header class="print-head">
    <div class="print-head-brand">
        <?php if ($logo !== null): ?>
            <img class="print-logo" src="<?= e($logo) ?>" alt="">
        <?php endif; ?>
        <div>
            <p class="print-org"><?= e($organisation !== '' ? $organisation : config('app.name', 'Kitwell')) ?></p>
            <p class="print-product muted"><?= e(config('app.full_name', 'Kitwell by Junction')) ?> — <?= e(config('app.product_tagline', 'Asset Management')) ?></p>
        </div>
    </div>

    <div class="print-head-meta">
        <h1><?= e($title ?? '') ?></h1>
        <?php if (!empty($subtitle)): ?>
            <p class="muted"><?= e($subtitle) ?></p>
        <?php endif; ?>
        <p class="muted">Printed <?= e(format_datetime(date('Y-m-d H:i:s'))) ?><?php
            if (auth_user() !== null) { echo ' by ' . e(auth_user()['name']); } ?></p>
    </div>
</header>
