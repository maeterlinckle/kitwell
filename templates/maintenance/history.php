<?php

use App\Models\MaintenanceLog;

/**
 * Completion history across every asset.
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} $result
 * @var array<string,mixed> $filters
 * @var array<int,array<string,mixed>> $users
 * @var float $totalCost
 */
$rows = $result['rows'];
?>
<div class="page-head">
    <div>
        <h1>Maintenance history</h1>
        <p class="muted">
            <?= number_format($result['total']) ?> record<?= $result['total'] === 1 ? '' : 's' ?>
            <?php if ($totalCost > 0): ?>
                · <?= e(format_money($totalCost)) ?> total cost<?= ($filters['from'] !== '' || $filters['to'] !== '') ? ' in this period' : '' ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <?php if (can('maintenance.complete')): ?>
            <a class="btn btn-primary" href="<?= e(url('/maintenance/log')) ?>">Record work</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/maintenance')) ?>">Back to schedules</a>
    </div>
</div>

<form method="get" action="<?= e(url('/maintenance/history')) ?>" class="card filter-card">
    <div class="search-row">
        <label class="sr-only" for="q">Search history</label>
        <input class="input input-search" type="search" id="q" name="q" enterkeyhint="search"
               placeholder="Search work done, parts, asset…" value="<?= e($filters['q']) ?>">
        <button class="btn btn-primary" type="submit">Search</button>
    </div>

    <div class="filter-grid">
        <div class="field">
            <label class="label" for="from">From</label>
            <input class="input" type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
        </div>

        <div class="field">
            <label class="label" for="to">To</label>
            <input class="input" type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
        </div>

        <div class="field">
            <label class="label" for="by">Performed by</label>
            <select class="input" id="by" name="by">
                <option value="">Anyone</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>" <?= (string) $filters['performed_by'] === (string) $user['id'] ? 'selected' : '' ?>>
                        <?= e($user['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <span class="label">Result</span>
            <div class="check-row">
                <?php foreach (MaintenanceLog::RESULTS as $resultOption): ?>
                    <label class="checkbox checkbox-compact">
                        <input type="checkbox" name="result[]" value="<?= e($resultOption) ?>"
                            <?= in_array($resultOption, (array) $filters['result'], true) ? 'checked' : '' ?>>
                        <span><?= e($resultOption) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Apply</button>
        <a class="btn btn-ghost" href="<?= e(url('/maintenance/history')) ?>">Clear</a>
    </div>
</form>

<?php if ($rows === []): ?>
    <div class="card empty-state">
        <h2>Nothing logged</h2>
        <p class="muted">Completed maintenance will appear here as it is recorded.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th scope="col">Date</th>
                <th scope="col">Asset</th>
                <th scope="col">Work</th>
                <th scope="col">By</th>
                <th scope="col">Result</th>
                <th scope="col">Cost</th>
                <?php if (can('maintenance.manage')): ?>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $log): ?>
                <tr>
                    <td class="nowrap"><?= e(format_date($log['performed_on'])) ?></td>
                    <td>
                        <a class="asset-link" href="<?= e(url('/assets/' . $log['asset_id'])) ?>">
                            <span class="mono asset-tag"><?= e($log['asset_tag']) ?></span>
                            <span class="asset-name"><?= e(str_limit((string) $log['asset_name'], 32)) ?></span>
                        </a>
                    </td>
                    <td>
                        <?php if (!empty($log['schedule_title'])): ?>
                            <strong><?= e($log['schedule_title']) ?></strong><br>
                        <?php endif; ?>
                        <?= e(str_limit((string) $log['work_done'], 110)) ?>
                        <div class="cell-sub">
                            <span class="badge badge-muted"><?= e($log['maintenance_type']) ?></span>
                            <?php if ((int) $log['photo_count'] > 0): ?>
                                <span class="badge"><?= (int) $log['photo_count'] ?> photo<?= (int) $log['photo_count'] === 1 ? '' : 's' ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?= e($log['performed_by_user_name'] ?? $log['performed_by_name'] ?? '—') ?></td>
                    <td><span class="badge result-<?= e(strtolower((string) $log['result'])) ?>"><?= e($log['result']) ?></span></td>
                    <td class="nowrap"><?= $log['cost'] !== null ? e(format_money($log['cost'])) : '—' ?></td>
                    <?php if (can('maintenance.manage')): ?>
                        <td class="nowrap">
                            <a class="btn btn-sm" href="<?= e(url('/maintenance/logs/' . $log['id'] . '/edit')) ?>">Edit</a>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php
            $params = array_filter([
                'q' => $filters['q'], 'from' => $filters['from'], 'to' => $filters['to'], 'by' => $filters['performed_by'],
            ], static fn ($v): bool => $v !== '');
            $base = url('/maintenance/history') . '?' . http_build_query($params) . (count($params) ? '&' : '');
            ?>
            <?php if ($result['page'] > 1): ?>
                <a class="btn btn-sm" href="<?= e($base . 'page=' . ($result['page'] - 1)) ?>" rel="prev">Previous</a>
            <?php endif; ?>
            <span class="muted pagination-info">Page <?= (int) $result['page'] ?> of <?= (int) $result['pages'] ?></span>
            <?php if ($result['page'] < $result['pages']): ?>
                <a class="btn btn-sm" href="<?= e($base . 'page=' . ($result['page'] + 1)) ?>" rel="next">Next</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
