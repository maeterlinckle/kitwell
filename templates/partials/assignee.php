<?php
/**
 * A person or a team, with the kind made visible.
 *
 * A name on its own does not say whether it is a person or a group, and the two
 * mean different things: a team's job is everybody's to pick up, a person's is
 * theirs. So the kind is always shown — as a badge here, and via
 * App\Models\Assignment::label() as "(team)" wherever there is only text: the
 * report columns, the calendar feed and the emails.
 *
 * Takes the three values rather than a row, because two different things are
 * owned this way. A maintenance schedule is *assigned to* somebody; an asset
 * has somebody *responsible* for it. Their columns are named differently and
 * neither should have to pretend to be the other to render a badge.
 *
 * @var string|null $name
 * @var string|null $kind          'user' | 'team' | null
 * @var mixed       $teamIsActive  0 when the team has been archived; omit if unknown
 * @var string|null $none          What to say when nothing is set
 */
$name = trim((string) ($name ?? ''));
$none = $none ?? '—';

if ($name === '') {
    echo '<span class="muted">' . e($none) . '</span>';

    return;
}

$isTeam = ($kind ?? '') === 'team';
?>
<span class="assignee">
    <?= e($name) ?>
    <?php if ($isTeam): ?>
        <span class="badge badge-role">Team</span>
        <?php /* An archived team keeps the work it already had, so this is not
                 an error — but it is worth saying, because nobody will be
                 offered that team for anything new. */ ?>
        <?php if (isset($teamIsActive) && (int) $teamIsActive === 0): ?>
            <span class="badge">archived</span>
        <?php endif; ?>
    <?php endif; ?>
</span>
