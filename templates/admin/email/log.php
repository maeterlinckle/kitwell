<?php
/**
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} $result
 * @var array<string,string> $filters
 * @var array<string,mixed>  $summary
 * @var array<string,string> $templateNames
 * @var string $section
 */
$query = array_filter([
    'status'   => $filters['status'],
    'template' => $filters['template_key'],
    'q'        => $filters['q'],
], static fn (string $v): bool => $v !== '');

$queryString = http_build_query($query);
?>
<div class="page-head">
    <div>
        <h1>Email log</h1>
        <p class="muted">Every message this application has tried to send, whether it worked or not.</p>
    </div>
</div>

<?= partial('partials/email-nav', ['section' => $section]) ?>

<div class="stat-grid">
    <div class="stat-card">
        <span class="stat-value"><?= number_format((int) $summary['sent']) ?></span>
        <span class="stat-label">Sent</span>
    </div>
    <div class="stat-card<?= (int) $summary['failed'] > 0 ? ' stat-danger' : '' ?>">
        <span class="stat-value"><?= number_format((int) $summary['failed']) ?></span>
        <span class="stat-label">Failed</span>
    </div>
    <div class="stat-card<?= (int) $summary['failed_7'] > 0 ? ' stat-danger' : '' ?>">
        <span class="stat-value"><?= number_format((int) $summary['failed_7']) ?></span>
        <span class="stat-label">Failed in 7 days</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $summary['last_sent_at'] === null ? '—' : e(format_date((string) $summary['last_sent_at'])) ?></span>
        <span class="stat-label">Last sent</span>
    </div>
</div>

<form method="get" action="<?= e(url('/admin/email/log')) ?>" class="filter-bar">
    <div class="field field-inline">
        <label class="sr-only" for="q">Search</label>
        <input class="input" type="search" id="q" name="q" placeholder="Address, subject or error…"
               value="<?= e($filters['q']) ?>">
    </div>

    <div class="field field-inline">
        <label class="sr-only" for="status">Result</label>
        <select class="input" id="status" name="status">
            <option value="">Sent and failed</option>
            <option value="sent" <?= $filters['status'] === 'sent' ? 'selected' : '' ?>>Sent only</option>
            <option value="failed" <?= $filters['status'] === 'failed' ? 'selected' : '' ?>>Failed only</option>
        </select>
    </div>

    <div class="field field-inline">
        <label class="sr-only" for="template">Template</label>
        <select class="input" id="template" name="template">
            <option value="">Every template</option>
            <?php foreach ($templateNames as $key => $name): ?>
                <option value="<?= e($key) ?>" <?= $filters['template_key'] === $key ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-ghost" href="<?= e(url('/admin/email/log')) ?>">Clear</a>
</form>

<p class="muted"><?= number_format((int) $result['total']) ?> message(s).</p>

<div class="table-wrap">
    <table class="table">
        <thead>
        <tr>
            <th scope="col">When</th>
            <th scope="col">Result</th>
            <th scope="col">To</th>
            <th scope="col">Subject</th>
            <th scope="col">Template</th>
            <th scope="col">Triggered by</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($result['rows'] === []): ?>
            <tr><td colspan="6" class="empty">Nothing has been sent yet.</td></tr>
        <?php endif; ?>

        <?php foreach ($result['rows'] as $row): ?>
            <tr>
                <td class="nowrap"><?= e(format_datetime((string) $row['created_at'])) ?></td>
                <td>
                    <?php if ((string) $row['status'] === 'sent'): ?>
                        <span class="badge badge-ok">Sent</span>
                    <?php else: ?>
                        <span class="badge badge-danger">Failed</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="mono"><?= e($row['recipient']) ?></span>
                    <?php if (!empty($row['recipient_name'])): ?>
                        <span class="muted"><?= e($row['recipient_name']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= e(str_limit((string) $row['subject'], 60)) ?>
                    <?php if (!empty($row['error'])): ?>
                        <p class="field-error"><?= e($row['error']) ?></p>
                    <?php endif; ?>
                </td>
                <td class="nowrap">
                    <?php if ($row['template_key'] === null): ?>
                        <span class="muted">—</span>
                    <?php else: ?>
                        <?= e($templateNames[(string) $row['template_key']] ?? (string) $row['template_key']) ?>
                    <?php endif; ?>
                </td>
                <td class="nowrap">
                    <?php if ((string) $row['trigger_source'] === 'user'): ?>
                        <?= e($row['user_name']) ?>
                    <?php else: ?>
                        <span class="badge badge-muted">Scheduled</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($result['pages'] > 1): ?>
    <nav class="pagination" aria-label="Pages">
        <?php $base = url('/admin/email/log') . ($queryString !== '' ? '?' . $queryString . '&' : '?'); ?>
        <?php if ($result['page'] > 1): ?>
            <a class="btn btn-sm" href="<?= e($base . 'page=' . ($result['page'] - 1)) ?>" rel="prev">Previous</a>
        <?php endif; ?>
        <span class="muted pagination-info">Page <?= (int) $result['page'] ?> of <?= (int) $result['pages'] ?></span>
        <?php if ($result['page'] < $result['pages']): ?>
            <a class="btn btn-sm" href="<?= e($base . 'page=' . ($result['page'] + 1)) ?>" rel="next">Next</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
