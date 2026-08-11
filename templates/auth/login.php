<?php
/**
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 * @var bool                 $expired
 */
?>
<h1 class="auth-title">Sign in</h1>
<p class="auth-subtitle">Access to the asset register is restricted to authorised users.</p>

<?php if (!empty($expired)): ?>
    <div class="flash flash-warning"><span class="flash-text">Your session timed out. Please sign in again.</span></div>
<?php endif; ?>

<form method="post" action="<?= e(url('/login')) ?>" class="form" autocomplete="on" novalidate>
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

    <div class="field">
        <label class="label" for="password">Password</label>
        <div class="input-with-button">
            <input class="input<?= isset($errors['password']) ? ' has-error' : '' ?>"
                   type="password" id="password" name="password"
                   autocomplete="current-password" required>
            <button type="button" class="btn btn-ghost btn-inline" data-toggle-password="password">Show</button>
        </div>
        <?php if (isset($errors['password'])): ?>
            <p class="field-error"><?= e($errors['password']) ?></p>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary btn-block btn-lg">Sign in</button>
</form>

<?php /* The link is shown whether or not email is configured. The page behind it
         is honest either way — with no SMTP it says so and names the thing that
         does work — and hiding it would leave somebody who has forgotten their
         password looking at a page with no answer on it at all. */ ?>
<p class="auth-help muted">
    <a href="<?= e(url('/forgot-password')) ?>">Forgotten your password?</a>
</p>
