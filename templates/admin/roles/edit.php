<?php
/**
 * @var array<string,mixed> $role
 * @var array<string,array<int,array<string,mixed>>> $permissions
 * @var array<int,int> $assigned
 */
?>
<div class="page-head">
    <div>
        <h1>Permissions: <?= e($role['name']) ?></h1>
        <p class="muted"><?= e($role['description']) ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/roles')) ?>">Back to roles</a>
</div>

<form method="post" action="<?= e(url('/admin/roles/' . $role['id'])) ?>" class="form">
    <?= csrf_field() ?>

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
        <button type="submit" class="btn btn-primary btn-lg">Save permissions</button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/roles')) ?>">Cancel</a>
    </div>
</form>
