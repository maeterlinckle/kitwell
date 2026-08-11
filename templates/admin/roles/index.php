<?php
/**
 * @var array<int,array<string,mixed>> $roles
 * @var array<string,array<int,array<string,mixed>>> $permissions
 * @var int $permissionTotal
 */
?>
<div class="page-head">
    <div>
        <h1>Roles &amp; permissions</h1>
        <p class="muted">Permissions are stored as data, so new roles and finer-grained permissions can be added without changing the database structure.</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-primary" href="<?= e(url('/admin/roles/create')) ?>">Add role</a>
    </div>
</div>

<?php /* A row each rather than a card each: this list only grows, and four
         tall cards already filled a screen. Everything the cards carried is
         still here — name, description, users, permissions, machine name. */ ?>
<div class="card">
    <div class="table-wrap">
        <table class="table role-table">
            <thead>
            <tr>
                <th scope="col">Role</th>
                <th scope="col" class="num">Users</th>
                <th scope="col" class="num">Permissions</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($roles as $role): ?>
                <?php $superuser = (int) $role['is_superuser'] === 1; ?>
                <tr>
                    <td>
                        <div class="role-name">
                            <strong><?= e($role['name']) ?></strong>
                            <?php if ($superuser): ?>
                                <span class="badge badge-warn">Full access</span>
                            <?php elseif ((int) $role['is_system'] === 1): ?>
                                <span class="badge badge-muted">Built in</span>
                            <?php endif; ?>
                        </div>
                        <div class="cell-sub"><?= e((string) ($role['description'] ?? '')) ?></div>
                        <div class="cell-sub mono muted"><?= e($role['slug']) ?></div>
                    </td>
                    <td class="num nowrap"><?= (int) $role['user_count'] ?></td>
                    <td class="num nowrap">
                        <?php if ($superuser): ?>
                            <span title="Holds every permission implicitly">all <?= (int) $permissionTotal ?></span>
                        <?php else: ?>
                            <?= (int) $role['permission_count'] ?> <span class="muted">/ <?= (int) $permissionTotal ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap">
                        <?php if ($superuser): ?>
                            <span class="muted">Nothing to configure</span>
                        <?php else: ?>
                            <a class="btn btn-sm" href="<?= e(url('/admin/roles/' . $role['id'] . '/edit')) ?>">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<h2 class="section-title">All permissions</h2>

<?php /* One table, not one per group: separate tables size their columns
         independently, which is what made the slugs and names stagger down the
         page. A single table with a fixed layout keeps every row on the same
         grid, and the group headings become rows inside it. */ ?>
<div class="card">
    <div class="table-wrap">
        <table class="table table-compact permission-table">
            <colgroup>
                <col class="permission-col-slug">
                <col class="permission-col-name">
                <col>
            </colgroup>
            <thead>
            <tr>
                <th scope="col">Permission</th>
                <th scope="col">Name</th>
                <th scope="col">What it allows</th>
            </tr>
            </thead>
            <?php foreach ($permissions as $group => $items): ?>
                <tbody>
                <tr class="permission-group-row">
                    <th scope="rowgroup" colspan="3"><?= e($group) ?></th>
                </tr>
                <?php foreach ($items as $permission): ?>
                    <tr>
                        <td class="mono nowrap"><?= e($permission['slug']) ?></td>
                        <td><strong><?= e($permission['name']) ?></strong></td>
                        <td class="muted"><?= e((string) ($permission['description'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            <?php endforeach; ?>
        </table>
    </div>
</div>
