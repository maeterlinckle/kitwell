<?php

use App\Models\Hirer;

/**
 * @var array<int,array<string,mixed>> $hirers
 * @var array<string,mixed> $filters
 */
?>
<div class="page-head">
    <div>
        <h1>Hirers</h1>
        <p class="muted"><?= count($hirers) ?> record<?= count($hirers) === 1 ? '' : 's' ?></p>
    </div>
    <?php if (can('hirers.manage')): ?>
        <a class="btn btn-primary" href="<?= e(url('/hirers/create')) ?>">Add hirer</a>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(url('/hirers')) ?>" class="filter-bar">
    <div class="field field-inline">
        <label class="sr-only" for="q">Search</label>
        <input class="input" type="search" id="q" name="q" placeholder="Search name, company, email…"
               value="<?= e($filters['q']) ?>">
    </div>

    <div class="field field-inline">
        <label class="sr-only" for="type">Type</label>
        <select class="input" id="type" name="type">
            <option value="">People and companies</option>
            <?php foreach (Hirer::TYPES as $type): ?>
                <option value="<?= e($type) ?>" <?= $filters['type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option>
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

    <label class="checkbox checkbox-compact">
        <input type="checkbox" name="out" value="1" <?= !empty($filters['with_open_hires']) ? 'checked' : '' ?>>
        <span>Has items out</span>
    </label>

    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-ghost" href="<?= e(url('/hirers')) ?>">Clear</a>
</form>

<?php if ($hirers === []): ?>
    <div class="card empty-state">
        <h2>No hirers</h2>
        <p class="muted">Add the people and companies who take items out.</p>
        <?php if (can('hirers.manage')): ?>
            <a class="btn btn-primary" href="<?= e(url('/hirers/create')) ?>">Add the first hirer</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Type</th>
                <th scope="col">Contact</th>
                <th scope="col">Login</th>
                <th scope="col">Out now</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($hirers as $hirer): ?>
                <tr class="<?= (int) $hirer['is_active'] === 1 ? '' : 'row-muted' ?>">
                    <td>
                        <a href="<?= e(url('/hirers/' . $hirer['id'])) ?>"><strong><?= e($hirer['name']) ?></strong></a>
                        <?php if (!empty($hirer['company_name']) && $hirer['company_name'] !== $hirer['name']): ?>
                            <div class="cell-sub"><?= e($hirer['company_name']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($hirer['reference'])): ?>
                            <div class="cell-sub mono"><?= e($hirer['reference']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-muted"><?= e($hirer['hirer_type']) ?></span></td>
                    <td class="break">
                        <?php if (!empty($hirer['email'])): ?>
                            <a href="mailto:<?= e($hirer['email']) ?>"><?= e($hirer['email']) ?></a><br>
                        <?php endif; ?>
                        <?= e($hirer['phone'] ?? '') ?>
                    </td>
                    <td>
                        <?php if (!empty($hirer['user_name'])): ?>
                            <span class="badge badge-ok">Linked</span>
                            <div class="cell-sub"><?= e($hirer['user_name']) ?></div>
                        <?php else: ?>
                            <span class="badge badge-muted">No login</span>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap">
                        <?php if ((int) $hirer['open_hires'] > 0): ?>
                            <strong><?= (int) $hirer['open_hires'] ?></strong>
                            <?php if ((int) $hirer['overdue_hires'] > 0): ?>
                                <span class="badge hire-overdue"><?= (int) $hirer['overdue_hires'] ?> overdue</span>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <a class="btn btn-sm" href="<?= e(url('/hirers/' . $hirer['id'])) ?>">Open</a>
                        <?php if (can('hirers.manage')): ?>
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('/hirers/' . $hirer['id'] . '/edit')) ?>">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
