<?php

use App\Models\Asset;
use App\Models\MaintenanceLog;

/**
 * Correct a completed maintenance record.
 *
 * Separate from maintenance/complete.php on purpose: completing a job also
 * rolls the schedule forward, puts the asset back in stock and can raise a
 * follow-up check. None of that should happen again when someone fixes a typo
 * in a record from three months ago — this form only changes what the record
 * says about what happened.
 *
 * @var array<string,mixed> $log
 * @var array<int,array<string,mixed>> $users
 * @var array<int,array<string,mixed>> $history
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */

/** The stored value, unless the form is coming back from a failed validation. */
$value = static function (string $field, $fallback = '') use ($log, $old) {
    return old($old, $field, (string) ($log[$field] ?? $fallback));
};

$fieldLabels = [
    'performed_on'         => 'Date performed',
    'maintenance_type'     => 'Type of work',
    'result'               => 'Result',
    'performed_by_user_id' => 'Performed by (staff)',
    'performed_by_name'    => 'Performed by (contractor)',
    'work_done'            => 'Work done',
    'parts_used'           => 'Parts used',
    'cost'                 => 'Cost',
    'downtime_minutes'     => 'Downtime (minutes)',
    'condition_after'      => 'Condition afterwards',
    'notes'                => 'Notes',
];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Maintenance record</p>
        <h1>Edit record</h1>
        <p class="muted">
            <a href="<?= e(url('/assets/' . $log['asset_id'])) ?>"><span class="mono"><?= e($log['asset_tag']) ?></span></a>
            — <?= e($log['asset_name']) ?>
            · recorded <?= e(format_date((string) $log['created_at'])) ?>
            <?php if (!empty($log['created_by_name'])): ?> by <?= e($log['created_by_name']) ?><?php endif; ?>
            <?php if (!empty($log['schedule_title'])): ?>
                · against <strong><?= e($log['schedule_title']) ?></strong>
            <?php endif; ?>
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/assets/' . $log['asset_id'] . '#maintenance')) ?>">Cancel</a>
</div>

<div class="card notice-card">
    <p class="muted">
        Corrections are kept. Every change is written to the activity log with the
        old and new value of each field, who made it and when — the history below
        is that trail for this record.
    </p>
</div>

<form method="post" action="<?= e(url('/maintenance/logs/' . $log['id'])) ?>" class="form form-wide" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <h2>What was done</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="performed_on">Date performed</label>
                <input class="input<?= isset($errors['performed_on']) ? ' has-error' : '' ?>" type="date"
                       id="performed_on" name="performed_on" required max="<?= e(date('Y-m-d')) ?>"
                       value="<?= e($value('performed_on')) ?>">
                <?php if (isset($errors['performed_on'])): ?><p class="field-error"><?= e($errors['performed_on']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="maintenance_type">Type of work</label>
                <select class="input" id="maintenance_type" name="maintenance_type" required>
                    <?php foreach (MaintenanceLog::TYPES as $type): ?>
                        <option value="<?= e($type) ?>" <?= $value('maintenance_type') === $type ? 'selected' : '' ?>>
                            <?= e(ucfirst(str_replace('-', ' ', $type))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label class="label" for="work_done">Work done</label>
            <textarea class="input<?= isset($errors['work_done']) ? ' has-error' : '' ?>" id="work_done" name="work_done"
                      rows="4" required maxlength="5000"><?= e($value('work_done')) ?></textarea>
            <?php if (isset($errors['work_done'])): ?><p class="field-error"><?= e($errors['work_done']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="parts_used">Parts used <span class="optional">(optional)</span></label>
            <textarea class="input" id="parts_used" name="parts_used" rows="2" maxlength="5000"><?= e($value('parts_used')) ?></textarea>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="result">Result</label>
                <select class="input" id="result" name="result" required>
                    <?php foreach (MaintenanceLog::RESULTS as $result): ?>
                        <option value="<?= e($result) ?>" <?= $value('result') === $result ? 'selected' : '' ?>>
                            <?= e($result) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="condition_after">Condition afterwards <span class="optional">(optional)</span></label>
                <select class="input" id="condition_after" name="condition_after">
                    <option value="">Not recorded</option>
                    <?php foreach (Asset::CONDITIONS as $condition): ?>
                        <option value="<?= e($condition) ?>" <?= $value('condition_after') === $condition ? 'selected' : '' ?>>
                            <?= e($condition) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">
                    What this record says the condition was at the time. Changing it here does not
                    alter the asset's condition now — set that on the asset itself.
                </p>
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
                            <?= $value('performed_by_user_id') === (string) $user['id'] ? 'selected' : '' ?>>
                            <?= e($user['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="performed_by_name">Or an external contractor <span class="optional">(optional)</span></label>
                <input class="input" type="text" id="performed_by_name" name="performed_by_name" maxlength="191"
                       value="<?= e($value('performed_by_name')) ?>">
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="cost">Cost (<?= e(config('app.currency_symbol', '£')) ?>) <span class="optional">(optional)</span></label>
                <input class="input" type="number" id="cost" name="cost" step="0.01" min="0" inputmode="decimal"
                       value="<?= e($value('cost')) ?>">
            </div>

            <div class="field">
                <label class="label" for="downtime_minutes">Downtime (minutes) <span class="optional">(optional)</span></label>
                <input class="input" type="number" id="downtime_minutes" name="downtime_minutes" min="0" max="65535"
                       step="5" inputmode="numeric" value="<?= e($value('downtime_minutes')) ?>">
            </div>
        </div>
    </div>

    <div class="card">
        <h2>The correction</h2>

        <div class="field">
            <label class="label" for="notes">Notes <span class="optional">(optional)</span></label>
            <textarea class="input" id="notes" name="notes" rows="3" maxlength="5000"><?= e($value('notes')) ?></textarea>
        </div>

        <div class="field">
            <label class="label" for="edit_reason">Why are you changing this? <span class="optional">(optional)</span></label>
            <input class="input<?= isset($errors['edit_reason']) ? ' has-error' : '' ?>" type="text"
                   id="edit_reason" name="edit_reason" maxlength="191"
                   placeholder="e.g. Wrong date entered on the day"
                   value="<?= e(old($old, 'edit_reason')) ?>">
            <p class="field-hint">Stored with the change. In a year's time this is the bit that explains it.</p>
            <?php if (isset($errors['edit_reason'])): ?><p class="field-error"><?= e($errors['edit_reason']) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="form-actions sticky-actions">
        <button type="submit" class="btn btn-primary btn-lg">Save correction</button>
        <a class="btn btn-ghost" href="<?= e(url('/assets/' . $log['asset_id'] . '#maintenance')) ?>">Cancel</a>
    </div>
</form>

<div class="card">
    <h2>Edit history</h2>

    <?php if ($history === []): ?>
        <p class="muted">This record has not been changed since it was written.</p>
    <?php else: ?>
        <ul class="audit-list">
            <?php foreach ($history as $entry): ?>
                <?php
                $changes = json_decode((string) ($entry['changes'] ?? ''), true);
                $changes = is_array($changes) ? $changes : [];
                $reason  = $changes['reason'] ?? null;
                unset($changes['reason']);
                ?>
                <li class="audit-entry">
                    <p class="audit-meta">
                        <strong><?= e($entry['user_name']) ?></strong>
                        · <?= e(format_datetime((string) $entry['created_at'])) ?>
                        <?php if ($reason !== null): ?>
                            · <span class="audit-reason"><?= e((string) $reason) ?></span>
                        <?php endif; ?>
                    </p>

                    <?php if ($changes !== []): ?>
                        <table class="table audit-table">
                            <thead>
                            <tr><th>Field</th><th>Was</th><th>Became</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($changes as $field => $change): ?>
                                <tr>
                                    <td><?= e($fieldLabels[$field] ?? (string) $field) ?></td>
                                    <td class="audit-was"><?= ($change['from'] ?? null) === null || $change['from'] === ''
                                        ? '<span class="muted">empty</span>'
                                        : e(str_limit((string) $change['from'], 160)) ?></td>
                                    <td><?= ($change['to'] ?? null) === null || $change['to'] === ''
                                        ? '<span class="muted">empty</span>'
                                        : e(str_limit((string) $change['to'], 160)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="muted"><?= e((string) $entry['description']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
