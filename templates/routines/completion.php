<?php

use App\Core\Upload;
use App\Models\MaintenanceRoutine;
use App\Models\RoutineCompletion;

/**
 * A completed routine, laid out as it was filled in.
 *
 * The version behind $pages is the one this completion followed, never the
 * current one, so what is shown here is what was actually asked at the time.
 *
 * @var array<string,mixed> $completion
 * @var array<int,array<string,mixed>> $pages
 * @var array<int,array<string,mixed>> $responses keyed by step id
 * @var array<int,array<int,array<string,mixed>>> $files keyed by step id
 * @var array<int,array{name:?string,at:?string}> $attribution keyed by step id
 * @var array<int,array{name:?string,at:string}> $pageCompletions keyed by page id
 * @var bool $batched
 */
$id      = (int) $completion['id'];
$assetId = (int) $completion['asset_id'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">
            <a href="<?= e(url('/assets/' . $assetId)) ?>"><span class="mono"><?= e($completion['asset_tag']) ?></span></a>
            <?= e(str_limit((string) $completion['asset_name'], 60)) ?>
        </p>
        <h1><?= e($completion['routine_name']) ?></h1>
        <p class="badge-row">
            <span class="badge" title="The edition of the routine that was followed">
                v<?= (int) $completion['version_number'] ?>
            </span>
            <?php if (!empty($completion['result'])): ?>
                <span class="badge badge-muted"><?= e($completion['result']) ?></span>
            <?php endif; ?>
            <span class="badge badge-muted">
                <?= e(format_date((string) ($completion['performed_on'] ?? $completion['completed_at']))) ?>
            </span>
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-primary" href="<?= e(url('/maintenance/completions/' . $id . '/pdf')) ?>">Download PDF</a>
        <a class="btn btn-ghost" href="<?= e(url('/assets/' . $assetId . '#maintenance')) ?>">Asset history</a>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-main">
        <?php foreach ($pages as $index => $page): ?>
            <section class="card">
                <div class="card-head">
                    <h2><?= (int) $index + 1 ?>. <?= e($page['title']) ?></h2>
                    <?php /* A batched run is completed a page at a time, so the
                             page is where the name belongs — not repeated
                             against each of its steps. */ ?>
                    <?php $pageDone = $pageCompletions[(int) $page['id']] ?? null; ?>
                    <?php if ($batched && $pageDone !== null): ?>
                        <span class="muted">
                            <?php if ($pageDone['name'] !== null): ?><?= e($pageDone['name']) ?>, <?php endif; ?>
                            <?= e(format_datetime($pageDone['at'])) ?>
                        </span>
                    <?php elseif ($batched): ?>
                        <span class="badge badge-muted">Not completed</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($page['description'])): ?>
                    <p class="muted"><?= e($page['description']) ?></p>
                <?php endif; ?>

                <dl class="answer-list">
                    <?php foreach ($page['steps'] as $step): ?>
                        <?php
                        $stepId    = (int) $step['id'];
                        $answer    = RoutineCompletion::answer($step, $responses[$stepId] ?? null);
                        $stepFiles = $files[$stepId] ?? [];
                        $isFile    = in_array((string) $step['field_type'], MaintenanceRoutine::FILE_TYPES, true);
                        ?>
                        <div class="answer-row">
                            <dt class="answer-question">
                                <?= e($step['label']) ?>
                                <?php if (!empty($step['help_text'])): ?>
                                    <span class="answer-help muted"><?= e($step['help_text']) ?></span>
                                <?php endif; ?>
                                <?php $by = $batched ? null : ($attribution[$stepId] ?? null); ?>
                                <?php if ($by !== null && $by['name'] !== null): ?>
                                    <span class="answer-help muted">
                                        <?= e($by['name']) ?><?php if ($by['at'] !== null): ?>, <?= e(format_datetime((string) $by['at'])) ?><?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </dt>
                            <dd class="answer-value">
                                <?php if ($isFile): ?>
                                    <?php if ($stepFiles === []): ?>
                                        <span class="muted">Nothing attached</span>
                                    <?php else: ?>
                                        <?php $photos = array_values(array_filter($stepFiles, static fn (array $f): bool => $f['file_kind'] === 'photo')); ?>
                                        <?php $documents = array_values(array_filter($stepFiles, static fn (array $f): bool => $f['file_kind'] !== 'photo')); ?>

                                        <?php if ($photos !== []): ?>
                                            <ul class="photo-grid photo-grid-compact">
                                                <?php foreach ($photos as $photo): ?>
                                                    <?php $src = url('/maintenance/completions/' . $id . '/files/' . (int) $photo['id']); ?>
                                                    <li class="photo-tile">
                                                        <a class="photo-link" href="<?= e($src) ?>" data-lightbox
                                                           data-caption="<?= e($step['label']) ?>"
                                                           data-meta="<?= e(format_date((string) $completion['completed_at'])) ?>">
                                                            <img src="<?= e($src) ?>" loading="lazy" decoding="async"
                                                                 alt="Photograph recorded for <?= e($step['label']) ?>">
                                                        </a>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>

                                        <?php if ($documents !== []): ?>
                                            <ul class="file-list file-list-compact">
                                                <?php foreach ($documents as $document): ?>
                                                    <?php $href = url('/maintenance/completions/' . $id . '/files/' . (int) $document['id']); ?>
                                                    <li class="file-item">
                                                        <span class="file-icon" aria-hidden="true">PDF</span>
                                                        <span class="file-body">
                                                            <a class="file-title" href="<?= e($href) ?>" target="_blank" rel="noopener">
                                                                <?= e(Upload::displayName((string) ($document['original_filename'] ?: 'Document'))) ?>
                                                            </a>
                                                            <span class="file-meta muted">
                                                                <?= e(Upload::formatBytes((int) $document['file_size_bytes'])) ?>
                                                            </span>
                                                        </span>
                                                        <span class="file-actions">
                                                            <a class="btn btn-sm" href="<?= e($href . '?download=1') ?>">Download</a>
                                                        </span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php elseif ($answer === null): ?>
                                    <span class="muted">Not answered</span>
                                <?php elseif (is_array($answer)): ?>
                                    <ul class="answer-choices">
                                        <?php foreach ($answer as $choice): ?>
                                            <li><?= e($choice) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php elseif ((string) $step['field_type'] === 'boolean'): ?>
                                    <span class="badge <?= $answer === 'Yes' ? 'badge-ok' : 'badge-danger' ?>"><?= e($answer) ?></span>
                                <?php else: ?>
                                    <span class="prewrap"><?= e($answer) ?></span>
                                <?php endif; ?>
                            </dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </section>
        <?php endforeach; ?>

        <?php if (!empty($completion['log_notes'])): ?>
            <div class="card">
                <h2>Notes</h2>
                <p class="prewrap"><?= e($completion['log_notes']) ?></p>
            </div>
        <?php endif; ?>
    </div>

    <aside class="detail-side">
        <div class="card">
            <h2>Record</h2>
            <dl class="detail-list detail-list-tight detail-list-stacked">
                <div>
                    <dt>Routine</dt>
                    <dd>
                        <a href="<?= e(url('/maintenance/routines/' . (int) $completion['routine_id'])) ?>">
                            <?= e($completion['routine_name']) ?>
                        </a>
                        — version <?= (int) $completion['version_number'] ?>
                    </dd>
                </div>
                <?php if ((int) $completion['allow_out_of_order'] === 1 && !empty($completion['started_by_name'])): ?>
                    <div>
                        <dt>Started by</dt>
                        <dd>
                            <?= e((string) $completion['started_by_name']) ?>
                            <?php if ($completion['started_at'] !== null): ?>
                                <span class="cell-sub"><?= e(format_datetime((string) $completion['started_at'])) ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <div>
                    <dt><?= (int) $completion['allow_out_of_order'] === 1 ? 'Signed off by' : 'Carried out by' ?></dt>
                    <dd><?= e((string) ($completion['completed_by_name'] ?? 'Unknown')) ?></dd>
                </div>
                <div>
                    <dt>Completed</dt>
                    <dd><?= e(format_datetime((string) $completion['completed_at'])) ?></dd>
                </div>
                <?php if (!empty($completion['schedule_title'])): ?>
                    <div>
                        <dt>Scheduled job</dt>
                        <dd>
                            <a href="<?= e(url('/maintenance/' . (int) $completion['schedule_id'])) ?>">
                                <?= e($completion['schedule_title']) ?>
                            </a>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if ($completion['maintenance_log_id'] !== null): ?>
                    <div>
                        <dt>Maintenance entry</dt>
                        <dd><a href="<?= e(url('/maintenance/history?asset_id=' . $assetId)) ?>">In the asset's history</a></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>

        <div class="card">
            <h2>Share it</h2>
            <p class="field-hint">
                The PDF carries the organisation's masthead, every question with its answer, and the
                version number of the procedure that was followed.
            </p>
            <a class="btn btn-block" href="<?= e(url('/maintenance/completions/' . $id . '/pdf')) ?>">Download PDF</a>
        </div>
    </aside>
</div>
