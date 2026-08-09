<?php
/**
 * One loan in full.
 *
 * @var array<string,mixed> $loan
 * @var array<int,array<string,mixed>> $photosOut
 * @var array<int,array<string,mixed>> $photosIn
 */
$id   = (int) $loan['id'];
$open = $loan['returned_at'] === null;
?>
<div class="page-head">
    <div>
        <p class="eyebrow mono"><?= e($loan['reference'] ?? 'Loan #' . $id) ?></p>
        <h1><?= e($loan['asset_name']) ?></h1>
        <p class="badge-row">
            <span class="badge loan-<?= e(strtolower((string) $loan['effective_status'])) ?>"><?= e($loan['effective_status']) ?></span>
            <a class="badge mono" href="<?= e(url('/assets/' . $loan['asset_id'])) ?>"><?= e($loan['asset_tag']) ?></a>
            <a class="badge badge-role" href="<?= e(url('/borrowers/' . $loan['borrower_id'])) ?>"><?= e($loan['borrower_name']) ?></a>
        </p>
    </div>
    <div class="head-actions">
        <?php if ($open && can('loans.return')): ?>
            <a class="btn btn-primary" href="<?= e(url('/loans/' . $id . '/return')) ?>">Book in</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/loans')) ?>">All loans</a>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-main">
        <div class="card">
            <h2>Loan</h2>
            <dl class="detail-list">
                <div><dt>Checked out</dt><dd><?= e(format_datetime($loan['checked_out_at'])) ?><?= !empty($loan['checked_out_by_name']) ? ' by ' . e($loan['checked_out_by_name']) : '' ?></dd></div>
                <div>
                    <dt>Due back</dt>
                    <dd>
                        <?= e(format_date($loan['due_back_date'])) ?>
                        <?php if ($open && $loan['days_until_due'] !== null): ?>
                            <?php $d = (int) $loan['days_until_due']; ?>
                            <span class="muted">(<?= $d < 0
                                ? abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' overdue'
                                : ($d === 0 ? 'today' : 'in ' . (int) $d . ' day' . ($d === 1 ? '' : 's')) ?>)</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <?php if (!$open): ?>
                    <div><dt>Returned</dt><dd><?= e(format_datetime($loan['returned_at'])) ?><?= !empty($loan['returned_to_name']) ? ' to ' . e($loan['returned_to_name']) : '' ?></dd></div>
                <?php endif; ?>
                <div><dt>Condition out</dt><dd><?= e($loan['condition_out'] ?? '—') ?></dd></div>
                <?php if (!$open): ?>
                    <div><dt>Condition in</dt><dd><?= e($loan['condition_in'] ?? '—') ?></dd></div>
                <?php endif; ?>
                <div><dt>Purpose</dt><dd><?= e($loan['purpose'] ?? '—') ?></dd></div>
                <?php if ($loan['hire_charge'] !== null): ?>
                    <div><dt>Hire charge</dt><dd><?= e(format_money($loan['hire_charge'])) ?></dd></div>
                <?php endif; ?>
            </dl>
        </div>

        <?php if (!empty($loan['returned_condition_notes'])): ?>
            <div class="card">
                <h2>Condition notes on return</h2>
                <p class="prewrap"><?= e($loan['returned_condition_notes']) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($loan['notes'])): ?>
            <div class="card">
                <h2>Notes</h2>
                <p class="prewrap"><?= e($loan['notes']) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($photosOut !== [] || $photosIn !== []): ?>
            <div class="card">
                <h2>Condition photos</h2>

                <?php foreach ([['out', 'Going out', $photosOut], ['in', 'On return', $photosIn]] as [$stage, $label, $photos]): ?>
                    <?php if ($photos === []) { continue; } ?>
                    <h3 class="group-title"><?= e($label) ?></h3>
                    <ul class="photo-grid photo-grid-compact">
                        <?php foreach ($photos as $photo): ?>
                            <?php $src = url('/loans/' . $id . '/photos/' . $photo['id']); ?>
                            <li class="photo-tile">
                                <a class="photo-link" href="<?= e($src) ?>" data-lightbox
                                   data-caption="<?= e($label . ' — ' . $loan['asset_tag']) ?>"
                                   data-meta="<?= e(format_date($photo['created_at']) . ' · ' . ($photo['uploaded_by_name'] ?? '')) ?>">
                                    <img src="<?= e($src) ?>" alt="<?= e($label) ?> photo" loading="lazy" decoding="async">
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <aside class="detail-side">
        <div class="card">
            <h2>Borrower</h2>
            <dl class="detail-list detail-list-tight">
                <div><dt>Name</dt><dd><a href="<?= e(url('/borrowers/' . $loan['borrower_id'])) ?>"><?= e($loan['borrower_name']) ?></a></dd></div>
                <div><dt>Type</dt><dd><?= e($loan['borrower_type']) ?></dd></div>
                <?php if (!empty($loan['company_name'])): ?>
                    <div><dt>Company</dt><dd><?= e($loan['company_name']) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($loan['borrower_email'])): ?>
                    <div><dt>Email</dt><dd><a href="mailto:<?= e($loan['borrower_email']) ?>"><?= e($loan['borrower_email']) ?></a></dd></div>
                <?php endif; ?>
                <?php if (!empty($loan['borrower_phone'])): ?>
                    <div><dt>Phone</dt><dd><a href="tel:<?= e($loan['borrower_phone']) ?>"><?= e($loan['borrower_phone']) ?></a></dd></div>
                <?php endif; ?>
            </dl>
        </div>

        <?php if ($open && can('loans.manage')): ?>
            <div class="card">
                <h2>Extend</h2>
                <form method="post" action="<?= e(url('/loans/' . $id . '/extend')) ?>" class="form">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label" for="due_back_date">New due-back date</label>
                        <input class="input" type="date" id="due_back_date" name="due_back_date" required
                               min="<?= e(date('Y-m-d')) ?>" value="<?= e((string) $loan['due_back_date']) ?>">
                    </div>
                    <button type="submit" class="btn btn-block">Extend loan</button>
                </form>
            </div>
        <?php endif; ?>
    </aside>
</div>
