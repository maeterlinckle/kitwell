<?php
/**
 * @var array<string,string|null> $settings
 * @var array<string,string|null> $logos  light/dark logo URLs, null when unset
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

<?php /* Its own form, above the rest: an upload has nothing to do with the
         other settings, and putting it inside their form would mean every logo
         change re-validated and re-saved all of them. */ ?>
<div class="card">
    <h2>Logo</h2>
    <p class="muted">
        Shown in the top-left corner in place of the <span class="brand-mark brand-mark-inline"><?= e(config('app.mark', 'KW')) ?></span> box,
        on printed paperwork, and in the header of outbound email. Optional — leave both empty to keep the box.
    </p>

    <form method="post" action="<?= e(url('/admin/settings/logo')) ?>" class="form" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="logo-grid">
            <?php foreach (['light' => 'Light mode', 'dark' => 'Dark mode'] as $variant => $label): ?>
                <div class="logo-slot">
                    <div class="logo-preview logo-preview-<?= e($variant) ?>">
                        <?php if ($logos[$variant] !== null): ?>
                            <img src="<?= e($logos[$variant]) ?>" alt="<?= e($label) ?> logo">
                        <?php else: ?>
                            <span class="muted">None uploaded</span>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label class="label" for="logo_<?= e($variant) ?>"><?= e($label) ?></label>
                        <input class="input" type="file" id="logo_<?= e($variant) ?>" name="logo_<?= e($variant) ?>"
                               accept="image/png,image/jpeg,image/webp">
                        <p class="field-hint">PNG, JPEG or WebP, up to 2&nbsp;MB. Scaled to fit by height, so make it at least 72px tall.</p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save logo</button>
        </div>
    </form>

    <?php foreach (['light' => 'Light mode', 'dark' => 'Dark mode'] as $variant => $label): ?>
        <?php if ($logos[$variant] !== null): ?>
            <form method="post" action="<?= e(url('/admin/settings/logo/' . $variant . '/remove')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-ghost"
                        data-confirm="Remove the <?= e(strtolower($label)) ?> logo?">Remove <?= e(strtolower($label)) ?> logo</button>
            </form>
        <?php endif; ?>
    <?php endforeach; ?>
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
        <h2>Interface</h2>

        <div class="field">
            <label class="label" for="flash_auto_hide_seconds">Hide confirmation banners after (seconds)</label>
            <input class="input<?= isset($errors['flash_auto_hide_seconds']) ? ' has-error' : '' ?>" type="number"
                   id="flash_auto_hide_seconds" name="flash_auto_hide_seconds" min="0" max="120" step="1" required
                   value="<?= e(old($old, 'flash_auto_hide_seconds', $setting('flash_auto_hide_seconds', '6'))) ?>">
            <p class="field-hint">
                <strong>0 keeps them until they are closed.</strong> This applies to green confirmations
                only — “saved”, “added”, “welcome back”. Warnings and errors always stay until dismissed,
                because they are usually the only place the problem is stated. The close button is there
                either way, and the countdown pauses while you are hovering over or tabbed into a banner.
            </p>
            <?php if (isset($errors['flash_auto_hide_seconds'])): ?><p class="field-error"><?= e($errors['flash_auto_hide_seconds']) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>Two-factor authentication</h2>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="two_factor_required" value="1"
                    <?= $setting('two_factor_required', '0') === '1' ? 'checked' : '' ?>
                    <?= $canRequireTwoFactor ? '' : 'disabled' ?>>
                <span>Require it for everyone
                    <span class="field-hint">
                        Anybody without a second factor is walked through setting one up at their next
                        sign-in. Individual users can always switch it on for themselves from their own
                        account page; this makes it compulsory.
                    </span>
                </span>
            </label>

            <?php if (!$canRequireTwoFactor): ?>
                <p class="field-error">
                    Not available yet: <a href="<?= e(url('/admin/email')) ?>">email is not configured</a>,
                    so a user without an authenticator app would have no way to receive a code — and no
                    way to sign in. Set up email first.
                </p>
            <?php endif; ?>
            <?php if (isset($errors['two_factor_required'])): ?><p class="field-error"><?= e($errors['two_factor_required']) ?></p><?php endif; ?>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="trusted_device_days">Trust a device for (days)</label>
                <input class="input<?= isset($errors['trusted_device_days']) ? ' has-error' : '' ?>" type="number"
                       id="trusted_device_days" name="trusted_device_days" min="1" max="365" step="1" required
                       value="<?= e(old($old, 'trusted_device_days', $setting('trusted_device_days', '30'))) ?>">
                <p class="field-hint">The outer limit on “don’t ask again on this computer”.</p>
                <?php if (isset($errors['trusted_device_days'])): ?><p class="field-error"><?= e($errors['trusted_device_days']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="trusted_device_idle_days">…or until unused for (days)</label>
                <input class="input<?= isset($errors['trusted_device_idle_days']) ? ' has-error' : '' ?>" type="number"
                       id="trusted_device_idle_days" name="trusted_device_idle_days" min="1" max="365" step="1" required
                       value="<?= e(old($old, 'trusted_device_idle_days', $setting('trusted_device_idle_days', '14'))) ?>">
                <p class="field-hint">
                    A machine not signed in from for this long is asked again. Capped at the figure on the
                    left. A code is also required again if the browser or the network changes.
                </p>
                <?php if (isset($errors['trusted_device_idle_days'])): ?><p class="field-error"><?= e($errors['trusted_device_idle_days']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="email_otp_minutes">Emailed code lasts (minutes)</label>
                <input class="input<?= isset($errors['email_otp_minutes']) ? ' has-error' : '' ?>" type="number"
                       id="email_otp_minutes" name="email_otp_minutes" min="1" max="60" step="1" required
                       value="<?= e(old($old, 'email_otp_minutes', $setting('email_otp_minutes', '10'))) ?>">
                <p class="field-hint">Only used for people without an authenticator app.</p>
                <?php if (isset($errors['email_otp_minutes'])): ?><p class="field-error"><?= e($errors['email_otp_minutes']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="two_factor_max_attempts">Wrong codes allowed</label>
                <input class="input<?= isset($errors['two_factor_max_attempts']) ? ' has-error' : '' ?>" type="number"
                       id="two_factor_max_attempts" name="two_factor_max_attempts" min="3" max="10" step="1" required
                       value="<?= e(old($old, 'two_factor_max_attempts', $setting('two_factor_max_attempts', '5'))) ?>">
                <p class="field-hint">
                    Then the sign-in is torn up and has to start from the password again. Wrong codes also
                    count towards the ordinary sign-in lockout, so guessing six digits locks the account.
                </p>
                <?php if (isset($errors['two_factor_max_attempts'])): ?><p class="field-error"><?= e($errors['two_factor_max_attempts']) ?></p><?php endif; ?>
            </div>
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
