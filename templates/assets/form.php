<?php

use App\Models\Asset;

/**
 * Create / edit an asset.
 *
 * @var array<string,mixed>|null $asset
 * @var array<string,mixed>|null $parent
 * @var string|null $suggestedTag
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,array<string,mixed>> $locations
 * @var array<int,array<string,mixed>> $parents
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$isEdit = $asset !== null;
$action = $isEdit ? url('/assets/' . $asset['id']) : url('/assets');

/** Current value for a field: old input first, then the record, then a default. */
$value = static function (string $field, mixed $default = '') use ($old, $asset): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    if ($asset !== null && array_key_exists($field, $asset) && $asset[$field] !== null) {
        return (string) $asset[$field];
    }

    return (string) $default;
};

$checked = static function (string $field, bool $default) use ($old, $asset): bool {
    if ($old !== []) {
        return isset($old[$field]);
    }

    if ($asset !== null) {
        return (int) ($asset[$field] ?? 0) === 1;
    }

    return $default;
};

$parentId = $value('parent_asset_id', $parent['id'] ?? '');
?>
<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit asset' : 'Add asset' ?></h1>
        <?php if ($isEdit): ?>
            <p class="muted mono"><?= e($asset['asset_tag']) ?></p>
        <?php elseif ($parent !== null): ?>
            <p class="muted">Attaching to <strong><?= e($parent['asset_tag']) ?></strong> — <?= e($parent['name']) ?></p>
        <?php endif; ?>
    </div>
    <a class="btn btn-ghost" href="<?= e($isEdit ? url('/assets/' . $asset['id']) : url('/assets')) ?>">Cancel</a>
</div>

<form method="post" action="<?= e($action) ?>" class="form form-wide" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <h2>Identification</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="asset_tag">Asset tag / barcode</label>
                <div class="input-with-scan">
                    <input class="input mono<?= isset($errors['asset_tag']) ? ' has-error' : '' ?>" type="text"
                           id="asset_tag" name="asset_tag" maxlength="64" required
                           autocapitalize="characters" autocomplete="off" spellcheck="false"
                           value="<?= e($value('asset_tag', $suggestedTag ?? '')) ?>">
                    <?= partial('partials/scan-button', ['target' => 'asset_tag']) ?>
                </div>
                <p class="field-hint">
                    <?= $isEdit
                        ? 'Changing this means the printed label no longer matches — reprint it afterwards.'
                        : 'Generated for you. Overwrite it to record a tag the item already carries.' ?>
                </p>
                <?php if (isset($errors['asset_tag'])): ?><p class="field-error"><?= e($errors['asset_tag']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="barcode">Existing barcode <span class="optional">(optional)</span></label>
                <div class="input-with-scan">
                    <input class="input mono<?= isset($errors['barcode']) ? ' has-error' : '' ?>" type="text"
                           id="barcode" name="barcode" maxlength="64" autocomplete="off" spellcheck="false"
                           value="<?= e($value('barcode')) ?>">
                    <?= partial('partials/scan-button', ['target' => 'barcode']) ?>
                </div>
                <p class="field-hint">A manufacturer or previous-system barcode. Scanning either finds this asset.</p>
                <?php if (isset($errors['barcode'])): ?><p class="field-error"><?= e($errors['barcode']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label class="label" for="name">Name</label>
            <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name" name="name"
                   maxlength="191" required value="<?= e($value('name')) ?>">
            <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="description">Description <span class="optional">(optional)</span></label>
            <textarea class="input" id="description" name="description" rows="3" maxlength="5000"><?= e($value('description')) ?></textarea>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="category_id">Category</label>
                <select class="input" id="category_id" name="category_id">
                    <option value="">Not set</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" <?= $value('category_id') === (string) $category['id'] ? 'selected' : '' ?>>
                            <?= e($category['parent_name'] !== null ? $category['parent_name'] . ' → ' . $category['name'] : $category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (can('categories.manage')): ?>
                    <p class="field-hint"><a href="<?= e(url('/admin/categories')) ?>">Manage categories</a></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="location_id">Location</label>
                <select class="input" id="location_id" name="location_id">
                    <option value="">Not set</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= (int) $location['id'] ?>" <?= $value('location_id') === (string) $location['id'] ? 'selected' : '' ?>>
                            <?= e($location['display_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (can('locations.manage')): ?>
                    <p class="field-hint"><a href="<?= e(url('/admin/locations')) ?>">Manage locations</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Status &amp; condition</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="status">Status</label>
                <select class="input" id="status" name="status" required>
                    <?php foreach (Asset::STATUSES as $status): ?>
                        <option value="<?= e($status) ?>" <?= $value('status', 'In Stock') === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="condition_rating">Condition</label>
                <select class="input" id="condition_rating" name="condition_rating" required>
                    <?php foreach (Asset::CONDITIONS as $condition): ?>
                        <option value="<?= e($condition) ?>" <?= $value('condition_rating', 'Good') === $condition ? 'selected' : '' ?>><?= e($condition) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="is_hireable" value="1" <?= $checked('is_hireable', true) ? 'checked' : '' ?>>
                <span>Available to hire out<span class="field-hint">Untick for fixed machinery or anything that must not leave site.</span></span>
            </label>
        </div>
    </div>

    <div class="card">
        <h2>Make &amp; model</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="manufacturer">Manufacturer</label>
                <input class="input" type="text" id="manufacturer" name="manufacturer" maxlength="191" value="<?= e($value('manufacturer')) ?>">
            </div>
            <div class="field">
                <label class="label" for="model">Model</label>
                <input class="input" type="text" id="model" name="model" maxlength="191" value="<?= e($value('model')) ?>">
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="serial_number">Serial number</label>
                <input class="input mono" type="text" id="serial_number" name="serial_number" maxlength="191"
                       autocapitalize="characters" spellcheck="false" value="<?= e($value('serial_number')) ?>">
            </div>
            <div class="field">
                <label class="label" for="manufacturer_url">Manufacturer website</label>
                <input class="input<?= isset($errors['manufacturer_url']) ? ' has-error' : '' ?>" type="url"
                       id="manufacturer_url" name="manufacturer_url" maxlength="500" inputmode="url"
                       placeholder="https://…" value="<?= e($value('manufacturer_url')) ?>">
                <p class="field-hint">Product or support page. Shown as a clickable link on the asset.</p>
                <?php if (isset($errors['manufacturer_url'])): ?><p class="field-error"><?= e($errors['manufacturer_url']) ?></p><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Electrical &amp; PAT</h2>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" id="requires_pat" name="requires_pat" value="1"
                       data-toggles="#pat-details" <?= $checked('requires_pat', false) ? 'checked' : '' ?>>
                <span>This item needs portable appliance testing</span>
            </label>
        </div>

        <div id="pat-details" class="conditional-block">
            <p class="field-hint">
                These describe the appliance itself, so the tester is never asked for
                them again. They decide which electrical tests the guided PAT flow asks for.
            </p>

            <div class="field-row">
                <div class="field">
                    <label class="label" for="appliance_class">Appliance class</label>
                    <select class="input<?= isset($errors['appliance_class']) ? ' has-error' : '' ?>"
                            id="appliance_class" name="appliance_class">
                        <option value="">— not established —</option>
                        <?php foreach (\App\Models\Asset::APPLIANCE_CLASSES as $option): ?>
                            <option value="<?= e($option) ?>" <?= $value('appliance_class') === $option ? 'selected' : '' ?>>
                                <?= e($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">
                        Class I is earthed, Class II double-insulated. This decides whether
                        earth continuity is tested.
                    </p>
                    <?php if (isset($errors['appliance_class'])): ?><p class="field-error"><?= e($errors['appliance_class']) ?></p><?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="load_rating_va">Load rating (VA) <span class="optional">(optional)</span></label>
                    <input class="input<?= isset($errors['load_rating_va']) ? ' has-error' : '' ?>" type="number"
                           id="load_rating_va" name="load_rating_va" step="1" min="0" max="9999999"
                           inputmode="decimal" value="<?= e($value('load_rating_va')) ?>">
                    <p class="field-hint">Rated load in volt-amps, shown to the tester for reference.</p>
                    <?php if (isset($errors['load_rating_va'])): ?><p class="field-error"><?= e($errors['load_rating_va']) ?></p><?php endif; ?>
                </div>
            </div>

            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" id="has_fuse" name="has_fuse" value="1"
                           data-toggles="#fuse-details" <?= $checked('has_fuse', false) ? 'checked' : '' ?>>
                    <span>The plug carries a fuse</span>
                </label>
                <p class="field-hint">Turn this off for unfused items — the PAT flow then skips the fuse check.</p>
            </div>

            <div id="fuse-details" class="conditional-block">
                <div class="field">
                    <label class="label" for="plug_fuse_rating_amps">Plug fuse rating</label>
                    <?php
                    $fuse       = $value('plug_fuse_rating_amps');
                    $fuseNumber = $fuse === '' ? '' : (string) (float) $fuse;
                    $nonStandard = $fuseNumber !== '' && !in_array($fuseNumber, \App\Models\Asset::FUSE_RATINGS, true);
                    ?>
                    <select class="input<?= isset($errors['plug_fuse_rating_amps']) ? ' has-error' : '' ?>"
                            id="plug_fuse_rating_amps" name="plug_fuse_rating_amps">
                        <option value="">— not recorded —</option>
                        <?php foreach (\App\Models\Asset::FUSE_RATINGS as $rating): ?>
                            <option value="<?= e($rating) ?>" <?= $fuseNumber === $rating ? 'selected' : '' ?>>
                                <?= e($rating) ?> A
                            </option>
                        <?php endforeach; ?>
                        <?php if ($nonStandard): ?>
                            <?php /* Kept so editing another field cannot silently discard existing data. */ ?>
                            <option value="<?= e($fuseNumber) ?>" selected>
                                <?= e($fuseNumber) ?> A — non-standard, please correct
                            </option>
                        <?php endif; ?>
                    </select>
                    <p class="field-hint">
                        The tester confirms the fitted fuse against this value rather than re-entering it.
                    </p>
                    <?php if (isset($errors['plug_fuse_rating_amps'])): ?><p class="field-error"><?= e($errors['plug_fuse_rating_amps']) ?></p><?php endif; ?>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label class="label" for="cable_csa_mm2">Cable CSA (mm²)</label>
                    <input class="input<?= isset($errors['cable_csa_mm2']) ? ' has-error' : '' ?>" type="number"
                           id="cable_csa_mm2" name="cable_csa_mm2" step="0.25" min="0" max="999"
                           inputmode="decimal" list="csa-values" value="<?= e($value('cable_csa_mm2')) ?>">
                    <datalist id="csa-values">
                        <option value="0.5"></option>
                        <option value="0.75"></option>
                        <option value="1"></option>
                        <option value="1.25"></option>
                        <option value="1.5"></option>
                        <option value="2.5"></option>
                    </datalist>
                    <p class="field-hint">Cross-sectional area of the flex conductors.</p>
                    <?php if (isset($errors['cable_csa_mm2'])): ?><p class="field-error"><?= e($errors['cable_csa_mm2']) ?></p><?php endif; ?>
                </div>
            </div>

            <div class="field">
                <label class="label" for="pat_interval_months">Retest interval (months) <span class="optional">(optional)</span></label>
                <input class="input" type="number" id="pat_interval_months" name="pat_interval_months"
                       min="1" max="120" step="1" inputmode="numeric" value="<?= e($value('pat_interval_months')) ?>">
                <p class="field-hint">Leave blank to use the site default when PAT records are entered.</p>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Purchase</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="purchase_date">Purchase date</label>
                <input class="input" type="date" id="purchase_date" name="purchase_date" value="<?= e($value('purchase_date')) ?>">
            </div>
            <div class="field">
                <label class="label" for="supplier">Supplier</label>
                <input class="input" type="text" id="supplier" name="supplier" maxlength="191" value="<?= e($value('supplier')) ?>">
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="purchase_cost">Purchase cost (<?= e(config('app.currency_symbol', '£')) ?>)</label>
                <input class="input" type="number" id="purchase_cost" name="purchase_cost" step="0.01" min="0"
                       inputmode="decimal" value="<?= e($value('purchase_cost')) ?>">
            </div>
            <div class="field">
                <label class="label" for="current_value">Current value (<?= e(config('app.currency_symbol', '£')) ?>)</label>
                <input class="input" type="number" id="current_value" name="current_value" step="0.01" min="0"
                       inputmode="decimal" value="<?= e($value('current_value')) ?>">
            </div>
        </div>

        <div class="field">
            <label class="label" for="warranty_expires_on">Warranty expires</label>
            <input class="input" type="date" id="warranty_expires_on" name="warranty_expires_on" value="<?= e($value('warranty_expires_on')) ?>">
        </div>
    </div>

    <div class="card">
        <h2>Attach to another asset</h2>
        <p class="muted">Makes this item a sub-asset, accessory or related item. It keeps its own tag, detail page and search entry.</p>

        <div class="field-row">
            <div class="field">
                <label class="label" for="parent_asset_id">Parent asset</label>
                <select class="input<?= isset($errors['parent_asset_id']) ? ' has-error' : '' ?>" id="parent_asset_id" name="parent_asset_id">
                    <option value="">Not attached — this is a standalone asset</option>
                    <?php foreach ($parents as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= (string) $parentId === (string) $option['id'] ? 'selected' : '' ?>>
                            <?= e($option['asset_tag'] . ' — ' . str_limit((string) $option['name'], 60)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['parent_asset_id'])): ?><p class="field-error"><?= e($errors['parent_asset_id']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="relationship_type">Relationship</label>
                <select class="input" id="relationship_type" name="relationship_type">
                    <?php foreach (Asset::RELATIONSHIPS as $relationship): ?>
                        <option value="<?= e($relationship) ?>" <?= $value('relationship_type', 'sub-asset') === $relationship ? 'selected' : '' ?>>
                            <?= e(ucfirst(str_replace('-', ' ', $relationship))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Notes</h2>
        <div class="field">
            <label class="sr-only" for="notes">Notes</label>
            <textarea class="input" id="notes" name="notes" rows="4" maxlength="5000"
                      placeholder="Anything worth knowing: quirks, service history, where the key lives…"><?= e($value('notes')) ?></textarea>
        </div>
    </div>

    <div class="form-actions sticky-actions">
        <button type="submit" class="btn btn-primary btn-lg"><?= $isEdit ? 'Save changes' : 'Register asset' ?></button>
        <?php if (!$isEdit): ?>
            <button type="submit" name="save_and_new" value="1" class="btn btn-lg">Save &amp; add another</button>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e($isEdit ? url('/assets/' . $asset['id']) : url('/assets')) ?>">Cancel</a>
    </div>
</form>
