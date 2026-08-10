<?php

use App\Models\Asset;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceSchedule;

/**
 * Record a completion — either against a schedule, or as unplanned work
 * logged straight onto an asset.
 *
 * @var array<string,mixed>|null $schedule
 * @var array<string,mixed> $asset
 * @var array<int,array<string,mixed>> $users
 * @var string|null $nextDue
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$isScheduled = $schedule !== null;
$action      = $isScheduled
    ? url('/maintenance/' . $schedule['id'] . '/complete')
    : url('/assets/' . $asset['id'] . '/maintenance/log');

$defaultType = $isScheduled ? (string) $schedule['maintenance_type'] : 'repair';
?>
<div class="page-head">
    <div>
        <h1><?= $isScheduled ? 'Complete maintenance' : 'Log maintenance' ?></h1>
        <p class="muted">
            <?php if ($isScheduled): ?>
                <strong><?= e($schedule['title']) ?></strong> ·
            <?php endif; ?>
            <a href="<?= e(url('/assets/' . $asset['id'])) ?>"><span class="mono"><?= e($asset['asset_tag']) ?></span></a>
            — <?= e($asset['name']) ?>
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e($isScheduled ? url('/maintenance/' . $schedule['id']) : url('/assets/' . $asset['id'])) ?>">Cancel</a>
</div>

<?php if ($isScheduled && !empty($schedule['instructions'])): ?>
    <div class="card notice-card">
        <h2>Instructions</h2>
        <p class="prewrap"><?= e($schedule['instructions']) ?></p>
    </div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form form-wide" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <h2>What was done</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="performed_on">Date performed</label>
                <input class="input<?= isset($errors['performed_on']) ? ' has-error' : '' ?>" type="date"
                       id="performed_on" name="performed_on" required max="<?= e(date('Y-m-d')) ?>"
                       value="<?= e(old($old, 'performed_on', date('Y-m-d'))) ?>">
                <?php if (isset($errors['performed_on'])): ?><p class="field-error"><?= e($errors['performed_on']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="maintenance_type">Type of work</label>
                <select class="input" id="maintenance_type" name="maintenance_type" required>
                    <?php foreach (MaintenanceLog::TYPES as $type): ?>
                        <option value="<?= e($type) ?>" <?= old($old, 'maintenance_type', $defaultType) === $type ? 'selected' : '' ?>>
                            <?= e(ucfirst(str_replace('-', ' ', $type))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label class="label" for="work_done">Work done</label>
            <textarea class="input<?= isset($errors['work_done']) ? ' has-error' : '' ?>" id="work_done" name="work_done"
                      rows="4" required maxlength="5000"
                      placeholder="What was checked, adjusted, replaced or repaired."><?= e(old($old, 'work_done')) ?></textarea>
            <?php if (isset($errors['work_done'])): ?><p class="field-error"><?= e($errors['work_done']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="parts_used">Parts used <span class="optional">(optional)</span></label>
            <textarea class="input" id="parts_used" name="parts_used" rows="2" maxlength="5000"
                      placeholder="e.g. Bosch guard 1619P06 x1"><?= e(old($old, 'parts_used')) ?></textarea>
        </div>

        <div class="field-row">
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
                <p class="field-hint">Setting this updates the asset's condition too.</p>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Who did it</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="performed_by_user_id">Staff member</label>
                <select class="input" id="performed_by_user_id" name="performed_by_user_id">
                    <option value="">Not one of our users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>"
                            <?= old($old, 'performed_by_user_id', (string) (auth_user()['id'] ?? '')) === (string) $user['id'] ? 'selected' : '' ?>>
                            <?= e($user['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="performed_by_name">Or an external contractor <span class="optional">(optional)</span></label>
                <input class="input" type="text" id="performed_by_name" name="performed_by_name" maxlength="191"
                       placeholder="Name of the company or engineer" value="<?= e(old($old, 'performed_by_name')) ?>">
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="cost">Cost (<?= e(config('app.currency_symbol', '£')) ?>) <span class="optional">(optional)</span></label>
                <input class="input" type="number" id="cost" name="cost" step="0.01" min="0" inputmode="decimal"
                       value="<?= e(old($old, 'cost')) ?>">
            </div>

            <div class="field">
                <label class="label" for="downtime_minutes">Downtime (minutes) <span class="optional">(optional)</span></label>
                <input class="input" type="number" id="downtime_minutes" name="downtime_minutes" min="0" max="65535"
                       step="5" inputmode="numeric" value="<?= e(old($old, 'downtime_minutes')) ?>">
            </div>
        </div>
    </div>

    <?php if ($isScheduled && $schedule['maintenance_type'] !== 'ad-hoc'): ?>
        <div class="card">
            <h2>Next time</h2>
            <p class="muted">
                This job repeats <?= e(strtolower(MaintenanceSchedule::describeFrequency($schedule))) ?>.
                The next due date is worked out from the date you performed it — change it here if you need a different date.
            </p>

            <div class="field">
                <label class="label" for="next_due_date">Next due</label>
                <input class="input<?= isset($errors['next_due_date']) ? ' has-error' : '' ?>" type="date"
                       id="next_due_date" name="next_due_date"
                       value="<?= e(old($old, 'next_due_date', (string) $nextDue)) ?>">
                <?php if (isset($errors['next_due_date'])): ?><p class="field-error"><?= e($errors['next_due_date']) ?></p><?php endif; ?>
            </div>
        </div>
    <?php elseif ($isScheduled): ?>
        <div class="card notice-card">
            <p class="muted">This is a one-off job, so it will be closed once you log this completion.</p>
        </div>
    <?php endif; ?>

    <?php /* "Check the belt again in three weeks." A one-off schedule, so it
             appears in the maintenance list and the reminders like any other
             job and closes itself once done — a follow-up must not quietly
             become a recurrence nobody meant to create. */ ?>
    <div class="card">
        <h2>Follow-up check <span class="optional">(optional)</span></h2>
        <p class="muted">
            Does this need looking at again? Schedule a one-off check now and it will appear in the
            maintenance list, and in the reminder emails, when it falls due.
        </p>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" id="schedule_followup" name="schedule_followup" value="1"
                       data-toggle-fields="followup-fields"
                    <?= old($old, 'schedule_followup') === '1' ? 'checked' : '' ?>>
                <span>Schedule a follow-up check</span>
            </label>
        </div>

        <div id="followup-fields" class="field-row">
            <div class="field">
                <label class="label" for="followup_interval">Check again in</label>
                <input class="input<?= isset($errors['followup_interval']) ? ' has-error' : '' ?>" type="number"
                       id="followup_interval" name="followup_interval" min="1" max="365" step="1"
                       inputmode="numeric" placeholder="3"
                       value="<?= e(old($old, 'followup_interval')) ?>">
                <?php if (isset($errors['followup_interval'])): ?>
                    <p class="field-error"><?= e($errors['followup_interval']) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="followup_unit"><span class="sr-only">Unit</span>&nbsp;</label>
                <select class="input" id="followup_unit" name="followup_unit">
                    <?php foreach (MaintenanceSchedule::UNITS as $unit): ?>
                        <option value="<?= e($unit) ?>" <?= old($old, 'followup_unit', 'weeks') === $unit ? 'selected' : '' ?>>
                            <?= e($unit) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">Counted from the date performed.</p>
            </div>

            <div class="field">
                <label class="label" for="followup_title">What to check <span class="optional">(optional)</span></label>
                <input class="input" type="text" id="followup_title" name="followup_title" maxlength="191"
                       placeholder="<?= e($isScheduled ? 'Follow-up check: ' . str_limit((string) $schedule['title'], 40) : 'Follow-up check') ?>"
                       value="<?= e(old($old, 'followup_title')) ?>">
                <p class="field-hint">The work you described above is copied into the new job's instructions.</p>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Evidence <span class="optional">(optional)</span></h2>

        <div class="field">
            <label class="label" for="photos">Photos</label>
            <input class="input" type="file" id="photos" name="photos[]" accept="image/*" multiple>
            <p class="field-hint">Before/after shots, or a photo of the fault. Up to <?= (int) (config('uploads.max_photo_bytes') / 1048576) ?> MB each.</p>
        </div>

        <div class="field">
            <label class="label" for="notes">Notes <span class="optional">(optional)</span></label>
            <textarea class="input" id="notes" name="notes" rows="3" maxlength="5000"
                      placeholder="Anything to flag for next time."><?= e(old($old, 'notes')) ?></textarea>
        </div>

        <?php if ($asset['status'] === 'In Maintenance'): ?>
            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="return_to_stock" value="1" checked>
                    <span>Put this asset back in stock<span class="field-hint">It is currently marked as in maintenance.</span></span>
                </label>
            </div>
        <?php endif; ?>
    </div>

    <div class="form-actions sticky-actions">
        <button type="submit" class="btn btn-primary btn-lg">Save maintenance record</button>
        <a class="btn btn-ghost" href="<?= e($isScheduled ? url('/maintenance/' . $schedule['id']) : url('/assets/' . $asset['id'])) ?>">Cancel</a>
    </div>
</form>
