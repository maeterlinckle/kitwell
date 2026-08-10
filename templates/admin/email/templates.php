<?php
/**
 * @var array<string,array<int,array<string,mixed>>> $grouped
 * @var int    $customised
 * @var string $section
 */
?>
<div class="page-head">
    <div>
        <h1>Email templates</h1>
        <p class="muted">
            The wording of every message the application sends. Editing one takes effect immediately —
            there is nothing to deploy.
        </p>
    </div>
</div>

<?= partial('partials/email-nav', ['section' => $section]) ?>

<div class="card notice-card">
    <p>
        Each template ships with sensible wording, so everything works before anyone edits anything.
        <?php if ($customised > 0): ?>
            <?= (int) $customised ?> of <?= count($grouped, COUNT_RECURSIVE) - count($grouped) ?> have been edited;
            any of them can be put back with one click.
        <?php else: ?>
            None have been edited yet.
        <?php endif; ?>
    </p>
</div>

<?php foreach ($grouped as $group => $templates): ?>
    <div class="card">
        <h2><?= e($group) ?></h2>

        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Template</th>
                    <th scope="col">Subject</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($templates as $template): ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('/admin/email/templates/' . $template['key'])) ?>"><?= e($template['name']) ?></a>
                            <p class="muted"><?= e($template['description']) ?></p>
                        </td>
                        <td><?= e(str_limit((string) $template['subject'], 70)) ?></td>
                        <td class="nowrap">
                            <?php if ($template['is_active'] !== true): ?>
                                <span class="badge badge-danger">Switched off</span>
                            <?php elseif ($template['is_customised'] === true): ?>
                                <span class="badge badge-ok">Edited</span>
                            <?php else: ?>
                                <span class="badge badge-muted">Default</span>
                            <?php endif; ?>

                            <?php if ($template['is_html'] === true): ?>
                                <span class="badge badge-role">HTML</span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap">
                            <a class="btn btn-sm" href="<?= e(url('/admin/email/templates/' . $template['key'])) ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>
