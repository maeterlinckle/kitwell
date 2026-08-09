<?php

use App\Models\PatRecord;

/**
 * Record / correct a PAT test.
 *
 * Every measurement carries its unit in the label, and the fields that do not
 * apply to the chosen appliance class are hidden — there is no earth
 * continuity reading to take on a Class II appliance.
 *
 * @var array<string,mixed>|null $record
 * @var array<string,mixed>|null $asset
 * @var array<int,array<string,mixed>> $assets
 * @var array<int,array<string,mixed>> $users
 * @var string|null $suggestedDue
 * @var int $interval
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$isEdit = $record !== null;
$action = $isEdit ? url('/pat/' . $record['id']) : url('/pat');

$value = static function (string $field, mixed $default = '') use ($old, $record): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    if ($record !== null && array_key_exists($field, $record) && $record[$field] !== null) {
        return (string) $record[$field];
    }

    return (string) $default;
};

/** Tri-state (pass / fail / not performed) helper. */
$triState = static function (string $field) use ($old, $record): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    if ($record !== null && $record[$field] !== null) {
        return ((int) $record[$field] === 1) ? '1' : '0';
    }

    return '';
};

$visualChecked = $old !== []
    ? isset($old['visual_inspection_pass'])
    : ($record === null || (int) $record['visual_inspection_pass'] === 1);

$currentClass = $value('appliance_class', 'Class I');
?>
<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Correct PAT test' : 'Record PAT test' ?></h1>
        <?php if ($asset !== null): ?>
            <p class="muted">
                <a href="<?= e(url('/assets/' . $asset['id'])) ?>"><span class="mono"><?= e($asset['asset_tag']) ?></span></a>
                — <?= e($asset['name']) ?>
                <?php if (!empty($asset['plug_fuse_rating_amps'])): ?>
                    · fitted fuse on record: <?= e(PatRecord::measurement($asset['plug_fuse_rating_amps'], 'A')) ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <a class="btn btn-ghost" href="<?= e($asset !== null ? url('/assets/' . $asset['id'] . '/pat') : url('/pat')) ?>">Cancel</a>
</div>

<form method="post" action="<?= e($action) ?>" class="form form-wide" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <h2>Test</h2>

        <?php if ($asset !== null): ?>
            <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">
        <?php else: ?>
            <div class="field">
                <label class="label" for="asset_id">Asset</label>
                <select class="input<?= isset($errors['asset_id']) ? ' has-error' : '' ?>" id="asset_id" name="asset_id" required>
                    <option value="">Choose an asset…</option>
                    <?php foreach ($assets as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= $value('asset_id') === (string) $option['id'] ? 'selected' : '' ?>>
                            <?= e($option['asset_tag'] . ' — ' . str_limit((string) $option['name'], 60)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">Only assets flagged as requiring PAT are listed.</p>
                <?php if (isset($errors['asset_id'])): ?><p class="field-error"><?= e($errors['asset_id']) ?></p><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="field-row">
            <div class="field">
                <label class="label" for="test_date">Test date</label>
                <input class="input<?= isset($errors['test_date']) ? ' has-error' : '' ?>" type="date" id="test_date"
                       name="test_date" required max="<?= e(date('Y-m-d')) ?>"
                       value="<?= e($value('test_date', date('Y-m-d'))) ?>">
                <?php if (isset($errors['test_date'])): ?><p class="field-error"><?= e($errors['test_date']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="retest_due_date">Retest due</label>
                <input class="input<?= isset($errors['retest_due_date']) ? ' has-error' : '' ?>" type="date"
                       id="retest_due_date" name="retest_due_date"
                       value="<?= e($value('retest_due_date', (string) $suggestedDue)) ?>">
                <p class="field-hint">Suggested from the <?= (int) $interval ?>-month interval for this asset. Change it if the item warrants a different period.</p>
                <?php if (isset($errors['retest_due_date'])): ?><p class="field-error"><?= e($errors['retest_due_date']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label class="label" for="appliance_class">Appliance class</label>
            <select class="input" id="appliance_class" name="appliance_class" required data-pat-class>
                <?php foreach (PatRecord::CLASSES as $class): ?>
                    <option value="<?= e($class) ?>" <?= $currentClass === $class ? 'selected' : '' ?>><?= e($class) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">Class I is earthed; Class II is double-insulated and has no earth to test.</p>
        </div>
    </div>

    <div class="card">
        <h2>Checks</h2>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="visual_inspection_pass" value="1" <?= $visualChecked ? 'checked' : '' ?>>
                <span>
                    Visual inspection passed
                    <span class="field-hint">Case, cable, plug, fuse and connections all sound. An item that fails here fails overall.</span>
                </span>
            </label>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="functional_check_pass">Functional check</label>
                <select class="input" id="functional_check_pass" name="functional_check_pass">
                    <option value=""  <?= $triState('functional_check_pass') === ''  ? 'selected' : '' ?>>Not performed</option>
                    <option value="1" <?= $triState('functional_check_pass') === '1' ? 'selected' : '' ?>>Pass</option>
                    <option value="0" <?= $triState('functional_check_pass') === '0' ? 'selected' : '' ?>>Fail</option>
                </select>
            </div>

            <div class="field">
                <label class="label" for="polarity_pass">Polarity check</label>
                <select class="input" id="polarity_pass" name="polarity_pass">
                    <option value=""  <?= $triState('polarity_pass') === ''  ? 'selected' : '' ?>>Not performed</option>
                    <option value="1" <?= $triState('polarity_pass') === '1' ? 'selected' : '' ?>>Pass</option>
                    <option value="0" <?= $triState('polarity_pass') === '0' ? 'selected' : '' ?>>Fail</option>
                </select>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Readings</h2>
        <p class="muted">Leave a reading blank if it was not taken.</p>

        <div class="field" data-pat-when-class="Class I">
            <label class="label" for="earth_continuity_ohms">Earth continuity (Ω)</label>
            <input class="input<?= isset($errors['earth_continuity_ohms']) ? ' has-error' : '' ?>" type="number"
                   id="earth_continuity_ohms" name="earth_continuity_ohms" step="0.001" min="0" max="9999"
                   inputmode="decimal" value="<?= e($value('earth_continuity_ohms')) ?>">
            <p class="field-hint">Resistance in ohms. Class I only — a Class II appliance has no earth conductor.</p>
            <?php if (isset($errors['earth_continuity_ohms'])): ?><p class="field-error"><?= e($errors['earth_continuity_ohms']) ?></p><?php endif; ?>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="insulation_resistance_mohms">Insulation resistance (MΩ)</label>
                <input class="input<?= isset($errors['insulation_resistance_mohms']) ? ' has-error' : '' ?>" type="number"
                       id="insulation_resistance_mohms" name="insulation_resistance_mohms" step="0.01" min="0" max="999999"
                       inputmode="decimal" value="<?= e($value('insulation_resistance_mohms')) ?>">
                <p class="field-hint">Megohms. Enter the tester's ceiling value if it reads over range.</p>
                <?php if (isset($errors['insulation_resistance_mohms'])): ?><p class="field-error"><?= e($errors['insulation_resistance_mohms']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="leakage_current_ma">Leakage current (mA)</label>
                <input class="input<?= isset($errors['leakage_current_ma']) ? ' has-error' : '' ?>" type="number"
                       id="leakage_current_ma" name="leakage_current_ma" step="0.001" min="0" max="9999"
                       inputmode="decimal" value="<?= e($value('leakage_current_ma')) ?>">
                <p class="field-hint">Milliamps.</p>
                <?php if (isset($errors['leakage_current_ma'])): ?><p class="field-error"><?= e($errors['leakage_current_ma']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="load_test_va">Load / power (VA) <span class="optional">(optional)</span></label>
                <input class="input" type="number" id="load_test_va" name="load_test_va" step="0.01" min="0"
                       inputmode="decimal" value="<?= e($value('load_test_va')) ?>">
                <p class="field-hint">Volt-amps.</p>
            </div>

            <div class="field">
                <label class="label" for="fuse_fitted_amps">Fuse fitted (A) <span class="optional">(optional)</span></label>
                <input class="input" type="number" id="fuse_fitted_amps" name="fuse_fitted_amps" step="0.5" min="0"
                       inputmode="decimal" list="pat-fuse-ratings" value="<?= e($value('fuse_fitted_amps')) ?>">
                <datalist id="pat-fuse-ratings">
                    <option value="3"></option><option value="5"></option>
                    <option value="10"></option><option value="13"></option>
                </datalist>
                <p class="field-hint">Amps — the fuse actually found or fitted at the time of test.</p>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Result</h2>

        <div class="field">
            <span class="label">Overall result</span>
            <div class="radio-cards radio-cards-inline">
                <label class="radio-card">
                    <input type="radio" name="overall_result" value="Pass" data-pat-result
                        <?= $value('overall_result', 'Pass') === 'Pass' ? 'checked' : '' ?>>
                    <span><strong>Pass</strong><span class="muted">Safe for continued use.</span></span>
                </label>

                <label class="radio-card">
                    <input type="radio" name="overall_result" value="Fail" data-pat-result
                        <?= $value('overall_result') === 'Fail' ? 'checked' : '' ?>>
                    <span><strong>Fail</strong><span class="muted">Must not be used until the fault is put right.</span></span>
                </label>
            </div>
            <?php if (isset($errors['overall_result'])): ?><p class="field-error"><?= e($errors['overall_result']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="pat_label_serial">PAT label serial <span class="optional">(optional)</span></label>
            <input class="input mono" type="text" id="pat_label_serial" name="pat_label_serial" maxlength="100"
                   autocapitalize="characters" spellcheck="false" value="<?= e($value('pat_label_serial')) ?>">
            <p class="field-hint">The serial printed on the label stuck to the item.</p>
        </div>

        <div class="field" data-pat-when-result="Fail">
            <label class="label" for="remedial_action">Remedial action</label>
            <textarea class="input" id="remedial_action" name="remedial_action" rows="3" maxlength="5000"
                      placeholder="What was done, or what needs doing before this item goes back into use."><?= e($value('remedial_action')) ?></textarea>
        </div>

        <?php if (!$isEdit): ?>
            <div data-pat-when-result="Fail">
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="withdraw_from_use" value="1" checked>
                        <span>Move this asset to “In Maintenance”<span class="field-hint">Keeps a failed item out of the available stock.</span></span>
                    </label>
                </div>

                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="mark_out_of_service" value="1">
                        <span>Mark the condition as “Out of Service”</span>
                    </label>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Tester</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="tester_user_id">Staff member</label>
                <select class="input" id="tester_user_id" name="tester_user_id">
                    <option value="">Not one of our users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>"
                            <?= $value('tester_user_id', (string) (auth_user()['id'] ?? '')) === (string) $user['id'] ? 'selected' : '' ?>>
                            <?= e($user['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="tester_name">Tester name <span class="optional">(or contractor)</span></label>
                <input class="input" type="text" id="tester_name" name="tester_name" maxlength="191"
                       value="<?= e($value('tester_name')) ?>">
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="tester_reference">Tester ID / competency reference <span class="optional">(optional)</span></label>
                <input class="input" type="text" id="tester_reference" name="tester_reference" maxlength="100"
                       value="<?= e($value('tester_reference')) ?>">
            </div>

            <div class="field">
                <label class="label" for="test_equipment">Test equipment <span class="optional">(optional)</span></label>
                <input class="input" type="text" id="test_equipment" name="test_equipment" maxlength="191"
                       placeholder="Make, model and serial of the PAT tester" value="<?= e($value('test_equipment')) ?>">
            </div>
        </div>

        <div class="field">
            <label class="label" for="notes">Notes <span class="optional">(optional)</span></label>
            <textarea class="input" id="notes" name="notes" rows="3" maxlength="5000"><?= e($value('notes')) ?></textarea>
        </div>
    </div>

    <div class="form-actions sticky-actions">
        <button type="submit" class="btn btn-primary btn-lg"><?= $isEdit ? 'Save changes' : 'Record test' ?></button>
        <a class="btn btn-ghost" href="<?= e($asset !== null ? url('/assets/' . $asset['id'] . '/pat') : url('/pat')) ?>">Cancel</a>
    </div>
</form>
