<?php

use App\Models\Asset;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceSchedule;

/**
 * Carrying out a routine.
 *
 * One page at a time with a progress rail, in the same spirit as the PAT
 * wizard — and, like it, only a convenience. Without JavaScript every page is
 * simply visible at once and the form submits perfectly well; the server
 * enforces the required steps either way.
 *
 * The last page is not part of the routine. It is the maintenance record the
 * completion produces, which is what puts the work in the asset's history.
 *
 * @var array<string,mixed> $asset
 * @var array<string,mixed> $routine
 * @var array<string,mixed> $version
 * @var array<string,mixed>|null $schedule
 * @var array<int,array<string,mixed>> $pages
 * @var array<int,array<string,mixed>> $users
 * @var string $startedAt
 * @var string|null $nextDue
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$assetId    = (int) $asset['id'];
$routineId  = (int) $routine['id'];
$scheduleId = $schedule === null ? 0 : (int) $schedule['id'];

$action = url('/assets/' . $assetId . '/routines/' . $routineId . '/run'
    . ($scheduleId > 0 ? '?schedule=' . $scheduleId : ''));

$cancelUrl = $scheduleId > 0 ? url('/maintenance/' . $scheduleId) : url('/assets/' . $assetId);

/** Answers as they were posted, so a rejected submission is not retyped. */
$answers = is_array($old['step'] ?? null) ? $old['step'] : [];

$finishStep = count($pages) + 1;
?>
<div class="page-head">
    <div>
        <p class="eyebrow">
            <a href="<?= e(url('/assets/' . $assetId)) ?>"><span class="mono"><?= e($asset['asset_tag']) ?></span></a>
            <?= e(str_limit((string) $asset['name'], 60)) ?>
        </p>
        <h1><?= e($routine['name']) ?></h1>
        <p class="badge-row">
            <span class="badge">v<?= (int) $version['version_number'] ?></span>
            <?php if ($schedule !== null): ?>
                <span class="badge badge-muted"><?= e(str_limit((string) $schedule['title'], 60)) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e($cancelUrl) ?>">Cancel</a>
</div>

<?php if ($errors !== []): ?>
    <div class="flash flash-error">
        <span class="flash-text">
            <strong>Nothing was saved.</strong> Some steps still need answering — they are marked below.
        </span>
    </div>
<?php endif; ?>

<?php if ($schedule !== null && !empty($schedule['instructions'])): ?>
    <div class="card notice-card">
        <h2>Instructions for this job</h2>
        <p class="prewrap"><?= e($schedule['instructions']) ?></p>
    </div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form routine-wizard"
      data-routine-wizard novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="started_at" value="<?= e($startedAt) ?>">
    <?php if ($scheduleId > 0): ?>
        <input type="hidden" name="schedule_id" value="<?= (int) $scheduleId ?>">
    <?php endif; ?>

    <ol class="wizard-progress" data-wizard-progress aria-label="Progress"></ol>

    <?php foreach ($pages as $index => $page): ?>
        <section class="card wizard-step routine-run-page"
                 data-wizard-step="<?= (int) $index + 1 ?>"
                 data-step-name="<?= e(str_limit((string) $page['title'], 28)) ?>">
            <h2><?= (int) $index + 1 ?>. <?= e($page['title']) ?></h2>
            <?php if (!empty($page['description'])): ?>
                <p class="muted"><?= e($page['description']) ?></p>
            <?php endif; ?>

            <?php foreach ($page['steps'] as $step): ?>
                <?= partial('partials/routine-field', [
                    'step'     => $step,
                    'disabled' => false,
                    'answers'  => $answers,
                    'errors'   => $errors,
                ]) ?>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

    <?php /* The record the completion becomes. Not authored by whoever built
             the routine — every routine produces a maintenance entry, and the
             fields that entry needs are the same ones every other completion
             form asks for. */ ?>
    <section class="card wizard-step" data-wizard-step="<?= (int) $finishStep ?>" data-step-name="Finish">
        <h2><?= (int) $finishStep ?>. Finish</h2>
        <p class="muted">This is recorded in <?= e($asset['asset_tag']) ?>'s maintenance history.</p>

        <div class="field-row">
            <div class="field">
                <label class="label" for="performed_on">Date performed</label>
                <input class="input<?= isset($errors['performed_on']) ? ' has-error' : '' ?>" type="date"
                       id="performed_on" name="performed_on" required max="<?= e(date('Y-m-d')) ?>"
                       value="<?= e(old($old, 'performed_on', date('Y-m-d'))) ?>">
                <?php if (isset($errors['performed_on'])): ?><p class="field-error"><?= e($errors['performed_on']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="result">Result</label>
                <select class="input" id="result" name="result" required>
                    <?php foreach (MaintenanceLog::RESULTS as $result): ?>
                        <option value="<?= e($result) ?>" <?= old($old, 'result', 'Completed') === $result ? 'selected' : '' ?>>
                            <?= e($result) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="performed_by_user_id">Carried out by</label>
                <select class="input" id="performed_by_user_id" name="performed_by_user_id">
                    <option value="">— me —</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>"
                            <?= old($old, 'performed_by_user_id') === (string) $user['id'] ? 'selected' : '' ?>>
                            <?= e($user['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="condition_after">Condition afterwards <span class="optional">(optional)</span></label>
                <select class="input" id="condition_after" name="condition_after">
                    <option value="">Leave unchanged (<?= e($asset['condition_rating']) ?>)</option>
                    <?php foreach (Asset::CONDITIONS as $condition): ?>
                        <option value="<?= e($condition) ?>" <?= old($old, 'condition_after') === $condition ? 'selected' : '' ?>>
                            <?= e($condition) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label class="label" for="notes">Anything to add <span class="optional">(optional)</span></label>
            <textarea class="input" id="notes" name="notes" rows="3" maxlength="5000"
                      placeholder="Anything the steps above did not cover."><?= e(old($old, 'notes')) ?></textarea>
        </div>

        <?php if ($schedule !== null && $schedule['maintenance_type'] !== 'ad-hoc'): ?>
            <div class="field">
                <label class="label" for="next_due_date">Next due</label>
                <input class="input<?= isset($errors['next_due_date']) ? ' has-error' : '' ?>" type="date"
                       id="next_due_date" name="next_due_date"
                       value="<?= e(old($old, 'next_due_date', (string) $nextDue)) ?>">
                <p class="field-hint">
                    Worked out from the date performed — this job repeats
                    <?= e(strtolower(MaintenanceSchedule::describeFrequency($schedule))) ?>.
                </p>
                <?php if (isset($errors['next_due_date'])): ?><p class="field-error"><?= e($errors['next_due_date']) ?></p><?php endif; ?>
            </div>
        <?php elseif ($schedule !== null): ?>
            <p class="muted">This is a one-off job, so it closes once this is saved.</p>
        <?php endif; ?>

        <?php if ($asset['status'] === 'In Maintenance'): ?>
            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="return_to_stock" value="1" checked>
                    <span>Put this asset back in stock<span class="field-hint">It is currently marked as in maintenance.</span></span>
                </label>
            </div>
        <?php endif; ?>
    </section>

    <div class="wizard-nav">
        <button type="button" class="btn" data-wizard-back hidden>&larr; Back</button>
        <span class="wizard-count muted" data-wizard-count></span>
        <button type="button" class="btn btn-primary" data-wizard-next hidden>Next &rarr;</button>
        <button type="submit" class="btn btn-primary" data-wizard-save>Save this record</button>
    </div>
</form>

<script src="<?= e(asset_url('js/routine-wizard.js')) ?>" defer></script>
