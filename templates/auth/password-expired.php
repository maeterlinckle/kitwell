<?php

use App\Models\PasswordPolicy;

/**
 * The password has aged out of the policy. Set a new one before going on.
 *
 * Rendered in the signed-out layout even though the visitor *is* signed in:
 * the navigation would offer a way past a page that exists to have no way
 * past it.
 *
 * @var array<string,mixed>  $user
 * @var int                  $days
 * @var array<string,string> $errors
 */

// The minimum this account's policy asks for. Resolved once, so that what is
// printed on the control is what the server will enforce.
$passwordMinLength = PasswordPolicy::forUser($user)['min_length'];

?>
<h1 class="auth-title">Your password has expired</h1>
<p class="auth-subtitle">
    Passwords on this site are changed every <?= (int) $days ?> days. Choose a new one to carry on
    as <strong><?= e((string) $user['email']) ?></strong>.
</p>

<form method="post" action="<?= e(url('/password/expired')) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <div class="field">
        <label class="label" for="current_password">Current password</label>
        <input class="input<?= isset($errors['current_password']) ? ' has-error' : '' ?>" type="password"
               id="current_password" name="current_password" autocomplete="current-password" required autofocus>
        <?php if (isset($errors['current_password'])): ?><p class="field-error"><?= e($errors['current_password']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label class="label" for="password">New password</label>
        <div class="input-with-button">
            <input class="input<?= isset($errors['password']) ? ' has-error' : '' ?>" type="password"
                   id="password" name="password" autocomplete="new-password" required
                   minlength="<?= (int) $passwordMinLength ?>">
            <button type="button" class="btn btn-ghost btn-inline" data-toggle-password="password">Show</button>
        </div>
        <p class="field-hint"><?= e(PasswordPolicy::describe($user)) ?></p>
        <?php if (isset($errors['password'])): ?><p class="field-error"><?= e($errors['password']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label class="label" for="password_confirmation">Confirm new password</label>
        <input class="input<?= isset($errors['password_confirmation']) ? ' has-error' : '' ?>" type="password"
               id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
        <?php if (isset($errors['password_confirmation'])): ?><p class="field-error"><?= e($errors['password_confirmation']) ?></p><?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary btn-block btn-lg">Set new password and continue</button>
</form>

<form method="post" action="<?= e(url('/logout')) ?>" class="auth-help">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-ghost btn-sm">Sign out instead</button>
</form>
