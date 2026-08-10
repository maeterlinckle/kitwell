<?php
/**
 * @var array<string,string|null> $settings
 * @var array<int,string> $problems
 * @var bool   $ready
 * @var string $passwordSource
 * @var bool   $libraryOk
 * @var bool   $cryptoOk
 * @var array<string,string> $encryptions
 * @var array<string,mixed>  $logSummary
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 * @var string $section
 */
$setting  = static fn (string $key, string $default = ''): string => (string) ($settings[$key] ?? $default);
$enabled  = ($settings['mail_enabled'] ?? '0') === '1';
$authUser = auth_user();
?>
<div class="page-head">
    <div>
        <h1>Email</h1>
        <p class="muted">The SMTP server this application sends through. Nothing is sent until this is configured and switched on.</p>
    </div>
</div>

<?= partial('partials/email-nav', ['section' => $section]) ?>

<?php if ($problems !== []): ?>
    <div class="card card-warn">
        <h2>Not ready to send</h2>
        <ul class="plain-list">
            <?php foreach ($problems as $problem): ?>
                <li><?= e($problem) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php elseif ($enabled): ?>
    <div class="card card-ok">
        <p><strong>Email is configured and switched on.</strong>
            <?php if ($logSummary['last_sent_at'] !== null): ?>
                Last successful send <?= e(format_datetime((string) $logSummary['last_sent_at'])) ?>.
            <?php endif; ?>
            <?php if ((int) $logSummary['failed_7'] > 0): ?>
                <a href="<?= e(url('/admin/email/log?status=failed')) ?>"><?= (int) $logSummary['failed_7'] ?> failure(s) in the last 7 days.</a>
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/email')) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <h2>Server</h2>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="mail_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                <span>Send email from this application</span>
            </label>
            <p class="field-hint">
                Leave this off while you are setting things up. With it off nothing is sent, and
                anything that would have been sent is recorded in the log as blocked rather than
                silently dropped.
            </p>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="mail_host">SMTP host</label>
                <input class="input mono<?= isset($errors['mail_host']) ? ' has-error' : '' ?>" type="text"
                       id="mail_host" name="mail_host" maxlength="191" spellcheck="false" autocomplete="off"
                       placeholder="smtp.example.com"
                       value="<?= e(old($old, 'mail_host', $setting('mail_host'))) ?>">
                <?php if (isset($errors['mail_host'])): ?><p class="field-error"><?= e($errors['mail_host']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="mail_port">Port</label>
                <input class="input<?= isset($errors['mail_port']) ? ' has-error' : '' ?>" type="number"
                       id="mail_port" name="mail_port" min="1" max="65535" step="1" required
                       value="<?= e(old($old, 'mail_port', $setting('mail_port', '587'))) ?>">
                <?php if (isset($errors['mail_port'])): ?><p class="field-error"><?= e($errors['mail_port']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="mail_encryption">Encryption</label>
                <select class="input" id="mail_encryption" name="mail_encryption" required>
                    <?php foreach ($encryptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= old($old, 'mail_encryption', $setting('mail_encryption', 'tls')) === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <p class="field-hint">
            Most providers want STARTTLS on port 587. Port 465 is the older implicit-TLS style.
            Only choose “none” for a relay on the same machine or a trusted local network.
        </p>
    </div>

    <div class="card">
        <h2>Sign-in</h2>
        <p class="muted">Leave both blank for a relay that does not authenticate.</p>

        <div class="field-row">
            <div class="field">
                <label class="label" for="mail_username">Username</label>
                <input class="input<?= isset($errors['mail_username']) ? ' has-error' : '' ?>" type="text"
                       id="mail_username" name="mail_username" maxlength="191" spellcheck="false" autocomplete="off"
                       value="<?= e(old($old, 'mail_username', $setting('mail_username'))) ?>">
                <?php if (isset($errors['mail_username'])): ?><p class="field-error"><?= e($errors['mail_username']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="mail_password">Password</label>

                <?php if ($passwordSource === 'env'): ?>
                    <input class="input" type="text" id="mail_password" value="Set in .env (MAIL_PASSWORD)" disabled>
                    <p class="field-hint">
                        The password is coming from <span class="mono">MAIL_PASSWORD</span> in
                        <span class="mono">.env</span>, which takes precedence over anything stored here.
                        Change it there.
                    </p>
                <?php else: ?>
                    <input class="input<?= isset($errors['mail_password']) ? ' has-error' : '' ?>" type="password"
                           id="mail_password" name="mail_password" maxlength="255" autocomplete="new-password"
                           placeholder="<?= $passwordSource === 'database' ? '•••••••• (unchanged)' : '' ?>">
                    <p class="field-hint">
                        <?php if ($passwordSource === 'database'): ?>
                            A password is stored. Leave this blank to keep it.
                        <?php else: ?>
                            Stored encrypted with the <span class="mono">APP_KEY</span> from
                            <span class="mono">.env</span>, never in plain text.
                        <?php endif; ?>
                    </p>
                    <?php if (isset($errors['mail_password'])): ?><p class="field-error"><?= e($errors['mail_password']) ?></p><?php endif; ?>

                    <?php if ($passwordSource === 'database'): ?>
                        <label class="checkbox">
                            <input type="checkbox" name="mail_password_clear" value="1">
                            <span>Remove the stored password</span>
                        </label>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$cryptoOk && $passwordSource !== 'env'): ?>
            <p class="field-error">
                The password cannot be stored securely on this installation yet — see the list above.
                Until that is fixed, set <span class="mono">MAIL_PASSWORD</span> in
                <span class="mono">.env</span> instead.
            </p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Addresses</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="mail_from_address">“From” address</label>
                <input class="input<?= isset($errors['mail_from_address']) ? ' has-error' : '' ?>" type="email"
                       id="mail_from_address" name="mail_from_address" maxlength="190" autocomplete="off"
                       placeholder="assets@example.com"
                       value="<?= e(old($old, 'mail_from_address', $setting('mail_from_address'))) ?>">
                <p class="field-hint">Many providers insist this matches the account you are signing in as.</p>
                <?php if (isset($errors['mail_from_address'])): ?><p class="field-error"><?= e($errors['mail_from_address']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="mail_from_name">“From” name</label>
                <input class="input" type="text" id="mail_from_name" name="mail_from_name" maxlength="120"
                       value="<?= e(old($old, 'mail_from_name', $setting('mail_from_name', (string) config('app.name', 'Asset Register')))) ?>">
            </div>

            <div class="field">
                <label class="label" for="mail_reply_to">Reply-to <span class="optional">(optional)</span></label>
                <input class="input<?= isset($errors['mail_reply_to']) ? ' has-error' : '' ?>" type="email"
                       id="mail_reply_to" name="mail_reply_to" maxlength="190" autocomplete="off">
                <p class="field-hint">Where replies should go, if that is not the “from” address. Current value:
                    <span class="mono"><?= e($setting('mail_reply_to') !== '' ? $setting('mail_reply_to') : 'none') ?></span>.
                </p>
                <?php if (isset($errors['mail_reply_to'])): ?><p class="field-error"><?= e($errors['mail_reply_to']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label class="label" for="mail_timeout">Timeout (seconds)</label>
            <input class="input<?= isset($errors['mail_timeout']) ? ' has-error' : '' ?>" type="number"
                   id="mail_timeout" name="mail_timeout" min="5" max="120" step="1" required
                   value="<?= e(old($old, 'mail_timeout', $setting('mail_timeout', '15'))) ?>">
            <p class="field-hint">How long to wait for the server before giving up. The reminder run is not
                interactive, so a longer value here costs nobody anything.</p>
            <?php if (isset($errors['mail_timeout'])): ?><p class="field-error"><?= e($errors['mail_timeout']) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Save email settings</button>
    </div>
</form>

<div class="card">
    <h2>Send a test message</h2>
    <p class="muted">
        Prove the connection before anything relies on it. The result — including the server's own
        error message when it fails — appears here and in the log.
    </p>

    <form method="post" action="<?= e(url('/admin/email/test')) ?>" class="form-inline" novalidate>
        <?= csrf_field() ?>
        <div class="field field-inline">
            <label class="sr-only" for="test_email">Send to</label>
            <input class="input<?= isset($errors['test_email']) ? ' has-error' : '' ?>" type="email"
                   id="test_email" name="test_email" maxlength="190" required
                   placeholder="you@example.com"
                   value="<?= e(old($old, 'test_email', (string) ($authUser['email'] ?? ''))) ?>">
        </div>
        <button type="submit" class="btn" <?= $ready ? '' : 'disabled' ?>>Send test email</button>
    </form>

    <?php if (isset($errors['test_email'])): ?><p class="field-error"><?= e($errors['test_email']) ?></p><?php endif; ?>

    <?php if (!$ready): ?>
        <p class="field-hint">Fix the problems above and switch sending on first.</p>
    <?php endif; ?>
</div>

<?php if (!$libraryOk): ?>
    <div class="card">
        <h2>Installing the mail library</h2>
        <p>
            Sending is done by PHPMailer, the one package this application depends on at runtime.
            On the server, run:
        </p>
        <pre class="mono">sudo <?= e(rtrim(str_replace('\\', '/', (string) config('app.root', '.')), '/')) ?>/manage.sh composer-install</pre>
        <p class="muted">
            That installs Composer first if the machine does not have it, then fetches PHPMailer
            and sets the file permissions. Everything else in the application works without it —
            only sending is unavailable.
        </p>
    </div>
<?php endif; ?>
