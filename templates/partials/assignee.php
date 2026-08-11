<?php
/**
 * Who a maintenance schedule belongs to.
 *
 * A name on its own does not say whether it is a person or a group, and the
 * two mean different things: a team's job is everybody's to pick up, a
 * person's is theirs. So the kind is always shown, as a badge on screen and —
 * via MaintenanceSchedule::assigneeLabel() — as "(team)" wherever there is only
 * text: the report columns, the calendar feed and the reminder emails.
 *
 * @var array<string,mixed> $schedule
 * @var string|null         $none  What to say when nothing is assigned
 */
$name = trim((string) ($schedule['assigned_to_name'] ?? ''));
$none = $none ?? '—';

if ($name === '') {
    echo '<span class="muted">' . e($none) . '</span>';

    return;
}

$isTeam = ($schedule['assigned_to_kind'] ?? '') === 'team';
?>
<span class="assignee">
    <?= e($name) ?>
    <?php if ($isTeam): ?>
        <span class="badge badge-role">Team</span>
        <?php /* An archived team keeps the work it already had, so this is not
                 an error — but it is worth saying, because nobody will be
                 offered that team for anything new. */ ?>
        <?php if (array_key_exists('assigned_team_is_active', $schedule) && (int) $schedule['assigned_team_is_active'] === 0): ?>
            <span class="badge">archived</span>
        <?php endif; ?>
    <?php endif; ?>
</span>
