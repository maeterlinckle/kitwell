<?php

use App\Models\MaintenanceRoutine;

/**
 * One routine: what it asks now, every edition it has had, and where it has
 * been used.
 *
 * @var array<string,mixed> $routine
 * @var array<string,mixed>|null $current
 * @var array<int,array<string,mixed>> $versions
 * @var array<int,array<string,mixed>> $pages
 * @var array<int,array<string,mixed>> $completions
 */
$routineId = (int) $routine['id'];
$archived  = $routine['status'] !== 'active';
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('/maintenance/routines')) ?>">Maintenance routines</a></p>
        <h1><?= e($routine['name']) ?></h1>
        <p class="badge-row">
            <?php if ($current !== null): ?>
                <span class="badge">v<?= (int) $current['version_number'] ?> live</span>
            <?php else: ?>
                <span class="badge badge-muted">Nothing published</span>
            <?php endif; ?>
            <?php if ($routine['draft_version_id'] !== null): ?>
                <span class="badge badge-warn">v<?= (int) $routine['draft_version_number'] ?> draft</span>
            <?php endif; ?>
            <?php if ($archived): ?>
                <span class="badge badge-muted">Archived</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <?php if (can('routines.manage')): ?>
            <a class="btn btn-primary" href="<?= e(url('/maintenance/routines/' . $routineId . '/edit')) ?>">Edit</a>
        <?php endif; ?>
        <a class="btn" href="<?= e(url('/maintenance/routines/' . $routineId . '/preview')) ?>">Preview</a>
        <a class="btn btn-ghost" href="<?= e(url('/maintenance/routines')) ?>">All routines</a>
    </div>
</div>

<?php if ($archived): ?>
    <div class="flash flash-info">
        <span class="flash-text">
            This routine is archived, so it is not offered for new work. Everything already recorded
            against it is unaffected.
        </span>
    </div>
<?php endif; ?>

<div class="detail-grid">
    <div class="detail-main">
        <?php if (!empty($routine['description'])): ?>
            <div class="card">
                <h2>What it is for</h2>
                <p class="prewrap"><?= e($routine['description']) ?></p>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-head">
                <h2>What it asks<?= $current === null ? '' : ' (v' . (int) $current['version_number'] . ')' ?></h2>
            </div>

            <?php if ($pages === []): ?>
                <p class="muted">
                    Nothing is published yet.
                    <?php if (can('routines.manage')): ?>
                        <a href="<?= e(url('/maintenance/routines/' . $routineId . '/edit')) ?>">Build it</a>.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <?php foreach ($pages as $index => $page): ?>
                    <section class="routine-summary-page">
                        <h3><?= (int) $index + 1 ?>. <?= e($page['title']) ?></h3>
                        <?php if (!empty($page['description'])): ?>
                            <p class="muted"><?= e($page['description']) ?></p>
                        <?php endif; ?>

                        <ul class="routine-summary-steps">
                            <?php foreach ($page['steps'] as $step): ?>
                                <li>
                                    <span class="routine-summary-label"><?= e($step['label']) ?></span>
                                    <span class="badge badge-muted"><?= e(MaintenanceRoutine::typeLabel((string) $step['field_type'])) ?></span>
                                    <?php if ((int) $step['is_required'] === 1): ?>
                                        <span class="badge">Required</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-head">
                <h2>Recently carried out</h2>
            </div>

            <?php if ($completions === []): ?>
                <p class="muted">This routine has not been run yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Asset</th>
                                <th>Version</th>
                                <th>Completed</th>
                                <th>By</th>
                                <th class="actions"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completions as $completion): ?>
                                <tr>
                                    <td>
                                        <a href="<?= e(url('/assets/' . (int) $completion['asset_id'])) ?>">
                                            <span class="mono"><?= e($completion['asset_tag']) ?></span>
                                        </a>
                                        <div class="cell-sub"><?= e(str_limit((string) $completion['asset_name'], 50)) ?></div>
                                    </td>
                                    <td><span class="badge">v<?= (int) $completion['version_number'] ?></span></td>
                                    <td><?= e(format_date((string) $completion['completed_at'])) ?></td>
                                    <td><?= e((string) ($completion['completed_by_name'] ?? 'Unknown')) ?></td>
                                    <td class="actions">
                                        <a class="btn btn-sm" href="<?= e(url('/maintenance/completions/' . (int) $completion['id'])) ?>">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <aside class="detail-side">
        <div class="card">
            <h2>Versions</h2>
            <dl class="detail-list detail-list-tight detail-list-stacked">
                <?php foreach ($versions as $version): ?>
                    <div>
                        <dt>
                            v<?= (int) $version['version_number'] ?>
                            <?php if ((int) $version['is_current'] === 1): ?>
                                <span class="badge">Live</span>
                            <?php elseif ($version['published_at'] === null): ?>
                                <span class="badge badge-warn">Draft</span>
                            <?php else: ?>
                                <span class="badge badge-muted">Superseded</span>
                            <?php endif; ?>
                        </dt>
                        <dd class="muted">
                            <?php if ($version['published_at'] !== null): ?>
                                Published <?= e(format_date((string) $version['published_at'])) ?>
                                <?php if (!empty($version['published_by_name'])): ?>
                                    by <?= e($version['published_by_name']) ?>
                                <?php endif; ?>
                                ·
                            <?php endif; ?>
                            <?= (int) $version['completion_count'] ?> run<?= (int) $version['completion_count'] === 1 ? '' : 's' ?>
                            ·
                            <a href="<?= e(url('/maintenance/routines/' . $routineId . '/preview?version=' . (int) $version['id'])) ?>">Preview</a>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </div>

        <div class="card">
            <h2>Record</h2>
            <dl class="detail-list detail-list-tight detail-list-stacked">
                <div>
                    <dt>Created</dt>
                    <dd><?= e(format_date((string) $routine['created_at'])) ?><?php
                        if (!empty($routine['created_by_name'])): ?> by <?= e($routine['created_by_name']) ?><?php endif; ?></dd>
                </div>
                <div>
                    <dt>Times carried out</dt>
                    <dd><?= (int) $routine['completion_count'] ?></dd>
                </div>
            </dl>
        </div>

        <?php if (can('routines.manage')): ?>
            <div class="card">
                <h2>Manage</h2>
                <form method="post" action="<?= e(url('/maintenance/routines/' . $routineId . '/status')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="status" value="<?= $archived ? 'active' : 'archived' ?>">
                    <button type="submit" class="btn btn-block">
                        <?= $archived ? 'Restore this routine' : 'Archive this routine' ?>
                    </button>
                </form>
                <p class="field-hint">
                    Archiving takes it off the list a technician can start from. It is never deleted:
                    the records that followed it have to keep working.
                </p>
            </div>
        <?php endif; ?>
    </aside>
</div>
