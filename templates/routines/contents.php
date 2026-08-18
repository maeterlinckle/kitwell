<?php

use App\Controllers\RoutineRunController;
use App\Models\MaintenanceRoutine;
use App\Models\RoutineCompletion;

/**
 * An open run: every page and step, what it says now, and who put it there.
 *
 * This is the page a second station lands on. Nothing here is sequential —
 * every unanswered piece of work is one click away, whatever page it sits on —
 * and the run is signed off from the bottom once the required parts are done.
 *
 * What "a piece of work" means depends on the version. A checklist run answers
 * a step at a time, so each step carries its own action. A page-batched one
 * answers a page at a time, so the page carries it and the steps below are a
 * summary.
 *
 * @var array<string,mixed> $completion
 * @var array<int,array<string,mixed>> $pages
 * @var array<int,array<string,mixed>> $responses   keyed by step id
 * @var array<int,array<int,array<string,mixed>>> $files keyed by step id
 * @var bool $batched
 * @var array<int,array{name:?string,at:?string}> $attribution keyed by step id
 * @var array<int,array{name:?string,at:string}> $pageCompletions keyed by page id
 * @var array<int,array<string,mixed>> $outstanding
 */
$id      = (int) $completion['id'];
$assetId = (int) $completion['asset_id'];
$unit    = $batched ? 'page' : 'step';

/**
 * Progress, counted in whatever the unit of work actually is.
 *
 * A batched run is judged by its pages, because a page is what somebody sits
 * down and finishes; counting its steps would report a page half-done, which
 * is a state it cannot be in.
 */
$total = 0;
$done  = 0;

foreach ($pages as $page) {
    if ($batched) {
        $total++;

        if (isset($pageCompletions[(int) $page['id']])) {
            $done++;
        }

        continue;
    }

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
            <span class="badge badge-muted">
                <?= (int) $done ?> of <?= (int) $total ?>
                <?= e($unit) ?><?= $total === 1 ? '' : 's' ?> done
            </span>
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
        <?php if ($batched): ?>
            Pages can be done in any order, by anybody, but each one is answered in a single sitting.
        <?php else: ?>
            Steps can be answered in any order, by anybody.
        <?php endif; ?>
        <?php if ($outstanding === []): ?>
            Everything required has been done — this run is ready to sign off.
        <?php else: ?>
            <?= count($outstanding) ?> required <?= e($unit) ?><?= count($outstanding) === 1 ? '' : 's' ?>
            still to complete before it can be signed off.
        <?php endif; ?>
    </span>
</div>

<div class="detail-grid">
    <div class="detail-main">
        <?php foreach ($pages as $index => $page): ?>
            <?php
            $pageId   = (int) $page['id'];
            $pageDone = $pageCompletions[$pageId] ?? null;
            $pageUrl  = url('/maintenance/completions/' . $id . '/pages/' . $pageId);
            ?>
            <section class="card" id="page-<?= (int) $pageId ?>">
                <div class="card-head">
                    <h2><?= (int) $index + 1 ?>. <?= e($page['title']) ?></h2>

                    <?php if ($batched): ?>
                        <div class="card-actions">
                            <?php if ((int) $page['required_for_signoff'] === 1 && $pageDone === null): ?>
                                <span class="badge badge-warn">Required</span>
                            <?php endif; ?>
                            <a class="btn btn-sm<?= $pageDone === null ? ' btn-primary' : '' ?>" href="<?= e($pageUrl) ?>">
                                <?= $pageDone === null ? 'Answer' : 'Change' ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($batched): ?>
                    <p class="muted">
                        <?php if ($pageDone === null): ?>
                            Not started.
                        <?php else: ?>
                            Completed<?php if ($pageDone['name'] !== null): ?> by <?= e($pageDone['name']) ?><?php endif; ?>
                            on <?= e(format_datetime($pageDone['at'])) ?>.
                        <?php endif; ?>
                        <?= count((array) $page['steps']) ?> step<?= count((array) $page['steps']) === 1 ? '' : 's' ?>,
                        answered together.
                    </p>
                <?php endif; ?>

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
                                <?php if ($batched): ?>
                                    <span class="run-step-label"><?= e($step['label']) ?></span>
                                <?php else: ?>
                                    <a class="run-step-label" href="<?= e($stepUrl) ?>"><?= e($step['label']) ?></a>
                                <?php endif; ?>

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
                                        <?php /* In a batched run the page says who did it, so repeating
                                                 the same name against every step of it is noise. */ ?>
                                        <?php if (!$batched && $by !== null && $by['name'] !== null): ?>
                                            &middot; <?= e($by['name']) ?>
                                        <?php endif; ?>
                                        <?php if (!$batched && $by !== null && $by['at'] !== null): ?>
                                            &middot; <?= e(format_datetime((string) $by['at'])) ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </span>
                            </span>

                            <?php if (!$batched): ?>
                                <span class="run-step-actions">
                                    <?php if ($required && !$answered): ?>
                                        <span class="badge badge-warn">Required</span>
                                    <?php endif; ?>
                                    <a class="btn btn-sm<?= $answered ? '' : ' btn-primary' ?>" href="<?= e($stepUrl) ?>">
                                        <?= $answered ? 'Change' : 'Answer' ?>
                                    </a>
                                </span>
                            <?php elseif ($required): ?>
                                <span class="run-step-actions">
                                    <span class="badge badge-muted">Required</span>
                                </span>
                            <?php endif; ?>
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
                    <dt>Worked through</dt>
                    <dd><?= $batched ? 'A page at a time, in any order' : 'A step at a time, in any order' ?></dd>
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
                    <dd><?= (int) $done ?> of <?= (int) $total ?> <?= e($unit) ?><?= $total === 1 ? '' : 's' ?> done</dd>
                </div>
            </dl>
        </div>

        <?php if ($outstanding !== []): ?>
            <div class="card">
                <h2>Still required</h2>
                <ul class="plain-list">
                    <?php foreach ($outstanding as $item): ?>
                        <li>
                            <?php if ($batched): ?>
                                <a href="<?= e(url('/maintenance/completions/' . $id . '/pages/' . (int) $item['id'])) ?>">
                                    <?= e((string) $item['title']) ?>
                                </a>
                            <?php else: ?>
                                <a href="<?= e(url('/maintenance/completions/' . $id . '/steps/' . (int) $item['id'])) ?>">
                                    <?= e((string) $item['label']) ?>
                                </a>
                                <span class="cell-sub"><?= e((string) $item['page_title']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Finish</h2>
            <p class="field-hint">
                Signing off records the maintenance entry against the asset and closes the run.
                <?= $batched
                    ? 'Each page keeps the name of whoever completed it.'
                    : 'Each answer keeps the name of whoever gave it.' ?>
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
