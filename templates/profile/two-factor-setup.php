<?php

use App\Core\QrCode;

/**
 * Enrolling an authenticator app.
 *
 * Nothing is saved until the code below is proved: the secret sits in the
 * session until then, so an abandoned setup does not leave a credential on the
 * account that its owner never scanned.
 *
 * @var string               $secret  Formatted in groups of four
 * @var string               $uri     The otpauth:// URI behind the QR code
 * @var string               $issuer
 * @var array<string,string> $errors
 */
?>
<div class="page-head">
    <div>
        <h1>Set up an authenticator app</h1>
        <p class="muted">Google Authenticator, Authy, 1Password, Bitwarden — any of them will do.</p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/profile/security')) ?>">Cancel</a>
</div>

<div class="card">
    <h2>1. Scan this</h2>

    <div class="totp-enrol">
        <?php /* Generated on this server and inlined: the thing in the picture
                 *is* the secret, so it must never be a request to somebody
                 else's server to draw it.

                 Called here rather than passed in as a string, so what reaches
                 the page is visibly markup from a known generator — the same
                 shape as Barcode::svg() on the label pages, and what lets the
                 escaping audit tell deliberate markup from a variable that
                 forgot its e(). */ ?>
        <div class="totp-qr"><?= QrCode::svg($uri, 4, 'Scan this with your authenticator app') ?></div>

        <div class="totp-manual">
            <p>Can’t scan it? Add an account by hand with:</p>
            <dl class="detail-list detail-list-tight">
                <div><dt>Account</dt><dd class="break"><?= e($issuer) ?></dd></div>
                <div><dt>Key</dt><dd class="mono break"><?= e($secret) ?></dd></div>
                <div><dt>Type</dt><dd>Time-based, 6 digits, 30 seconds</dd></div>
            </dl>
            <p class="field-hint">
                Treat that key like a password. Anybody who has it can generate your codes.
            </p>
        </div>
    </div>
</div>

<form method="post" action="<?= e(url('/profile/security/totp')) ?>" class="form card" novalidate>
    <?= csrf_field() ?>

    <h2>2. Prove it works</h2>
    <p class="muted">
        Enter the code your app is showing now. Nothing is saved until this matches — so a setup you
        abandon leaves no trace on your account.
    </p>

    <div class="field">
        <label class="label" for="code">Code from the app</label>
        <input class="input input-code" type="text" id="code" name="code"
               inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*"
               maxlength="7" required autofocus spellcheck="false">
        <?php if (isset($errors['code'])): ?><p class="field-error"><?= e($errors['code']) ?></p><?php endif; ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Turn on two-factor authentication</button>
        <a class="btn btn-ghost" href="<?= e(url('/profile/security')) ?>">Cancel</a>
    </div>
</form>
