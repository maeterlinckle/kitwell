<?php
/**
 * Enrolment at sign-in, for an account that must have a second factor and does
 * not yet — the site-wide requirement being switched on.
 *
 * Deliberately not the same page as the one in a user's own account: this
 * request has no session. What it offers is the quick route (email codes, one
 * click) and the better one (an authenticator app, once they are in).
 *
 * @var array<string,mixed> $user
 * @var bool                $emailAvailable
 */
?>
<h1 class="auth-title">Two-factor authentication is required</h1>

<p class="auth-subtitle">
    Your password is right — but this register now asks for a second check at sign-in, and your
    account does not have one set up yet.
</p>

<?php if ($emailAvailable): ?>
    <p>
        The quickest way in is a code by email. You can swap to an authenticator app afterwards from
        <strong>My account → Security</strong>, which is faster and works without a signal.
    </p>

    <form method="post" action="<?= e(url('/two-factor/setup')) ?>" class="form">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Email me a code to sign in</button>
    </form>
<?php else: ?>
    <p>
        This server cannot send email, and your account has no authenticator app set up — so there is
        no way to get you a code.
    </p>
    <p>
        Ask an administrator to configure email, or to switch off the site-wide requirement until they
        have. Neither takes long, and both are on the Settings page.
    </p>
<?php endif; ?>

<p class="auth-help">
    <form method="post" action="<?= e(url('/two-factor/cancel')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost btn-sm">Back to sign in</button>
    </form>
</p>
