<?php

use App\Models\MaintenanceSchedule;

/**
 * Create / edit a maintenance schedule.
 *
 * @var array<string,mixed>|null $schedule
 * @var array<string,mixed>|null $asset
 * @var array<int,array<string,mixed>> $assets
 * @var array<int,array<string,mixed>> $users
 * @var array<int,array<string,mixed>> $teams
 * @var array<int,array<string,mixed>> $routines
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$isEdit = $schedule !== null;
$action = $isEdit ? url('/maintenance/' . $schedule['id']) : url('/maintenance');

$value = static function (string $field, mixed $default = '') use ($old, $schedule): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    if ($schedule !== null && array_key_exists($field, $schedule) && $schedule[$field] !== null) {
        return (string) $schedule[$field];
    }

    return (string) $default;
};

$currentType = $value('maintenance_type', 'routine');

// A rejected form comes back through `old`, which carries the combined value;
// otherwise it is derived from whichever of the two columns is set.
$assignedTo = array_key_exists('assigned_to', $old)
    ? (string) $old['assigned_to']
    : MaintenanceSchedule::assigneeValue($schedule);
?>
<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit schedule' : 'New maintenance schedule' ?></h1>
        <?php if ($asset !== null): ?>
            <p class="muted">
                For <a href="<?= e(url('/assets/' . $asset['id'])) ?>"><span class="mono"><?= e($asset['asset_tag']) ?></span></a>
                — <?= e($asset['name']) ?>
            </p>
        <?php endif; ?>
    </div>
    <a class="btn btn-ghost" href="<?= e($isEdit ? url('/maintenance/' . $schedule['id']) : url('/maintenance')) ?>">Cancel</a>
</div>

<form method="post" action="<?= e($action) ?>" class="form form-wide" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <h2>The job</h2>

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
                <?php if (isset($errors['asset_id'])): ?><p class="field-error"><?= e($errors['asset_id']) ?></p><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="field">
            <label class="label" for="title">Title</label>
            <input class="input<?= isset($errors['title']) ? ' has-error' : '' ?>" type="text" id="title" name="title"
                   maxlength="191" required placeholder="e.g. Annual service, Brush inspection"
                   value="<?= e($value('title')) ?>">
            <?php if (isset($errors['title'])): ?><p class="field-error"><?= e($errors['title']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="instructions">Instructions <span class="optional">(optional)</span></label>
            <textarea class="input" id="instructions" name="instructions" rows="4" maxlength="5000"
                      placeholder="What needs doing, and anything the person doing it should know."><?= e($value('instructions')) ?></textarea>
        </div>

        <?php /* A routine sits beside the instructions rather than instead of
                 them: a job can have a procedure to follow and a line of
                 context about this particular machine. */ ?>
        <div class="field">
            <label class="label" for="routine_id">Routine to fill in <span class="optional">(optional)</span></label>
            <select class="input<?= isset($errors['routine_id']) ? ' has-error' : '' ?>" id="routine_id" name="routine_id">
                <option value="">No routine — record the work as free text</option>
                <?php foreach ($routines as $routine): ?>
                    <option value="<?= (int) $routine['id'] ?>" <?= $value('routine_id') === (string) $routine['id'] ? 'selected' : '' ?>>
                        <?= e($routine['name']) ?> (v<?= (int) $routine['current_version_number'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">
                Completing this job then steps through the routine instead of the free-text form, and
                follows whichever version is live at the time.
                <?php if ($routines === []): ?>
                    No routine has been published yet.
                <?php endif; ?>
            </p>
            <?php if (isset($errors['routine_id'])): ?><p class="field-error"><?= e($errors['routine_id']) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>How often</h2>

        <div class="field">
            <span class="label">Schedule type</span>
            <div class="radio-cards">
                <label class="radio-card">
                    <input type="radio" name="maintenance_type" value="routine" data-schedule-type
                        <?= $currentType === 'routine' ? 'checked' : '' ?>>
                    <span>
                        <strong>Routine</strong>
                        <span class="muted">Repeats on a standard cadence — weekly, monthly, quarterly, annually.</span>
                    </span>
                </label>

                <label class="radio-card">
                    <input type="radio" name="maintenance_type" value="periodic" data-schedule-type
                        <?= $currentType === 'periodic' ? 'checked' : '' ?>>
                    <span>
                        <strong>Periodic</strong>
                        <span class="muted">Repeats on an interval you choose, e.g. every 18 months.</span>
                    </span>
                </label>

                <label class="radio-card">
                    <input type="radio" name="maintenance_type" value="ad-hoc" data-schedule-type
                        <?= $currentType === 'ad-hoc' ? 'checked' : '' ?>>
                    <span>
                        <strong>One-off</strong>
                        <span class="muted">A single planned job. It closes itself once completed.</span>
                    </span>
                </label>
            </div>
        </div>

        <div class="field" data-when-type="routine">
            <label class="label" for="routine_preset">Cadence</label>
            <select class="input" id="routine_preset" name="routine_preset">
                <option value="">Use the interval below</option>
                <?php foreach (MaintenanceSchedule::ROUTINE_PRESETS as $key => $preset): ?>
                    <?php
                    $isCurrent = $isEdit
                        && (int) $schedule['frequency_interval'] === $preset['interval']
                        && $schedule['frequency_unit'] === $preset['unit'];
                    ?>
                    <option value="<?= e($key) ?>" <?= $isCurrent ? 'selected' : '' ?>><?= e($preset['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">Picking a cadence fills in the interval for you.</p>
        </div>

        <div class="field-row" data-when-type="routine periodic">
            <div class="field">
                <label class="label" for="frequency_interval">Repeat every</label>
                <input class="input<?= isset($errors['frequency_interval']) ? ' has-error' : '' ?>" type="number"
                       id="frequency_interval" name="frequency_interval" min="1" max="999" step="1" inputmode="numeric"
                       value="<?= e($value('frequency_interval', '6')) ?>">
                <?php if (isset($errors['frequency_interval'])): ?><p class="field-error"><?= e($errors['frequency_interval']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="frequency_unit">Unit</label>
                <select class="input" id="frequency_unit" name="frequency_unit">
                    <?php foreach (MaintenanceSchedule::UNITS as $unit): ?>
                        <option value="<?= e($unit) ?>" <?= $value('frequency_unit', 'months') === $unit ? 'selected' : '' ?>>
                            <?= e($unit) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="next_due_date">
                    Next due<span data-when-type="ad-hoc"> (the date of the job)</span>
                </label>
                <input class="input<?= isset($errors['next_due_date']) ? ' has-error' : '' ?>" type="date"
                       id="next_due_date" name="next_due_date" value="<?= e($value('next_due_date')) ?>">
                <p class="field-hint">Leave blank on a recurring job and it will be worked out from the last completion.</p>
                <?php if (isset($errors['next_due_date'])): ?><p class="field-error"><?= e($errors['next_due_date']) ?></p><?php endif; ?>
            </div>

            <div class="field" data-when-type="routine periodic">
                <label class="label" for="last_completed_date">Last completed <span class="optional">(optional)</span></label>
                <input class="input" type="date" id="last_completed_date" name="last_completed_date"
                       max="<?= e(date('Y-m-d')) ?>" value="<?= e($value('last_completed_date')) ?>">
                <p class="field-hint">Records history that predates this system.</p>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Who and how long</h2>

        <div class="field-row">
            <?php /* One control, not two. A schedule belongs to a person or to
                     a team, never to both, and two separate dropdowns would let
                     someone say it belongs to both and leave the reminder code
                     to pick. The prefixed value is unpacked by
                     MaintenanceSchedule::parseAssignee(). */ ?>
            <div class="field">
                <label class="label" for="assigned_to">Assigned to <span class="optional">(optional)</span></label>
                <select class="input" id="assigned_to" name="assigned_to">
                    <option value="">Nobody in particular</option>

                    <?php if ($teams !== []): ?>
                        <optgroup label="Teams">
                            <?php foreach ($teams as $team): ?>
                                <option value="team:<?= (int) $team['id'] ?>" <?= $assignedTo === 'team:' . (int) $team['id'] ? 'selected' : '' ?>>
                                    <?= e($team['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>

                    <optgroup label="People">
                        <?php foreach ($users as $user): ?>
                            <option value="user:<?= (int) $user['id'] ?>" <?= $assignedTo === 'user:' . (int) $user['id'] ? 'selected' : '' ?>>
                                <?= e($user['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <p class="field-hint">
                    Assign it to a team and every member is reminded about it, and every member can
                    record it as done.
                </p>
            </div>

            <div class="field">
                <label class="label" for="estimated_minutes">Estimated time (minutes) <span class="optional">(optional)</span></label>
                <input class="input" type="number" id="estimated_minutes" name="estimated_minutes"
                       min="1" max="65535" step="5" inputmode="numeric" value="<?= e($value('estimated_minutes')) ?>">
            </div>
        </div>

        <?php if ($isEdit): ?>
            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="is_active" value="1" <?= (int) $schedule['is_active'] === 1 ? 'checked' : '' ?>>
                    <span>Schedule is active<span class="field-hint">Untick to close it without deleting its history.</span></span>
                </label>
            </div>
        <?php endif; ?>
    </div>

    <div class="form-actions sticky-actions">
        <button type="submit" class="btn btn-primary btn-lg"><?= $isEdit ? 'Save schedule' : 'Create schedule' ?></button>
        <a class="btn btn-ghost" href="<?= e($isEdit ? url('/maintenance/' . $schedule['id']) : url('/maintenance')) ?>">Cancel</a>
    </div>
</form>
