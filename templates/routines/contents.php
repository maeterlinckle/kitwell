<?php

use App\Controllers\RoutineRunController;
use App\Models\MaintenanceRoutine;
use App\Models\RoutineCompletion;

/**
 * An open run: every page and step, what it says now, and who put it there.
 *
 * This is the page a second station lands on. Nothing here is sequential —
 * every unanswered step is one click away, whichever page it sits on — and the
 * run is signed off from the bottom once the required ones are done.
 *
 * @var array<string,mixed> $completion
 * @var array<int,array<string,mixed>> $pages
 * @var array<int,array<string,mixed>> $responses   keyed by step id
 * @var array<int,array<int,array<string,mixed>>> $files keyed by step id
 * @var array<int,array{name:?string,at:?string}> $attribution keyed by step id
 * @var array<int,array<string,mixed>> $outstanding
 */
$id      = (int) $completion['id'];
$assetId = (int) $completion['asset_id'];

$total = 0;
$done  = 0;

foreach ($pages as $page) {
    foreach ((array) $page['steps'] as $step) {
        $total++;

        if (RoutineRunController::isAnswered($step, $responses, $files)) {
            $done++;
        }
    }
}
?>
<div class="page-head">
    <div>
        <p class="eyebrow">
            <a href="<?= e(url('/assets/' . $assetId)) ?>"><span class="mono"><?= e($completion['asset_tag']) ?></span></a>
            <?= e(str_limit((string) $completion['asset_name'], 60)) ?>
        </p>
        <h1><?= e($completion['routine_name']) ?></h1>
        <p class="badge-row">
            <span class="badge badge-warn">Open</span>
            <span class="badge">v<?= (int) $completion['version_number'] ?></span>
            <span class="badge badge-muted"><?= (int) $done ?> of <?= (int) $total ?> answered</span>
            <?php if (!empty($completion['schedule_title'])): ?>
                <span class="badge badge-muted"><?= e(str_limit((string) $completion['schedule_title'], 50)) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-primary" href="<?= e(url('/maintenance/completions/' . $id . '/submit')) ?>">Sign off</a>
        <a class="btn btn-ghost" href="<?= e(url('/assets/' . $assetId)) ?>">Asset</a>
    </div>
</div>

<div class="flash flash-info">
    <span class="flash-text">
        Steps can be answered in any order, by anybody.
        <?php if ($outstanding === []): ?>
            Everything required has been answered — this run is ready to sign off.
        <?php else: ?>
            <?= count($outstanding) ?> required step<?= count($outstanding) === 1 ? '' : 's' ?>
            still to answer before it can be signed off.
        <?php endif; ?>
    </span>
</div>

<div class="detail-grid">
    <div class="detail-main">
        <?php foreach ($pages as $index => $page): ?>
            <section class="card">
                <div class="card-head">
                    <h2><?= (int) $index + 1 ?>. <?= e($page['title']) ?></h2>
                </div>

                <?php if (!empty($page['description'])): ?>
                    <p class="muted"><?= e($page['description']) ?></p>
                <?php endif; ?>

                <?php if ($page['steps'] === []): ?>
                    <p class="muted">No steps on this page.</p>
                <?php endif; ?>

                <ul class="run-step-list">
                    <?php foreach ($page['steps'] as $step): ?>
                        <?php
                        $stepId    = (int) $step['id'];
                        $answered  = RoutineRunController::isAnswered($step, $responses, $files);
                        $required  = (int) $step['is_required'] === 1;
                        $by        = $attribution[$stepId] ?? null;
                        $answer    = RoutineCompletion::answer($step, $responses[$stepId] ?? null);
                        $stepFiles = $files[$stepId] ?? [];
                        $stepUrl   = url('/maintenance/completions/' . $id . '/steps/' . $stepId);
                        ?>
                        <li class="run-step<?= $answered ? ' is-done' : '' ?>" id="step-<?= (int) $stepId ?>">
                            <span class="run-step-mark" aria-hidden="true"><?= $answered ? '&check;' : '' ?></span>

                            <span class="run-step-body">
                                <a class="run-step-label" href="<?= e($stepUrl) ?>"><?= e($step['label']) ?></a>

                                <span class="run-step-meta muted">
                                    <?php if (!$answered): ?>
                                        Not started
                                    <?php else: ?>
                                        <?php if (in_array((string) $step['field_type'], MaintenanceRoutine::FILE_TYPES, true)): ?>
                                            <?= count($stepFiles) ?> file<?= count($stepFiles) === 1 ? '' : 's' ?> attached
                                        <?php elseif (is_array($answer)): ?>
                                            <?= e(implode(', ', $answer)) ?>
                                        <?php else: ?>
                                            <?= e(str_limit((string) $answer, 80)) ?>
                                        <?php endif; ?>
                                        <?php if ($by !== null && $by['name'] !== null): ?>
                                            &middot; <?= e($by['name']) ?>
                                        <?php endif; ?>
                                        <?php if ($by !== null && $by['at'] !== null): ?>
                                            &middot; <?= e(format_datetime((string) $by['at'])) ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </span>
                            </span>

                            <span class="run-step-actions">
                                <?php if ($required && !$answered): ?>
                                    <span class="badge badge-warn">Required</span>
                                <?php endif; ?>
                                <a class="btn btn-sm<?= $answered ? '' : ' btn-primary' ?>" href="<?= e($stepUrl) ?>">
                                    <?= $answered ? 'Change' : 'Answer' ?>
                                </a>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </div>

    <aside class="detail-side">
        <div class="card">
            <h2>This run</h2>
            <dl class="detail-list detail-list-tight detail-list-stacked">
                <div>
                    <dt>Routine</dt>
                    <dd>
                        <a href="<?= e(url('/maintenance/routines/' . (int) $completion['routine_id'])) ?>">
                            <?= e($completion['routine_name']) ?>
                        </a>
                        &mdash; version <?= (int) $completion['version_number'] ?>
                    </dd>
                </div>
                <div>
                    <dt>Started</dt>
                    <dd>
                        <?= e(format_datetime((string) $completion['started_at'])) ?>
                        <?php if (!empty($completion['started_by_name'])): ?>
                            by <?= e($completion['started_by_name']) ?>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Progress</dt>
                    <dd><?= (int) $done ?> of <?= (int) $total ?> steps answered</dd>
                </div>
            </dl>
        </div>

        <?php if ($outstanding !== []): ?>
            <div class="card">
                <h2>Still required</h2>
                <ul class="plain-list">
                    <?php foreach ($outstanding as $step): ?>
                        <li>
                            <a href="<?= e(url('/maintenance/completions/' . $id . '/steps/' . (int) $step['id'])) ?>">
                                <?= e($step['label']) ?>
                            </a>
                            <span class="cell-sub"><?= e((string) $step['page_title']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Finish</h2>
            <p class="field-hint">
                Signing off records the maintenance entry against the asset and closes the run. Each
                answer keeps the name of whoever gave it.
            </p>
            <a class="btn btn-block btn-primary" href="<?= e(url('/maintenance/completions/' . $id . '/submit')) ?>">
                Sign off this routine
            </a>

            <form method="post" action="<?= e(url('/maintenance/completions/' . $id . '/discard')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-block btn-ghost"
                        data-confirm="Discard this run? Every answer and photograph on it is lost, and nothing is recorded against the asset.">
                    Discard this run
                </button>
            </form>
        </div>
    </aside>
</div>
