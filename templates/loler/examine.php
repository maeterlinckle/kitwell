<?php

use App\Models\LolerExamination;

/**
 * The guided LOLER thorough examination.
 *
 * Three pages, in the order the work is actually done: confirm what the
 * equipment is, record what the examination found, then say who examined it
 * and for whom. The page order also happens to walk Schedule 1 — (3) and (5),
 * then (6) to (8), then (1), (2), (4) and (9) to (11) — and each block says
 * which paragraph it answers, so a change here can be checked against the
 * regulation rather than against somebody's memory of it.
 *
 * Without JavaScript every page is simply visible at once and the form submits.
 * LolerController::validateExamination() checks all of it either way.
 *
 * @var array<string,mixed> $asset
 * @var array<string,mixed>|null $previous
 * @var int $interval
 * @var string|null $suggestedNext
 * @var string $basis
 * @var array<int,array<string,mixed>> $examiners
 * @var array{name:string,address:string} $organisation
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$assetId = (int) $asset['id'];

$value = static function (string $field, mixed $default = '') use ($old): string {
    return array_key_exists($field, $old) ? (string) $old[$field] : (string) $default;
};

$ticked = static function (string $field, bool $default = false) use ($old): bool {
    return $old === [] ? $default : array_key_exists($field, $old);
};

$manufactureUnknown = $old === []
    ? (int) ($asset['loler_manufacture_unknown'] ?? 0) === 1
    : array_key_exists('manufacture_unknown', $old);

/** Defect rows as they were posted, so a rejected submission is not retyped. */
$defects = is_array($old['defect'] ?? null) ? array_values($old['defect']) : [];

if ($defects === []) {
    $defects = [['category' => '', 'part_identified' => '', 'description' => '', 'remedy' => '', 'becomes_danger_by' => '']];
}
?>
<div class="page-head">
    <div>
        <p class="eyebrow">
            <a href="<?= e(url('/assets/' . $assetId)) ?>"><span class="mono"><?= e($asset['asset_tag']) ?></span></a>
            <?= e(str_limit((string) $asset['name'], 60)) ?>
        </p>
        <h1>Report of thorough examination</h1>
        <p class="muted">LOLER 1998 regulation 9 &middot; report content per Schedule 1</p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/assets/' . $assetId . '/loler')) ?>">Examination history</a>
</div>

<?php if ($errors !== []): ?>
    <div class="flash flash-error">
        <span class="flash-text">
            <strong>Nothing was recorded.</strong> The report is incomplete or contradicts itself &mdash;
            the fields below say what needs attention.
        </span>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/assets/' . $assetId . '/loler/examine')) ?>"
      class="form form-wide loler-wizard" novalidate
      data-loler-wizard
      data-org-name="<?= e($organisation['name']) ?>"
      data-org-address="<?= e($organisation['address']) ?>">
    <?= csrf_field() ?>

    <ol class="wizard-progress" data-wizard-progress aria-label="Progress"></ol>

    <?php /* ---- 1. The equipment — Schedule 1(3) and (5) ------------------ */ ?>
    <section class="card wizard-step" data-wizard-step="1" data-step-name="The equipment">
        <h2>1. The equipment</h2>
        <p class="muted">
            Held against the asset and shown here to be checked against the equipment in front of
            you. Correct anything that is wrong &mdash; the correction is written back to the asset,
            so the register stays right from here on.
        </p>

        <div class="confirm-all-wrap" data-confirm-all-wrap hidden>
            <button type="button" class="btn btn-sm" data-confirm-all>Confirm all as shown</button>
            <span class="field-hint">Tick every box below in one go, when nothing has changed.</span>
        </div>

        <div class="confirm-row<?= isset($errors['loler_type']) ? ' has-error' : '' ?>">
            <div class="field">
                <label class="label" for="loler_type">Type of lifting equipment or accessory</label>
                <select class="input" id="loler_type" name="loler_type" required>
                    <option value="">&mdash; choose &mdash;</option>
                    <?php foreach (LolerExamination::TYPES as $key => [$label, $kind]): ?>
                        <option value="<?= e($key) ?>"
                                data-kind="<?= e($kind) ?>"
                            <?= $value('loler_type', (string) ($asset['loler_type'] ?? '')) === $key ? 'selected' : '' ?>>
                            <?= e($label) ?><?= $kind === 'accessory' ? ' (accessory for lifting)' : ($kind === 'persons' ? ' (lifts persons)' : '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">
                    An accessory for lifting, or equipment that lifts people, must be examined at least
                    every 6 months; other lifting equipment at least every 12.
                </p>
                <?php if (isset($errors['loler_type'])): ?><p class="field-error"><?= e($errors['loler_type']) ?></p><?php endif; ?>
            </div>
            <label class="checkbox confirm-tick">
                <input type="checkbox" name="confirm_type" value="1" data-confirm required
                    <?= $ticked('confirm_type') ? 'checked' : '' ?>>
                <span>Confirmed</span>
            </label>
        </div>

        <div class="confirm-row<?= isset($errors['interval_months']) ? ' has-error' : '' ?>">
            <div class="field">
                <label class="label" for="interval_months">Examination interval (months)</label>
                <input class="input" type="number" id="interval_months" name="interval_months"
                       min="1" max="120" step="1" inputmode="numeric" required
                       data-interval
                       value="<?= e($value('interval_months', (string) $interval)) ?>">
                <p class="field-hint">
                    6 or 12 months as the regulation sets, or whatever interval a written examination
                    scheme specifies for this item.
                </p>
                <?php if (isset($errors['interval_months'])): ?><p class="field-error"><?= e($errors['interval_months']) ?></p><?php endif; ?>
            </div>
            <label class="checkbox confirm-tick">
                <input type="checkbox" name="confirm_interval" value="1" data-confirm required
                    <?= $ticked('confirm_interval') ? 'checked' : '' ?>>
                <span>Confirmed</span>
            </label>
        </div>

        <div class="confirm-row<?= isset($errors['swl']) || isset($errors['swl_unit']) ? ' has-error' : '' ?>">
            <div class="field">
                <span class="label">Safe working load / working load limit</span>
                <div class="field-row">
                    <div class="field">
                        <label class="sr-only" for="swl">SWL</label>
                        <input class="input" type="number" id="swl" name="swl" step="0.001" min="0"
                               inputmode="decimal"
                               value="<?= e($value('swl', $asset['loler_swl'] === null ? '' : rtrim(rtrim((string) $asset['loler_swl'], '0'), '.'))) ?>">
                    </div>
                    <div class="field">
                        <label class="sr-only" for="swl_unit">Unit</label>
                        <select class="input" id="swl_unit" name="swl_unit">
                            <option value="">&mdash; unit &mdash;</option>
                            <?php foreach (LolerExamination::SWL_UNITS as $unit): ?>
                                <option value="<?= e($unit) ?>"
                                    <?= $value('swl_unit', (string) ($asset['loler_swl_unit'] ?? '')) === $unit ? 'selected' : '' ?>>
                                    <?= e($unit) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="swl_configuration">
                        Configuration <span class="optional">(optional)</span>
                    </label>
                    <input class="input" type="text" id="swl_configuration" name="swl_configuration" maxlength="255"
                           placeholder="e.g. 12 m boom, outriggers fully extended"
                           value="<?= e($value('swl_configuration')) ?>">
                    <p class="field-hint">
                        Where the safe working load depends on how the equipment is set up, name the
                        configuration this examination covers.
                    </p>
                </div>
                <?php if (isset($errors['swl_unit'])): ?><p class="field-error"><?= e($errors['swl_unit']) ?></p><?php endif; ?>
            </div>
            <label class="checkbox confirm-tick">
                <input type="checkbox" name="confirm_swl" value="1" data-confirm required
                    <?= $ticked('confirm_swl') ? 'checked' : '' ?>>
                <span>Confirmed</span>
            </label>
        </div>

        <div class="confirm-row">
            <div class="field">
                <label class="label" for="serial_number">Serial number</label>
                <input class="input mono" type="text" id="serial_number" name="serial_number" maxlength="191"
                       value="<?= e($value('serial_number', (string) ($asset['serial_number'] ?? ''))) ?>">
                <p class="field-hint">The asset's own serial number. Correcting it here corrects it on the asset.</p>
            </div>
            <label class="checkbox confirm-tick">
                <input type="checkbox" name="confirm_serial" value="1" data-confirm required
                    <?= $ticked('confirm_serial') ? 'checked' : '' ?>>
                <span>Confirmed</span>
            </label>
        </div>

        <div class="confirm-row<?= isset($errors['date_of_manufacture']) ? ' has-error' : '' ?>">
            <div class="field">
                <label class="label" for="date_of_manufacture">Date of manufacture</label>
                <input class="input" type="date" id="date_of_manufacture" name="date_of_manufacture"
                       max="<?= e(date('Y-m-d')) ?>" data-manufacture-date
                       value="<?= e($value('date_of_manufacture', (string) ($asset['loler_date_of_manufacture'] ?? ''))) ?>">
                <label class="checkbox">
                    <input type="checkbox" name="manufacture_unknown" value="1" data-manufacture-unknown
                        <?= $manufactureUnknown ? 'checked' : '' ?>>
                    <span>
                        Not known or not marked
                        <span class="field-hint">
                            Schedule 1(3) asks for the date of manufacture where known. Older and
                            unbranded equipment frequently carries none.
                        </span>
                    </span>
                </label>
                <?php if (isset($errors['date_of_manufacture'])): ?><p class="field-error"><?= e($errors['date_of_manufacture']) ?></p><?php endif; ?>
            </div>
            <label class="checkbox confirm-tick">
                <input type="checkbox" name="confirm_manufacture" value="1" data-confirm required
                    <?= $ticked('confirm_manufacture') ? 'checked' : '' ?>>
                <span>Confirmed</span>
            </label>
        </div>
    </section>

    <?php /* ---- 2. The examination — Schedule 1(6), (7) and (8) ----------- */ ?>
    <section class="card wizard-step" data-wizard-step="2" data-step-name="The examination">
        <h2>2. The examination</h2>

        <div class="field">
            <label class="label" for="examination_basis">This examination is being carried out</label>
            <select class="input" id="examination_basis" name="examination_basis" required data-basis>
                <?php foreach (LolerExamination::BASES as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $value('examination_basis', $basis) === $key ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">Schedule 1(7)(a). Suggested from the type and interval above.</p>
        </div>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="is_first_examination" value="1"
                       data-toggles="#first-examination" <?= $ticked('is_first_examination') ? 'checked' : '' ?>>
                <span>
                    This is the first thorough examination after installation, or after assembly at a
                    new site or in a new location
                    <span class="field-hint">Schedule 1(6).</span>
                </span>
            </label>
        </div>

        <div id="first-examination" class="conditional-block">
            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="installed_correctly" value="1"
                        <?= $ticked('installed_correctly') ? 'checked' : '' ?>>
                    <span>It has been installed correctly<span class="field-hint">Schedule 1(6)(b).</span></span>
                </label>
            </div>
        </div>

        <h3 class="group-title">What the examination found</h3>

        <div class="field">
            <span class="label">Outcome</span>
            <div class="radio-cards">
                <label class="radio-card">
                    <input type="radio" name="outcome" value="none" data-outcome
                        <?= $value('outcome', 'none') === 'none' ? 'checked' : '' ?>>
                    <span class="radio-card-body">
                        <span class="radio-card-title">None</span>
                        <span class="radio-card-hint">
                            No defect found. The equipment passed this examination.
                        </span>
                    </span>
                </label>
                <label class="radio-card">
                    <input type="radio" name="outcome" value="defects" data-outcome
                        <?= $value('outcome') === 'defects' ? 'checked' : '' ?>>
                    <span class="radio-card-body">
                        <span class="radio-card-title">Defects found</span>
                        <span class="radio-card-hint">
                            One or more defects, each categorised below.
                        </span>
                    </span>
                </label>
            </div>
            <?php if (isset($errors['outcome'])): ?><p class="field-error"><?= e($errors['outcome']) ?></p><?php endif; ?>
        </div>

        <div id="defect-block" data-defect-block<?= $value('outcome', 'none') === 'defects' ? '' : ' hidden' ?>>
            <p class="field-hint">
                Schedule 1(8) splits a defect two ways, and the consequences differ. A defect that
                <strong>is</strong> a danger stops the equipment being used until it is put right; one
                that <strong>could become</strong> a danger stops it being used after the date you give.
            </p>

            <div data-defect-rows>
                <?php foreach ($defects as $index => $defect): ?>
                    <?php $key = 'defect.' . $index; ?>
                    <fieldset class="defect-row<?= isset($errors[$key]) ? ' has-error' : '' ?>" data-defect-row>
                        <legend>Defect <span data-defect-number><?= (int) $index + 1 ?></span></legend>

                        <div class="field">
                            <span class="label">Category</span>
                            <?php foreach (LolerExamination::DEFECT_CATEGORIES as $categoryKey => $meta): ?>
                                <label class="checkbox">
                                    <input type="radio" name="defect[<?= (int) $index ?>][category]"
                                           value="<?= e($categoryKey) ?>" data-defect-category
                                        <?= (string) ($defect['category'] ?? '') === $categoryKey ? 'checked' : '' ?>>
                                    <span>
                                        <?= e($meta['label']) ?>
                                        <span class="field-hint"><?= e($meta['help']) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="field-row">
                            <div class="field">
                                <label class="label" for="defect-part-<?= (int) $index ?>">Part with the defect</label>
                                <input class="input" type="text" id="defect-part-<?= (int) $index ?>"
                                       name="defect[<?= (int) $index ?>][part_identified]" maxlength="255"
                                       placeholder="e.g. Lower hook block"
                                       value="<?= e((string) ($defect['part_identified'] ?? '')) ?>">
                                <p class="field-hint">Schedule 1(8)(a).</p>
                            </div>

                            <div class="field" data-defect-when<?= (string) ($defect['category'] ?? '') === 'becoming_danger' ? '' : ' hidden' ?>>
                                <label class="label" for="defect-by-<?= (int) $index ?>">Could become a danger by</label>
                                <input class="input" type="date" id="defect-by-<?= (int) $index ?>"
                                       name="defect[<?= (int) $index ?>][becomes_danger_by]"
                                       value="<?= e((string) ($defect['becomes_danger_by'] ?? '')) ?>">
                                <p class="field-hint">Schedule 1(8)(c)(i).</p>
                            </div>
                        </div>

                        <div class="field">
                            <label class="label" for="defect-desc-<?= (int) $index ?>">Description of the defect</label>
                            <textarea class="input" id="defect-desc-<?= (int) $index ?>"
                                      name="defect[<?= (int) $index ?>][description]" rows="2" maxlength="5000"><?= e((string) ($defect['description'] ?? '')) ?></textarea>
                        </div>

                        <div class="field">
                            <label class="label" for="defect-remedy-<?= (int) $index ?>">
                                Repair, renewal or alteration required
                            </label>
                            <textarea class="input" id="defect-remedy-<?= (int) $index ?>"
                                      name="defect[<?= (int) $index ?>][remedy]" rows="2" maxlength="5000"><?= e((string) ($defect['remedy'] ?? '')) ?></textarea>
                            <p class="field-hint">Schedule 1(8)(b) and (8)(c)(ii).</p>
                        </div>

                        <div class="field">
                            <label class="checkbox">
                                <input type="checkbox" name="defect[<?= (int) $index ?>][serious_injury_risk]" value="1"
                                       data-serious
                                    <?= !empty($defect['serious_injury_risk']) ? 'checked' : '' ?>>
                                <span>
                                    This defect involves an existing or imminent risk of serious personal injury
                                    <span class="field-hint">
                                        Regulation 10(1)(c) then requires <strong>you</strong> to send a copy of
                                        this report to the relevant enforcing authority as soon as is
                                        practicable. This application records that the duty applies; it does
                                        not send anything to anyone.
                                    </span>
                                </span>
                            </label>
                        </div>

                        <div class="defect-notice" data-serious-notice hidden>
                            <strong>Regulation 10(1)(c) applies.</strong>
                            A copy of this report must be sent to the relevant enforcing authority as soon as
                            is practicable &mdash; the HSE where the equipment is hired or leased, otherwise the
                            enforcing authority for these premises. Sending it is yours to do.
                        </div>

                        <?php if (isset($errors[$key])): ?><p class="field-error"><?= e($errors[$key]) ?></p><?php endif; ?>
                    </fieldset>
                <?php endforeach; ?>
            </div>

            <div class="form-actions form-actions-inline">
                <button type="button" class="btn btn-sm" data-add-defect hidden>Add another defect</button>
            </div>
        </div>

        <h3 class="group-title">Testing</h3>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="testing_carried_out" value="1"
                       data-toggles="#test-particulars" <?= $ticked('testing_carried_out') ? 'checked' : '' ?>>
                <span>This examination included testing<span class="field-hint">Schedule 1(8)(e).</span></span>
            </label>
        </div>

        <div id="test-particulars" class="conditional-block">
            <div class="field">
                <label class="label" for="test_particulars">Particulars of the test</label>
                <textarea class="input<?= isset($errors['test_particulars']) ? ' has-error' : '' ?>"
                          id="test_particulars" name="test_particulars" rows="3" maxlength="5000"
                          placeholder="What was tested, how, and to what load."><?= e($value('test_particulars')) ?></textarea>
                <?php if (isset($errors['test_particulars'])): ?><p class="field-error"><?= e($errors['test_particulars']) ?></p><?php endif; ?>
            </div>
        </div>

        <h3 class="group-title">Declaration</h3>

        <div class="field<?= isset($errors['safe_to_operate']) ? ' has-error' : '' ?>">
            <label class="checkbox">
                <input type="checkbox" name="safe_to_operate" value="1" data-safe
                    <?= $ticked('safe_to_operate') ? 'checked' : '' ?>>
                <span>
                    In my opinion this equipment would be safe to operate
                    <span class="field-hint">
                        Schedule 1(6)(b) and (7)(b). This is your professional judgement as the
                        competent person, not something the application determines.
                    </span>
                </span>
            </label>
            <?php if (isset($errors['safe_to_operate'])): ?><p class="field-error"><?= e($errors['safe_to_operate']) ?></p><?php endif; ?>
        </div>

        <div class="defect-notice" data-danger-notice hidden>
            <strong>A defect that is a danger to persons has been recorded.</strong>
            The employer must be notified forthwith (regulation 10(1)(a)), and the equipment must not be
            used before the defect is rectified (regulation 10(3)(a)).
        </div>

        <div class="field" data-out-of-service hidden>
            <label class="checkbox">
                <input type="checkbox" name="take_out_of_service" value="1" checked>
                <span>
                    Mark this asset as faulty so it is not issued
                    <span class="field-hint">
                        A record in this system is not a physical control. Make sure the equipment
                        itself is taken out of use.
                    </span>
                </span>
            </label>
        </div>

        <div class="field">
            <label class="label" for="notes">Notes <span class="optional">(optional)</span></label>
            <textarea class="input" id="notes" name="notes" rows="2" maxlength="5000"><?= e($value('notes')) ?></textarea>
        </div>
    </section>

    <?php /* ---- 3. The report — Schedule 1(1),(2),(4),(8)(d),(9)-(11) ----- */ ?>
    <section class="card wizard-step" data-wizard-step="3" data-step-name="The report">
        <h2>3. The report</h2>

        <h3 class="group-title">Who it is for</h3>

        <div class="field">
            <label class="label" for="employer_name">
                Employer for whom the examination was made
                <button type="button" class="btn btn-sm btn-inline" data-fill="employer" hidden>Use our details</button>
            </label>
            <input class="input<?= isset($errors['employer_name']) ? ' has-error' : '' ?>" type="text"
                   id="employer_name" name="employer_name" maxlength="191" required
                   value="<?= e($value('employer_name', $organisation['name'])) ?>">
            <?php if (isset($errors['employer_name'])): ?><p class="field-error"><?= e($errors['employer_name']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="employer_address">Employer address</label>
            <textarea class="input<?= isset($errors['employer_address']) ? ' has-error' : '' ?>"
                      id="employer_address" name="employer_address" rows="3" maxlength="500" required><?= e($value('employer_address', $organisation['address'])) ?></textarea>
            <p class="field-hint">Schedule 1(1).</p>
            <?php if (isset($errors['employer_address'])): ?><p class="field-error"><?= e($errors['employer_address']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="examination_address">
                Address of the premises where the examination was made
                <button type="button" class="btn btn-sm btn-inline" data-fill="examination" hidden>Use our details</button>
            </label>
            <textarea class="input<?= isset($errors['examination_address']) ? ' has-error' : '' ?>"
                      id="examination_address" name="examination_address" rows="3" maxlength="500" required><?= e($value('examination_address', $organisation['address'])) ?></textarea>
            <p class="field-hint">Schedule 1(2).</p>
            <?php if (isset($errors['examination_address'])): ?><p class="field-error"><?= e($errors['examination_address']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="owner_name">
                Owner of the equipment, or whom it is hired or leased from
                <span class="optional">(optional)</span>
                <button type="button" class="btn btn-sm btn-inline" data-fill="owner" hidden>Use our details</button>
            </label>
            <input class="input" type="text" id="owner_name" name="owner_name" maxlength="191"
                   value="<?= e($value('owner_name', $organisation['name'])) ?>">
            <p class="field-hint">
                Where the equipment is hired or leased, regulation 10(1)(b)(ii) requires a copy of this
                report to go to them as well as to the employer.
            </p>
        </div>

        <div class="field">
            <label class="label" for="owner_address">Owner address <span class="optional">(optional)</span></label>
            <textarea class="input" id="owner_address" name="owner_address" rows="3" maxlength="500"><?= e($value('owner_address', $organisation['address'])) ?></textarea>
        </div>

        <h3 class="group-title">Who made the examination</h3>

        <div class="field">
            <label class="label" for="examiner_user_id">Examiner (competent person)</label>
            <select class="input<?= isset($errors['examiner_user_id']) ? ' has-error' : '' ?>"
                    id="examiner_user_id" name="examiner_user_id" required>
                <option value="">&mdash; choose &mdash;</option>
                <?php foreach ($examiners as $examiner): ?>
                    <option value="<?= (int) $examiner['id'] ?>"
                        <?= $value('examiner_user_id', (string) (auth_user()['id'] ?? '')) === (string) $examiner['id'] ? 'selected' : '' ?>>
                        <?= e($examiner['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">
                Only accounts holding the LOLER examination permission are listed, and the server
                refuses any other.
            </p>
            <?php if (isset($errors['examiner_user_id'])): ?><p class="field-error"><?= e($errors['examiner_user_id']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="examiner_qualifications">
                Qualifications <span class="optional">(optional)</span>
            </label>
            <input class="input" type="text" id="examiner_qualifications" name="examiner_qualifications" maxlength="500"
                   placeholder="e.g. LEEA Diploma, 12 years on overhead cranes"
                   value="<?= e($value('examiner_qualifications')) ?>">
            <p class="field-hint">Schedule 1(9).</p>
        </div>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="examiner_self_employed" value="1"
                       data-hides="#examiner-employer"
                    <?= $ticked('examiner_self_employed') ? 'checked' : '' ?>>
                <span>The examiner is self-employed<span class="field-hint">Schedule 1(9).</span></span>
            </label>
        </div>

        <div id="examiner-employer" class="conditional-block">
            <div class="field">
                <label class="label" for="examiner_employer_name">
                    Examiner's employer
                    <button type="button" class="btn btn-sm btn-inline" data-fill="examiner" hidden>Use our details</button>
                </label>
                <input class="input" type="text" id="examiner_employer_name" name="examiner_employer_name" maxlength="191"
                       value="<?= e($value('examiner_employer_name', $organisation['name'])) ?>">
            </div>

            <div class="field">
                <label class="label" for="examiner_employer_address">Examiner's employer address</label>
                <textarea class="input" id="examiner_employer_address" name="examiner_employer_address"
                          rows="3" maxlength="500"><?= e($value('examiner_employer_address', $organisation['address'])) ?></textarea>
            </div>
        </div>

        <h3 class="group-title">Dates</h3>

        <div class="field-row">
            <div class="field">
                <label class="label" for="previous_examination_date">
                    Date of the last thorough examination <span class="optional">(if any)</span>
                </label>
                <input class="input<?= isset($errors['previous_examination_date']) ? ' has-error' : '' ?>" type="date"
                       id="previous_examination_date" name="previous_examination_date"
                       value="<?= e($value('previous_examination_date', (string) ($previous['examined_on'] ?? ''))) ?>">
                <p class="field-hint">
                    Schedule 1(4).
                    <?= $previous === null
                        ? 'No earlier examination is on record here; enter one from a paper record if there is one.'
                        : 'Taken from the last examination recorded here.' ?>
                </p>
                <?php if (isset($errors['previous_examination_date'])): ?><p class="field-error"><?= e($errors['previous_examination_date']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="examined_on">Date of this examination</label>
                <input class="input<?= isset($errors['examined_on']) ? ' has-error' : '' ?>" type="date"
                       id="examined_on" name="examined_on" required max="<?= e(date('Y-m-d')) ?>"
                       data-examined-on
                       value="<?= e($value('examined_on', date('Y-m-d'))) ?>">
                <p class="field-hint">Schedule 1(8)(f).</p>
                <?php if (isset($errors['examined_on'])): ?><p class="field-error"><?= e($errors['examined_on']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="next_examination_date">
                    Latest date by which the next thorough examination must be carried out
                </label>
                <input class="input<?= isset($errors['next_examination_date']) ? ' has-error' : '' ?>" type="date"
                       id="next_examination_date" name="next_examination_date" required
                       data-next-examination
                       value="<?= e($value('next_examination_date', (string) $suggestedNext)) ?>">
                <p class="field-hint">
                    Schedule 1(8)(d). Worked out from the date of examination and the interval above;
                    change it if a written scheme or the condition of the equipment calls for sooner.
                </p>
                <?php if (isset($errors['next_examination_date'])): ?><p class="field-error"><?= e($errors['next_examination_date']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="reported_on">Date of this report</label>
                <input class="input<?= isset($errors['reported_on']) ? ' has-error' : '' ?>" type="date"
                       id="reported_on" name="reported_on" required
                       value="<?= e($value('reported_on', date('Y-m-d'))) ?>">
                <p class="field-hint">Schedule 1(11).</p>
                <?php if (isset($errors['reported_on'])): ?><p class="field-error"><?= e($errors['reported_on']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="card notice-card">
            <h3>Authentication</h3>
            <p class="muted">
                Regulation 10(1)(b) requires this report to be authenticated by signature or equally
                secure means. Submitting it records <strong><?= e((string) (auth_user()['name'] ?? '')) ?></strong>
                as having authenticated it, with the date and time. The printed report carries that
                statement and a signature line if a written signature is also wanted.
            </p>
        </div>
    </section>

    <div class="wizard-nav">
        <button type="button" class="btn" data-wizard-back hidden>&larr; Back</button>
        <span class="wizard-count muted" data-wizard-count></span>
        <button type="button" class="btn btn-primary" data-wizard-next hidden>Next &rarr;</button>
        <button type="submit" class="btn btn-primary btn-lg" data-wizard-save>Record this report</button>
    </div>
</form>

<script src="<?= e(asset_url('js/loler-wizard.js')) ?>" defer></script>
