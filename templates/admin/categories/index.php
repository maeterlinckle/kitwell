<?php
/**
 * @var array<int,array<string,mixed>> $categories
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$topLevel = array_filter($categories, static fn (array $c): bool => $c['parent_id'] === null);
?>
<div class="page-head">
    <div>
        <h1>Categories</h1>
        <p class="muted">Used to group assets in the register and in the filters. <?= count($categories) ?> in total.</p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/locations')) ?>">Locations</a>
</div>

<form method="post" action="<?= e(url('/admin/categories')) ?>" class="card form" novalidate>
    <?= csrf_field() ?>
    <h2>Add a category</h2>

    <div class="field-row">
        <div class="field">
            <label class="label" for="name">Name</label>
            <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name" name="name"
                   maxlength="120" required value="<?= e(old($old, 'name')) ?>">
            <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="parent_id">Inside <span class="optional">(optional)</span></label>
            <select class="input" id="parent_id" name="parent_id">
                <option value="">Top level</option>
                <?php foreach ($topLevel as $category): ?>
                    <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="field">
        <label class="label" for="description">Description <span class="optional">(optional)</span></label>
        <input class="input" type="text" id="description" name="description" maxlength="255" value="<?= e(old($old, 'description')) ?>">
    </div>

    <button type="submit" class="btn btn-primary">Add category</button>
</form>

<?php if ($categories === []): ?>
    <div class="card empty-state">
        <p class="muted">No categories yet. Add the first one above.</p>
    </div>
<?php else: ?>
    <div class="reference-list">
        <?php foreach ($categories as $category): ?>
            <form method="post" action="<?= e(url('/admin/categories/' . $category['id'])) ?>"
                  class="card reference-row <?= (int) $category['is_active'] === 1 ? '' : 'is-inactive' ?>">
                <?= csrf_field() ?>

                <div class="reference-fields">
                    <div class="field">
                        <label class="label" for="cat-name-<?= (int) $category['id'] ?>">Name</label>
                        <input class="input" type="text" id="cat-name-<?= (int) $category['id'] ?>" name="name"
                               maxlength="120" required value="<?= e($category['name']) ?>">
                    </div>

                    <div class="field">
                        <label class="label" for="cat-parent-<?= (int) $category['id'] ?>">Inside</label>
                        <select class="input" id="cat-parent-<?= (int) $category['id'] ?>" name="parent_id">
                            <option value="">Top level</option>
                            <?php foreach ($topLevel as $option): ?>
                                <?php if ((int) $option['id'] !== (int) $category['id']): ?>
                                    <option value="<?= (int) $option['id'] ?>" <?= (int) $category['parent_id'] === (int) $option['id'] ? 'selected' : '' ?>>
                                        <?= e($option['name']) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field field-grow">
                        <label class="label" for="cat-desc-<?= (int) $category['id'] ?>">Description</label>
                        <input class="input" type="text" id="cat-desc-<?= (int) $category['id'] ?>" name="description"
                               maxlength="255" value="<?= e($category['description']) ?>">
                    </div>
                </div>

                <div class="reference-meta">
                    <label class="checkbox checkbox-compact">
                        <input type="checkbox" name="is_active" value="1" <?= (int) $category['is_active'] === 1 ? 'checked' : '' ?>>
                        <span>Active</span>
                    </label>

                    <span class="muted">
                        <?php if ((int) $category['asset_count'] > 0): ?>
                            <a href="<?= e(url('/assets?category=' . $category['id'])) ?>"><?= (int) $category['asset_count'] ?> asset<?= (int) $category['asset_count'] === 1 ? '' : 's' ?></a>
                        <?php else: ?>
                            No assets
                        <?php endif; ?>
                    </span>

                    <div class="reference-actions">
                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    </div>
                </div>
            </form>

            <?php if ((int) $category['asset_count'] === 0): ?>
                <form method="post" action="<?= e(url('/admin/categories/' . $category['id'] . '/delete')) ?>" class="reference-delete">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-ghost"
                            data-confirm="Delete “<?= e($category['name']) ?>”?">Delete <?= e($category['name']) ?></button>
                </form>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
