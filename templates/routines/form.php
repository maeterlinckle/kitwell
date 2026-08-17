<?php

/**
 * Naming a new routine. What it asks is built afterwards, in the editor.
 *
 * @var array<string,mixed>|null $routine
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
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Create and start building</button>
        <a class="btn btn-ghost" href="<?= e(url('/maintenance/routines')) ?>">Cancel</a>
    </div>
</form>
