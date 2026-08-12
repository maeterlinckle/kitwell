<?php

/**
 * API keys, and the switch for the interface itself.
 *
 * @var array<int,array<string,mixed>> $keys
 * @var array<int,array<string,mixed>> $users
 * @var array<string,string>           $scopes
 * @var bool                           $enabled
 * @var int                            $rateLimit
 * @var array<string,string|null>      $settings
 * @var array<string,mixed>|null       $freshKey
 * @var array<string,string>           $errors
 * @var array<string,mixed>            $old
 */
$setting = static fn (string $key, string $default = ''): string => (string) ($settings[$key] ?? $default);
$live    = 0;

foreach ($keys as $key) {
    if ($key['revoked_at'] === null && ($key['expires_at'] === null || strtotime((string) $key['expires_at']) > time())) {
        $live++;
    }
}
?>
<div class="page-head">
    <div>
        <h1>API keys</h1>
        <p class="muted">
            A key lets a script sign in as one of your users. It can do exactly what that person can
            do in the interface — no more, ever — and a read-only key can do less.
        </p>
    </div>
    <a class="btn" href="<?= e(url('/api/docs')) ?>">API documentation</a>
</div>

<?php if ($freshKey !== null): ?>
    <?php /* The only time the secret exists outside the caller's own notes. It
             is pulled from the session as this page renders, so a refresh will
             not show it again — which is the behaviour that makes "copy it now"
             true rather than a suggestion. */ ?>
    <div class="card card-warn">
        <h2>Copy this key now</h2>
        <p>
            This is the only time <strong><?= e($freshKey['name']) ?></strong> will be shown. It acts as
            <strong><?= e($freshKey['user']) ?></strong>. Only a fingerprint of it is stored, so it cannot be
            shown again — if it is lost, issue another and revoke this one.
        </p>
        <p class="key-reveal mono"><?= e($freshKey['token']) ?></p>
        <p class="field-hint">
            Send it as <span class="mono">Authorization: Bearer <?= e($freshKey['token']) ?></span>.
            Treat it like a password: it is one.
        </p>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/api/settings')) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <h2>The interface</h2>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="api_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                <span>Answer API requests
                    <span class="field-hint">
                        Off by default. While it is off every endpoint answers 503 with a reason rather
                        than 404, so somebody testing can tell "not enabled" from "not there".
                    </span>
                </span>
            </label>
        </div>

        <?php if ($enabled): ?>
            <p class="muted">
                Base address: <span class="mono"><?= e(rtrim((string) config('app.url'), '/') . '/api/v1') ?></span> ·
                <a href="<?= e(url('/api/docs')) ?>">documentation and try-it-out</a>
            </p>
        <?php endif; ?>

        <div class="field-row">
            <div class="field">
                <label class="label" for="api_rate_limit">Requests per minute, per key</label>
                <input class="input<?= isset($errors['api_rate_limit']) ? ' has-error' : '' ?>" type="number"
                       id="api_rate_limit" name="api_rate_limit" min="1" max="10000" step="1" required
                       value="<?= e(old($old, 'api_rate_limit', $setting('api_rate_limit', '120'))) ?>">
                <p class="field-hint">
                    Counted in fixed one-minute windows. Over it, the key gets 429 and a
                    <span class="mono">Retry-After</span>.
                </p>
                <?php if (isset($errors['api_rate_limit'])): ?>
                    <p class="field-error"><?= e($errors['api_rate_limit']) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="api_default_per_page">Default page size</label>
                <input class="input<?= isset($errors['api_default_per_page']) ? ' has-error' : '' ?>" type="number"
                       id="api_default_per_page" name="api_default_per_page" min="1" max="1000" step="1" required
                       value="<?= e(old($old, 'api_default_per_page', $setting('api_default_per_page', '25'))) ?>">
                <?php if (isset($errors['api_default_per_page'])): ?>
                    <p class="field-error"><?= e($errors['api_default_per_page']) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="api_max_per_page">Maximum page size</label>
                <input class="input<?= isset($errors['api_max_per_page']) ? ' has-error' : '' ?>" type="number"
                       id="api_max_per_page" name="api_max_per_page" min="1" max="1000" step="1" required
                       value="<?= e(old($old, 'api_max_per_page', $setting('api_max_per_page', '100'))) ?>">
                <p class="field-hint">A larger <span class="mono">per_page</span> is clamped to this, not refused.</p>
                <?php if (isset($errors['api_max_per_page'])): ?>
                    <p class="field-error"><?= e($errors['api_max_per_page']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save API settings</button>
        </div>
    </div>
</form>

<form method="post" action="<?= e(url('/admin/api/keys')) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <h2>Issue a key</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="name">What is it for?</label>
                <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name"
                       name="name" maxlength="120" required placeholder="e.g. Stores dashboard"
                       value="<?= e(old($old, 'name', '')) ?>">
                <p class="field-hint">Shown in the list below. Name it after the thing that will hold it.</p>
                <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="user_id">Acts as</label>
                <select class="input<?= isset($errors['user_id']) ? ' has-error' : '' ?>" id="user_id" name="user_id" required>
                    <option value="">Choose a user</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>" <?= old($old, 'user_id', '') === (string) $user['id'] ? 'selected' : '' ?>>
                            <?= e($user['name']) ?> — <?= e($user['role_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">
                    The key inherits this person's role exactly. Change their role and every key they
                    hold changes with it, in the same instant.
                </p>
                <?php if (isset($errors['user_id'])): ?><p class="field-error"><?= e($errors['user_id']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="scope">Access</label>
                <select class="input" id="scope" name="scope" required>
                    <?php foreach ($scopes as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= old($old, 'scope', 'read') === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">
                    Read-only refuses everything except GET, whatever the user could otherwise do.
                    Start here unless the thing holding the key needs to write.
                </p>
            </div>

            <div class="field">
                <label class="label" for="expires_on">Expires <span class="optional">(optional)</span></label>
                <input class="input<?= isset($errors['expires_on']) ? ' has-error' : '' ?>" type="date"
                       id="expires_on" name="expires_on" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>"
                       value="<?= e(old($old, 'expires_on', '')) ?>">
                <p class="field-hint">Leave blank for a key that does not expire. A date is safer for anything temporary.</p>
                <?php if (isset($errors['expires_on'])): ?><p class="field-error"><?= e($errors['expires_on']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Create key</button>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-head">
        <h2>Keys <span class="count-pill"><?= count($keys) ?></span></h2>
        <span class="muted"><?= (int) $live ?> usable</span>
    </div>

    <?php if ($keys === []): ?>
        <p class="empty">No keys have been issued.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-compact">
                <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Key</th>
                    <th scope="col">Acts as</th>
                    <th scope="col">Access</th>
                    <th scope="col">State</th>
                    <th scope="col">Last used</th>
                    <th scope="col">Requests</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($keys as $key): ?>
                    <?php
                    $expired = $key['expires_at'] !== null && strtotime((string) $key['expires_at']) < time();
                    $dead    = $key['revoked_at'] !== null || $expired || (int) $key['user_is_active'] !== 1;
                    ?>
                    <tr class="<?= $dead ? 'row-muted' : '' ?>">
                        <td><?= e($key['name']) ?></td>
                        <td class="mono nowrap"><?= e($key['token_prefix']) ?>…</td>
                        <td>
                            <?= e($key['user_name']) ?>
                            <div class="cell-sub"><?= e($key['role_name']) ?></div>
                        </td>
                        <td>
                            <span class="badge <?= $key['scope'] === 'full' ? 'badge-warn' : 'badge-muted' ?>">
                                <?= $key['scope'] === 'full' ? 'Full' : 'Read only' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($key['revoked_at'] !== null): ?>
                                <span class="badge badge-danger">Revoked</span>
                            <?php elseif ($expired): ?>
                                <span class="badge badge-danger">Expired</span>
                            <?php elseif ((int) $key['user_is_active'] !== 1): ?>
                                <span class="badge badge-danger">User inactive</span>
                            <?php elseif ($key['expires_at'] !== null): ?>
                                <span class="badge badge-ok">Until <?= e(format_date($key['expires_at'])) ?></span>
                            <?php else: ?>
                                <span class="badge badge-ok">Active</span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap">
                            <?= $key['last_used_at'] === null ? '<span class="muted">Never</span>' : e(format_datetime($key['last_used_at'])) ?>
                            <?php if (!empty($key['last_used_ip'])): ?>
                                <div class="cell-sub mono"><?= e($key['last_used_ip']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap"><?= number_format((int) $key['request_count']) ?></td>
                        <td class="actions">
                            <?php if ($key['revoked_at'] === null): ?>
                                <form method="post" action="<?= e(url('/admin/api/keys/' . (int) $key['id'] . '/revoke')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-warning"
                                            data-confirm="Revoke “<?= e($key['name']) ?>”? Anything using it stops working immediately.">
                                        Revoke
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= e(url('/admin/api/keys/' . (int) $key['id'] . '/delete')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-ghost"
                                        data-confirm="Delete “<?= e($key['name']) ?>” outright? Revoking keeps the record of what it did.">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>What a key can do</h2>
    <p>
        Nothing its owner could not. An API request adopts the key's user and then runs the same
        permission checks the interface runs — there is no separate list of what an API may reach,
        because a second list is a thing that falls out of step with the first.
    </p>
    <ul class="plain-list">
        <li>Revoking is immediate. There is no cache and no token lifetime to wait out.</li>
        <li>Deactivating the user stops every key they hold, without touching the keys.</li>
        <li>Deleting the user deletes their keys with them.</li>
        <li>The secret is stored only as a SHA-256, so a copy of the database is not a set of working credentials.</li>
    </ul>
</div>
