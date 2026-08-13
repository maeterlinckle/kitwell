<?php
/**
 * Asset templates: the starting points the Add asset form can offer.
 *
 * @var array<int,array<string,mixed>> $templates
 */
?>
<div class="page-head">
    <div>
        <h1>Asset templates</h1>
        <p class="muted">
            Starting points for equipment you register often. A template fills
            the Add asset form in and brings its photos and documents with it;
            everything it supplies can still be changed before the asset is
            created.
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-primary" href="<?= e(url('/admin/templates/create')) ?>">Add template</a>
    </div>
</div>

<div class="card">
    <?php if ($templates === []): ?>
        <p class="empty muted">
            No templates yet. One is worth making as soon as you find yourself
            typing the same make, model and electrical details twice.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Fills in</th>
                        <th>Files</th>
                        <th>Offered</th>
                        <th class="actions"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $template): ?>
                        <?php
                        $id      = (int) $template['id'];
                        $summary = array_values(array_filter([
                            $template['category_name'] ?? null,
                            trim(((string) ($template['manufacturer'] ?? '')) . ' ' . ((string) ($template['model'] ?? ''))) ?: null,
                            $template['location_name'] ?? null,
                        ]));
                        ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('/admin/templates/' . $id . '/edit')) ?>"><?= e($template['name']) ?></a>
                                <?php if (!empty($template['description'])): ?>
                                    <div class="cell-sub"><?= e(str_limit((string) $template['description'], 80)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $summary === [] ? '<span class="muted">—</span>' : e(implode(' · ', $summary)) ?>
                            </td>
                            <td><?= (int) $template['media_count'] ?></td>
                            <td>
                                <?php if ((int) $template['is_active'] === 1): ?>
                                    <span class="badge badge-ok">Yes</span>
                                <?php else: ?>
                                    <span class="badge">Hidden</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <a class="btn btn-sm" href="<?= e(url('/admin/templates/' . $id . '/edit')) ?>">Edit</a>
                                <?php if (can('assets.create')): ?>
                                    <a class="btn btn-sm" href="<?= e(url('/assets/create?template=' . $id)) ?>">Use</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
