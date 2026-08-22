<?php

use App\Models\PasswordPolicy;

/**
 * Create/edit user.
 *
 * @var array<string,mixed>|null $user
 * @var array<int,array<string,mixed>> $roles
 * @var bool                     $canInvite     Can this install email an invitation?
 * @var string                   $inviteExpiry  "3 days"
 * @var array<string,mixed>|null $invite        The most recent invite row, if any
 * @var string|null              $inviteState   accepted | pending | expired | null
 * @var array<string,array<int,array<string,mixed>>> $permissions      All permissions, grouped
 * @var array<int,int>                                $rolePermissions  Ids the role gives
 * @var array<int,string>                             $userPermissions  Overrides: id => grant|deny
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$isEdit = $user !== null;
$action = $isEdit ? url('/admin/users/' . $user['id']) : url('/admin/users');

// The minimum this account's policy asks for. Resolved once, so that what is
// printed on the control is what the server will enforce.
$passwordMinLength = PasswordPolicy::forUser($user)['min_length'];

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
                           id="password" name="password" autocomplete="new-password" required
                           minlength="<?= (int) PasswordPolicy::appMinLength() ?>">
                    <button type="button" class="btn btn-ghost btn-inline" data-toggle-password="password">Show</button>
                </div>
                <p class="field-hint">
                    <?= e(PasswordPolicy::describe()) ?>
                    Ask the user to change it after their first sign-in.
                </p>
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
                       autocomplete="new-password" required
                       minlength="<?= (int) $passwordMinLength ?>">
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

    <?php
        $appPolicy    = PasswordPolicy::forUser();
        $userPolicy   = PasswordPolicy::forUser($user);
        $expiryMode   = $user['password_expiry_days'] === null
            ? 'inherit'
            : ((int) $user['password_expiry_days'] === 0 ? 'never' : 'custom');
        $complexMode  = ($user['password_min_length'] === null && $user['password_min_classes'] === null)
            ? 'inherit'
            : 'custom';
        $expiresAt    = PasswordPolicy::expiresAt($user);
    ?>
    <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/password-policy')) ?>" class="form card" novalidate>
        <?= csrf_field() ?>
        <h2>Password policy for this account</h2>
        <p class="muted">
            Everything here overrides
            <a href="<?= e(url('/admin/settings')) ?>">the site-wide policy</a> for this account alone.
            Leave both on <em>site policy</em> and the account simply follows whatever the site decides,
            now and in future.
        </p>

        <div class="field">
            <label class="label" for="password_expiry_mode">Password expiry</label>
            <select class="input" id="password_expiry_mode" name="password_expiry_mode" data-expiry-mode>
                <option value="inherit" <?= $expiryMode === 'inherit' ? 'selected' : '' ?>>
                    Site policy — <?= $appPolicy['expiry_days'] > 0
                        ? 'every ' . (int) $appPolicy['expiry_days'] . ' days'
                        : 'never expires' ?>
                </option>
                <option value="never" <?= $expiryMode === 'never' ? 'selected' : '' ?>>
                    Never expires
                </option>
                <option value="custom" <?= $expiryMode === 'custom' ? 'selected' : '' ?>>
                    Expires after a set number of days
                </option>
            </select>
            <p class="field-hint">
                <strong>Never expires</strong> is not the same as site policy. It is a decision to exempt this
                account, and it survives a later change to the site-wide figure — which is the point of it
                for a shared rig or service account that nobody can be asked to log in and rotate.
            </p>
            <?php if (isset($errors['password_expiry_mode'])): ?><p class="field-error"><?= e($errors['password_expiry_mode']) ?></p><?php endif; ?>
        </div>

        <div class="field" data-expiry-days<?= $expiryMode === 'custom' ? '' : ' hidden' ?>>
            <label class="label" for="password_expiry_days">Expires after (days)</label>
            <input class="input<?= isset($errors['password_expiry_days']) ? ' has-error' : '' ?>" type="number"
                   id="password_expiry_days" name="password_expiry_days" min="1" max="3650" step="1"
                   value="<?= e((string) ($user['password_expiry_days'] ?: $appPolicy['expiry_days'] ?: 90)) ?>">
            <?php if (isset($errors['password_expiry_days'])): ?><p class="field-error"><?= e($errors['password_expiry_days']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="password_complexity_mode">Minimum complexity</label>
            <select class="input" id="password_complexity_mode" name="password_complexity_mode" data-complexity-mode>
                <option value="inherit" <?= $complexMode === 'inherit' ? 'selected' : '' ?>>
                    Site policy — <?= (int) $appPolicy['min_length'] ?> characters,
                    <?= (int) $appPolicy['min_classes'] ?> of 4 character types
                </option>
                <option value="custom" <?= $complexMode === 'custom' ? 'selected' : '' ?>>
                    Set it for this account
                </option>
            </select>
        </div>

        <div class="field-row" data-complexity-fields<?= $complexMode === 'custom' ? '' : ' hidden' ?>>
            <div class="field">
                <label class="label" for="user_password_min_length">Minimum length</label>
                <input class="input<?= isset($errors['password_min_length']) ? ' has-error' : '' ?>" type="number"
                       id="user_password_min_length" name="password_min_length" min="8" max="64" step="1"
                       value="<?= e((string) ($user['password_min_length'] ?? $appPolicy['min_length'])) ?>">
                <?php if (isset($errors['password_min_length'])): ?><p class="field-error"><?= e($errors['password_min_length']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="user_password_min_classes">Different character types required</label>
                <select class="input" id="user_password_min_classes" name="password_min_classes">
                    <?php foreach ([1, 2, 3, 4] as $count): ?>
                        <option value="<?= (int) $count ?>"
                            <?= (int) ($user['password_min_classes'] ?? $appPolicy['min_classes']) === $count ? 'selected' : '' ?>>
                            <?= (int) $count ?> of 4
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <p class="field-hint">
            In force now: <?= e(PasswordPolicy::describe($user)) ?>
            <?php if ($expiresAt !== null): ?>
                This password expires <strong><?= e(format_datetime($expiresAt)) ?></strong>.
            <?php endif; ?>
        </p>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save password policy</button>
        </div>
    </form>
    <?php $isSuperuser = (int) ($user['role_is_superuser'] ?? 0) === 1; ?>
    <?php /* Deciding what somebody may do is the same act as editing a role, so
             it carries roles.manage — and the card is not shown at all to
             somebody who could not save it. A form nobody can submit is worse
             than no form. */ ?>
    <?php if (can('roles.manage')): ?>
    <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/permissions')) ?>" class="form card" novalidate>
        <?= csrf_field() ?>
        <h2>Permissions for this account</h2>
        <p class="muted">
            The <strong><?= e((string) $user['role_name']) ?></strong> role is the baseline. Anything set here
            applies to <?= e((string) $user['name']) ?> alone — one extra ability, or one withheld — so a person
            who needs a little more or a little less than their role does not need a role of their own.
            <a href="<?= e(url('/admin/roles')) ?>">Change the role itself</a> when it is the whole role that is
            wrong.
        </p>

        <?php if ($isSuperuser): ?>
            <p class="field-error">
                <strong>This account's role is a superuser, so nothing here has any effect.</strong>
                A superuser holds every permission, and withholding one is deliberately not possible: denying an
                administrator <span class="mono">users.manage</span> and <span class="mono">roles.manage</span>
                would lock this installation out of its own administration, and nothing reachable from a browser
                can undo it. Move the account to another role first if it should not have everything.
            </p>
        <?php endif; ?>

        <?php foreach ($permissions as $group => $items): ?>
            <div class="permission-group">
                <div class="permission-group-head">
                    <h3><?= e($group) ?></h3>
                </div>

                <div class="permission-list permission-list-override">
                    <?php foreach ($items as $permission): ?>
                        <?php
                            $pid       = (int) $permission['id'];
                            $fromRole  = in_array($pid, $rolePermissions, true);
                            $override  = $userPermissions[$pid] ?? '';
                            $field     = 'override[' . $pid . ']';
                        ?>
                        <div class="permission-item permission-override<?= $override !== '' ? ' is-overridden' : '' ?>">
                            <span class="permission-name"><?= e($permission['name']) ?></span>
                            <span class="permission-desc muted"><?= e((string) $permission['description']) ?></span>
                            <span class="permission-slug mono muted"><?= e($permission['slug']) ?></span>

                            <span class="permission-choice" role="group"
                                  aria-label="<?= e($permission['name']) ?>">
                                <label class="choice">
                                    <input type="radio" name="<?= e($field) ?>" value="inherit"
                                        <?= $override === '' ? 'checked' : '' ?>
                                        <?= $isSuperuser ? 'disabled' : '' ?>>
                                    <span>Role<span class="choice-sub"><?= $fromRole ? 'allows' : 'does not' ?></span></span>
                                </label>
                                <label class="choice choice-allow">
                                    <input type="radio" name="<?= e($field) ?>" value="grant"
                                        <?= $override === 'grant' ? 'checked' : '' ?>
                                        <?= $isSuperuser ? 'disabled' : '' ?>>
                                    <span>Allow</span>
                                </label>
                                <label class="choice choice-deny">
                                    <input type="radio" name="<?= e($field) ?>" value="deny"
                                        <?= $override === 'deny' ? 'checked' : '' ?>
                                        <?= $isSuperuser ? 'disabled' : '' ?>>
                                    <span>Deny</span>
                                </label>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <p class="field-hint">
            <strong>Deny beats allow, and both beat the role.</strong> An account left on <em>Role</em> follows
            whatever the role says now and in future — which is what you want unless there is a reason not to.
            Every change here is written to the <a href="<?= e(url('/admin/activity')) ?>">activity log</a> with
            what it was before.
        </p>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" <?= $isSuperuser ? 'disabled' : '' ?>>Save permissions</button>
        </div>
    </form>
    <?php endif; ?>
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
