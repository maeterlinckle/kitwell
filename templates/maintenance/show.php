<?php

use App\Models\MaintenanceSchedule;

/**
 * One schedule, with its completion history.
 *
 * @var array<string,mixed> $schedule
 * @var array<int,array<string,mixed>> $logs
 * @var array<string,mixed>|null $routine
 * @var array<int,array<string,mixed>> $completions keyed by maintenance log id
 * @var string|null $nextDue
 */
$id = (int) $schedule['id'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">
            <a href="<?= e(url('/assets/' . $schedule['asset_id'])) ?>"><span class="mono"><?= e($schedule['asset_tag']) ?></span></a>
            <?= e(str_limit((string) $schedule['asset_name'], 50)) ?>
        </p>
        <h1><?= e($schedule['title']) ?></h1>
        <p class="badge-row">
            <span class="badge due-<?= e(strtolower(str_replace(' ', '-', (string) $schedule['due_status']))) ?>">
                <?= e($schedule['due_status']) ?>
            </span>
            <span class="badge badge-muted"><?= e($schedule['maintenance_type']) ?></span>
            <span class="badge"><?= e(MaintenanceSchedule::describeFrequency($schedule)) ?></span>
        </p>
    </div>

    <div class="head-actions">
        <?php if (can('maintenance.complete') && (int) $schedule['is_active'] === 1): ?>
            <a class="btn btn-primary" href="<?= e(url('/maintenance/' . $id . '/complete')) ?>">Complete</a>
        <?php endif; ?>
        <?php if (can('maintenance.manage')): ?>
            <a class="btn" href="<?= e(url('/maintenance/' . $id . '/edit')) ?>">Edit</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/maintenance')) ?>">All maintenance</a>
    </div>
</div>

<?php if ((int) $schedule['is_active'] === 0): ?>
    <div class="flash flash-info">
        <span class="flash-text">
            This schedule is closed<?= $schedule['maintenance_type'] === 'ad-hoc' ? ' — one-off jobs close automatically once completed' : '' ?>.
            Its history is kept below.
        </span>
    </div>
<?php endif; ?>

<div class="detail-grid">
    <div class="detail-main">
        <?php if (!empty($schedule['instructions'])): ?>
            <div class="card">
                <h2>Instructions</h2>
                <p class="prewrap"><?= e($schedule['instructions']) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($routine !== null): ?>
            <div class="card notice-card">
                <h2>This job follows a routine</h2>
                <p>
                    Completing it steps through
                    <a href="<?= e(url('/maintenance/routines/' . (int) $routine['id'])) ?>"><?= e($routine['name']) ?></a>
                    <?php if ($routine['current_version_id'] !== null): ?>
                        — currently version <?= (int) $routine['current_version_number'] ?>.
                    <?php else: ?>
                        , which has nothing published yet, so the free-text form is used until it does.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-head">
                <h2>Completion history <span class="count-pill"><?= count($logs) ?></span></h2>
            </div>

            <?php if ($logs === []): ?>
                <p class="muted">Nothing logged against this schedule yet.</p>
            <?php else: ?>
                <ul class="log-list">
                    <?php foreach ($logs as $log): ?>
                        <li class="log-item" id="log-<?= (int) $log['id'] ?>">
                            <div class="log-head">
                                <span class="log-date"><?= e(format_date($log['performed_on'])) ?></span>
                                <span class="badge result-<?= e(strtolower((string) $log['result'])) ?>"><?= e($log['result']) ?></span>
                                <span class="badge badge-muted"><?= e($log['maintenance_type']) ?></span>
                                <?php if (isset($completions[(int) $log['id']])): ?>
                                    <?php $completion = $completions[(int) $log['id']]; ?>
                                    <a class="badge badge-link" href="<?= e(url('/maintenance/completions/' . (int) $completion['id'])) ?>">
                                        <?= e($completion['routine_name']) ?> v<?= (int) $completion['version_number'] ?>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <p class="prewrap log-work"><?= e($log['work_done']) ?></p>

                            <dl class="log-meta">
                                <div>
                                    <dt>By</dt>
                                    <dd><?= e($log['performed_by_user_name'] ?? $log['performed_by_name'] ?? 'Not recorded') ?></dd>
                                </div>
                                <?php if (!empty($log['parts_used'])): ?>
                                    <div><dt>Parts</dt><dd><?= e($log['parts_used']) ?></dd></div>
                                <?php endif; ?>
                                <?php if ($log['cost'] !== null): ?>
                                    <div><dt>Cost</dt><dd><?= e(format_money($log['cost'])) ?></dd></div>
                                <?php endif; ?>
                                <?php if ($log['downtime_minutes'] !== null): ?>
                                    <div><dt>Downtime</dt><dd><?= (int) $log['downtime_minutes'] ?> min</dd></div>
                                <?php endif; ?>
                                <?php if (!empty($log['condition_after'])): ?>
                                    <div><dt>Condition after</dt><dd><?= e($log['condition_after']) ?></dd></div>
                                <?php endif; ?>
                            </dl>

                            <?php if (!empty($log['notes'])): ?>
                                <p class="muted prewrap"><?= e($log['notes']) ?></p>
                            <?php endif; ?>

                            <?php if ((int) $log['photo_count'] > 0): ?>
                                <?= partial('partials/maintenance-log-evidence', ['log' => $log]) ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <aside class="detail-side">
        <div class="card">
            <h2>Schedule</h2>
            <?php /* Stacked, like the Record card on the asset page and for
                     the same reason: in a 320px rail a two-column list gives
                     "Assigned to" and "Created" about 120px for a name, a
                     date and a badge, and they wrap into a ragged block
                     against a wall of white space. */ ?>
            <dl class="detail-list detail-list-tight detail-list-stacked">
                <div><dt>Next due</dt><dd><?= e(format_date($schedule['next_due_date'])) ?></dd></div>
                <div><dt>Last completed</dt><dd><?= e(format_date($schedule['last_completed_date'])) ?></dd></div>
                <div><dt>Repeats</dt><dd><?= e(MaintenanceSchedule::describeFrequency($schedule)) ?></dd></div>
                <div><dt>Assigned to</dt><dd><?= partial('partials/assignee', MaintenanceSchedule::assigneeParts($schedule) + ['none' => 'Nobody in particular']) ?></dd></div>
                <?php if ($schedule['estimated_minutes'] !== null): ?>
                    <div><dt>Estimated</dt><dd><?= (int) $schedule['estimated_minutes'] ?> minutes</dd></div>
                <?php endif; ?>
                <div><dt>Created</dt><dd><?= e(format_date($schedule['created_at'])) ?><?= !empty($schedule['created_by_name']) ? ' by ' . e($schedule['created_by_name']) : '' ?></dd></div>
            </dl>
        </div>

        <div class="card">
            <h2>Asset</h2>
            <dl class="detail-list detail-list-tight detail-list-stacked">
                <div><dt>Tag</dt><dd class="mono"><a href="<?= e(url('/assets/' . $schedule['asset_id'])) ?>"><?= e($schedule['asset_tag']) ?></a></dd></div>
                <div><dt>Status</dt><dd><span class="badge status-<?= e(strtolower(str_replace(' ', '-', (string) $schedule['asset_status']))) ?>"><?= e($schedule['asset_status']) ?></span></dd></div>
                <div><dt>Condition</dt><dd><?= e($schedule['asset_condition']) ?></dd></div>
                <?php if (!empty($schedule['location_name'])): ?>
                    <div><dt>Location</dt><dd><?= e($schedule['location_name']) ?></dd></div>
                <?php endif; ?>
            </dl>
        </div>

        <?php if (can('maintenance.manage')): ?>
            <div class="card danger-card">
                <h2>Manage</h2>
                <p class="muted">Deleting a schedule keeps every completion already logged against the asset.</p>
                <form method="post" action="<?= e(url('/maintenance/' . $id . '/delete')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-warning btn-block"
                            data-confirm="Delete the schedule “<?= e($schedule['title']) ?>”? Its <?= (int) $schedule['completion_count'] ?> completion record(s) will be kept.">
                        Delete schedule
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </aside>
</div>
