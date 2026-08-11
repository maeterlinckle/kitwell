<?php

use App\Controllers\AssetController;
use App\Models\Asset;

/**
 * Options for the register export: which rows, and which columns.
 *
 * @var array<string,mixed> $filters
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,array<string,mixed>> $locations
 * @var int $total
 * @var array<string,array<string,mixed>> $extras
 */
$queryString = AssetController::queryString($filters);
$selected    = (array) ($filters['extras'] ?? []);
?>
<div class="page-head">
    <div>
        <h1>Export the asset register</h1>
        <p class="muted">Choose which assets and which columns, then download the CSV.</p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/export')) ?>">Back to export</a>
</div>

<form method="get" action="<?= e(url('/assets/export')) ?>" class="form">
    <div class="card">
        <h2>Which assets</h2>

        <div class="field">
            <label class="label" for="q">Search <span class="optional">(optional)</span></label>
            <input class="input" type="search" id="q" name="q" value="<?= e((string) $filters['q']) ?>"
                   placeholder="Tag, name, serial, manufacturer…">
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="category">Category</label>
                <select class="input" id="category" name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>"
                            <?= (string) $filters['category_id'] === (string) $category['id'] ? 'selected' : '' ?>>
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="location">Location</label>
                <select class="input" id="location" name="location">
                    <option value="">All locations</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= (int) $location['id'] ?>"
                            <?= (string) $filters['location_id'] === (string) $location['id'] ? 'selected' : '' ?>>
                            <?= e($location['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <span class="label">Status</span>
            <div class="check-row">
                <?php foreach (Asset::STATUSES as $status): ?>
                    <label class="checkbox checkbox-compact">
                        <input type="checkbox" name="status[]" value="<?= e($status) ?>"
                            <?= in_array($status, (array) $filters['status'], true) ? 'checked' : '' ?>>
                        <span><?= e($status) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="field">
            <label class="checkbox checkbox-compact">
                <input type="checkbox" name="archived" value="1" <?= !empty($filters['include_archived']) ? 'checked' : '' ?>>
                <span>Include retired assets</span>
            </label>
        </div>

        <p class="field-hint">
            <strong><?= number_format($total) ?></strong> asset<?= $total === 1 ? '' : 's' ?> match at the moment.
            Changing the boxes above changes what the download contains.
        </p>
    </div>

    <div class="card">
        <h2>Extra columns</h2>
        <p class="muted">
            The core columns are always included and are the ones the importer reads back. These
            three groups are derived from other records, so they are appended at the end and ignored
            on re-import.
        </p>

        <div class="check-row">
            <?php foreach ($extras as $key => $extra): ?>
                <label class="checkbox checkbox-compact">
                    <input type="checkbox" name="extras[]" value="<?= e($key) ?>"
                        <?= in_array($key, $selected, true) ? 'checked' : '' ?>>
                    <span><?= e($extra['label']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Download CSV</button>
        <a class="btn" href="<?= e(url('/export/assets/select' . ($queryString !== '' ? '?' . $queryString : ''))) ?>">
            Pick individual assets instead
        </a>
    </div>
</form>
