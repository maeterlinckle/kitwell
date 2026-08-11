<?php
/**
 * @var array<int,array<string,mixed>> $teams
 */
?>
<div class="page-head">
    <div>
        <h1>Teams</h1>
        <p class="muted">
            A group work can be assigned to instead of one person. Everyone in a team is reminded
            about the team’s jobs, and anyone in it can record one as done.
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-primary" href="<?= e(url('/admin/teams/create')) ?>">Add team</a>
    </div>
</div>

<?php if ($teams === []): ?>
    <div class="card empty-state">
        <h2>No teams yet</h2>
        <p class="muted">
            Until there is one, maintenance schedules can only be assigned to an individual — which
            is fine until that person is away and the job goes quiet.
        </p>
        <a class="btn btn-primary" href="<?= e(url('/admin/teams/create')) ?>">Add the first team</a>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Team</th>
                    <th scope="col" class="num">Members</th>
                    <th scope="col" class="num">Open jobs</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($teams as $team): ?>
                    <?php $archived = (int) $team['is_active'] !== 1; ?>
                    <tr class="<?= $archived ? 'row-muted' : '' ?>">
                        <td>
                            <div class="role-name">
                                <strong><?= e($team['name']) ?></strong>
                                <?php if ($archived): ?>
                                    <span class="badge badge-muted">Archived</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($team['description'])): ?>
                                <div class="cell-sub"><?= e($team['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="num nowrap"><?= (int) $team['member_count'] ?></td>
                        <td class="num nowrap">
                            <?php if ((int) $team['schedule_count'] > 0): ?>
                                <a href="<?= e(url('/maintenance?assignee=team%3A' . (int) $team['id'])) ?>"><?= (int) $team['schedule_count'] ?></a>
                            <?php else: ?>
                                <span class="muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap actions">
                            <a class="btn btn-sm" href="<?= e(url('/admin/teams/' . $team['id'] . '/edit')) ?>">Edit</a>
                            <form method="post" action="<?= e(url('/admin/teams/' . $team['id'] . '/status')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-ghost"
                                        data-confirm="<?= $archived
                                            ? 'Make “' . e($team['name']) . '” available for new work again?'
                                            : 'Archive “' . e($team['name']) . '”? It keeps the jobs already assigned to it and its members keep their reminders — it just will not be offered for anything new.' ?>">
                                    <?= $archived ? 'Bring back' : 'Archive' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
