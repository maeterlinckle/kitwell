<?php

/**
 * Which routine to run against this asset.
 *
 * @var array<string,mixed> $asset
 * @var array<int,array<string,mixed>> $routines
 */
$assetId = (int) $asset['id'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">
            <a href="<?= e(url('/assets/' . $assetId)) ?>"><span class="mono"><?= e($asset['asset_tag']) ?></span></a>
            <?= e(str_limit((string) $asset['name'], 60)) ?>
        </p>
        <h1>Run a maintenance routine</h1>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/assets/' . $assetId)) ?>">Cancel</a>
</div>

<?php if ($routines === []): ?>
    <div class="card empty-state">
        <h2>No routines are available</h2>
        <p class="muted">
            A routine has to be published before it can be run, and an archived one is not offered.
            <?php if (can('routines.manage')): ?>
                <a href="<?= e(url('/maintenance/routines')) ?>">Manage routines</a>.
            <?php endif; ?>
        </p>
        <a class="btn" href="<?= e(url('/assets/' . $assetId . '/maintenance/log')) ?>">Log maintenance without one</a>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-head">
            <h2>Choose one</h2>
        </div>

        <ul class="pick-list">
            <?php foreach ($routines as $routine): ?>
                <li class="pick-item">
                    <a class="pick-link" href="<?= e(url('/assets/' . $assetId . '/routines/' . (int) $routine['id'] . '/run')) ?>">
                        <span class="pick-title"><?= e($routine['name']) ?></span>
                        <?php if (!empty($routine['description'])): ?>
                            <span class="pick-sub muted"><?= e(str_limit((string) $routine['description'], 120)) ?></span>
                        <?php endif; ?>
                    </a>
                    <span class="pick-meta">
                        <span class="badge">v<?= (int) $routine['current_version_number'] ?></span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
