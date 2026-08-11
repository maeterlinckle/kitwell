<?php
/**
 * Ask for a password-reset link.
 *
 * With no SMTP configured this page does not pretend: it explains and points at
 * the only route that actually works. A form whose submit button cannot
 * possibly do anything is worse than no form.
 *
 * @var bool                 $available
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
?>
<h1 class="auth-title">Forgotten password</h1>

<?php if (!$available): ?>
    <p class="auth-subtitle">
        This register cannot send email, so it cannot send you a reset link.
    </p>
    <p>
        Ask an administrator to set a new password for you — it takes them a moment from the Users
        page, and they can tell you what it is.
    </p>
    <p class="auth-help"><a href="<?= e(url('/login')) ?>">Back to sign in</a></p>

    <?php return; ?>
<?php endif; ?>

<p class="auth-subtitle">
    Enter the address you sign in with and we will email you a link to choose a new password.
</p>

<form method="post" action="<?= e(url('/forgot-password')) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <div class="field">
        <label class="label" for="email">Email address</label>
        <input class="input<?= isset($errors['email']) ? ' has-error' : '' ?>"
               type="email" id="email" name="email" inputmode="email"
               autocomplete="username" autocapitalize="none" spellcheck="false"
               value="<?= e(old($old, 'email')) ?>" required autofocus>
        <?php if (isset($errors['email'])): ?>
            <p class="field-error"><?= e($errors['email']) ?></p>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary btn-block btn-lg">Email me a link</button>
</form>

<p class="auth-help muted">
    Remembered it? <a href="<?= e(url('/login')) ?>">Back to sign in</a>
</p>
