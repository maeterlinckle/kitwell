<?php
/**
 * Add or edit one category.
 *
 * Its own page rather than a row of inputs in the list: that is what lets the
 * list be a tree you can read at a glance, and it gives the fields that are not
 * worth a column — the description, the active flag — somewhere to live.
 *
 * @var array<string,mixed>|null       $category
 * @var array<int,array<string,mixed>> $parents  Every category this one may sit inside
 * @var int                            $parentId Preselected when adding inside something
 * @var array<string,string>           $errors
 * @var array<string,mixed>            $old
 */
$isEdit = $category !== null;
$action = $isEdit ? url('/admin/categories/' . $category['id']) : url('/admin/categories');

$value = static function (string $field, string $default = '') use ($old, $category): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    return (string) ($category[$field] ?? $default);
};

$currentParent = array_key_exists('parent_id', $old)
    ? (int) $old['parent_id']
    : (int) ($category['parent_id'] ?? $parentId);
?>
<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit category' : 'Add category' ?></h1>
        <?php if ($isEdit && (int) $category['asset_count'] > 0): ?>
            <p class="muted">
                <a href="<?= e(url('/assets?category=' . (int) $category['id'])) ?>">
                    <?= (int) $category['asset_count'] ?> asset<?= (int) $category['asset_count'] === 1 ? '' : 's' ?>
                </a> in this category.
            </p>
        <?php endif; ?>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/categories')) ?>">Back to categories</a>
</div>

<form method="post" action="<?= e($action) ?>" class="form card" novalidate>
    <?= csrf_field() ?>

    <div class="field">
        <label class="label" for="name">Name</label>
        <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name" name="name"
               maxlength="120" required autofocus value="<?= e($value('name')) ?>">
        <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label class="label" for="parent_id">Inside <span class="optional">(optional)</span></label>
        <select class="input<?= isset($errors['parent_id']) ? ' has-error' : '' ?>" id="parent_id" name="parent_id">
            <option value="">Top level</option>
            <?php foreach ($parents as $option): ?>
                <option value="<?= (int) $option['id'] ?>" <?= $currentParent === (int) $option['id'] ? 'selected' : '' ?>>
                    <?= e((string) $option['path']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($isEdit): ?>
            <p class="field-hint">
                A category cannot be moved inside itself or inside one of its own children, so those are
                not listed.
            </p>
        <?php endif; ?>
        <?php if (isset($errors['parent_id'])): ?><p class="field-error"><?= e($errors['parent_id']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label class="label" for="description">Description <span class="optional">(optional)</span></label>
        <input class="input" type="text" id="description" name="description" maxlength="255"
               value="<?= e($value('description')) ?>">
    </div>

    <?php if ($isEdit): ?>
        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="is_active" value="1" <?= (int) $category['is_active'] === 1 ? 'checked' : '' ?>>
                <span>Active<span class="field-hint">An inactive category stays on the assets already using it, but is not offered for new ones.</span></span>
            </label>
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Add category' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/categories')) ?>">Cancel</a>
    </div>
</form>
