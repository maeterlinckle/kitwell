<?php

use App\Models\Hirer;

/**
 * Everything currently with one hirer, plus their history.
 *
 * @var array<string,mixed> $hirer
 * @var array<int,array<string,mixed>> $openHires
 * @var array<int,array<string,mixed>> $pastHires
 */
$id = (int) $hirer['id'];
?>
<div class="page-head">
    <div>
        <h1><?= e($hirer['name']) ?></h1>
        <p class="badge-row">
            <span class="badge badge-muted"><?= e($hirer['hirer_type']) ?></span>
            <?php if ((int) $hirer['is_active'] !== 1): ?>
                <span class="badge badge-warn">Inactive</span>
            <?php endif; ?>
            <?php if (!empty($hirer['user_name'])): ?>
                <span class="badge badge-ok">Self-service login</span>
            <?php endif; ?>
            <?php if ((int) $hirer['overdue_hires'] > 0): ?>
                <span class="badge hire-overdue"><?= (int) $hirer['overdue_hires'] ?> overdue</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <?php if (can('hires.create') && (int) $hirer['is_active'] === 1): ?>
            <a class="btn btn-primary" href="<?= e(url('/hires/checkout')) ?>">Check something out</a>
        <?php endif; ?>
        <?php if (can('hirers.manage')): ?>
            <a class="btn" href="<?= e(url('/hirers/' . $id . '/edit')) ?>">Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-main">
        <div class="card">
            <div class="card-head">
                <h2>Out now <span class="count-pill"><?= count($openHires) ?></span></h2>
            </div>

            <?php if ($openHires === []): ?>
                <p class="muted">Nothing is currently out with this hirer.</p>
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
                        <?php foreach ($openHires as $hire): ?>
                            <tr>
                                <td>
                                    <a class="asset-link" href="<?= e(url('/assets/' . $hire['asset_id'])) ?>">
                                        <span class="mono asset-tag"><?= e($hire['asset_tag']) ?></span>
                                        <span class="asset-name"><?= e(str_limit((string) $hire['asset_name'], 40)) ?></span>
                                    </a>
                                </td>
                                <td class="nowrap"><?= e(format_date($hire['checked_out_at'])) ?></td>
                                <td class="nowrap">
                                    <span class="badge hire-<?= e(strtolower((string) $hire['effective_status'])) ?>">
                                        <?= e(format_date($hire['due_back_date'])) ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <a class="btn btn-sm" href="<?= e(url('/hires/' . $hire['id'])) ?>">Open</a>
                                    <?php if (can('hires.return')): ?>
                                        <a class="btn btn-sm btn-primary" href="<?= e(url('/hires/' . $hire['id'] . '/return')) ?>">Book in</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($pastHires !== []): ?>
            <div class="card">
                <h2>History <span class="count-pill"><?= count($pastHires) ?></span></h2>
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
                        <?php foreach ($pastHires as $hire): ?>
                            <tr>
                                <td>
                                    <a href="<?= e(url('/hires/' . $hire['id'])) ?>">
                                        <span class="mono"><?= e($hire['asset_tag']) ?></span>
                                    </a>
                                    <div class="cell-sub"><?= e(str_limit((string) $hire['asset_name'], 36)) ?></div>
                                </td>
                                <td class="nowrap"><?= e(format_date($hire['checked_out_at'])) ?></td>
                                <td class="nowrap"><?= e(format_date($hire['returned_at'])) ?></td>
                                <td><?= e($hire['condition_in'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($hirer['notes'])): ?>
            <div class="card">
                <h2>Notes</h2>
                <p class="prewrap"><?= e($hirer['notes']) ?></p>
            </div>
        <?php endif; ?>
    </div>

    <aside class="detail-side">
        <div class="card">
            <h2>Contact</h2>
            <dl class="detail-list detail-list-tight">
                <?php if (!empty($hirer['company_name'])): ?>
                    <div><dt>Company</dt><dd><?= e($hirer['company_name']) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($hirer['reference'])): ?>
                    <div><dt>Reference</dt><dd class="mono"><?= e($hirer['reference']) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($hirer['email'])): ?>
                    <div><dt>Email</dt><dd class="break"><a href="mailto:<?= e($hirer['email']) ?>"><?= e($hirer['email']) ?></a></dd></div>
                <?php endif; ?>
                <?php if (!empty($hirer['phone'])): ?>
                    <div><dt>Phone</dt><dd><a href="tel:<?= e($hirer['phone']) ?>"><?= e($hirer['phone']) ?></a></dd></div>
                <?php endif; ?>
                <?php if (!empty($hirer['address'])): ?>
                    <div><dt>Address</dt><dd class="prewrap"><?= e($hirer['address']) ?></dd></div>
                <?php endif; ?>
                <div><dt>Total hires</dt><dd><?= (int) $hirer['total_hires'] ?></dd></div>
            </dl>
        </div>

        <div class="card">
            <h2>Self-service</h2>
            <?php if (!empty($hirer['user_name'])): ?>
                <p class="muted">
                    Signs in as <strong><?= e($hirer['user_name']) ?></strong>
                    (<?= e($hirer['user_email']) ?>) and sees only their own hires.
                </p>
                <?php if ((int) $hirer['user_is_active'] !== 1): ?>
                    <p class="field-error">That login is deactivated, so they cannot sign in.</p>
                <?php endif; ?>
                <?php if (($hirer['user_role'] ?? '') !== 'hirer'): ?>
                    <p class="field-hint">
                        Note: this login has the <strong><?= e($hirer['user_role']) ?></strong> role, so it can see
                        more than the hirer portal.
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <p class="muted">No login is linked, so this hirer cannot sign in to see their own items.</p>
                <?php if (can('hirers.manage')): ?>
                    <a class="btn btn-block" href="<?= e(url('/hirers/' . $id . '/edit')) ?>">Link a login</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if (can('hirers.manage') && (int) $hirer['total_hires'] === 0): ?>
            <div class="card danger-card">
                <h2>Manage</h2>
                <form method="post" action="<?= e(url('/hirers/' . $id . '/delete')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-warning btn-block"
                            data-confirm="Delete <?= e($hirer['name']) ?>?">Delete hirer</button>
                    <p class="field-hint">Only possible while they have no hire history.</p>
                </form>
            </div>
        <?php endif; ?>
    </aside>
</div>
