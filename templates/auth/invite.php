<?php

use App\Models\UserToken;

/**
 * Accepting an invitation: confirm who the account is for, then set a password.
 *
 * A link that has expired or already been used says so plainly and says what to
 * do next. "Not found" would be true and useless.
 *
 * @var string                   $status
 * @var array<string,mixed>|null $user
 * @var string                   $token
 * @var array<string,string>     $errors
 */
?>
<?php if ($status !== UserToken::OK): ?>
    <h1 class="auth-title">
        <?= $status === UserToken::USED ? 'This invitation has been used' : 'This invitation has expired' ?>
    </h1>

    <?php if ($status === UserToken::USED): ?>
        <p class="auth-subtitle">
            The password on this account has already been set. If that was you, sign in below. If it
            was not, tell an administrator straight away.
        </p>
    <?php elseif ($status === UserToken::EXPIRED): ?>
        <p class="auth-subtitle">
            Invitations are only good for a short while. Ask an administrator to send you a fresh one —
            it takes them a moment.
        </p>
    <?php else: ?>
        <p class="auth-subtitle">
            That link is not one we recognise. It may have been mistyped, cut in half by a mail client,
            or replaced by a newer invitation — check for a later email before asking for another.
        </p>
    <?php endif; ?>

    <p class="auth-help"><a href="<?= e(url('/login')) ?>">Go to sign in</a></p>

    <?php return; ?>
<?php endif; ?>

<h1 class="auth-title">Welcome<?= $user !== null ? ', ' . e(explode(' ', (string) $user['name'])[0]) : '' ?></h1>
<p class="auth-subtitle">Check these are right, then choose a password to finish setting up your account.</p>

<dl class="detail-list detail-list-tight invite-summary">
    <div><dt>Name</dt><dd><?= e((string) $user['name']) ?></dd></div>
    <div><dt>Sign in with</dt><dd><?= e((string) $user['email']) ?></dd></div>
    <div><dt>Role</dt><dd><?= e((string) $user['role_name']) ?></dd></div>
</dl>

<p class="field-hint">
    Anything wrong there? An administrator can change it — the password you set below will still work.
</p>

<form method="post" action="<?= e(url('/invite/' . $token)) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <div class="field">
        <label class="label" for="password">Choose a password</label>
        <div class="input-with-button">
            <input class="input<?= isset($errors['password']) ? ' has-error' : '' ?>" type="password"
                   id="password" name="password" autocomplete="new-password" required minlength="12" autofocus>
            <button type="button" class="btn btn-ghost btn-inline" data-toggle-password="password">Show</button>
        </div>
        <p class="field-hint">At least 12 characters. A short phrase you will remember beats a short muddle you will not.</p>
        <?php if (isset($errors['password'])): ?><p class="field-error"><?= e($errors['password']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label class="label" for="password_confirmation">Confirm password</label>
        <input class="input<?= isset($errors['password_confirmation']) ? ' has-error' : '' ?>" type="password"
               id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
        <?php if (isset($errors['password_confirmation'])): ?><p class="field-error"><?= e($errors['password_confirmation']) ?></p><?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary btn-block btn-lg">Set password</button>
</form>
