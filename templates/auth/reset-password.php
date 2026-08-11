<?php

use App\Models\UserToken;

/**
 * Choose a new password from an emailed link.
 *
 * @var string                   $status
 * @var array<string,mixed>|null $user
 * @var string                   $token
 * @var array<string,string>     $errors
 */
?>
<?php if ($status !== UserToken::OK): ?>
    <h1 class="auth-title">
        <?= $status === UserToken::USED ? 'This link has been used' : 'This link is no longer valid' ?>
    </h1>

    <?php if ($status === UserToken::USED): ?>
        <p class="auth-subtitle">
            The password has already been changed with this link. If that was you, sign in below. If it
            was not, ask for a new link now and tell an administrator.
        </p>
    <?php elseif ($status === UserToken::EXPIRED): ?>
        <p class="auth-subtitle">
            Reset links are deliberately short-lived. Ask for another and use it while it is fresh.
        </p>
    <?php else: ?>
        <p class="auth-subtitle">
            That link is not one we recognise. It may have been mistyped, cut in half by a mail client,
            or replaced by a newer request.
        </p>
    <?php endif; ?>

    <p class="auth-help">
        <a href="<?= e(url('/forgot-password')) ?>">Ask for a new link</a>
        · <a href="<?= e(url('/login')) ?>">Sign in</a>
    </p>

    <?php return; ?>
<?php endif; ?>

<h1 class="auth-title">Choose a new password</h1>
<p class="auth-subtitle">For <strong><?= e((string) $user['email']) ?></strong>.</p>

<form method="post" action="<?= e(url('/reset-password/' . $token)) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <div class="field">
        <label class="label" for="password">New password</label>
        <div class="input-with-button">
            <input class="input<?= isset($errors['password']) ? ' has-error' : '' ?>" type="password"
                   id="password" name="password" autocomplete="new-password" required minlength="12" autofocus>
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

    <button type="submit" class="btn btn-primary btn-block btn-lg">Change password</button>
</form>
