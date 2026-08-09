<?php
/**
 * @var array<int,array<string,mixed>> $entries
 * @var array<int,array<string,mixed>> $users
 * @var array<string,string> $filters
 * @var int $total
 */
?>
<div class="page-head">
    <div>
        <h1>Activity log</h1>
        <p class="muted">Showing the most recent <?= count($entries) ?> of <?= number_format($total) ?> entries.</p>
    </div>
</div>

<form method="get" action="<?= e(url('/admin/activity')) ?>" class="filter-bar">
    <div class="field field-inline">
        <label class="sr-only" for="type">Entity</label>
        <select class="input" id="type" name="type">
            <option value="">All entities</option>
            <?php foreach (['user', 'role', 'asset', 'loan', 'pat_record', 'maintenance_log', 'system'] as $type): ?>
                <option value="<?= e($type) ?>" <?= $filters['entity_type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field field-inline">
        <label class="sr-only" for="user">User</label>
        <select class="input" id="user" name="user">
            <option value="">All users</option>
            <?php foreach ($users as $user): ?>
                <option value="<?= (int) $user['id'] ?>" <?= $filters['user_id'] === (string) $user['id'] ? 'selected' : '' ?>>
                    <?= e($user['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-ghost" href="<?= e(url('/admin/activity')) ?>">Clear</a>
</form>

<div class="table-wrap">
    <table class="table">
        <thead>
        <tr>
            <th scope="col">When</th>
            <th scope="col">Who</th>
            <th scope="col">Action</th>
            <th scope="col">Entity</th>
            <th scope="col">Detail</th>
            <th scope="col">IP</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($entries === []): ?>
            <tr><td colspan="6" class="empty">Nothing logged yet.</td></tr>
        <?php endif; ?>

        <?php foreach ($entries as $entry): ?>
            <tr>
                <td class="nowrap"><?= e(format_datetime($entry['created_at'])) ?></td>
                <td><?= e($entry['user_name']) ?></td>
                <td><span class="badge"><?= e($entry['action']) ?></span></td>
                <td class="mono nowrap"><?= e($entry['entity_type']) ?><?= $entry['entity_id'] !== null ? ' #' . (int) $entry['entity_id'] : '' ?></td>
                <td>
                    <?= e($entry['description']) ?>
                    <?php if (!empty($entry['changes'])): ?>
                        <details class="changes">
                            <summary>Changes</summary>
                            <pre class="mono"><?= e(json_encode(json_decode((string) $entry['changes'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                        </details>
                    <?php endif; ?>
                </td>
                <td class="mono nowrap muted"><?= e($entry['ip_address']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
