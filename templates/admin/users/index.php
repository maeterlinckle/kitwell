<?php
/**
 * @var array<int,array<string,mixed>> $users
 * @var array<int,array<string,mixed>> $roles
 * @var array<string,string> $filters
 */
?>
<div class="page-head">
    <div>
        <h1>Users</h1>
        <p class="muted"><?= count($users) ?> account<?= count($users) === 1 ? '' : 's' ?></p>
    </div>
    <?php if (can('users.manage')): ?>
        <a class="btn btn-primary" href="<?= e(url('/admin/users/create')) ?>">Add user</a>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(url('/admin/users')) ?>" class="filter-bar">
    <div class="field field-inline">
        <label class="sr-only" for="q">Search</label>
        <input class="input" type="search" id="q" name="q" placeholder="Search name or email"
               value="<?= e($filters['search']) ?>">
    </div>

    <div class="field field-inline">
        <label class="sr-only" for="role">Role</label>
        <select class="input" id="role" name="role">
            <option value="">All roles</option>
            <?php foreach ($roles as $role): ?>
                <option value="<?= (int) $role['id'] ?>" <?= $filters['role_id'] === (string) $role['id'] ? 'selected' : '' ?>>
                    <?= e($role['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field field-inline">
        <label class="sr-only" for="active">Status</label>
        <select class="input" id="active" name="active">
            <option value="">Active and inactive</option>
            <option value="1" <?= $filters['is_active'] === '1' ? 'selected' : '' ?>>Active only</option>
            <option value="0" <?= $filters['is_active'] === '0' ? 'selected' : '' ?>>Inactive only</option>
        </select>
    </div>

    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-ghost" href="<?= e(url('/admin/users')) ?>">Clear</a>
</form>

<div class="table-wrap">
    <table class="table">
        <thead>
        <tr>
            <th scope="col">Name</th>
            <th scope="col">Email</th>
            <th scope="col">Role</th>
            <th scope="col">Status</th>
            <th scope="col">Last signed in</th>
            <th scope="col"><span class="sr-only">Actions</span></th>
        </tr>
        </thead>
        <tbody>
        <?php if ($users === []): ?>
            <tr><td colspan="6" class="empty">No users match those filters.</td></tr>
        <?php endif; ?>

        <?php foreach ($users as $user): ?>
            <tr class="<?= (int) $user['is_active'] === 1 ? '' : 'row-muted' ?>">
                <td>
                    <strong><?= e($user['name']) ?></strong>
                    <?php if (!empty($user['job_title'])): ?>
                        <div class="cell-sub"><?= e($user['job_title']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="break"><?= e($user['email']) ?></td>
                <td><span class="badge badge-role"><?= e($user['role_name']) ?></span></td>
                <td>
                    <?php if ((int) $user['is_active'] === 1): ?>
                        <span class="badge badge-ok">Active</span>
                    <?php else: ?>
                        <span class="badge badge-muted">Inactive</span>
                    <?php endif; ?>
                </td>
                <td class="nowrap"><?= e(format_datetime($user['last_login_at'])) ?></td>
                <td class="actions">
                    <?php if (can('users.manage')): ?>
                        <a class="btn btn-sm" href="<?= e(url('/admin/users/' . $user['id'] . '/edit')) ?>">Edit</a>
                        <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/status')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-ghost"
                                    data-confirm="<?= (int) $user['is_active'] === 1 ? 'Deactivate ' . e($user['name']) . '?' : 'Reactivate ' . e($user['name']) . '?' ?>">
                                <?= (int) $user['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
