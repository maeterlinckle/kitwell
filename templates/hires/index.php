<?php

use App\Models\Hire;

/**
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} $result
 * @var array<string,mixed> $filters
 * @var array<string,int> $summary
 * @var array<int,array<string,mixed>> $hirers
 * @var string $queryString
 */
$rows       = $result['rows'];
$hasFilters = ($filters['q'] ?? '') !== '' || !empty($filters['status']) || !empty($filters['hirer_id'])
    || !empty($filters['from']) || !empty($filters['to']);
?>
<div class="page-head">
    <div>
        <h1>Hires &amp; hires</h1>
        <p class="muted"><?= number_format($result['total']) ?> hire<?= $result['total'] === 1 ? '' : 's' ?><?= $hasFilters ? ' matching your filters' : '' ?></p>
    </div>
    <div class="head-actions">
        <?php if (can('hires.create')): ?>
            <a class="btn" href="<?= e(url('/scan?mode=checkout')) ?>">Scan to check out</a>
            <a class="btn btn-primary" href="<?= e(url('/hires/checkout')) ?>">Check out</a>
        <?php endif; ?>
    </div>
</div>

<div class="stat-grid">
    <a class="stat-card stat-link <?= $summary['overdue'] > 0 ? 'stat-danger' : '' ?>"
       href="<?= e(url('/hires?status%5B%5D=Overdue')) ?>">
        <span class="stat-value"><?= (int) $summary['overdue'] ?></span>
        <span class="stat-label">Overdue</span>
    </a>
    <a class="stat-card stat-link stat-info" href="<?= e(url('/hires?status%5B%5D=Out')) ?>">
        <span class="stat-value"><?= (int) $summary['out'] ?></span>
        <span class="stat-label">Out now</span>
    </a>
    <a class="stat-card stat-link <?= $summary['due_soon'] > 0 ? 'stat-warn' : '' ?>"
       href="<?= e(url('/hires?status%5B%5D=Out&sort=due')) ?>">
        <span class="stat-value"><?= (int) $summary['due_soon'] ?></span>
        <span class="stat-label">Due within <?= (int) $summary['due_days'] ?> day<?= $summary['due_days'] === 1 ? '' : 's' ?></span>
    </a>
    <a class="stat-card stat-link" href="<?= e(url('/hires?status%5B%5D=Returned')) ?>">
        <span class="stat-value"><?= (int) $summary['returned_30'] ?></span>
        <span class="stat-label">Returned in 30 days</span>
    </a>
</div>

<form method="get" action="<?= e(url('/hires')) ?>" class="card filter-card">
    <div class="search-row">
        <label class="sr-only" for="q">Search hires</label>
        <input class="input input-search" type="search" id="q" name="q" enterkeyhint="search"
               placeholder="Search reference, asset, hirer…" value="<?= e($filters['q']) ?>">
        <button class="btn btn-primary" type="submit">Search</button>
    </div>

    <details class="filter-details" <?= $hasFilters ? 'open' : '' ?>>
        <summary>Filters<?= $hasFilters ? ' <span class="badge badge-role">active</span>' : '' ?></summary>

        <div class="filter-grid">
            <div class="field">
                <span class="label">Status</span>
                <div class="check-row">
                    <?php foreach (Hire::STATUSES as $status): ?>
                        <label class="checkbox checkbox-compact">
                            <input type="checkbox" name="status[]" value="<?= e($status) ?>"
                                <?= in_array($status, (array) $filters['status'], true) ? 'checked' : '' ?>>
                            <span><?= e($status) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="field">
                <label class="label" for="hirer">Hirer</label>
                <select class="input" id="hirer" name="hirer">
                    <option value="">Anyone</option>
                    <?php foreach ($hirers as $hirer): ?>
                        <option value="<?= (int) $hirer['id'] ?>" <?= (string) $filters['hirer_id'] === (string) $hirer['id'] ? 'selected' : '' ?>>
                            <?= e($hirer['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="from">Checked out from</label>
                <input class="input" type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
            </div>

            <div class="field">
                <label class="label" for="to">Checked out to</label>
                <input class="input" type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
            </div>

            <div class="field">
                <label class="label" for="sort">Sort by</label>
                <select class="input" id="sort" name="sort">
                    <option value="due"      <?= $filters['sort'] === 'due' ? 'selected' : '' ?>>Soonest due first</option>
                    <option value="recent"   <?= $filters['sort'] === 'recent' ? 'selected' : '' ?>>Recently checked out</option>
                    <option value="asset"    <?= $filters['sort'] === 'asset' ? 'selected' : '' ?>>Asset tag</option>
                    <option value="hirer" <?= $filters['sort'] === 'hirer' ? 'selected' : '' ?>>Hirer</option>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Apply filters</button>
            <a class="btn btn-ghost" href="<?= e(url('/hires')) ?>">Clear all</a>
        </div>
    </details>
</form>

<?php if ($rows === []): ?>
    <div class="card empty-state">
        <h2><?= $hasFilters ? 'Nothing matched' : 'No hires yet' ?></h2>
        <p class="muted">
            <?= $hasFilters ? 'Try fewer filters.' : 'Check an asset out and it will appear here.' ?>
        </p>
        <?php if (!$hasFilters && can('hires.create')): ?>
            <a class="btn btn-primary" href="<?= e(url('/hires/checkout')) ?>">Check something out</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th scope="col">Status</th>
                <th scope="col">Asset</th>
                <th scope="col">Hirer</th>
                <th scope="col">Out</th>
                <th scope="col">Due back</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $hire): ?>
                <tr class="<?= $hire['effective_status'] === 'Returned' ? 'row-muted' : '' ?>">
                    <td class="nowrap">
                        <span class="badge hire-<?= e(strtolower((string) $hire['effective_status'])) ?>">
                            <?= e($hire['effective_status']) ?>
                        </span>
                        <?php if (!empty($hire['reference'])): ?>
                            <div class="cell-sub mono"><?= e($hire['reference']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="asset-link" href="<?= e(url('/assets/' . $hire['asset_id'])) ?>">
                            <span class="mono asset-tag"><?= e($hire['asset_tag']) ?></span>
                            <span class="asset-name"><?= e(str_limit((string) $hire['asset_name'], 36)) ?></span>
                        </a>
                    </td>
                    <td>
                        <a href="<?= e(url('/hirers/' . $hire['hirer_id'])) ?>"><?= e($hire['hirer_name']) ?></a>
                        <?php if (!empty($hire['company_name']) && $hire['company_name'] !== $hire['hirer_name']): ?>
                            <div class="cell-sub"><?= e($hire['company_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap"><?= e(format_date($hire['checked_out_at'])) ?></td>
                    <td class="nowrap">
                        <?= e(format_date($hire['due_back_date'])) ?>
                        <?php if ($hire['returned_at'] === null && $hire['days_until_due'] !== null): ?>
                            <?php $d = (int) $hire['days_until_due']; ?>
                            <div class="cell-sub">
                                <?= $d < 0
                                    ? abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' late'
                                    : ($d === 0 ? 'today' : 'in ' . (int) $d . ' day' . ($d === 1 ? '' : 's')) ?>
                            </div>
                        <?php elseif ($hire['returned_at'] !== null): ?>
                            <div class="cell-sub">back <?= e(format_date($hire['returned_at'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <a class="btn btn-sm" href="<?= e(url('/hires/' . $hire['id'])) ?>">Open</a>
                        <?php if ($hire['returned_at'] === null && can('hires.return')): ?>
                            <a class="btn btn-sm btn-primary" href="<?= e(url('/hires/' . $hire['id'] . '/return')) ?>">Book in</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php $base = url('/hires') . ($queryString !== '' ? '?' . $queryString . '&' : '?'); ?>
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
