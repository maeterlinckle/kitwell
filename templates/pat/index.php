<?php
/**
 * The PAT register: every asset that requires testing, with its current status.
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} $result
 * @var array<string,mixed> $filters
 * @var array<string,int> $summary
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,array<string,mixed>> $locations
 * @var string $queryString
 */
$rows       = $result['rows'];
$hasFilters = ($filters['q'] ?? '') !== '' || !empty($filters['status'])
    || !empty($filters['category_id']) || !empty($filters['location_id']) || !empty($filters['include_retired']);

$needsAttention = $summary['overdue'] + $summary['failed'] + $summary['never_tested'];
?>
<div class="page-head">
    <div>
        <h1>PAT testing</h1>
        <p class="muted">
            <?= number_format($result['total']) ?> asset<?= $result['total'] === 1 ? '' : 's' ?><?= $hasFilters ? ' matching your filters' : ' requiring testing' ?>
        </p>
    </div>
    <?php if (can('pat.manage')): ?>
        <a class="btn btn-primary" href="<?= e(url('/pat/create')) ?>">Record a test</a>
    <?php endif; ?>
</div>

<div class="stat-grid">
    <a class="stat-card stat-link <?= $summary['overdue'] > 0 ? 'stat-danger' : '' ?>"
       href="<?= e(url('/pat?status%5B%5D=Overdue')) ?>">
        <span class="stat-value"><?= (int) $summary['overdue'] ?></span>
        <span class="stat-label">Retest overdue</span>
    </a>
    <a class="stat-card stat-link <?= $summary['failed'] > 0 ? 'stat-danger' : '' ?>"
       href="<?= e(url('/pat?status%5B%5D=Failed')) ?>">
        <span class="stat-value"><?= (int) $summary['failed'] ?></span>
        <span class="stat-label">Last test failed</span>
    </a>
    <a class="stat-card stat-link <?= $summary['never_tested'] > 0 ? 'stat-warn' : '' ?>"
       href="<?= e(url('/pat?status%5B%5D=Never+tested')) ?>">
        <span class="stat-value"><?= (int) $summary['never_tested'] ?></span>
        <span class="stat-label">Never tested</span>
    </a>
    <a class="stat-card stat-link <?= $summary['due_soon'] > 0 ? 'stat-warn' : '' ?>"
       href="<?= e(url('/pat?status%5B%5D=Due+soon')) ?>">
        <span class="stat-value"><?= (int) $summary['due_soon'] ?></span>
        <span class="stat-label">Due within <?= (int) $summary['due_days'] ?> days</span>
    </a>
    <a class="stat-card stat-link" href="<?= e(url('/pat?status%5B%5D=Current')) ?>">
        <span class="stat-value"><?= (int) $summary['current'] ?></span>
        <span class="stat-label">In date</span>
    </a>
</div>

<?php if ($needsAttention > 0 && !$hasFilters): ?>
    <div class="flash flash-warning">
        <span class="flash-text">
            <?= (int) $needsAttention ?> item<?= $needsAttention === 1 ? '' : 's' ?> need attention —
            overdue, failed, or never tested.
        </span>
    </div>
<?php endif; ?>

<form method="get" action="<?= e(url('/pat')) ?>" class="card filter-card">
    <div class="search-row">
        <label class="sr-only" for="q">Search</label>
        <input class="input input-search" type="search" id="q" name="q" enterkeyhint="search"
               placeholder="Search asset tag, name, serial, PAT label…" value="<?= e($filters['q']) ?>">
        <?= partial('partials/scan-button', ['target' => 'q', 'submit' => true]) ?>
        <button class="btn btn-primary" type="submit">Search</button>
    </div>

    <details class="filter-details" <?= $hasFilters ? 'open' : '' ?>>
        <summary>Filters<?= $hasFilters ? ' <span class="badge badge-role">active</span>' : '' ?></summary>

        <div class="filter-grid">
            <div class="field">
                <span class="label">Status</span>
                <div class="check-row">
                    <?php foreach (['Overdue', 'Failed', 'Never tested', 'Due soon', 'Current', 'No retest date'] as $status): ?>
                        <label class="checkbox checkbox-compact">
                            <input type="checkbox" name="status[]" value="<?= e($status) ?>"
                                <?= in_array($status, (array) $filters['status'], true) ? 'checked' : '' ?>>
                            <span><?= e($status) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="field">
                <label class="label" for="category">Category</label>
                <select class="input" id="category" name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" <?= (string) $filters['category_id'] === (string) $category['id'] ? 'selected' : '' ?>>
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="location">Location</label>
                <select class="input" id="location" name="location">
                    <option value="">All locations</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= (int) $location['id'] ?>" <?= (string) $filters['location_id'] === (string) $location['id'] ? 'selected' : '' ?>>
                            <?= e($location['display_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="sort">Sort by</label>
                <select class="input" id="sort" name="sort">
                    <option value="due"    <?= $filters['sort'] === 'due' ? 'selected' : '' ?>>Soonest retest first</option>
                    <option value="tested" <?= $filters['sort'] === 'tested' ? 'selected' : '' ?>>Most recently tested</option>
                    <option value="asset"  <?= $filters['sort'] === 'asset' ? 'selected' : '' ?>>Asset tag</option>
                    <option value="name"   <?= $filters['sort'] === 'name' ? 'selected' : '' ?>>Name</option>
                </select>
            </div>

            <div class="field">
                <span class="label">Retired</span>
                <label class="checkbox checkbox-compact">
                    <input type="checkbox" name="retired" value="1" <?= !empty($filters['include_retired']) ? 'checked' : '' ?>>
                    <span>Include retired assets</span>
                </label>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Apply filters</button>
            <a class="btn btn-ghost" href="<?= e(url('/pat')) ?>">Clear all</a>
        </div>
    </details>
</form>

<?php if ($rows === []): ?>
    <div class="card empty-state">
        <h2><?= $hasFilters ? 'Nothing matched' : 'No assets require PAT' ?></h2>
        <p class="muted">
            <?= $hasFilters
                ? 'Try fewer filters.'
                : 'Tick “Requires PAT” on an asset and it will appear here.' ?>
        </p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th scope="col">Status</th>
                <th scope="col">Asset</th>
                <th scope="col">Last tested</th>
                <th scope="col">Retest due</th>
                <th scope="col">Class</th>
                <th scope="col">PAT label</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr class="<?= $row['asset_status'] === 'Retired' ? 'row-muted' : '' ?>">
                    <td class="nowrap">
                        <span class="badge pat-status-<?= e(strtolower(str_replace(' ', '-', (string) $row['pat_status']))) ?>">
                            <?= e($row['pat_status']) ?>
                        </span>
                        <?php if ($row['days_until_due'] !== null): ?>
                            <?php $d = (int) $row['days_until_due']; ?>
                            <div class="cell-sub">
                                <?= $d < 0
                                    ? abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' late'
                                    : ($d === 0 ? 'today' : 'in ' . (int) $d . ' day' . ($d === 1 ? '' : 's')) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="asset-link" href="<?= e(url('/assets/' . $row['id'])) ?>">
                            <span class="mono asset-tag"><?= e($row['asset_tag']) ?></span>
                            <span class="asset-name"><?= e(str_limit((string) $row['name'], 40)) ?></span>
                        </a>
                        <?php /* Location only. The fuse rating and cable CSA are
                                 properties of the appliance, not of its test
                                 status, and they belong on the asset's own page
                                 rather than repeated down a list you scan. */ ?>
                        <div class="cell-sub"><?= e($row['location_name'] ?? '') ?></div>
                    </td>
                    <td class="nowrap">
                        <?= e(format_date($row['test_date'])) ?>
                        <?php if ((int) $row['test_count'] > 1): ?>
                            <div class="cell-sub"><?= (int) $row['test_count'] ?> tests</div>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap"><?= e(format_date($row['retest_due_date'])) ?></td>
                    <td class="nowrap"><?= e($row['appliance_class'] ?? '—') ?></td>
                    <td class="mono nowrap"><?= e($row['pat_label_serial'] ?? '—') ?></td>
                    <td class="actions">
                        <?php if (can('pat.manage')): ?>
                            <a class="btn btn-sm btn-primary" href="<?= e(url('/pat/create?asset=' . $row['id'])) ?>">Test</a>
                        <?php endif; ?>
                        <?php if ((int) $row['test_count'] > 0): ?>
                            <a class="btn btn-sm" href="<?= e(url('/assets/' . $row['id'] . '/pat')) ?>">History</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php $base = url('/pat') . ($queryString !== '' ? '?' . $queryString . '&' : '?'); ?>
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
