<?php

use App\Models\MaintenanceRoutine;

/**
 * Opening a run of a routine whose steps may be answered out of order.
 *
 * Such a routine is not a form to fill in and post — it is a run that gets
 * opened, worked through by whoever reaches it, and signed off at the end. So
 * this is a deliberate button rather than a page that starts one by being
 * looked at.
 *
 * @var array<string,mixed> $asset
 * @var array<string,mixed> $routine
 * @var array<string,mixed> $version
 * @var array<string,mixed>|null $schedule
 * @var array<int,array<string,mixed>> $pages
 */
$assetId    = (int) $asset['id'];
$routineId  = (int) $routine['id'];
$scheduleId = $schedule === null ? 0 : (int) $schedule['id'];

$stepCount = 0;
foreach ($pages as $page) {
    $stepCount += count((array) $page['steps']);
}
?>
<div class="page-head">
    <div>
        <p class="eyebrow">
            <a href="<?= e(url('/assets/' . $assetId)) ?>"><span class="mono"><?= e($asset['asset_tag']) ?></span></a>
            <?= e(str_limit((string) $asset['name'], 60)) ?>
        </p>
        <h1><?= e($routine['name']) ?></h1>
        <p class="badge-row">
            <span class="badge">v<?= (int) $version['version_number'] ?></span>
            <span class="badge badge-muted">Checklist</span>
            <?php if ($schedule !== null): ?>
                <span class="badge badge-muted"><?= e(str_limit((string) $schedule['title'], 60)) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/assets/' . $assetId)) ?>">Cancel</a>
</div>

<div class="card">
    <h2>Start this routine</h2>

    <?php if (!empty($routine['description'])): ?>
        <p class="prewrap"><?= e($routine['description']) ?></p>
    <?php endif; ?>

    <p>
        <?= (int) $stepCount ?> step<?= $stepCount === 1 ? '' : 's' ?>
        across <?= count($pages) ?> page<?= count($pages) === 1 ? '' : 's' ?>.
        They can be answered in any order and by anybody, so the run stays open until
        somebody signs it off.
    </p>

    <?php if ($schedule !== null && !empty($schedule['instructions'])): ?>
        <h3 class="group-title">Instructions for this job</h3>
        <p class="prewrap"><?= e($schedule['instructions']) ?></p>
    <?php endif; ?>

    <ol class="routine-summary-steps">
        <?php foreach ($pages as $index => $page): ?>
            <li>
                <span class="routine-summary-label"><?= (int) $index + 1 ?>. <?= e($page['title']) ?></span>
                <span class="badge badge-muted">
                    <?= count((array) $page['steps']) ?> step<?= count((array) $page['steps']) === 1 ? '' : 's' ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ol>

    <form method="post" action="<?= e(url('/assets/' . $assetId . '/routines/' . $routineId . '/start'
        . ($scheduleId > 0 ? '?schedule=' . $scheduleId : ''))) ?>">
        <?= csrf_field() ?>
        <div class="form-actions form-actions-inline">
            <button type="submit" class="btn btn-primary btn-lg">Start the run</button>
            <a class="btn btn-ghost" href="<?= e(url('/maintenance/routines/' . $routineId . '/preview')) ?>">Preview it first</a>
        </div>
    </form>
</div>
