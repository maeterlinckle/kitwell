<?php
/**
 * @var array<string,mixed> $user
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
?>
<div class="page-head">
    <div>
        <h1>My account</h1>
        <p class="muted">
            <?= e($user['role_name']) ?>
            <?php if (!empty($user['last_login_at'])): ?>
                · last signed in <?= e(format_datetime($user['last_login_at'])) ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<form method="post" action="<?= e(url('/profile')) ?>" class="form card" novalidate>
    <?= csrf_field() ?>
    <h2>Details</h2>

    <div class="field">
        <label class="label" for="name">Full name</label>
        <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name" name="name"
               value="<?= e(old($old, 'name', $user['name'])) ?>" required maxlength="150">
        <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label class="label" for="email">Email address</label>
        <input class="input<?= isset($errors['email']) ? ' has-error' : '' ?>" type="email" id="email" name="email"
               autocapitalize="none" spellcheck="false"
               value="<?= e(old($old, 'email', $user['email'])) ?>" required maxlength="190">
        <?php if (isset($errors['email'])): ?><p class="field-error"><?= e($errors['email']) ?></p><?php endif; ?>
    </div>

    <div class="field-row">
        <div class="field">
            <label class="label" for="job_title">Job title <span class="optional">(optional)</span></label>
            <input class="input" type="text" id="job_title" name="job_title" maxlength="150"
                   value="<?= e(old($old, 'job_title', $user['job_title'] ?? '')) ?>">
        </div>
        <div class="field">
            <label class="label" for="phone">Phone <span class="optional">(optional)</span></label>
            <input class="input" type="tel" id="phone" name="phone" maxlength="50"
                   value="<?= e(old($old, 'phone', $user['phone'] ?? '')) ?>">
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Save details</button>
    </div>
</form>

<form method="post" action="<?= e(url('/profile/password')) ?>" class="form card" novalidate>
    <?= csrf_field() ?>
    <h2>Change password</h2>

    <div class="field">
        <label class="label" for="current_password">Current password</label>
        <input class="input<?= isset($errors['current_password']) ? ' has-error' : '' ?>" type="password"
               id="current_password" name="current_password" autocomplete="current-password" required>
        <?php if (isset($errors['current_password'])): ?><p class="field-error"><?= e($errors['current_password']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label class="label" for="password">New password</label>
        <div class="input-with-button">
            <input class="input<?= isset($errors['password']) ? ' has-error' : '' ?>" type="password"
                   id="password" name="password" autocomplete="new-password" required minlength="12">
            <button type="button" class="btn btn-ghost btn-inline" data-toggle-password="password">Show</button>
        </div>
        <p class="field-hint">At least 12 characters.</p>
        <?php if (isset($errors['password'])): ?><p class="field-error"><?= e($errors['password']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label class="label" for="password_confirmation">Confirm new password</label>
        <input class="input<?= isset($errors['password_confirmation']) ? ' has-error' : '' ?>" type="password"
               id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
        <?php if (isset($errors['password_confirmation'])): ?><p class="field-error"><?= e($errors['password_confirmation']) ?></p><?php endif; ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Change password</button>
    </div>
</form>

<div class="card">
    <h2>What my role can do</h2>
    <p class="muted">Role: <strong><?= e($user['role_name']) ?></strong></p>
    <ul class="permission-chips">
        <?php foreach (\App\Core\Auth::permissions() as $slug): ?>
            <li class="chip mono"><?= e($slug) ?></li>
        <?php endforeach; ?>
        <?php if (\App\Core\Auth::isAdmin()): ?>
            <li class="chip chip-warn">Full access (administrator)</li>
        <?php endif; ?>
    </ul>
</div>
