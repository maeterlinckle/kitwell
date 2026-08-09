<?php
/**
 * @var array<int,array<string,mixed>> $roles
 * @var array<string,array<int,array<string,mixed>>> $permissions
 */
?>
<div class="page-head">
    <div>
        <h1>Roles &amp; permissions</h1>
        <p class="muted">Permissions are stored as data, so new roles and finer-grained permissions can be added without changing the database structure.</p>
    </div>
</div>

<div class="card-grid">
    <?php foreach ($roles as $role): ?>
        <div class="card role-card">
            <div class="role-card-head">
                <h2><?= e($role['name']) ?></h2>
                <?php if ((int) $role['is_superuser'] === 1): ?>
                    <span class="badge badge-warn">Full access</span>
                <?php endif; ?>
            </div>
            <p class="muted"><?= e($role['description']) ?></p>
            <ul class="meta-list">
                <li><strong><?= (int) $role['user_count'] ?></strong> user<?= (int) $role['user_count'] === 1 ? '' : 's' ?></li>
                <li><strong><?= (int) $role['permission_count'] ?></strong> permission<?= (int) $role['permission_count'] === 1 ? '' : 's' ?></li>
                <li class="mono muted"><?= e($role['slug']) ?></li>
            </ul>

            <?php if ((int) $role['is_superuser'] === 1): ?>
                <p class="field-hint">Always holds every permission — nothing to configure.</p>
            <?php else: ?>
                <a class="btn" href="<?= e(url('/admin/roles/' . $role['id'] . '/edit')) ?>">Edit permissions</a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<h2 class="section-title">All permissions</h2>
<div class="card">
    <?php foreach ($permissions as $group => $items): ?>
        <h3 class="group-title"><?= e($group) ?></h3>
        <div class="table-wrap">
            <table class="table table-compact">
                <tbody>
                <?php foreach ($items as $permission): ?>
                    <tr>
                        <td class="mono nowrap"><?= e($permission['slug']) ?></td>
                        <td><strong><?= e($permission['name']) ?></strong></td>
                        <td class="muted"><?= e($permission['description']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</div>
