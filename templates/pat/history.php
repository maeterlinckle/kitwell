<?php
/**
 * Full PAT history for one asset, newest first.
 *
 * @var array<string,mixed> $asset
 * @var array<int,array<string,mixed>> $records
 * @var array<string,mixed>|null $status
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow mono"><?= e($asset['asset_tag']) ?></p>
        <h1>PAT history</h1>
        <p class="muted">
            <?= count($records) ?> test<?= count($records) === 1 ? '' : 's' ?> recorded for <?= e($asset['name']) ?><?php
            if ($records !== []) {
                $oldest = end($records);
                echo ', from ' . e(format_date($oldest['test_date'])) . ' to ' . e(format_date($records[0]['test_date']));
            }
            ?>.
        </p>
    </div>
    <div class="head-actions">
        <?php if (can('pat.manage')): ?>
            <a class="btn btn-primary" href="<?= e(url('/pat/create?asset=' . $asset['id'])) ?>">Record test</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/assets/' . $asset['id'])) ?>">Back to asset</a>
    </div>
</div>

<?php /* Status only: "Record test" and "Back to asset" are already in the page
         head above, and a banner repeating them is two of every button. */ ?>
<?= partial('partials/pat-status', ['asset' => $asset, 'status' => $status, 'actions' => false]) ?>

<?php if ($records === []): ?>
    <div class="card empty-state">
        <h2>No tests recorded</h2>
        <p class="muted">
            Each test is kept as its own record, so this page becomes the full testing history
            for the item — not just the latest sticker on the plug.
        </p>
        <?php if (can('pat.manage')): ?>
            <a class="btn btn-primary" href="<?= e(url('/pat/create?asset=' . $asset['id'])) ?>">Record the first test</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <ul class="pat-history">
        <?php foreach ($records as $index => $record): ?>
            <?php if ($index === 0): ?>
                <p class="section-title">Most recent</p>
            <?php elseif ($index === 1): ?>
                <p class="section-title">Earlier tests</p>
            <?php endif; ?>

            <?= partial('partials/pat-record', ['record' => $record, 'showActions' => true]) ?>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
