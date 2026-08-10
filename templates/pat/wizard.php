<?php

use App\Models\Asset;
use App\Models\PatRecord;

/**
 * The guided PAT test.
 *
 * One step per screen, in the order the test is actually performed, with the
 * asset's fixed values carried alongside throughout so the tester never has to
 * remember or re-enter them.
 *
 * Without JavaScript every step is simply visible at once and the form still
 * submits — the overall result is derived on the server either way, so the
 * gating below is a convenience, never the control.
 *
 * @var array<string,mixed> $asset
 * @var array<int,array<string,mixed>> $users
 * @var string|null $suggestedDue
 * @var int $interval
 * @var array<int,string> $tests        electrical tests this class calls for
 * @var array<string,mixed>|null $lastTest
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */

$class    = (string) ($asset['appliance_class'] ?? '');
$hasFuse  = (int) ($asset['has_fuse'] ?? 0) === 1;
$fuse     = $asset['plug_fuse_rating_amps'];
$load     = $asset['load_rating_va'];

$value = static function (string $field, mixed $default = '') use ($old): string {
    return array_key_exists($field, $old) ? (string) $old[$field] : (string) $default;
};

// Which visual checks apply. The fuse check simply does not exist on an
// unfused item, so it is dropped rather than shown greyed out.
$visualChecks = PatRecord::VISUAL_CHECKS;
if (!$hasFuse) {
    unset($visualChecks['visual_fuse_pass']);
}

$stepCount = 4 + ($tests === [] ? 0 : 1);
?>
<div class="page-head">
    <div>
        <h1>Record a PAT test</h1>
        <p class="muted">
            <a href="<?= e(url('/assets/' . (int) $asset['id'])) ?>"><?= e($asset['asset_tag']) ?></a>
            · <?= e($asset['name']) ?>
        </p>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e(url('/assets/' . (int) $asset['id'] . '/pat')) ?>">Test history</a>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="flash flash-error">
        <p><strong>The test was not saved.</strong> Every applicable check has to be answered before a result can be recorded.</p>
    </div>
<?php endif; ?>

<div class="pat-wizard-layout">

    <?php /* ---- Step 1: the fixed values, visible throughout ------------- */ ?>
    <aside class="card pat-context" aria-label="Asset details for this test">
        <h2>This appliance</h2>
        <dl class="pat-context-list">
            <div>
                <dt>Appliance class</dt>
                <dd><strong><?= e($class) ?></strong></dd>
            </div>
            <div>
                <dt>Load rating</dt>
                <dd><?= $load !== null ? e(rtrim(rtrim(number_format((float) $load, 2, '.', ','), '0'), '.')) . ' VA' : '<span class="muted">not recorded</span>' ?></dd>
            </div>
            <div>
                <dt>Fuse</dt>
                <dd>
                    <?php if ($hasFuse): ?>
                        <?= $fuse !== null
                            ? '<strong>' . e(rtrim(rtrim(number_format((float) $fuse, 2, '.', ''), '0'), '.')) . ' A</strong> fitted'
                            : '<span class="muted">fused, rating not recorded</span>' ?>
                    <?php else: ?>
                        <span class="muted">none — fuse check skipped</span>
                    <?php endif; ?>
                </dd>
            </div>
            <?php if ($asset['cable_csa_mm2'] !== null): ?>
                <div>
                    <dt>Cable CSA</dt>
                    <dd><?= e(rtrim(rtrim(number_format((float) $asset['cable_csa_mm2'], 2, '.', ''), '0'), '.')) ?> mm²</dd>
                </div>
            <?php endif; ?>
            <?php if ($lastTest !== null): ?>
                <div>
                    <dt>Last test</dt>
                    <dd>
                        <?= e(format_date($lastTest['test_date'])) ?>
                        — <?= e($lastTest['overall_result']) ?>
                    </dd>
                </div>
            <?php endif; ?>
        </dl>
        <p class="field-hint">
            These are properties of the appliance, not of this test.
            <?php if (can('assets.edit')): ?>
                <a href="<?= e(url('/assets/' . (int) $asset['id'] . '/edit')) ?>">Correct them on the asset</a>
                if anything here is wrong.
            <?php endif; ?>
        </p>
    </aside>

    <form method="post" action="<?= e(url('/pat')) ?>" class="form pat-wizard" data-pat-wizard novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">

        <ol class="wizard-progress" data-wizard-progress aria-label="Progress"></ol>

        <?php /* ---- Step 2: visual and physical inspection --------------- */ ?>
        <section class="card wizard-step" data-wizard-step="1" data-step-name="Visual inspection">
            <h2>1. Visual inspection</h2>
            <p class="muted">Before any electrical test. Anything failing here stops the test.</p>

            <?php foreach ($visualChecks as $column => [$label, $help]): ?>
                <div class="check-row<?= isset($errors[$column]) ? ' has-error' : '' ?>">
                    <div class="check-text">
                        <h3><?= e($label) ?></h3>
                        <p class="field-hint">
                            <?php if ($column === 'visual_fuse_pass' && $fuse !== null): ?>
                                Confirm the fuse actually fitted is rated
                                <strong><?= e(rtrim(rtrim(number_format((float) $fuse, 2, '.', ''), '0'), '.')) ?> A</strong>,
                                as recorded for this asset.
                            <?php else: ?>
                                <?= e($help) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?= partial("partials/verdict", ["name" => $column, "current" => $value($column), "error" => $errors[$column] ?? ""]) ?>
                </div>
            <?php endforeach; ?>
        </section>

        <?php /* ---- Step 3: electrical tests for this class -------------- */ ?>
        <?php if ($tests !== []): ?>
            <section class="card wizard-step" data-wizard-step="2" data-step-name="Electrical tests">
                <h2>2. Electrical tests</h2>
                <p class="muted">
                    The tests a <?= e($class) ?> appliance calls for.
                    <?php if ($class === 'Class II'): ?>
                        There is no earth path on a double-insulated item, so earth continuity is not tested.
                    <?php endif; ?>
                </p>

                <?php foreach ($tests as $key): ?>
                    <?php [$valueColumn, $verdictColumn, $label, $unit, $step] = PatRecord::ELECTRICAL_TESTS[$key]; ?>

                    <?php if ($key === 'earth_continuity'): ?>
                        <div class="field">
                            <label class="label" for="extension_lead_metres">
                                Extension lead under test (m) <span class="optional">(optional)</span>
                            </label>
                            <input class="input" type="number" id="extension_lead_metres" name="extension_lead_metres"
                                   step="0.5" min="0" max="500" inputmode="decimal"
                                   data-guide-lead
                                   value="<?= e($value('extension_lead_metres')) ?>">
                            <p class="field-hint">Raises the earth continuity guideline below. Leave blank for the appliance alone.</p>
                        </div>
                    <?php endif; ?>

                    <div class="check-row check-row-test<?= isset($errors[$valueColumn]) || isset($errors[$verdictColumn]) ? ' has-error' : '' ?>">
                        <div class="check-text">
                            <h3><?= e($label) ?></h3>
                            <div class="field">
                                <label class="sr-only" for="<?= e($valueColumn) ?>"><?= e($label) ?> in <?= e($unit) ?></label>
                                <div class="input-with-unit">
                                    <input class="input<?= isset($errors[$valueColumn]) ? ' has-error' : '' ?>"
                                           type="number" id="<?= e($valueColumn) ?>" name="<?= e($valueColumn) ?>"
                                           step="<?= e($step) ?>" min="0" inputmode="decimal"
                                           value="<?= e($value($valueColumn)) ?>">
                                    <span class="input-unit"><?= e($unit) ?></span>
                                </div>
                                <?php if (isset($errors[$valueColumn])): ?>
                                    <p class="field-error"><?= e($errors[$valueColumn]) ?></p>
                                <?php endif; ?>
                            </div>
                            <?php
                            // The live recalculation in pat-wizard.js reads the
                            // configured settings from these attributes, so
                            // tuning them in Admin → Settings changes both the
                            // printed text and the maths behind it.
                            $g = $key === 'earth_continuity' ? PatRecord::guidelines() : null;
                            ?>
                            <p class="field-hint guideline"<?php if ($g !== null): ?> data-guide-earth
                               data-base="<?= e((string) $g['earth_base_ohm']) ?>"
                               data-per="<?= e((string) $g['earth_lead_ohm']) ?>"
                               data-metres="<?= e((string) $g['earth_lead_metres']) ?>"<?php endif; ?>>
                                <span class="guideline-tag">Guidance</span>
                                <?= e(PatRecord::guidelineText($key, $class, (float) $value('extension_lead_metres', '0'))) ?>
                                Your judgement decides the result, not this figure.
                            </p>
                        </div>
                        <?= partial("partials/verdict", ["name" => $verdictColumn, "current" => $value($verdictColumn), "error" => $errors[$verdictColumn] ?? ""]) ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php /* ---- Step 4: functional check ------------------------------ */ ?>
        <section class="card wizard-step" data-wizard-step="<?= $tests === [] ? 2 : 3 ?>" data-step-name="Function">
            <h2><?= $tests === [] ? 2 : 3 ?>. Functional check</h2>
            <div class="check-row<?= isset($errors['functional_check_pass']) ? ' has-error' : '' ?>">
                <div class="check-text">
                    <h3>Does the appliance function correctly when operated?</h3>
                    <p class="field-hint">Switch it on and use it as intended, where it is safe to do so.</p>
                </div>
                <?= partial("partials/verdict", ["name" => "functional_check_pass", "current" => $value("functional_check_pass"), "error" => $errors["functional_check_pass"] ?? ""]) ?>
            </div>
        </section>

        <?php /* ---- Step 5: result and record ----------------------------- */ ?>
        <section class="card wizard-step" data-wizard-step="<?= $tests === [] ? 3 : 4 ?>" data-step-name="Result">
            <h2><?= $tests === [] ? 3 : 4 ?>. Result</h2>

            <div class="result-banner" data-result-banner hidden>
                <p data-result-text></p>
            </div>

            <div class="field" data-fail-notes hidden>
                <label class="label" for="remedial_action">What failed, and what was done</label>
                <textarea class="input" id="remedial_action" name="remedial_action" rows="3"
                          placeholder="e.g. Cable damaged near the plug — item withdrawn and tagged."><?= e($value('remedial_action')) ?></textarea>
                <p class="field-hint">The failed checks are recorded automatically; this is for the detail.</p>
            </div>

            <div class="field-row">
                <div class="field">
                    <label class="label" for="test_date">Test date</label>
                    <input class="input<?= isset($errors['test_date']) ? ' has-error' : '' ?>" type="date"
                           id="test_date" name="test_date" required
                           max="<?= e(date('Y-m-d')) ?>"
                           value="<?= e($value('test_date', date('Y-m-d'))) ?>">
                    <?php if (isset($errors['test_date'])): ?><p class="field-error"><?= e($errors['test_date']) ?></p><?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="retest_due_date">Retest due</label>
                    <input class="input<?= isset($errors['retest_due_date']) ? ' has-error' : '' ?>" type="date"
                           id="retest_due_date" name="retest_due_date"
                           value="<?= e($value('retest_due_date', (string) $suggestedDue)) ?>">
                    <p class="field-hint">Suggested from the <?= (int) $interval ?>-month interval for this asset. Change it if needed.</p>
                    <?php if (isset($errors['retest_due_date'])): ?><p class="field-error"><?= e($errors['retest_due_date']) ?></p><?php endif; ?>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label class="label" for="tester_user_id">Tester</label>
                    <select class="input" id="tester_user_id" name="tester_user_id">
                        <option value="">— me —</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= (int) $user['id'] ?>" <?= $value('tester_user_id') === (string) $user['id'] ? 'selected' : '' ?>>
                                <?= e($user['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="label" for="tester_name">Or an outside tester <span class="optional">(optional)</span></label>
                    <input class="input" type="text" id="tester_name" name="tester_name" maxlength="191"
                           value="<?= e($value('tester_name')) ?>">
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label class="label" for="test_equipment">Test equipment <span class="optional">(optional)</span></label>
                    <input class="input" type="text" id="test_equipment" name="test_equipment" maxlength="191"
                           value="<?= e($value('test_equipment')) ?>">
                </div>

                <div class="field">
                    <label class="label" for="pat_label_serial">PAT label serial <span class="optional">(optional)</span></label>
                    <input class="input mono" type="text" id="pat_label_serial" name="pat_label_serial" maxlength="100"
                           value="<?= e($value('pat_label_serial')) ?>">
                </div>
            </div>

            <div class="field">
                <label class="label" for="notes">Notes <span class="optional">(optional)</span></label>
                <textarea class="input" id="notes" name="notes" rows="2"><?= e($value('notes')) ?></textarea>
            </div>
        </section>

        <div class="wizard-nav">
            <button type="button" class="btn" data-wizard-back hidden>&larr; Back</button>
            <span class="wizard-count muted" data-wizard-count></span>
            <button type="button" class="btn btn-primary" data-wizard-next hidden>Next &rarr;</button>
            <button type="submit" class="btn btn-primary" data-wizard-save>Save test</button>
        </div>
    </form>
</div>

<script src="<?= e(asset_url('js/pat-wizard.js')) ?>" defer></script>
