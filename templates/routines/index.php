<?php

/**
 * Every routine a site has, whatever state it is in.
 *
 * Readable by anyone who can see maintenance; only a `routines.manage` holder
 * gets the buttons that change one.
 *
 * @var array<int,array<string,mixed>> $routines
 */
$active   = array_values(array_filter($routines, static fn (array $r): bool => $r['status'] === 'active'));
$archived = array_values(array_filter($routines, static fn (array $r): bool => $r['status'] !== 'active'));
?>
<div class="page-head">
    <div>
        <h1>Maintenance routines</h1>
        <p class="muted">Procedures a technician steps through and fills in while doing the work.</p>
    </div>
    <div class="head-actions">
        <?php if (can('routines.manage')): ?>
            <a class="btn btn-primary" href="<?= e(url('/maintenance/routines/create')) ?>">New routine</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/maintenance')) ?>">Schedules</a>
    </div>
</div>

<?php if ($routines === []): ?>
    <div class="card empty-state">
        <h2>No routines yet</h2>
        <p class="muted">
            A routine is a set of questions — checks, readings, photographs — asked in a fixed order
            every time a job is done. Build one and it can be run against any asset, or attached to a
            maintenance schedule so completing that job fills it in.
        </p>
        <?php if (can('routines.manage')): ?>
            <a class="btn btn-primary" href="<?= e(url('/maintenance/routines/create')) ?>">Build the first one</a>
        <?php else: ?>
            <p class="muted">Building one needs the “Manage maintenance routines” permission.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php foreach ([['Active', $active], ['Archived', $archived]] as [$heading, $group]): ?>
        <?php if ($group === []): continue; endif; ?>

        <div class="card">
            <div class="card-head">
                <h2><?= e($heading) ?></h2>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Routine</th>
                            <th>Live version</th>
                            <th>Draft</th>
                            <th class="num">Times run</th>
                            <th class="actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group as $routine): ?>
                            <?php $id = (int) $routine['id']; ?>
                            <tr>
                                <td>
                                    <a href="<?= e(url('/maintenance/routines/' . $id)) ?>">
                                        <?= e($routine['name']) ?>
                                    </a>
                                    <?php if (!empty($routine['description'])): ?>
                                        <div class="cell-sub"><?= e(str_limit((string) $routine['description'], 90)) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($routine['current_version_id'] !== null): ?>
                                        <span class="badge">v<?= (int) $routine['current_version_number'] ?></span>
                                        <span class="muted">published <?= e(format_date((string) $routine['current_published_at'])) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-muted">Not published</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($routine['draft_version_id'] !== null): ?>
                                        <span class="badge badge-warn">v<?= (int) $routine['draft_version_number'] ?> draft</span>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= (int) $routine['completion_count'] ?></td>
                                <td class="actions">
                                    <?php if (can('routines.manage')): ?>
                                        <a class="btn btn-sm" href="<?= e(url('/maintenance/routines/' . $id . '/edit')) ?>">Edit</a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm" href="<?= e(url('/maintenance/routines/' . $id . '/preview')) ?>">Preview</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
