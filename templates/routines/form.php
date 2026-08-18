<?php

/**
 * Naming a new routine. What it asks is built afterwards, in the editor.
 *
 * @var array<string,mixed>|null $routine
 * @var array<int,array{id:int,path:string}> $categories
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
?>
<div class="page-head">
    <div>
        <h1>New maintenance routine</h1>
        <p class="muted">Name it first; the pages and steps come next.</p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/maintenance/routines')) ?>">Cancel</a>
</div>

<form method="post" action="<?= e(url('/maintenance/routines')) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <div class="field">
            <label class="label" for="name">Name</label>
            <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text"
                   id="name" name="name" required maxlength="191"
                   placeholder="e.g. Forklift daily pre-use check"
                   value="<?= e(old($old, 'name')) ?>">
            <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="description">Description <span class="optional">(optional)</span></label>
            <textarea class="input" id="description" name="description" rows="3" maxlength="1000"
                      placeholder="What this procedure is for, and when it should be used."><?= e(old($old, 'description')) ?></textarea>
        </div>

        <div class="field">
            <label class="label" for="category_id">Applies to <span class="optional">(optional)</span></label>
            <select class="input<?= isset($errors['category_id']) ? ' has-error' : '' ?>" id="category_id" name="category_id">
                <option value="">Any asset</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>" <?= old($old, 'category_id') === (string) $category['id'] ? 'selected' : '' ?>>
                        <?= e($category['path']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">
                Restricts the routine to assets in this category and every category nested beneath it.
                Leave it on <em>Any asset</em> and the routine is offered for everything.
            </p>
            <?php if (isset($errors['category_id'])): ?><p class="field-error"><?= e($errors['category_id']) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Create and start building</button>
        <a class="btn btn-ghost" href="<?= e(url('/maintenance/routines')) ?>">Cancel</a>
    </div>
</form>
