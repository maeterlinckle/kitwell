<?php
/**
 * My account → Security: the user's own second factor, backup codes and
 * trusted devices.
 *
 * @var array<string,mixed>            $user
 * @var bool                           $hasTotp
 * @var bool                           $enabled
 * @var bool                           $required
 * @var bool                           $emailAvailable
 * @var int                            $backupCodes
 * @var array<int,array<string,mixed>> $devices
 * @var int                            $trustDays
 * @var int                            $idleDays
 * @var array<int,string>              $freshCodes
 */
?>
<div class="page-head">
    <div>
        <h1>Security</h1>
        <p class="muted">Two-factor authentication for your own account.</p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/profile')) ?>">My account</a>
</div>

<?php if ($freshCodes !== []): ?>
    <?php /* Shown exactly once, right after they are made. There is no route
             that shows them again — only hashes are kept — which is the whole
             point of them being worth writing down. */ ?>
    <div class="card notice-card">
        <h2>Your backup codes</h2>
        <p>
            Each one works <strong>once</strong>, in place of a code from your app. Print them or put
            them somewhere that is not your phone. <strong>This is the only time they are shown.</strong>
        </p>

        <ul class="backup-codes">
            <?php foreach ($freshCodes as $code): ?>
                <li class="mono"><?= e($code) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2>Two-factor authentication</h2>
        <?php if ($enabled): ?>
            <span class="badge badge-ok">On</span>
        <?php else: ?>
            <span class="badge badge-muted">Off</span>
        <?php endif; ?>
    </div>

    <?php if ($required): ?>
        <p class="muted">Required for everyone on this site, so it cannot be switched off.</p>
    <?php endif; ?>

    <?php if ($enabled): ?>
        <p>
            <?php if ($hasTotp): ?>
                You are using an <strong>authenticator app</strong>. Set up
                <?= e(format_datetime((string) $user['totp_confirmed_at'])) ?>.
            <?php else: ?>
                You are using <strong>codes by email</strong>. An authenticator app is quicker and works
                without a signal — worth the two minutes if you have a phone to hand.
            <?php endif; ?>
        </p>

        <p class="muted">
            <?= (int) $backupCodes ?> backup code<?= $backupCodes === 1 ? '' : 's' ?> left.
            <?php if ($backupCodes === 0): ?>
                <strong>Generate some</strong> — without one, losing your phone means asking an
                administrator to remove your second factor.
            <?php endif; ?>
        </p>

        <div class="form-actions">
            <?php if (!$hasTotp): ?>
                <a class="btn btn-primary" href="<?= e(url('/profile/security/totp')) ?>">Set up an authenticator app</a>
            <?php else: ?>
                <a class="btn" href="<?= e(url('/profile/security/totp')) ?>">Set up a different app</a>
            <?php endif; ?>

            <form method="post" action="<?= e(url('/profile/security/backup-codes')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn"
                        data-confirm="Generate a new set? The codes you have now stop working.">New backup codes</button>
            </form>

            <?php if (!$required): ?>
                <form method="post" action="<?= e(url('/profile/security/disable')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost"
                            data-confirm="Turn off two-factor authentication? Your backup codes and trusted devices are cleared too.">Turn off</button>
                </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p>
            A second check at sign-in means a stolen password is not enough on its own. Pick one:
        </p>

        <div class="form-actions">
            <a class="btn btn-primary" href="<?= e(url('/profile/security/totp')) ?>">Use an authenticator app</a>

            <?php if ($emailAvailable): ?>
                <form method="post" action="<?= e(url('/profile/security/email')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn">Email me a code instead</button>
                </form>
            <?php endif; ?>
        </div>

        <p class="field-hint">
            An authenticator app — Google Authenticator, Authy, 1Password, and most password managers —
            generates a code on your phone without needing a signal.
            <?php if (!$emailAvailable): ?>
                Codes by email are not available: this server is not set up to send any.
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>

<?php if ($enabled): ?>
    <div class="card">
        <div class="card-head">
            <h2>Trusted devices</h2>
            <span class="count-pill"><?= count($devices) ?></span>
        </div>

        <p class="muted">
            Machines that will not be asked for a code — for up to <?= (int) $trustDays ?> days, or until
            <?= (int) $idleDays ?> days go by without a sign-in. A code is asked for again straight away if
            the browser changes or you sign in from a different network.
        </p>

        <?php if ($devices === []): ?>
            <p class="muted">None. Every sign-in asks for a code.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th scope="col">Device</th>
                        <th scope="col">Last used</th>
                        <th scope="col">Trusted until</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($devices as $device): ?>
                        <tr>
                            <td>
                                <strong><?= e((string) $device['label']) ?></strong>
                                <div class="cell-sub muted">first trusted from <?= e((string) $device['ip_address']) ?></div>
                            </td>
                            <td class="nowrap"><?= e(format_datetime((string) $device['last_seen_at'])) ?></td>
                            <td class="nowrap"><?= e(format_date((string) $device['expires_at'])) ?></td>
                            <td class="actions">
                                <form method="post" action="<?= e(url('/profile/security/devices/' . (int) $device['id'] . '/forget')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-ghost">Forget</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="form-actions">
                <form method="post" action="<?= e(url('/profile/security/devices/forget-all')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost"
                            data-confirm="Forget every device, including this one? The next sign-in on each will ask for a code.">Forget all devices</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
