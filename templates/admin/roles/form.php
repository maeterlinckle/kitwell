<?php
/**
 * Create or edit a role. One template for both: the permission picker is the
 * bulk of it and there is no reason for two copies of it to drift apart.
 *
 * @var array<string,mixed>|null $role  null when creating
 * @var array<string,array<int,array<string,mixed>>> $permissions
 * @var array<int,int> $assigned
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$isNew    = $role === null;
$isSystem = !$isNew && (int) $role['is_system'] === 1;
$action   = $isNew ? url('/admin/roles') : url('/admin/roles/' . $role['id']);

$value = static function (string $field) use ($role, $old): string {
    return old($old, $field, (string) ($role[$field] ?? ''));
};
?>
<div class="page-head">
    <div>
        <h1><?= $isNew ? 'Add role' : 'Edit ' . e($role['name']) ?></h1>
        <p class="muted">
            <?php if ($isNew): ?>
                A role is a name and a set of permissions. Nothing else changes — permissions are
                stored as data, so this needs no code or schema change.
            <?php else: ?>
                Tick what this role may do. Anything unticked is refused by the server, not merely
                hidden from the menu.
            <?php endif; ?>
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/roles')) ?>">Back to roles</a>
</div>

<form method="post" action="<?= e($action) ?>" class="form">
    <?= csrf_field() ?>

    <div class="card">
        <h2>The role</h2>

        <div class="field">
            <label class="label" for="name">Name</label>
            <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name" name="name"
                   maxlength="100" required value="<?= e($value('name')) ?>"
                   <?= $isSystem ? 'readonly' : '' ?>>
            <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
            <?php if ($isSystem): ?>
                <p class="field-hint">This role ships with the application, so its name is fixed. Its permissions can still be changed.</p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="description">Description <span class="optional">(optional)</span></label>
            <input class="input<?= isset($errors['description']) ? ' has-error' : '' ?>" type="text"
                   id="description" name="description" maxlength="255"
                   placeholder="Who this is for, in a few words"
                   value="<?= e($value('description')) ?>" <?= $isSystem ? 'readonly' : '' ?>>
            <?php if (isset($errors['description'])): ?><p class="field-error"><?= e($errors['description']) ?></p><?php endif; ?>
        </div>

        <?php if (!$isNew): ?>
            <p class="field-hint">
                Machine name <span class="mono"><?= e($role['slug']) ?></span> — fixed once created, because that is
                what the code refers to.
            </p>
        <?php endif; ?>
    </div>

    <?php foreach ($permissions as $group => $items): ?>
        <div class="card permission-group">
            <div class="permission-group-head">
                <h2><?= e($group) ?></h2>
                <button type="button" class="btn btn-sm btn-ghost" data-check-group>Select all</button>
            </div>

            <div class="permission-list">
                <?php foreach ($items as $permission): ?>
                    <label class="checkbox permission-item">
                        <input type="checkbox" name="permissions[]" value="<?= (int) $permission['id'] ?>"
                            <?= in_array((int) $permission['id'], $assigned, true) ? 'checked' : '' ?>>
                        <span>
                            <span class="permission-name"><?= e($permission['name']) ?></span>
                            <span class="permission-desc muted"><?= e($permission['description']) ?></span>
                            <span class="permission-slug mono muted"><?= e($permission['slug']) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="form-actions sticky-actions">
        <button type="submit" class="btn btn-primary btn-lg"><?= $isNew ? 'Create role' : 'Save permissions' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/roles')) ?>">Cancel</a>
    </div>
</form>
