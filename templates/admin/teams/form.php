<?php
/**
 * Create a team, or edit one and manage its members.
 *
 * Members are only offered once the team exists, because a membership row needs
 * a team id to point at. Creating therefore lands on this same page in edit
 * mode, with the member list ready.
 *
 * @var array<string,mixed>|null       $team
 * @var array<int,array<string,mixed>> $members
 * @var array<int,array<string,mixed>> $candidates
 * @var array<string,string>           $errors
 * @var array<string,mixed>            $old
 */
$isEdit = $team !== null;
$action = $isEdit ? url('/admin/teams/' . $team['id']) : url('/admin/teams');

$value = static function (string $field) use ($old, $team): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    return (string) ($team[$field] ?? '');
};
?>
<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit team' : 'Add team' ?></h1>
        <?php if ($isEdit && (int) $team['is_active'] !== 1): ?>
            <p class="muted">
                This team is archived. It keeps the work already assigned to it and its members still
                get those reminders; it is not offered for anything new.
            </p>
        <?php endif; ?>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/teams')) ?>">Back to teams</a>
</div>

<form method="post" action="<?= e($action) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <div class="field">
            <label class="label" for="name">Team name</label>
            <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name" name="name"
                   maxlength="120" required value="<?= e($value('name')) ?>"
                   placeholder="e.g. Bench fitters">
            <?php if (isset($errors['name'])): ?>
                <p class="field-error"><?= e($errors['name']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="description">Description <span class="optional">(optional)</span></label>
            <input class="input" type="text" id="description" name="description" maxlength="255"
                   value="<?= e($value('description')) ?>"
                   placeholder="What this team looks after">
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create team' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/teams')) ?>">Cancel</a>
    </div>
</form>

<?php if ($isEdit): ?>
    <h2 class="section-title">Members</h2>

    <div class="card">
        <?php if ($members === []): ?>
            <p class="muted">
                Nobody is in this team yet. Until somebody is, work assigned to it reaches no one.
            </p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Role</th>
                        <th scope="col">In the team since</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($members as $member): ?>
                        <tr>
                            <td>
                                <strong><?= e($member['name']) ?></strong>
                                <?php if ((int) $member['is_active'] !== 1): ?>
                                    <span class="badge badge-muted">Deactivated</span>
                                <?php endif; ?>
                                <div class="cell-sub muted"><?= e((string) $member['email']) ?></div>
                            </td>
                            <td><?= e((string) $member['role_name']) ?></td>
                            <td class="nowrap"><?= e(format_date($member['joined_at'])) ?></td>
                            <td class="nowrap actions">
                                <form method="post" action="<?= e(url('/admin/teams/' . $team['id'] . '/members/' . $member['id'] . '/remove')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-ghost"
                                            data-confirm="Remove <?= e($member['name']) ?> from “<?= e($team['name']) ?>”? They stop receiving this team’s reminders.">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($candidates === []): ?>
            <p class="muted">Everyone with an active account is already in this team.</p>
        <?php else: ?>
            <form method="post" action="<?= e(url('/admin/teams/' . $team['id'] . '/members')) ?>" class="form-inline">
                <?= csrf_field() ?>
                <div class="field field-inline">
                    <label class="label" for="user_id">Add somebody</label>
                    <select class="input" id="user_id" name="user_id">
                        <?php foreach ($candidates as $candidate): ?>
                            <option value="<?= (int) $candidate['id'] ?>">
                                <?= e($candidate['name']) ?> — <?= e((string) $candidate['role_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn">Add to team</button>
            </form>

            <p class="field-hint">
                Being in a team does not grant anything. A member still needs permission to see
                maintenance to be reminded about it, and permission to record work to complete it.
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>
