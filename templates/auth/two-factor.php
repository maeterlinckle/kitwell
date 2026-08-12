<?php

use App\Services\TwoFactor;

/**
 * The code entry screen, between a correct password and a session.
 *
 * @var string               $method
 * @var array<string,mixed>  $user
 * @var int                  $minutes
 * @var int                  $backupCodes
 * @var int                  $trustDays
 * @var array<string,string> $errors
 */
$isEmail = $method === TwoFactor::METHOD_EMAIL;

/**
 * "j••@example.com".
 *
 * Enough for the owner to recognise which mailbox to look in, not enough to be
 * a way of reading somebody's address off a sign-in screen — this page is
 * reachable by anyone who has guessed a password, and the address is the other
 * half of the credential.
 */
$masked = static function (string $email): string {
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

    if ($domain === '') {
        return '•••';
    }

    return mb_substr($name, 0, 1) . str_repeat('•', max(2, mb_strlen($name) - 1)) . '@' . $domain;
};
?>
<h1 class="auth-title">One more step</h1>

<p class="auth-subtitle">
    <?php if ($isEmail): ?>
        We have emailed a six-digit code to <strong><?= e($masked((string) $user['email'])) ?></strong>.
        It lasts <?= (int) $minutes ?> minutes.
    <?php else: ?>
        Open your authenticator app and enter the six-digit code for
        <strong><?= e($masked((string) $user['email'])) ?></strong>.
    <?php endif; ?>
</p>

<form method="post" action="<?= e(url('/two-factor')) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <div class="field">
        <label class="label" for="code">Code</label>
        <?php /* inputmode + autocomplete are what make a phone offer the code
                 from the notification rather than a keyboard. */ ?>
        <input class="input input-code" type="text" id="code" name="code"
               inputmode="numeric" autocomplete="one-time-code" pattern="[0-9A-Za-z\-]*"
               maxlength="11" required autofocus spellcheck="false">
        <p class="field-hint">A backup code works here too, if you have one.</p>
    </div>

    <div class="field">
        <label class="checkbox">
            <input type="checkbox" name="trust_device" value="1">
            <span>Don’t ask again on this computer
                <span class="field-hint">
                    For up to <?= (int) $trustDays ?> days. You will be asked again sooner if you use a
                    different browser or network, or if you do not sign in for a while. Never tick this on
                    a shared or public machine.
                </span>
            </span>
        </label>
    </div>

    <button type="submit" class="btn btn-primary btn-block btn-lg">Sign in</button>
</form>

<div class="auth-help">
    <?php if ($isEmail): ?>
        <form method="post" action="<?= e(url('/two-factor/resend')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost btn-sm">Email another code</button>
        </form>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/two-factor/cancel')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost btn-sm">Cancel</button>
    </form>
</div>

<?php if ($backupCodes === 0 && !$isEmail): ?>
    <p class="auth-help muted">
        No backup codes left. Generate a new set from your account page once you are in.
    </p>
<?php endif; ?>
