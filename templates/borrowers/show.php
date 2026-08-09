<?php

use App\Models\Borrower;

/**
 * Everything currently with one borrower, plus their history.
 *
 * @var array<string,mixed> $borrower
 * @var array<int,array<string,mixed>> $openLoans
 * @var array<int,array<string,mixed>> $pastLoans
 */
$id = (int) $borrower['id'];
?>
<div class="page-head">
    <div>
        <h1><?= e($borrower['name']) ?></h1>
        <p class="badge-row">
            <span class="badge badge-muted"><?= e($borrower['borrower_type']) ?></span>
            <?php if ((int) $borrower['is_active'] !== 1): ?>
                <span class="badge badge-warn">Inactive</span>
            <?php endif; ?>
            <?php if (!empty($borrower['user_name'])): ?>
                <span class="badge badge-ok">Self-service login</span>
            <?php endif; ?>
            <?php if ((int) $borrower['overdue_loans'] > 0): ?>
                <span class="badge loan-overdue"><?= (int) $borrower['overdue_loans'] ?> overdue</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <?php if (can('loans.create') && (int) $borrower['is_active'] === 1): ?>
            <a class="btn btn-primary" href="<?= e(url('/loans/checkout')) ?>">Check something out</a>
        <?php endif; ?>
        <?php if (can('borrowers.manage')): ?>
            <a class="btn" href="<?= e(url('/borrowers/' . $id . '/edit')) ?>">Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-main">
        <div class="card">
            <div class="card-head">
                <h2>Out now <span class="count-pill"><?= count($openLoans) ?></span></h2>
            </div>

            <?php if ($openLoans === []): ?>
                <p class="muted">Nothing is currently out with this borrower.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table table-compact">
                        <thead>
                        <tr>
                            <th scope="col">Asset</th>
                            <th scope="col">Out</th>
                            <th scope="col">Due back</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($openLoans as $loan): ?>
                            <tr>
                                <td>
                                    <a class="asset-link" href="<?= e(url('/assets/' . $loan['asset_id'])) ?>">
                                        <span class="mono asset-tag"><?= e($loan['asset_tag']) ?></span>
                                        <span class="asset-name"><?= e(str_limit((string) $loan['asset_name'], 40)) ?></span>
                                    </a>
                                </td>
                                <td class="nowrap"><?= e(format_date($loan['checked_out_at'])) ?></td>
                                <td class="nowrap">
                                    <span class="badge loan-<?= e(strtolower((string) $loan['effective_status'])) ?>">
                                        <?= e(format_date($loan['due_back_date'])) ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <a class="btn btn-sm" href="<?= e(url('/loans/' . $loan['id'])) ?>">Open</a>
                                    <?php if (can('loans.return')): ?>
                                        <a class="btn btn-sm btn-primary" href="<?= e(url('/loans/' . $loan['id'] . '/return')) ?>">Book in</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($pastLoans !== []): ?>
            <div class="card">
                <h2>History <span class="count-pill"><?= count($pastLoans) ?></span></h2>
                <div class="table-wrap">
                    <table class="table table-compact">
                        <thead>
                        <tr>
                            <th scope="col">Asset</th>
                            <th scope="col">Out</th>
                            <th scope="col">Returned</th>
                            <th scope="col">Condition back</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pastLoans as $loan): ?>
                            <tr>
                                <td>
                                    <a href="<?= e(url('/loans/' . $loan['id'])) ?>">
                                        <span class="mono"><?= e($loan['asset_tag']) ?></span>
                                    </a>
                                    <div class="cell-sub"><?= e(str_limit((string) $loan['asset_name'], 36)) ?></div>
                                </td>
                                <td class="nowrap"><?= e(format_date($loan['checked_out_at'])) ?></td>
                                <td class="nowrap"><?= e(format_date($loan['returned_at'])) ?></td>
                                <td><?= e($loan['condition_in'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($borrower['notes'])): ?>
            <div class="card">
                <h2>Notes</h2>
                <p class="prewrap"><?= e($borrower['notes']) ?></p>
            </div>
        <?php endif; ?>
    </div>

    <aside class="detail-side">
        <div class="card">
            <h2>Contact</h2>
            <dl class="detail-list detail-list-tight">
                <?php if (!empty($borrower['company_name'])): ?>
                    <div><dt>Company</dt><dd><?= e($borrower['company_name']) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($borrower['reference'])): ?>
                    <div><dt>Reference</dt><dd class="mono"><?= e($borrower['reference']) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($borrower['email'])): ?>
                    <div><dt>Email</dt><dd class="break"><a href="mailto:<?= e($borrower['email']) ?>"><?= e($borrower['email']) ?></a></dd></div>
                <?php endif; ?>
                <?php if (!empty($borrower['phone'])): ?>
                    <div><dt>Phone</dt><dd><a href="tel:<?= e($borrower['phone']) ?>"><?= e($borrower['phone']) ?></a></dd></div>
                <?php endif; ?>
                <?php if (!empty($borrower['address'])): ?>
                    <div><dt>Address</dt><dd class="prewrap"><?= e($borrower['address']) ?></dd></div>
                <?php endif; ?>
                <div><dt>Total loans</dt><dd><?= (int) $borrower['total_loans'] ?></dd></div>
            </dl>
        </div>

        <div class="card">
            <h2>Self-service</h2>
            <?php if (!empty($borrower['user_name'])): ?>
                <p class="muted">
                    Signs in as <strong><?= e($borrower['user_name']) ?></strong>
                    (<?= e($borrower['user_email']) ?>) and sees only their own loans.
                </p>
                <?php if ((int) $borrower['user_is_active'] !== 1): ?>
                    <p class="field-error">That login is deactivated, so they cannot sign in.</p>
                <?php endif; ?>
                <?php if (($borrower['user_role'] ?? '') !== 'borrower'): ?>
                    <p class="field-hint">
                        Note: this login has the <strong><?= e($borrower['user_role']) ?></strong> role, so it can see
                        more than the borrower portal.
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <p class="muted">No login is linked, so this borrower cannot sign in to see their own items.</p>
                <?php if (can('borrowers.manage')): ?>
                    <a class="btn btn-block" href="<?= e(url('/borrowers/' . $id . '/edit')) ?>">Link a login</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if (can('borrowers.manage') && (int) $borrower['total_loans'] === 0): ?>
            <div class="card danger-card">
                <h2>Manage</h2>
                <form method="post" action="<?= e(url('/borrowers/' . $id . '/delete')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-warning btn-block"
                            data-confirm="Delete <?= e($borrower['name']) ?>?">Delete borrower</button>
                    <p class="field-hint">Only possible while they have no loan history.</p>
                </form>
            </div>
        <?php endif; ?>
    </aside>
</div>
