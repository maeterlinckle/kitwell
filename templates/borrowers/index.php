<?php

use App\Models\Borrower;

/**
 * @var array<int,array<string,mixed>> $borrowers
 * @var array<string,mixed> $filters
 */
?>
<div class="page-head">
    <div>
        <h1>Borrowers</h1>
        <p class="muted"><?= count($borrowers) ?> record<?= count($borrowers) === 1 ? '' : 's' ?></p>
    </div>
    <?php if (can('borrowers.manage')): ?>
        <a class="btn btn-primary" href="<?= e(url('/borrowers/create')) ?>">Add borrower</a>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(url('/borrowers')) ?>" class="filter-bar">
    <div class="field field-inline">
        <label class="sr-only" for="q">Search</label>
        <input class="input" type="search" id="q" name="q" placeholder="Search name, company, email…"
               value="<?= e($filters['q']) ?>">
    </div>

    <div class="field field-inline">
        <label class="sr-only" for="type">Type</label>
        <select class="input" id="type" name="type">
            <option value="">People and companies</option>
            <?php foreach (Borrower::TYPES as $type): ?>
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
        <input type="checkbox" name="out" value="1" <?= !empty($filters['with_open_loans']) ? 'checked' : '' ?>>
        <span>Has items out</span>
    </label>

    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-ghost" href="<?= e(url('/borrowers')) ?>">Clear</a>
</form>

<?php if ($borrowers === []): ?>
    <div class="card empty-state">
        <h2>No borrowers</h2>
        <p class="muted">Add the people and companies who take items out.</p>
        <?php if (can('borrowers.manage')): ?>
            <a class="btn btn-primary" href="<?= e(url('/borrowers/create')) ?>">Add the first borrower</a>
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
            <?php foreach ($borrowers as $borrower): ?>
                <tr class="<?= (int) $borrower['is_active'] === 1 ? '' : 'row-muted' ?>">
                    <td>
                        <a href="<?= e(url('/borrowers/' . $borrower['id'])) ?>"><strong><?= e($borrower['name']) ?></strong></a>
                        <?php if (!empty($borrower['company_name']) && $borrower['company_name'] !== $borrower['name']): ?>
                            <div class="cell-sub"><?= e($borrower['company_name']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($borrower['reference'])): ?>
                            <div class="cell-sub mono"><?= e($borrower['reference']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-muted"><?= e($borrower['borrower_type']) ?></span></td>
                    <td class="break">
                        <?php if (!empty($borrower['email'])): ?>
                            <a href="mailto:<?= e($borrower['email']) ?>"><?= e($borrower['email']) ?></a><br>
                        <?php endif; ?>
                        <?= e($borrower['phone'] ?? '') ?>
                    </td>
                    <td>
                        <?php if (!empty($borrower['user_name'])): ?>
                            <span class="badge badge-ok">Linked</span>
                            <div class="cell-sub"><?= e($borrower['user_name']) ?></div>
                        <?php else: ?>
                            <span class="badge badge-muted">No login</span>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap">
                        <?php if ((int) $borrower['open_loans'] > 0): ?>
                            <strong><?= (int) $borrower['open_loans'] ?></strong>
                            <?php if ((int) $borrower['overdue_loans'] > 0): ?>
                                <span class="badge loan-overdue"><?= (int) $borrower['overdue_loans'] ?> overdue</span>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <a class="btn btn-sm" href="<?= e(url('/borrowers/' . $borrower['id'])) ?>">Open</a>
                        <?php if (can('borrowers.manage')): ?>
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('/borrowers/' . $borrower['id'] . '/edit')) ?>">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
