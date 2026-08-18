<?php

use App\Models\Asset;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceSchedule;

/**
 * Signing an open run off.
 *
 * The same fields the wizard's last page asks for — every routine produces a
 * maintenance entry, and that entry needs the same things however the answers
 * were gathered. What is different here is that the person filling this in is
 * closing out work several people may have done, so the page says so.
 *
 * @var array<string,mixed> $completion
 * @var array<string,mixed> $asset
 * @var array<string,mixed>|null $schedule
 * @var array<int,array<string,mixed>> $users
 * @var array<int,array<string,mixed>> $outstanding
 * @var string|null $nextDue
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$id = (int) $completion['id'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">
            <a href="<?= e(url('/maintenance/completions/' . $id)) ?>"><?= e($completion['routine_name']) ?></a>
            &middot; <span class="mono"><?= e($completion['asset_tag']) ?></span>
        </p>
        <h1>Sign off this routine</h1>
        <p class="badge-row">
            <span class="badge">v<?= (int) $completion['version_number'] ?></span>
            <?php if (!empty($completion['started_by_name'])): ?>
                <span class="badge badge-muted">Started by <?= e($completion['started_by_name']) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/maintenance/completions/' . $id)) ?>">Back to the run</a>
</div>

<?php if ($outstanding !== []): ?>
    <div class="flash flash-error">
        <span class="flash-text">
            <strong>Not ready.</strong>
            <?= count($outstanding) ?> required step<?= count($outstanding) === 1 ? '' : 's' ?>
            still <?= count($outstanding) === 1 ? 'has' : 'have' ?> no answer:
            <?php foreach ($outstanding as $index => $step): ?>
                <?= $index > 0 ? ', ' : '' ?>
                <a href="<?= e(url('/maintenance/completions/' . $id . '/steps/' . (int) $step['id'])) ?>"><?= e($step['label']) ?></a>
            <?php endforeach; ?>.
        </span>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/maintenance/completions/' . $id . '/submit')) ?>" class="form form-wide" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <h2>The maintenance record</h2>
        <p class="muted">
            This is what goes into <?= e($completion['asset_tag']) ?>'s history. Your name is recorded
            as having closed the run out; each answer keeps the name of whoever gave it.
        </p>

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
                <label class="label" for="performed_by_user_id">Recorded against</label>
                <select class="input" id="performed_by_user_id" name="performed_by_user_id">
                    <option value="">&mdash; me &mdash;</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>"
                            <?= old($old, 'performed_by_user_id') === (string) $user['id'] ? 'selected' : '' ?>>
                            <?= e($user['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">Who the maintenance entry names as having done the work.</p>
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
                      placeholder="Anything the steps did not cover."><?= e(old($old, 'notes')) ?></textarea>
        </div>

        <?php if ($schedule !== null && $schedule['maintenance_type'] !== 'ad-hoc'): ?>
            <div class="field">
                <label class="label" for="next_due_date">Next due</label>
                <input class="input<?= isset($errors['next_due_date']) ? ' has-error' : '' ?>" type="date"
                       id="next_due_date" name="next_due_date"
                       value="<?= e(old($old, 'next_due_date', (string) $nextDue)) ?>">
                <p class="field-hint">
                    Worked out from the date performed &mdash; this job repeats
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
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg"<?= $outstanding !== [] ? ' disabled' : '' ?>>
            Sign off and record
        </button>
        <a class="btn btn-ghost" href="<?= e(url('/maintenance/completions/' . $id)) ?>">Back to the run</a>
    </div>
</form>
