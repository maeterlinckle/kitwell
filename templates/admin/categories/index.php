<?php
/**
 * Categories as a tree.
 *
 * The list used to be one editable card per category — every field of every
 * entry on screen at once, which was fine at six and unreadable at sixty, and
 * showed the hierarchy only as a "Inside" dropdown you had to read one row at a
 * time. Editing now has its own page so this one can be a list you can scan.
 *
 * @var array<int,array<string,mixed>> $categories
 */
?>
<div class="page-head">
    <div>
        <h1>Categories</h1>
        <p class="muted">
            Used to group assets in the register and in the filters.
            <?= count($categories) ?> in total.
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('/admin/locations')) ?>">Locations</a>
        <a class="btn btn-primary" href="<?= e(url('/admin/categories/create')) ?>">Add category</a>
    </div>
</div>

<?php if ($categories === []): ?>
    <div class="card empty-state">
        <h2>No categories yet</h2>
        <p class="muted">Categories are what the register groups by, and what the filters offer.</p>
        <a class="btn btn-primary" href="<?= e(url('/admin/categories/create')) ?>">Add the first one</a>
    </div>
<?php else: ?>
    <div class="card tree-card">
        <?= partial('partials/reference-tree', [
            'items' => $categories,
            'base'  => '/admin/categories',
            'noun'  => 'category',
            'usage' => 'category',
        ]) ?>
    </div>
<?php endif; ?>
