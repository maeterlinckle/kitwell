<?php

use App\Models\MaintenanceSchedule;

/**
 * The maintenance work list.
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} $result
 * @var array<string,mixed> $filters
 * @var array{overdue:int,due_soon:int,scheduled:int,unscheduled:int,due_days:int} $summary
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,array<string,mixed>> $locations
 * @var array<int,array<string,mixed>> $users
 * @var string $queryString
 */
$rows       = $result['rows'];
$hasFilters = ($filters['q'] ?? '') !== '' || !empty($filters['status']) || !empty($filters['type'])
    || !empty($filters['assigned_to']) || !empty($filters['category_id'])
    || !empty($filters['location_id']) || !empty($filters['include_inactive']);
?>
<div class="page-head">
    <div>
        <h1>Maintenance</h1>
        <p class="muted"><?= number_format($result['total']) ?> schedule<?= $result['total'] === 1 ? '' : 's' ?><?= $hasFilters ? ' matching your filters' : '' ?></p>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e(url('/maintenance/history')) ?>">History</a>
        <?php /* Unplanned work is a first-class kind of maintenance, so it gets a
                 first-class button rather than living only inside an asset page. */ ?>
        <?php if (can('maintenance.complete')): ?>
            <a class="btn btn-primary" href="<?= e(url('/maintenance/log')) ?>">Record work</a>
        <?php endif; ?>
        <?php if (can('maintenance.manage')): ?>
            <a class="btn" href="<?= e(url('/maintenance/create')) ?>">New schedule</a>
        <?php endif; ?>
    </div>
</div>

<div class="stat-grid">
    <a class="stat-card stat-link <?= $summary['overdue'] > 0 ? 'stat-danger' : '' ?>"
       href="<?= e(url('/maintenance?status%5B%5D=Overdue')) ?>">
        <span class="stat-value"><?= (int) $summary['overdue'] ?></span>
        <span class="stat-label">Overdue</span>
    </a>
    <a class="stat-card stat-link <?= $summary['due_soon'] > 0 ? 'stat-warn' : '' ?>"
       href="<?= e(url('/maintenance?status%5B%5D=Due+soon')) ?>">
        <span class="stat-value"><?= (int) $summary['due_soon'] ?></span>
        <span class="stat-label">Due within <?= (int) $summary['due_days'] ?> days</span>
    </a>
    <a class="stat-card stat-link" href="<?= e(url('/maintenance?status%5B%5D=Scheduled')) ?>">
        <span class="stat-value"><?= (int) $summary['scheduled'] ?></span>
        <span class="stat-label">Scheduled later</span>
    </a>
    <?php if ($summary['unscheduled'] > 0): ?>
        <a class="stat-card stat-link" href="<?= e(url('/maintenance?status%5B%5D=Unscheduled')) ?>">
            <span class="stat-value"><?= (int) $summary['unscheduled'] ?></span>
            <span class="stat-label">No date set</span>
        </a>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(url('/maintenance')) ?>" class="card filter-card">
    <div class="search-row">
        <label class="sr-only" for="q">Search maintenance</label>
        <input class="input input-search" type="search" id="q" name="q" enterkeyhint="search"
               placeholder="Search job title, instructions, asset…" value="<?= e($filters['q']) ?>">
        <button class="btn btn-primary" type="submit">Search</button>
    </div>

    <details class="filter-details" <?= $hasFilters ? 'open' : '' ?>>
        <summary>Filters<?= $hasFilters ? ' <span class="badge badge-role">active</span>' : '' ?></summary>

        <div class="filter-grid">
            <div class="field">
                <span class="label">Status</span>
                <div class="check-row">
                    <?php foreach (['Overdue', 'Due soon', 'Scheduled', 'Unscheduled'] as $status): ?>
                        <label class="checkbox checkbox-compact">
                            <input type="checkbox" name="status[]" value="<?= e($status) ?>"
                                <?= in_array($status, (array) $filters['status'], true) ? 'checked' : '' ?>>
                            <span><?= e($status) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="field">
                <span class="label">Type</span>
                <div class="check-row">
                    <?php foreach (MaintenanceSchedule::TYPES as $type): ?>
                        <label class="checkbox checkbox-compact">
                            <input type="checkbox" name="type[]" value="<?= e($type) ?>"
                                <?= in_array($type, (array) $filters['type'], true) ? 'checked' : '' ?>>
                            <span><?= e(ucfirst($type)) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="field">
                <label class="label" for="assignee">Assigned to</label>
                <select class="input" id="assignee" name="assignee">
                    <option value="">Anyone</option>

                    <?php if ($teams !== []): ?>
                        <optgroup label="Teams">
                            <?php foreach ($teams as $team): ?>
                                <option value="team:<?= (int) $team['id'] ?>" <?= (string) $filters['assigned_to'] === 'team:' . (int) $team['id'] ? 'selected' : '' ?>>
                                    <?= e($team['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>

                    <optgroup label="People">
                        <?php foreach ($users as $user): ?>
                            <option value="user:<?= (int) $user['id'] ?>" <?= (string) $filters['assigned_to'] === 'user:' . (int) $user['id'] ? 'selected' : '' ?>>
                                <?= e($user['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
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
                    <option value="due"      <?= $filters['sort'] === 'due' ? 'selected' : '' ?>>Soonest due first</option>
                    <option value="due_desc" <?= $filters['sort'] === 'due_desc' ? 'selected' : '' ?>>Furthest away first</option>
                    <option value="asset"    <?= $filters['sort'] === 'asset' ? 'selected' : '' ?>>Asset tag</option>
                    <option value="title"    <?= $filters['sort'] === 'title' ? 'selected' : '' ?>>Job title</option>
                    <option value="recent"   <?= $filters['sort'] === 'recent' ? 'selected' : '' ?>>Recently completed</option>
                </select>
            </div>

            <div class="field">
                <span class="label">Inactive</span>
                <label class="checkbox checkbox-compact">
                    <input type="checkbox" name="inactive" value="1" <?= !empty($filters['include_inactive']) ? 'checked' : '' ?>>
                    <span>Include closed schedules</span>
                </label>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Apply filters</button>
            <a class="btn btn-ghost" href="<?= e(url('/maintenance')) ?>">Clear all</a>
        </div>
    </details>
</form>

<?php if ($rows === []): ?>
    <div class="card empty-state">
        <h2><?= $hasFilters ? 'Nothing matched' : 'No maintenance scheduled' ?></h2>
        <p class="muted">
            <?= $hasFilters
                ? 'Try fewer filters. Closed one-off jobs are hidden unless you tick “Include closed schedules”.'
                : 'Add a schedule to an asset and it will appear here as it falls due. Work that was never
                   scheduled — a repair, a broken part — is recorded rather than scheduled, and shows up
                   in the history instead.' ?>
        </p>
        <?php if (!$hasFilters): ?>
            <div class="form-actions">
                <?php if (can('maintenance.manage')): ?>
                    <a class="btn btn-primary" href="<?= e(url('/maintenance/create')) ?>">Create the first schedule</a>
                <?php endif; ?>
                <?php if (can('maintenance.complete')): ?>
                    <a class="btn" href="<?= e(url('/maintenance/log')) ?>">Record unplanned work</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th scope="col">Due</th>
                <th scope="col">Job</th>
                <th scope="col">Asset</th>
                <th scope="col">Every</th>
                <th scope="col">Assigned to</th>
                <th scope="col">Last done</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $schedule): ?>
                <tr class="<?= (int) $schedule['is_active'] === 1 ? '' : 'row-muted' ?>">
                    <td class="nowrap">
                        <span class="badge due-<?= e(strtolower(str_replace(' ', '-', (string) $schedule['due_status']))) ?>">
                            <?= e($schedule['due_status']) ?>
                        </span>
                        <div class="cell-sub">
                            <?= e(format_date($schedule['next_due_date'])) ?>
                            <?php if ($schedule['days_until_due'] !== null): ?>
                                <?php $days = (int) $schedule['days_until_due']; ?>
                                <br><?= $days < 0
                                    ? abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' late'
                                    : ($days === 0 ? 'today' : 'in ' . (int) $days . ' day' . ($days === 1 ? '' : 's')) ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <a href="<?= e(url('/maintenance/' . $schedule['id'])) ?>"><strong><?= e($schedule['title']) ?></strong></a>
                        <div class="cell-sub"><span class="badge badge-muted"><?= e($schedule['maintenance_type']) ?></span></div>
                    </td>
                    <td>
                        <a class="asset-link" href="<?= e(url('/assets/' . $schedule['asset_id'])) ?>">
                            <span class="mono asset-tag"><?= e($schedule['asset_tag']) ?></span>
                            <span class="asset-name"><?= e(str_limit((string) $schedule['asset_name'], 40)) ?></span>
                        </a>
                        <?php if (!empty($schedule['location_name'])): ?>
                            <div class="cell-sub"><?= e($schedule['location_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap"><?= e(MaintenanceSchedule::describeFrequency($schedule)) ?></td>
                    <td><?= partial('partials/assignee', MaintenanceSchedule::assigneeParts($schedule)) ?></td>
                    <td class="nowrap">
                        <?= e(format_date($schedule['last_completed_date'])) ?>
                        <?php if ((int) $schedule['completion_count'] > 0): ?>
                            <div class="cell-sub"><?= (int) $schedule['completion_count'] ?> logged</div>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <?php if (can('maintenance.complete') && (int) $schedule['is_active'] === 1): ?>
                            <a class="btn btn-sm btn-primary" href="<?= e(url('/maintenance/' . $schedule['id'] . '/complete')) ?>">Complete</a>
                        <?php endif; ?>
                        <?php if (can('maintenance.manage')): ?>
                            <a class="btn btn-sm" href="<?= e(url('/maintenance/' . $schedule['id'] . '/edit')) ?>">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php $base = url('/maintenance') . ($queryString !== '' ? '?' . $queryString . '&' : '?'); ?>
            <?php if ($result['page'] > 1): ?>
                <a class="btn btn-sm" href="<?= e($base . 'page=' . ($result['page'] - 1)) ?>" rel="prev">Previous</a>
            <?php endif; ?>
            <?php for ($p = max(1, $result['page'] - 2); $p <= min($result['pages'], max(1, $result['page'] - 2) + 4); $p++): ?>
                <?php if ($p === $result['page']): ?>
                    <span class="btn btn-sm btn-primary" aria-current="page"><?= (int) $p ?></span>
                <?php else: ?>
                    <a class="btn btn-sm" href="<?= e($base . 'page=' . $p) ?>"><?= (int) $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($result['page'] < $result['pages']): ?>
                <a class="btn btn-sm" href="<?= e($base . 'page=' . ($result['page'] + 1)) ?>" rel="next">Next</a>
            <?php endif; ?>
            <span class="muted pagination-info">Page <?= (int) $result['page'] ?> of <?= (int) $result['pages'] ?></span>
        </nav>
    <?php endif; ?>
<?php endif; ?>
