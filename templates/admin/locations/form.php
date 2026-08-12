<?php
/**
 * Add or edit one location.
 *
 * @var array<string,mixed>|null       $location
 * @var array<int,array<string,mixed>> $parents
 * @var int                            $parentId
 * @var array<string,string>           $errors
 * @var array<string,mixed>            $old
 */
$isEdit = $location !== null;
$action = $isEdit ? url('/admin/locations/' . $location['id']) : url('/admin/locations');

$value = static function (string $field, string $default = '') use ($old, $location): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    return (string) ($location[$field] ?? $default);
};

$currentParent = array_key_exists('parent_id', $old)
    ? (int) $old['parent_id']
    : (int) ($location['parent_id'] ?? $parentId);
?>
<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit location' : 'Add location' ?></h1>
        <?php if ($isEdit && (int) $location['asset_count'] > 0): ?>
            <p class="muted">
                <a href="<?= e(url('/assets?location=' . (int) $location['id'])) ?>">
                    <?= (int) $location['asset_count'] ?> asset<?= (int) $location['asset_count'] === 1 ? '' : 's' ?>
                </a> here.
            </p>
        <?php endif; ?>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/locations')) ?>">Back to locations</a>
</div>

<form method="post" action="<?= e($action) ?>" class="form card" novalidate>
    <?= csrf_field() ?>

    <div class="field-row">
        <div class="field">
            <label class="label" for="name">Name</label>
            <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name" name="name"
                   maxlength="120" required autofocus value="<?= e($value('name')) ?>">
            <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="code">Short code <span class="optional">(optional)</span></label>
            <input class="input mono" type="text" id="code" name="code" maxlength="40" value="<?= e($value('code')) ?>">
            <p class="field-hint">Printed on labels, e.g. WS-B3.</p>
        </div>
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
                A location cannot be moved inside itself or inside one of its own sub-locations, so those
                are not listed.
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
                <input type="checkbox" name="is_active" value="1" <?= (int) $location['is_active'] === 1 ? 'checked' : '' ?>>
                <span>Active<span class="field-hint">An inactive location stays on the assets already there, but is not offered for new ones.</span></span>
            </label>
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Add location' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/locations')) ?>">Cancel</a>
    </div>
</form>
