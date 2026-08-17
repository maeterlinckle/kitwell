<?php

use App\Models\MaintenanceRoutine;

/**
 * A routine as somebody carrying it out would meet it.
 *
 * The controls are the runner's own, rendered through the same partial and
 * then disabled — a drawing of a form would drift from the real one, and the
 * point of a preview is that it does not.
 *
 * @var array<string,mixed> $routine
 * @var array<string,mixed> $version
 * @var array<int,array<string,mixed>> $pages
 */
$routineId = (int) $routine['id'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">
            <a href="<?= e(url('/maintenance/routines/' . $routineId)) ?>"><?= e($routine['name']) ?></a>
        </p>
        <h1>Preview</h1>
        <p class="badge-row">
            <span class="badge"><?= e(MaintenanceRoutine::versionLabel($version)) ?></span>
            <?php if ((int) $version['is_current'] === 1): ?>
                <span class="badge">Live</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <?php if (can('routines.manage')): ?>
            <a class="btn" href="<?= e(url('/maintenance/routines/' . $routineId . '/edit')) ?>">Edit</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/maintenance/routines/' . $routineId)) ?>">Back</a>
    </div>
</div>

<div class="flash flash-info">
    <span class="flash-text">
        Nothing here is saved. This is what the form looks like on the shop floor, with every
        control switched off.
    </span>
</div>

<?php if ($pages === []): ?>
    <div class="card empty-state">
        <h2>Nothing to show</h2>
        <p class="muted">This version has no pages yet.</p>
    </div>
<?php else: ?>
    <?php foreach ($pages as $index => $page): ?>
        <section class="card routine-run-page">
            <h2><?= (int) $index + 1 ?>. <?= e($page['title']) ?></h2>
            <?php if (!empty($page['description'])): ?>
                <p class="muted"><?= e($page['description']) ?></p>
            <?php endif; ?>

            <?php if ($page['steps'] === []): ?>
                <p class="muted">No steps on this page.</p>
            <?php endif; ?>

            <?php foreach ($page['steps'] as $step): ?>
                <?= partial('partials/routine-field', [
                    'step'     => $step,
                    'disabled' => true,
                    'answers'  => [],
                    'errors'   => [],
                ]) ?>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
