<?php

use App\Core\Upload;

/**
 * Read-only detail of one item a borrower currently holds.
 *
 * $asset has already been reduced to the fields a borrower may see, so there
 * is nothing here to accidentally print.
 *
 * @var array<string,mixed> $loan
 * @var array<string,mixed> $asset
 * @var array<int,array<string,mixed>> $manuals
 * @var array<string,mixed>|null $photo
 * @var array{result:string,test_date:string,retest_due:?string,status:string}|null $pat
 */
$overdue = $loan['effective_status'] === 'Overdue';
$days    = (int) $loan['days_until_due'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow mono"><?= e($asset['asset_tag']) ?></p>
        <h1><?= e($asset['name']) ?></h1>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/my-loans')) ?>">Back to my loans</a>
</div>

<div class="card loan-summary <?= $overdue ? 'is-overdue' : '' ?>">
    <div>
        <span class="label">Taken out</span>
        <strong><?= e(format_date($loan['checked_out_at'])) ?></strong>
    </div>
    <div>
        <span class="label">Due back</span>
        <strong><?= e(format_date($loan['due_back_date'])) ?></strong>
        <span class="muted">
            <?php if ($overdue): ?>
                <?= abs($days) ?> day<?= abs($days) === 1 ? '' : 's' ?> overdue
            <?php elseif ($days === 0): ?>
                today
            <?php else: ?>
                in <?= (int) $days ?> day<?= $days === 1 ? '' : 's' ?>
            <?php endif; ?>
        </span>
    </div>
    <div>
        <span class="label">Condition</span>
        <strong><?= e($asset['condition_rating']) ?></strong>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-main">
        <?php if ($photo !== null): ?>
            <div class="card">
                <a class="my-loan-photo" href="<?= e(url('/my-loans/' . $loan['id'] . '/photo')) ?>"
                   data-lightbox data-caption="<?= e($asset['name']) ?>" data-meta="<?= e($asset['asset_tag']) ?>">
                    <img src="<?= e(url('/my-loans/' . $loan['id'] . '/photo')) ?>"
                         alt="Photo of <?= e($asset['name']) ?>" loading="lazy" decoding="async">
                </a>
            </div>
        <?php endif; ?>

        <?php if (!empty($asset['description'])): ?>
            <div class="card">
                <h2>About this item</h2>
                <p class="prewrap"><?= e($asset['description']) ?></p>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Details</h2>
            <dl class="detail-list">
                <div><dt>Asset tag</dt><dd class="mono"><?= e($asset['asset_tag']) ?></dd></div>
                <div><dt>Condition</dt><dd><?= e($asset['condition_rating']) ?></dd></div>
                <?php if (!empty($asset['manufacturer'])): ?>
                    <div><dt>Manufacturer</dt><dd><?= e($asset['manufacturer']) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($asset['model'])): ?>
                    <div><dt>Model</dt><dd><?= e($asset['model']) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($asset['manufacturer_url'])): ?>
                    <div>
                        <dt>Manufacturer website</dt>
                        <dd>
                            <a href="<?= e($asset['manufacturer_url']) ?>" target="_blank" rel="noopener noreferrer nofollow">
                                <?= e(str_limit(preg_replace('#^https?://(www\.)?#', '', (string) $asset['manufacturer_url']) ?? '', 48)) ?>
                                <span class="external-hint" aria-hidden="true">↗</span>
                                <span class="sr-only">(opens in a new tab)</span>
                            </a>
                        </dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>

        <?php if ($manuals !== []): ?>
            <div class="card">
                <h2>Manuals &amp; documents</h2>
                <ul class="file-list">
                    <?php foreach ($manuals as $manual): ?>
                        <li class="file-item">
                            <span class="file-icon" aria-hidden="true">PDF</span>
                            <span class="file-body">
                                <a class="file-title" href="<?= e(url('/my-loans/' . $loan['id'] . '/manuals/' . $manual['id'])) ?>"
                                   target="_blank" rel="noopener"><?= e($manual['title']) ?></a>
                                <span class="file-meta muted"><?= e(Upload::formatBytes((int) $manual['file_size_bytes'])) ?></span>
                            </span>
                            <span class="file-actions">
                                <a class="btn btn-sm" href="<?= e(url('/my-loans/' . $loan['id'] . '/manuals/' . $manual['id'] . '?download=1')) ?>">Download</a>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <aside class="detail-side">
        <?php if ($pat !== null): ?>
            <?php $patPassed = $pat['result'] === 'Pass'; ?>
            <div class="card pat-simple <?= $patPassed ? 'is-pass' : 'is-fail' ?>">
                <h2>Electrical safety test</h2>
                <p class="pat-simple-result">
                    <span class="badge pat-<?= $patPassed ? 'pass' : 'fail' ?>"><?= e($pat['result']) ?></span>
                </p>
                <dl class="detail-list detail-list-tight">
                    <div><dt>Tested</dt><dd><?= e(format_date($pat['test_date'])) ?></dd></div>
                    <div><dt>Next test due</dt><dd><?= e(format_date($pat['retest_due'])) ?></dd></div>
                </dl>
                <?php if (!$patPassed): ?>
                    <p class="field-error">This item did not pass its last test. Please check with the workshop before using it.</p>
                <?php elseif ($pat['status'] === 'Overdue'): ?>
                    <p class="field-hint">Its retest is now due — please mention it when you bring the item back.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Returning this</h2>
            <p class="muted">
                Bring the item back to the workshop by <strong><?= e(format_date($loan['due_back_date'])) ?></strong>.
                A member of staff will book it in and check it over.
            </p>
            <?php if (!empty($loan['borrower_email'])): ?>
                <p class="field-hint">Any problems, get in touch before the due date.</p>
            <?php endif; ?>
        </div>
    </aside>
</div>
