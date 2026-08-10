<?php
/**
 * One hire in full.
 *
 * @var array<string,mixed> $hire
 * @var array<int,array<string,mixed>> $photosOut
 * @var array<int,array<string,mixed>> $photosIn
 */
$id   = (int) $hire['id'];
$open = $hire['returned_at'] === null;
?>
<div class="page-head">
    <div>
        <p class="eyebrow mono"><?= e($hire['reference'] ?? 'Hire #' . $id) ?></p>
        <h1><?= e($hire['asset_name']) ?></h1>
        <p class="badge-row">
            <span class="badge hire-<?= e(strtolower((string) $hire['effective_status'])) ?>"><?= e($hire['effective_status']) ?></span>
            <a class="badge mono" href="<?= e(url('/assets/' . $hire['asset_id'])) ?>"><?= e($hire['asset_tag']) ?></a>
            <a class="badge badge-role" href="<?= e(url('/hirers/' . $hire['hirer_id'])) ?>"><?= e($hire['hirer_name']) ?></a>
        </p>
    </div>
    <div class="head-actions">
        <?php if ($open && can('hires.return')): ?>
            <a class="btn btn-primary" href="<?= e(url('/hires/' . $id . '/return')) ?>">Book in</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/hires')) ?>">All hires</a>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-main">
        <div class="card">
            <h2>Hire</h2>
            <dl class="detail-list">
                <div><dt>Checked out</dt><dd><?= e(format_datetime($hire['checked_out_at'])) ?><?= !empty($hire['checked_out_by_name']) ? ' by ' . e($hire['checked_out_by_name']) : '' ?></dd></div>
                <div>
                    <dt>Due back</dt>
                    <dd>
                        <?= e(format_date($hire['due_back_date'])) ?>
                        <?php if ($open && $hire['days_until_due'] !== null): ?>
                            <?php $d = (int) $hire['days_until_due']; ?>
                            <span class="muted">(<?= $d < 0
                                ? abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' overdue'
                                : ($d === 0 ? 'today' : 'in ' . (int) $d . ' day' . ($d === 1 ? '' : 's')) ?>)</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <?php if (!$open): ?>
                    <div><dt>Returned</dt><dd><?= e(format_datetime($hire['returned_at'])) ?><?= !empty($hire['returned_to_name']) ? ' to ' . e($hire['returned_to_name']) : '' ?></dd></div>
                <?php endif; ?>
                <div><dt>Condition out</dt><dd><?= e($hire['condition_out'] ?? '—') ?></dd></div>
                <?php if (!$open): ?>
                    <div><dt>Condition in</dt><dd><?= e($hire['condition_in'] ?? '—') ?></dd></div>
                <?php endif; ?>
                <div><dt>Purpose</dt><dd><?= e($hire['purpose'] ?? '—') ?></dd></div>
                <?php if ($hire['hire_charge'] !== null): ?>
                    <div><dt>Hire charge</dt><dd><?= e(format_money($hire['hire_charge'])) ?></dd></div>
                <?php endif; ?>
            </dl>
        </div>

        <?php if (!empty($hire['returned_condition_notes'])): ?>
            <div class="card">
                <h2>Condition notes on return</h2>
                <p class="prewrap"><?= e($hire['returned_condition_notes']) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($hire['notes'])): ?>
            <div class="card">
                <h2>Notes</h2>
                <p class="prewrap"><?= e($hire['notes']) ?></p>
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
                            <?php $src = url('/hires/' . $id . '/photos/' . $photo['id']); ?>
                            <li class="photo-tile">
                                <a class="photo-link" href="<?= e($src) ?>" data-lightbox
                                   data-caption="<?= e($label . ' — ' . $hire['asset_tag']) ?>"
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
            <h2>Hirer</h2>
            <dl class="detail-list detail-list-tight">
                <div><dt>Name</dt><dd><a href="<?= e(url('/hirers/' . $hire['hirer_id'])) ?>"><?= e($hire['hirer_name']) ?></a></dd></div>
                <div><dt>Type</dt><dd><?= e($hire['hirer_type']) ?></dd></div>
                <?php if (!empty($hire['company_name'])): ?>
                    <div><dt>Company</dt><dd><?= e($hire['company_name']) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($hire['hirer_email'])): ?>
                    <div><dt>Email</dt><dd><a href="mailto:<?= e($hire['hirer_email']) ?>"><?= e($hire['hirer_email']) ?></a></dd></div>
                <?php endif; ?>
                <?php if (!empty($hire['hirer_phone'])): ?>
                    <div><dt>Phone</dt><dd><a href="tel:<?= e($hire['hirer_phone']) ?>"><?= e($hire['hirer_phone']) ?></a></dd></div>
                <?php endif; ?>
            </dl>
        </div>

        <?php if ($open && can('hires.manage')): ?>
            <div class="card">
                <h2>Extend</h2>
                <form method="post" action="<?= e(url('/hires/' . $id . '/extend')) ?>" class="form">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label" for="due_back_date">New due-back date</label>
                        <input class="input" type="date" id="due_back_date" name="due_back_date" required
                               min="<?= e(date('Y-m-d')) ?>" value="<?= e((string) $hire['due_back_date']) ?>">
                    </div>
                    <button type="submit" class="btn btn-block">Extend hire</button>
                </form>
            </div>
        <?php endif; ?>
    </aside>
</div>
