<?php
/**
 * A single PAT test record.
 *
 * @var array<string,mixed> $record
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">
            <a href="<?= e(url('/assets/' . $record['asset_id'])) ?>"><span class="mono"><?= e($record['asset_tag']) ?></span></a>
            <?= e(str_limit((string) $record['asset_name'], 50)) ?>
        </p>
        <h1>PAT test · <?= e(format_date($record['test_date'])) ?></h1>
    </div>
    <div class="head-actions">
        <?php if (can('pat.manage')): ?>
            <a class="btn" href="<?= e(url('/pat/' . $record['id'] . '/edit')) ?>">Correct</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/assets/' . $record['asset_id'] . '/pat')) ?>">Full history</a>
    </div>
</div>

<ul class="pat-history">
    <?= partial('partials/pat-record', ['record' => $record, 'showActions' => true]) ?>
</ul>

<div class="card">
    <h2>Record</h2>
    <dl class="detail-list detail-list-tight">
        <div><dt>Recorded</dt><dd><?= e(format_datetime($record['created_at'])) ?><?= !empty($record['created_by_name']) ? ' by ' . e($record['created_by_name']) : '' ?></dd></div>
        <?php if ($record['updated_at'] !== $record['created_at']): ?>
            <div><dt>Last corrected</dt><dd><?= e(format_datetime($record['updated_at'])) ?></dd></div>
        <?php endif; ?>
    </dl>
</div>
