<?php

use App\Models\MaintenanceRoutine;

/**
 * One step of a routine, as the person carrying it out sees it.
 *
 * Shared by the runner and the preview so that what a routine's author is
 * shown is literally the control a technician will meet, not a drawing of it.
 * The preview passes $disabled, which is the only difference between them.
 *
 * @var array<string,mixed> $step
 * @var bool $disabled
 * @var array<int,mixed> $answers   Previous input, keyed by step id
 * @var array<string,string> $errors
 */
$step     = (array) $step;
$disabled = (bool) ($disabled ?? false);
$answers  = (array) ($answers ?? []);
$errors   = (array) ($errors ?? []);

$id       = (int) $step['id'];
$type     = (string) $step['field_type'];
$required = (int) $step['is_required'] === 1;
$name     = 'step[' . $id . ']';
$domId    = 'step-' . $id;
$error    = $errors['step.' . $id] ?? null;
$previous = $answers[$id] ?? null;
$options  = MaintenanceRoutine::options($step);

/** The previous answer as a plain string, for the single-value controls. */
$value = is_string($previous) ? $previous : '';
?>
<div class="routine-step<?= $error !== null ? ' has-error' : '' ?>"
     data-routine-step="<?= (int) $id ?>"
     data-required="<?= $required ? '1' : '0' ?>"
     data-field-type="<?= e($type) ?>">

    <?php if (in_array($type, ['boolean', 'single_choice', 'multi_choice', 'photo', 'document'], true)): ?>
        <p class="label routine-step-label" id="<?= e($domId) ?>-label">
            <?= e($step['label']) ?>
            <?php if ($required): ?><span class="req" title="Required">*</span><?php endif; ?>
        </p>
    <?php else: ?>
        <label class="label routine-step-label" for="<?= e($domId) ?>">
            <?= e($step['label']) ?>
            <?php if ($required): ?><span class="req" title="Required">*</span><?php endif; ?>
        </label>
    <?php endif; ?>

    <?php if (!empty($step['help_text'])): ?>
        <p class="field-hint routine-step-help"><?= e($step['help_text']) ?></p>
    <?php endif; ?>

    <?php switch ($type):
        case 'long_text': ?>
            <textarea class="input" id="<?= e($domId) ?>" name="<?= e($name) ?>" rows="4"
                      maxlength="5000" data-step-field<?= $disabled ? " disabled" : "" ?>><?= e($value) ?></textarea>
            <?php break; ?>

        <?php case 'number': ?>
            <div class="input-with-unit">
                <input class="input" type="number" id="<?= e($domId) ?>" name="<?= e($name) ?>"
                       step="any" inputmode="decimal" data-step-field
                       value="<?= e($value) ?>"<?= $disabled ? " disabled" : "" ?>>
                <?php if (!empty($step['unit'])): ?>
                    <span class="input-unit"><?= e($step['unit']) ?></span>
                <?php endif; ?>
            </div>
            <?php break; ?>

        <?php case 'date': ?>
            <input class="input" type="date" id="<?= e($domId) ?>" name="<?= e($name) ?>"
                   data-step-field value="<?= e($value) ?>"<?= $disabled ? " disabled" : "" ?>>
            <?php break; ?>

        <?php case 'boolean': ?>
            <div class="routine-choices routine-choices-inline" role="group" aria-labelledby="<?= e($domId) ?>-label">
                <?php foreach (['1' => 'Yes', '0' => 'No'] as $choiceValue => $choiceLabel): ?>
                    <label class="choice-pill">
                        <input type="radio" name="<?= e($name) ?>" value="<?= e((string) $choiceValue) ?>"
                               data-step-field <?= $value === (string) $choiceValue ? 'checked' : '' ?><?= $disabled ? " disabled" : "" ?>>
                        <span><?= e($choiceLabel) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php break; ?>

        <?php case 'single_choice': ?>
            <?php if ($options === []): ?>
                <p class="field-hint">This step offers no choices yet.</p>
            <?php elseif (count($options) > 6): ?>
                <select class="input" id="<?= e($domId) ?>" name="<?= e($name) ?>" data-step-field<?= $disabled ? " disabled" : "" ?>>
                    <option value="">— choose —</option>
                    <?php foreach ($options as $option): ?>
                        <option value="<?= e($option) ?>" <?= $value === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <div class="routine-choices" role="group" aria-labelledby="<?= e($domId) ?>-label">
                    <?php foreach ($options as $option): ?>
                        <label class="choice-pill">
                            <input type="radio" name="<?= e($name) ?>" value="<?= e($option) ?>"
                                   data-step-field <?= $value === $option ? 'checked' : '' ?><?= $disabled ? " disabled" : "" ?>>
                            <span><?= e($option) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php break; ?>

        <?php case 'multi_choice': ?>
            <?php $chosen = is_array($previous) ? array_map('strval', $previous) : []; ?>
            <?php if ($options === []): ?>
                <p class="field-hint">This step offers no choices yet.</p>
            <?php else: ?>
                <div class="routine-choices" role="group" aria-labelledby="<?= e($domId) ?>-label">
                    <?php foreach ($options as $option): ?>
                        <label class="choice-pill">
                            <input type="checkbox" name="<?= e($name) ?>[]" value="<?= e($option) ?>"
                                   data-step-field <?= in_array($option, $chosen, true) ? 'checked' : '' ?><?= $disabled ? " disabled" : "" ?>>
                            <span><?= e($option) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php break; ?>

        <?php case 'photo': ?>
            <?php /* The same camera/gallery pair the asset and maintenance forms
                     use, so a phone opens the camera straight away here too. */ ?>
            <?= partial('partials/photo-inputs', [
                'name'     => 'step_file_' . $id . '[]',
                'primary'  => false,
                'disabled' => $disabled,
            ]) ?>
            <p class="field-hint">
                Up to <?= (int) (config('uploads.max_photo_bytes') / 1048576) ?> MB each.
                Photographs taken here belong to this record alone.
            </p>
            <?php break; ?>

        <?php case 'document': ?>
            <input class="input" type="file" id="<?= e($domId) ?>" name="step_file_<?= (int) $id ?>[]"
                   accept="application/pdf,.pdf" multiple data-step-file<?= $disabled ? " disabled" : "" ?>>
            <p class="field-hint">PDF, up to <?= (int) (config('uploads.max_pdf_bytes') / 1048576) ?> MB each.</p>
            <?php break; ?>

        <?php default: ?>
            <input class="input" type="text" id="<?= e($domId) ?>" name="<?= e($name) ?>"
                   maxlength="1000" data-step-field value="<?= e($value) ?>"<?= $disabled ? " disabled" : "" ?>>
    <?php endswitch; ?>

    <?php if ($error !== null): ?>
        <p class="field-error"><?= e($error) ?></p>
    <?php endif; ?>
</div>
