<?php
/**
 * Locations as a tree — the same shape as categories, and the same partial.
 *
 * @var array<int,array<string,mixed>> $locations
 */
?>
<div class="page-head">
    <div>
        <h1>Locations</h1>
        <p class="muted">
            Where assets live. Locations sit inside one another, e.g. Main Workshop → Bench 3.
            <?= count($locations) ?> in total.
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(url('/admin/categories')) ?>">Categories</a>
        <a class="btn btn-primary" href="<?= e(url('/admin/locations/create')) ?>">Add location</a>
    </div>
</div>

<?php if ($locations === []): ?>
    <div class="card empty-state">
        <h2>No locations yet</h2>
        <p class="muted">A location can be a building, a room, a bay or a shelf — whatever you would say out loud when asked where something is.</p>
        <a class="btn btn-primary" href="<?= e(url('/admin/locations/create')) ?>">Add the first one</a>
    </div>
<?php else: ?>
    <div class="card tree-card">
        <?= partial('partials/reference-tree', [
            'items' => $locations,
            'base'  => '/admin/locations',
            'noun'  => 'location',
            'usage' => 'location',
        ]) ?>
    </div>
<?php endif; ?>
