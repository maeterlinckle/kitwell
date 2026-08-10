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

    <div class="card">
        <h2>PAT guideline pass ranges</h2>
        <p class="muted">
            Shown to the tester as helper text beside each reading in the guided test.
            <strong>These are guidance, not a rule.</strong> Nothing compares a reading
            against them to decide a result — the tester's own pass/fail choice is what
            records the outcome. Acceptable values vary by appliance, so tune these to
            your own policy.
        </p>

        <div class="field-row">
            <?php
            $guides = [
                'pat_guide_insulation_mohm'   => ['Insulation resistance (MΩ)', '1', 'Typical minimum. Shown as “≥ this value”.'],
                'pat_guide_leakage_class1_ma' => ['Leakage, Class I (mA)', '3.5', 'Typical maximum for an earthed appliance.'],
                'pat_guide_leakage_class2_ma' => ['Leakage, Class II (mA)', '0.25', 'Typical maximum for a double-insulated appliance.'],
            ];
            foreach ($guides as $key => [$label, $default, $hint]):
                ?>
                <div class="field">
                    <label class="label" for="<?= e($key) ?>"><?= e($label) ?></label>
                    <input class="input<?= isset($errors[$key]) ? ' has-error' : '' ?>" type="number"
                           id="<?= e($key) ?>" name="<?= e($key) ?>" min="0" step="0.01" required
                           inputmode="decimal"
                           value="<?= e(old($old, $key, $setting($key, $default))) ?>">
                    <p class="field-hint"><?= e($hint) ?></p>
                    <?php if (isset($errors[$key])): ?><p class="field-error"><?= e($errors[$key]) ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <h3>Earth continuity</h3>
        <p class="field-hint">
            The guideline shown is the base value, plus the lead allowance for every
            length of extension lead under test. With the defaults that reads as
            “0.1 Ω, plus 0.1 Ω per 7.5 m of lead”.
        </p>
        <div class="field-row">
            <?php
            $earth = [
                'pat_guide_earth_base_ohm'    => ['Base (Ω)', '0.1', 'For the appliance or lead alone.'],
                'pat_guide_earth_lead_ohm'    => ['Lead allowance (Ω)', '0.1', 'Added per length below.'],
                'pat_guide_earth_lead_metres' => ['Per length (m)', '7.5', 'The lead length that allowance covers.'],
            ];
            foreach ($earth as $key => [$label, $default, $hint]):
                ?>
                <div class="field">
                    <label class="label" for="<?= e($key) ?>"><?= e($label) ?></label>
                    <input class="input<?= isset($errors[$key]) ? ' has-error' : '' ?>" type="number"
                           id="<?= e($key) ?>" name="<?= e($key) ?>" min="0" step="0.01" required
                           inputmode="decimal"
                           value="<?= e(old($old, $key, $setting($key, $default))) ?>">
                    <p class="field-hint"><?= e($hint) ?></p>
                    <?php if (isset($errors[$key])): ?><p class="field-error"><?= e($errors[$key]) ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Save settings</button>
    </div>
</form>
