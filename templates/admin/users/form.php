<?php
/**
 * Create/edit user.
 *
 * @var array<string,mixed>|null $user
 * @var array<int,array<string,mixed>> $roles
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$isEdit = $user !== null;
$action = $isEdit ? url('/admin/users/' . $user['id']) : url('/admin/users');
?>
<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit user' : 'Add user' ?></h1>
        <?php if ($isEdit): ?>
            <p class="muted">Created <?= e(format_date($user['created_at'])) ?>
                <?php if (!empty($user['last_login_at'])): ?>
                    · last signed in <?= e(format_datetime($user['last_login_at'])) ?>
                    <?php if (!empty($user['last_login_ip'])): ?>from <?= e($user['last_login_ip']) ?><?php endif; ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/users')) ?>">Back to users</a>
</div>

<form method="post" action="<?= e($action) ?>" class="form card" novalidate>
    <?= csrf_field() ?>

    <div class="field">
        <label class="label" for="name">Full name</label>
        <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name" name="name"
               value="<?= e(old($old, 'name', $user['name'] ?? '')) ?>" required maxlength="150">
        <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label class="label" for="email">Email address</label>
        <input class="input<?= isset($errors['email']) ? ' has-error' : '' ?>" type="email" id="email" name="email"
               autocapitalize="none" spellcheck="false"
               value="<?= e(old($old, 'email', $user['email'] ?? '')) ?>" required maxlength="190">
        <p class="field-hint">Used to sign in.</p>
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

    <div class="field">
        <label class="label" for="role_id">Role</label>
        <select class="input<?= isset($errors['role_id']) ? ' has-error' : '' ?>" id="role_id" name="role_id" required>
            <?php $currentRole = old($old, 'role_id', (string) ($user['role_id'] ?? '')); ?>
            <?php foreach ($roles as $role): ?>
                <option value="<?= (int) $role['id'] ?>" <?= $currentRole === (string) $role['id'] ? 'selected' : '' ?>>
                    <?= e($role['name']) ?> — <?= e($role['description']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (isset($errors['role_id'])): ?><p class="field-error"><?= e($errors['role_id']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label class="checkbox">
            <input type="checkbox" name="is_active" value="1"
                <?= (!$isEdit || (int) $user['is_active'] === 1) ? 'checked' : '' ?>>
            <span>Account is active (can sign in)</span>
        </label>
    </div>

    <?php if (!$isEdit): ?>
        <fieldset class="fieldset">
            <legend>Initial password</legend>

            <div class="field">
                <label class="label" for="password">Password</label>
                <div class="input-with-button">
                    <input class="input<?= isset($errors['password']) ? ' has-error' : '' ?>" type="password"
                           id="password" name="password" autocomplete="new-password" required minlength="12">
                    <button type="button" class="btn btn-ghost btn-inline" data-toggle-password="password">Show</button>
                </div>
                <p class="field-hint">At least 12 characters. Ask the user to change it after their first sign-in.</p>
                <?php if (isset($errors['password'])): ?><p class="field-error"><?= e($errors['password']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="password_confirmation">Confirm password</label>
                <input class="input<?= isset($errors['password_confirmation']) ? ' has-error' : '' ?>" type="password"
                       id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
                <?php if (isset($errors['password_confirmation'])): ?><p class="field-error"><?= e($errors['password_confirmation']) ?></p><?php endif; ?>
            </div>
        </fieldset>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/users')) ?>">Cancel</a>
    </div>
</form>

<?php if ($isEdit): ?>
    <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/password')) ?>" class="form card" novalidate>
        <?= csrf_field() ?>
        <h2>Reset password</h2>
        <p class="muted">Sets a new password immediately. The user is not emailed — tell them separately.</p>

        <div class="field">
            <label class="label" for="reset_password">New password</label>
            <div class="input-with-button">
                <input class="input" type="password" id="reset_password" name="password"
                       autocomplete="new-password" required minlength="12">
                <button type="button" class="btn btn-ghost btn-inline" data-toggle-password="reset_password">Show</button>
            </div>
        </div>

        <div class="field">
            <label class="label" for="reset_password_confirmation">Confirm new password</label>
            <input class="input" type="password" id="reset_password_confirmation" name="password_confirmation"
                   autocomplete="new-password" required>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-warning" data-confirm="Reset this user's password?">Reset password</button>
        </div>
    </form>
<?php endif; ?>
