<?php
/**
 * Create/edit user.
 *
 * @var array<string,mixed>|null $user
 * @var array<int,array<string,mixed>> $roles
 * @var bool                     $canInvite     Can this install email an invitation?
 * @var string                   $inviteExpiry  "3 days"
 * @var array<string,mixed>|null $invite        The most recent invite row, if any
 * @var string|null              $inviteState   accepted | pending | expired | null
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$isEdit = $user !== null;
$action = $isEdit ? url('/admin/users/' . $user['id']) : url('/admin/users');
?>
<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit user' : 'Add user' ?></h1>
        <?php if ($isEdit): ?>
            <p class="muted">Created <?= e(format_date($user['created_at'])) ?>
                <?php if (!empty($user['last_login_at'])): ?>
                    · last signed in <?= e(format_datetime($user['last_login_at'])) ?>
                    <?php if (!empty($user['last_login_ip'])): ?>from <?= e($user['last_login_ip']) ?><?php endif; ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/users')) ?>">Back to users</a>
</div>

<form method="post" action="<?= e($action) ?>" class="form card" novalidate>
    <?= csrf_field() ?>

    <div class="field">
        <label class="label" for="name">Full name</label>
        <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name" name="name"
               value="<?= e(old($old, 'name', $user['name'] ?? '')) ?>" required maxlength="150">
        <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label class="label" for="email">Email address</label>
        <input class="input<?= isset($errors['email']) ? ' has-error' : '' ?>" type="email" id="email" name="email"
               autocapitalize="none" spellcheck="false"
               value="<?= e(old($old, 'email', $user['email'] ?? '')) ?>" required maxlength="190">
        <p class="field-hint">Used to sign in.</p>
        <?php if (isset($errors['email'])): ?><p class="field-error"><?= e($errors['email']) ?></p><?php endif; ?>
    </div>

    <div class="field-row">
        <div class="field">
            <label class="label" for="job_title">Job title <span class="optional">(optional)</span></label>
            <input class="input" type="text" id="job_title" name="job_title" maxlength="150"
                   value="<?= e(old($old, 'job_title', $user['job_title'] ?? '')) ?>">
        </div>

        <div class="field">
            <label class="label" for="phone">Phone <span class="optional">(optional)</span></label>
            <input class="input" type="tel" id="phone" name="phone" maxlength="50"
                   value="<?= e(old($old, 'phone', $user['phone'] ?? '')) ?>">
        </div>
    </div>

    <div class="field">
        <label class="label" for="role_id">Role</label>
        <select class="input<?= isset($errors['role_id']) ? ' has-error' : '' ?>" id="role_id" name="role_id" required>
            <?php $currentRole = old($old, 'role_id', (string) ($user['role_id'] ?? '')); ?>
            <?php foreach ($roles as $role): ?>
                <option value="<?= (int) $role['id'] ?>" <?= $currentRole === (string) $role['id'] ? 'selected' : '' ?>>
                    <?= e($role['name']) ?> — <?= e($role['description']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (isset($errors['role_id'])): ?><p class="field-error"><?= e($errors['role_id']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label class="checkbox">
            <input type="checkbox" name="is_active" value="1"
                <?= (!$isEdit || (int) $user['is_active'] === 1) ? 'checked' : '' ?>>
            <span>Account is active (can sign in)</span>
        </label>
    </div>

    <?php if (!$isEdit && $canInvite): ?>
        <?php /* No password field: the person who will use this account chooses
                 their own, which means nobody has to invent one, write it in a
                 message, and hope it gets changed later. */ ?>
        <div class="notice-inline">
            <strong>They will be emailed an invitation.</strong>
            It carries a link to confirm these details and set their own password, and it expires in
            <?= e($inviteExpiry) ?>. You can send a fresh one at any time from their page.
        </div>
    <?php endif; ?>

    <?php if (!$isEdit && !$canInvite): ?>
        <fieldset class="fieldset">
            <legend>Initial password</legend>

            <p class="field-hint">
                <a href="<?= e(url('/admin/email')) ?>">Email is not configured</a>, so this account
                needs a password now and you will have to pass it on yourself. Configure email and new
                users can set their own instead.
            </p>

            <div class="field">
                <label class="label" for="password">Password</label>
                <div class="input-with-button">
                    <input class="input<?= isset($errors['password']) ? ' has-error' : '' ?>" type="password"
                           id="password" name="password" autocomplete="new-password" required minlength="12">
                    <button type="button" class="btn btn-ghost btn-inline" data-toggle-password="password">Show</button>
                </div>
                <p class="field-hint">At least 12 characters. Ask the user to change it after their first sign-in.</p>
                <?php if (isset($errors['password'])): ?><p class="field-error"><?= e($errors['password']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="password_confirmation">Confirm password</label>
                <input class="input<?= isset($errors['password_confirmation']) ? ' has-error' : '' ?>" type="password"
                       id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
                <?php if (isset($errors['password_confirmation'])): ?><p class="field-error"><?= e($errors['password_confirmation']) ?></p><?php endif; ?>
            </div>
        </fieldset>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/users')) ?>">Cancel</a>
    </div>
</form>

<?php if ($isEdit && $inviteState !== null): ?>
    <?php /* Only shown once an invitation has actually been sent. An account
             created before email was configured has none, and inventing a
             status for it would be a fiction. */ ?>
    <div class="card">
        <h2>Invitation</h2>

        <?php if ($inviteState === 'accepted'): ?>
            <p class="muted">
                <span class="badge badge-ok">Accepted</span>
                <?= e($user['name']) ?> has set their own password
                <?php if ($invite !== null && !empty($invite['used_at'])): ?>
                    on <?= e(format_datetime($invite['used_at'])) ?>
                <?php endif; ?>.
            </p>
        <?php elseif ($inviteState === 'pending'): ?>
            <p class="muted">
                <span class="badge badge-warn">Awaiting setup</span>
                The invitation was sent
                <?php if ($invite !== null): ?>on <?= e(format_datetime($invite['created_at'])) ?><?php endif; ?>
                and expires
                <?php if ($invite !== null): ?>on <?= e(format_datetime($invite['expires_at'])) ?><?php endif; ?>.
                They cannot sign in until they have used it.
            </p>
        <?php else: ?>
            <p class="muted">
                <span class="badge badge-danger">Expired</span>
                The invitation lapsed before it was used, so this account still has no password its
                owner knows. Send a fresh one.
            </p>
        <?php endif; ?>

        <?php if ($canInvite): ?>
            <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/invite')) ?>" class="form-actions">
                <?= csrf_field() ?>
                <button type="submit" class="btn"
                        data-confirm="Send <?= e($user['name']) ?> a fresh invitation? Any earlier link stops working.">
                    <?= $inviteState === 'accepted' ? 'Send a new invitation' : 'Send it again' ?>
                </button>
            </form>
        <?php else: ?>
            <p class="field-hint">Email is not configured, so a fresh invitation cannot be sent from here.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($isEdit): ?>
    <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/password')) ?>" class="form card" novalidate>
        <?= csrf_field() ?>
        <h2>Reset password</h2>
        <p class="muted">
            Sets a new password immediately. The user is not emailed — tell them separately.
            <?php if ($canInvite): ?>
                If they can reach their own inbox, sending an invitation above is usually better: nobody
                has to say a password out loud.
            <?php endif; ?>
        </p>

        <div class="field">
            <label class="label" for="reset_password">New password</label>
            <div class="input-with-button">
                <input class="input" type="password" id="reset_password" name="password"
                       autocomplete="new-password" required minlength="12">
                <button type="button" class="btn btn-ghost btn-inline" data-toggle-password="reset_password">Show</button>
            </div>
        </div>

        <div class="field">
            <label class="label" for="reset_password_confirmation">Confirm new password</label>
            <input class="input" type="password" id="reset_password_confirmation" name="password_confirmation"
                   autocomplete="new-password" required>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-warning" data-confirm="Reset this user's password?">Reset password</button>
        </div>
    </form>

    <?php /* The lost-phone path. Not a reset — there is nothing to reset to,
             because the secret only exists on their device — so this removes
             the second factor and lets them set a new one up. */ ?>
    <?php if ((int) ($user['two_factor_enabled'] ?? 0) === 1): ?>
        <div class="card">
            <h2>Two-factor authentication</h2>
            <p class="muted">
                <span class="badge badge-ok">On</span>
                <?= !empty($user['totp_confirmed_at'])
                    ? 'Using an authenticator app, set up ' . e(format_date((string) $user['totp_confirmed_at'])) . '.'
                    : 'Using codes by email.' ?>
            </p>
            <p class="field-hint">
                If they have lost the device, remove it here. They can then sign in with just their
                password and set it up again — and if it is required site-wide, the next sign-in walks
                them through that. Check who you are talking to first: this is the step that turns a
                stolen password into an account.
            </p>

            <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/two-factor/reset')) ?>" class="form-actions">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-warning"
                        data-confirm="Remove two-factor authentication from <?= e($user['name']) ?>? Their backup codes and trusted devices go too.">
                    Remove two-factor authentication
                </button>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>
