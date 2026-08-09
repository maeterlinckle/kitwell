<?php
/**
 * @var array<string,string|null> $settings
 * @var string $nextTag
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$setting = static fn (string $key, string $default = ''): string => (string) ($settings[$key] ?? $default);
?>
<div class="page-head">
    <div>
        <h1>Settings</h1>
        <p class="muted">Application-wide options. Database credentials and security settings live in <span class="mono">.env</span>, not here.</p>
    </div>
</div>

<form method="post" action="<?= e(url('/admin/settings')) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <h2>Asset tags</h2>
        <p class="muted">New assets are tagged automatically as prefix + number. The next tag will be <span class="mono"><?= e($nextTag) ?></span>.</p>

        <div class="field-row">
            <div class="field">
                <label class="label" for="asset_tag_prefix">Prefix</label>
                <input class="input mono<?= isset($errors['asset_tag_prefix']) ? ' has-error' : '' ?>" type="text"
                       id="asset_tag_prefix" name="asset_tag_prefix" maxlength="20" spellcheck="false"
                       value="<?= e(old($old, 'asset_tag_prefix', $setting('asset_tag_prefix', 'AST-'))) ?>">
                <p class="field-hint">Letters, numbers and - _ / . only, so tags stay scannable as barcodes.</p>
                <?php if (isset($errors['asset_tag_prefix'])): ?><p class="field-error"><?= e($errors['asset_tag_prefix']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="asset_tag_pad">Number padding</label>
                <input class="input<?= isset($errors['asset_tag_pad']) ? ' has-error' : '' ?>" type="number"
                       id="asset_tag_pad" name="asset_tag_pad" min="1" max="12" step="1" required
                       value="<?= e(old($old, 'asset_tag_pad', $setting('asset_tag_pad', '4'))) ?>">
                <p class="field-hint">4 gives 0001, 6 gives 000001.</p>
                <?php if (isset($errors['asset_tag_pad'])): ?><p class="field-error"><?= e($errors['asset_tag_pad']) ?></p><?php endif; ?>
            </div>
        </div>

        <p class="field-hint">
            Changing the prefix starts a new sequence. Existing tags are never renumbered, and any tag
            already in use is skipped automatically.
        </p>
    </div>

    <div class="card">
        <h2>Printed labels</h2>

        <div class="field">
            <label class="label" for="organisation_name">Organisation name <span class="optional">(optional)</span></label>
            <input class="input" type="text" id="organisation_name" name="organisation_name" maxlength="120"
                   value="<?= e(old($old, 'organisation_name', $setting('organisation_name'))) ?>">
            <p class="field-hint">Printed above the barcode. Leave blank to keep labels minimal.</p>
        </div>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="label_show_name" value="1" <?= ($setting('label_show_name', '1') === '1') ? 'checked' : '' ?>>
                <span>Show the asset name on labels</span>
            </label>
        </div>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="label_show_location" value="1" <?= ($setting('label_show_location', '1') === '1') ? 'checked' : '' ?>>
                <span>Show the location on labels</span>
            </label>
        </div>
    </div>

    <div class="card">
        <h2>Maintenance</h2>

        <div class="field">
            <label class="label" for="maintenance_due_days">“Due soon” window (days)</label>
            <input class="input<?= isset($errors['maintenance_due_days']) ? ' has-error' : '' ?>" type="number"
                   id="maintenance_due_days" name="maintenance_due_days" min="1" max="365" step="1" required
                   value="<?= e(old($old, 'maintenance_due_days', $setting('maintenance_due_days', '30'))) ?>">
            <p class="field-hint">
                How far ahead to flag upcoming maintenance. Used by the dashboard, the maintenance
                list and the reports module, so every screen agrees on what “due soon” means.
            </p>
            <?php if (isset($errors['maintenance_due_days'])): ?><p class="field-error"><?= e($errors['maintenance_due_days']) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>PAT testing</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="pat_due_days">“Due soon” window (days)</label>
                <input class="input<?= isset($errors['pat_due_days']) ? ' has-error' : '' ?>" type="number"
                       id="pat_due_days" name="pat_due_days" min="1" max="365" step="1" required
                       value="<?= e(old($old, 'pat_due_days', $setting('pat_due_days', '30'))) ?>">
                <p class="field-hint">How far ahead a retest is flagged as coming up.</p>
                <?php if (isset($errors['pat_due_days'])): ?><p class="field-error"><?= e($errors['pat_due_days']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="pat_default_interval_months">Default retest interval (months)</label>
                <input class="input<?= isset($errors['pat_default_interval_months']) ? ' has-error' : '' ?>" type="number"
                       id="pat_default_interval_months" name="pat_default_interval_months" min="1" max="120" step="1" required
                       value="<?= e(old($old, 'pat_default_interval_months', $setting('pat_default_interval_months', '12'))) ?>">
                <p class="field-hint">
                    Used to suggest a retest date when an asset has no interval of its own.
                    The right interval depends on the equipment and where it is used, so an
                    individual asset can override this on its own record.
                </p>
                <?php if (isset($errors['pat_default_interval_months'])): ?><p class="field-error"><?= e($errors['pat_default_interval_months']) ?></p><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Save settings</button>
    </div>
</form>
