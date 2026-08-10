<?php
/**
 * @var array<string,string|null> $settings
 * @var array<string,string> $types
 * @var array<string,int>    $windows
 * @var array<int,array<string,mixed>> $candidates
 * @var array<int,int> $selectedIds
 * @var array{tracked:int,last_sent_at:?string} $tracking
 * @var bool $ready
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 * @var string $section
 */
$setting = static fn (string $key, string $default = ''): string => (string) ($settings[$key] ?? $default);

$typeHints = [
    'pat'         => 'Assets whose PAT retest is coming up or has passed. Uses the same status rules as the PAT register and the “Assets needing PAT” report.',
    'maintenance' => 'Maintenance schedules approaching or past their next due date.',
    'hire'        => 'Equipment approaching or past its due-back date.',
];

$typePermission = [
    'pat'         => 'pat.view',
    'maintenance' => 'maintenance.view',
    'hire'        => 'hires.view',
];
?>
<div class="page-head">
    <div>
        <h1>Email reminders</h1>
        <p class="muted">Sent by a scheduled task, not while anyone is using the site.</p>
    </div>
</div>

<?= partial('partials/email-nav', ['section' => $section]) ?>

<?php if (!$ready): ?>
    <div class="card card-warn">
        <p>
            <strong>Email is not ready to send.</strong>
            Reminders can be configured here, but nothing will go out until the
            <a href="<?= e(url('/admin/email')) ?>">connection settings</a> are working.
        </p>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/email/reminders')) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <?php foreach ($types as $type => $label): ?>
        <?php $window = (int) $windows[$type]; ?>
        <div class="card">
            <h2><?= e($label) ?></h2>
            <p class="muted"><?= e($typeHints[$type]) ?></p>

            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="reminder_<?= e($type) ?>_enabled" value="1"
                        <?= $setting('reminder_' . $type . '_enabled', '0') === '1' ? 'checked' : '' ?>>
                    <span>Send <?= e(mb_strtolower($label)) ?> reminders</span>
                </label>
            </div>

            <div class="field">
                <label class="label" for="reminder_<?= e($type) ?>_days">Remind this many days before due</label>
                <input class="input<?= isset($errors['reminder_' . $type . '_days']) ? ' has-error' : '' ?>" type="number"
                       id="reminder_<?= e($type) ?>_days" name="reminder_<?= e($type) ?>_days"
                       min="0" max="365" step="1"
                       value="<?= e(old($old, 'reminder_' . $type . '_days', $setting('reminder_' . $type . '_days', '0'))) ?>">
                <p class="field-hint">
                    Leave at <strong>0</strong> to use the same window the register and dashboard already
                    show — currently <?= e($window) ?> day(s). Set a number to use a different
                    one for reminders only.
                </p>
                <?php if (isset($errors['reminder_' . $type . '_days'])): ?>
                    <p class="field-error"><?= e($errors['reminder_' . $type . '_days']) ?></p>
                <?php endif; ?>
            </div>

            <?php if ($type === 'maintenance'): ?>
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="reminder_maintenance_assignee" value="1"
                            <?= $setting('reminder_maintenance_assignee', '1') === '1' ? 'checked' : '' ?>>
                        <span>Also tell the person a job is assigned to</span>
                    </label>
                    <p class="field-hint">
                        They get their own jobs only, whether or not they are on the notify list below.
                        They still need permission to see maintenance.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($type === 'hire'): ?>
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="reminder_hire_notify_hirer" value="1"
                            <?= $setting('reminder_hire_notify_hirer', '0') === '1' ? 'checked' : '' ?>>
                        <span>Also chase the hirer directly</span>
                    </label>
                    <p class="field-hint">
                        One message per item, to the address on the hirer's record, using the
                        “Overdue notice to a hirer” template. Hirers with no email address are skipped.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="card">
        <h2>Repeating</h2>

        <div class="field">
            <label class="label" for="reminder_repeat_days">Remind again after (days)</label>
            <input class="input<?= isset($errors['reminder_repeat_days']) ? ' has-error' : '' ?>" type="number"
                   id="reminder_repeat_days" name="reminder_repeat_days" min="1" max="90" step="1" required
                   value="<?= e(old($old, 'reminder_repeat_days', $setting('reminder_repeat_days', '7'))) ?>">
            <p class="field-hint">
                While an item stays due or overdue, how long to wait before mentioning it again to the
                same person. <strong>1</strong> means every time the task runs. Crossing from “due soon”
                to “overdue” always sends straight away, whatever this is set to — that is a different
                message, not a repeat of the same one.
            </p>
            <?php if (isset($errors['reminder_repeat_days'])): ?>
                <p class="field-error"><?= e($errors['reminder_repeat_days']) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($tracking['tracked'] > 0): ?>
            <p class="muted">
                <?= number_format($tracking['tracked']) ?> reminder(s) tracked.
                <?php if ($tracking['last_sent_at'] !== null): ?>
                    Most recent <?= e(format_datetime((string) $tracking['last_sent_at'])) ?>.
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Who to notify</h2>
        <p class="muted">
            Staff who should receive the reminder digests. Each person only ever receives the kinds of
            reminder their role lets them see — ticking a box here does not grant access to anything.
        </p>

        <?php if ($candidates === []): ?>
            <p class="empty">
                No active user has an email address and permission to see any of these records.
                Add one from <a href="<?= e(url('/admin/users')) ?>">Users</a>.
            </p>
        <?php else: ?>
            <div class="check-grid">
                <?php foreach ($candidates as $candidate): ?>
                    <label class="checkbox">
                        <input type="checkbox" name="reminder_recipient_user_ids[]" value="<?= (int) $candidate['id'] ?>"
                            <?= in_array((int) $candidate['id'], $selectedIds, true) ? 'checked' : '' ?>>
                        <span>
                            <strong><?= e($candidate['name']) ?></strong>
                            <span class="muted"><?= e($candidate['email']) ?></span>
                            <span class="badge-row">
                                <?php foreach ($candidate['types'] as $type): ?>
                                    <span class="badge badge-muted"><?= e($types[$type]) ?></span>
                                <?php endforeach; ?>
                            </span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Save reminder settings</button>
    </div>
</form>

<div class="card">
    <h2>Running the reminders</h2>
    <p>
        Reminders are sent by a scheduled task, so nothing here depends on somebody having the site open.
        Install it with <span class="mono">sudo ./manage.sh cron-install</span>, or add it by hand:
    </p>
    <pre class="mono">0 8 * * *  cd <?= e((string) config('app.root')) ?> &amp;&amp; php bin/send-reminders.php &gt;/dev/null</pre>
    <p class="muted">
        Once each morning is right for a workshop. Running it more often does not send more mail — an
        item already reminded about is skipped until the repeat window above has passed.
    </p>

    <div class="form-actions">
        <form method="post" action="<?= e(url('/admin/email/reminders/run')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="dry_run" value="1">
            <button type="submit" class="btn">Preview — show what would be sent</button>
        </form>

        <form method="post" action="<?= e(url('/admin/email/reminders/run')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn" <?= $ready ? '' : 'disabled' ?>>Run now and send</button>
        </form>
    </div>
</div>
