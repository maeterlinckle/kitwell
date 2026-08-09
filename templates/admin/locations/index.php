<?php
/**
 * @var array<int,array<string,mixed>> $locations
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$topLevel = array_filter($locations, static fn (array $l): bool => $l['parent_id'] === null);
?>
<div class="page-head">
    <div>
        <h1>Locations</h1>
        <p class="muted">Where assets live. Locations can sit inside one another, e.g. Main Workshop → Bench 3.</p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/categories')) ?>">Categories</a>
</div>

<form method="post" action="<?= e(url('/admin/locations')) ?>" class="card form" novalidate>
    <?= csrf_field() ?>
    <h2>Add a location</h2>

    <div class="field-row">
        <div class="field">
            <label class="label" for="name">Name</label>
            <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name" name="name"
                   maxlength="120" required value="<?= e(old($old, 'name')) ?>">
            <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="code">Short code <span class="optional">(optional)</span></label>
            <input class="input mono" type="text" id="code" name="code" maxlength="40" value="<?= e(old($old, 'code')) ?>">
            <p class="field-hint">Printed on labels, e.g. WS-B3.</p>
        </div>
    </div>

    <div class="field-row">
        <div class="field">
            <label class="label" for="parent_id">Inside <span class="optional">(optional)</span></label>
            <select class="input" id="parent_id" name="parent_id">
                <option value="">Top level</option>
                <?php foreach ($topLevel as $location): ?>
                    <option value="<?= (int) $location['id'] ?>"><?= e($location['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label class="label" for="description">Description <span class="optional">(optional)</span></label>
            <input class="input" type="text" id="description" name="description" maxlength="255" value="<?= e(old($old, 'description')) ?>">
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Add location</button>
</form>

<?php if ($locations === []): ?>
    <div class="card empty-state">
        <p class="muted">No locations yet. Add the first one above.</p>
    </div>
<?php else: ?>
    <div class="reference-list">
        <?php foreach ($locations as $location): ?>
            <form method="post" action="<?= e(url('/admin/locations/' . $location['id'])) ?>"
                  class="card reference-row <?= (int) $location['is_active'] === 1 ? '' : 'is-inactive' ?>">
                <?= csrf_field() ?>

                <div class="reference-fields">
                    <div class="field">
                        <label class="label" for="loc-name-<?= (int) $location['id'] ?>">Name</label>
                        <input class="input" type="text" id="loc-name-<?= (int) $location['id'] ?>" name="name"
                               maxlength="120" required value="<?= e($location['name']) ?>">
                    </div>

                    <div class="field">
                        <label class="label" for="loc-code-<?= (int) $location['id'] ?>">Code</label>
                        <input class="input mono" type="text" id="loc-code-<?= (int) $location['id'] ?>" name="code"
                               maxlength="40" value="<?= e($location['code']) ?>">
                    </div>

                    <div class="field">
                        <label class="label" for="loc-parent-<?= (int) $location['id'] ?>">Inside</label>
                        <select class="input" id="loc-parent-<?= (int) $location['id'] ?>" name="parent_id">
                            <option value="">Top level</option>
                            <?php foreach ($topLevel as $option): ?>
                                <?php if ((int) $option['id'] !== (int) $location['id']): ?>
                                    <option value="<?= (int) $option['id'] ?>" <?= (int) $location['parent_id'] === (int) $option['id'] ? 'selected' : '' ?>>
                                        <?= e($option['name']) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field field-grow">
                        <label class="label" for="loc-desc-<?= (int) $location['id'] ?>">Description</label>
                        <input class="input" type="text" id="loc-desc-<?= (int) $location['id'] ?>" name="description"
                               maxlength="255" value="<?= e($location['description']) ?>">
                    </div>
                </div>

                <div class="reference-meta">
                    <label class="checkbox checkbox-compact">
                        <input type="checkbox" name="is_active" value="1" <?= (int) $location['is_active'] === 1 ? 'checked' : '' ?>>
                        <span>Active</span>
                    </label>

                    <span class="muted">
                        <?php if ((int) $location['asset_count'] > 0): ?>
                            <a href="<?= e(url('/assets?location=' . $location['id'])) ?>"><?= (int) $location['asset_count'] ?> asset<?= (int) $location['asset_count'] === 1 ? '' : 's' ?></a>
                        <?php else: ?>
                            No assets
                        <?php endif; ?>
                    </span>

                    <div class="reference-actions">
                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    </div>
                </div>
            </form>

            <?php if ((int) $location['asset_count'] === 0): ?>
                <form method="post" action="<?= e(url('/admin/locations/' . $location['id'] . '/delete')) ?>" class="reference-delete">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-ghost"
                            data-confirm="Delete “<?= e($location['name']) ?>”?">Delete <?= e($location['name']) ?></button>
                </form>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
