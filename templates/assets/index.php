<?php

use App\Models\Asset;

/**
 * The asset register: keyword search, filters, bulk label printing.
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} $result
 * @var array<string,mixed> $filters
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,array<string,mixed>> $locations
 * @var array<int,array<string,mixed>> $photos Primary photo per asset id
 * @var string $queryString
 */
$rows       = $result['rows'];
$hasFilters = ($filters['q'] ?? '') !== ''
    || !empty($filters['category_id']) || !empty($filters['location_id'])
    || !empty($filters['status']) || !empty($filters['condition'])
    || ($filters['requires_pat'] ?? '') !== '' || ($filters['type'] ?? '') !== ''
    || !empty($filters['include_archived']);
?>
<div class="page-head">
    <div>
        <h1>Assets</h1>
        <p class="muted">
            <?= number_format($result['total']) ?> asset<?= $result['total'] === 1 ? '' : 's' ?><?= $hasFilters ? ' matching your filters' : '' ?>
        </p>
    </div>
    <div class="head-actions">
        <?php /* No export here at all — it is started from /export, which
                 explains the formats and carries the column options. */ ?>
        <a class="btn" href="<?= e(url('/assets/print' . ($queryString !== '' ? '?' . $queryString : ''))) ?>">Print list</a>
        <?php if (can('assets.create')): ?>
            <a class="btn btn-primary" href="<?= e(url('/assets/create')) ?>">Add asset</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" action="<?= e(url('/assets')) ?>" class="card filter-card">
    <div class="search-row">
        <label class="sr-only" for="q">Search assets</label>
        <input class="input input-search" type="search" id="q" name="q" enterkeyhint="search"
               placeholder="Search tag, name, serial, manufacturer…"
               value="<?= e($filters['q']) ?>" autocomplete="off">
        <?= partial('partials/scan-button', ['target' => 'q', 'submit' => true]) ?>
        <button class="btn btn-primary" type="submit">Search</button>
    </div>

    <details class="filter-details" <?= $hasFilters ? 'open' : '' ?>>
        <summary>Filters<?= $hasFilters ? ' <span class="badge badge-role">active</span>' : '' ?></summary>

        <div class="filter-grid">
            <div class="field">
                <label class="label" for="category">Category</label>
                <select class="input" id="category" name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" <?= (string) $filters['category_id'] === (string) $category['id'] ? 'selected' : '' ?>>
                            <?= e($category['parent_name'] !== null ? $category['parent_name'] . ' → ' . $category['name'] : $category['name']) ?>
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
                <label class="label" for="type">Item type</label>
                <select class="input" id="type" name="type">
                    <option value="">All items</option>
                    <option value="top" <?= $filters['type'] === 'top' ? 'selected' : '' ?>>Top-level assets only</option>
                    <option value="sub" <?= $filters['type'] === 'sub' ? 'selected' : '' ?>>Sub-assets &amp; accessories only</option>
                </select>
            </div>

            <div class="field">
                <label class="label" for="pat">PAT</label>
                <select class="input" id="pat" name="pat">
                    <option value="">Any</option>
                    <option value="1" <?= (string) $filters['requires_pat'] === '1' ? 'selected' : '' ?>>Requires PAT</option>
                    <option value="0" <?= (string) $filters['requires_pat'] === '0' ? 'selected' : '' ?>>No PAT needed</option>
                </select>
            </div>

            <div class="field">
                <span class="label">Status</span>
                <div class="check-row">
                    <?php foreach (Asset::STATUSES as $status): ?>
                        <label class="checkbox checkbox-compact">
                            <input type="checkbox" name="status[]" value="<?= e($status) ?>"
                                <?= in_array($status, (array) $filters['status'], true) ? 'checked' : '' ?>>
                            <span><?= e($status) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="field">
                <span class="label">Condition</span>
                <div class="check-row">
                    <?php foreach (Asset::CONDITIONS as $condition): ?>
                        <label class="checkbox checkbox-compact">
                            <input type="checkbox" name="condition[]" value="<?= e($condition) ?>"
                                <?= in_array($condition, (array) $filters['condition'], true) ? 'checked' : '' ?>>
                            <span><?= e($condition) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="field">
                <label class="label" for="sort">Sort by</label>
                <select class="input" id="sort" name="sort">
                    <?php foreach ([
                        'tag'       => 'Asset tag',
                        'name'      => 'Name',
                        'newest'    => 'Newest first',
                        'oldest'    => 'Oldest first',
                        'updated'   => 'Recently updated',
                        'status'    => 'Status',
                        'condition' => 'Worst condition first',
                        'value'     => 'Highest value first',
                    ] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= ($filters['sort'] ?? 'tag') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <span class="label">Archived</span>
                <label class="checkbox checkbox-compact">
                    <input type="checkbox" name="archived" value="1" <?= !empty($filters['include_archived']) ? 'checked' : '' ?>>
                    <span>Include retired assets</span>
                </label>
            </div>

        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Apply filters</button>
            <a class="btn btn-ghost" href="<?= e(url('/assets')) ?>">Clear all</a>
        </div>
    </details>
</form>

<?php if ($rows === []): ?>
    <div class="card empty-state">
        <h2><?= $hasFilters ? 'Nothing matched' : 'No assets yet' ?></h2>
        <p class="muted">
            <?= $hasFilters
                ? 'Try fewer filters, or a shorter search term. Retired assets are hidden unless you tick “Include retired assets”.'
                : 'Register your first asset to get started — the asset tag is generated for you.' ?>
        </p>
        <?php if ($hasFilters): ?>
            <a class="btn" href="<?= e(url('/assets')) ?>">Clear filters</a>
        <?php elseif (can('assets.create')): ?>
            <a class="btn btn-primary" href="<?= e(url('/assets/create')) ?>">Add the first asset</a>
        <?php endif; ?>
    </div>
<?php else: ?>

    <form method="get" action="<?= e(url('/assets/labels')) ?>" class="bulk-bar" data-selectable>
        <div class="bulk-bar-left">
            <label class="checkbox checkbox-compact">
                <input type="checkbox" data-select-all>
                <span>Select all on this page</span>
            </label>
            <span class="muted" data-selected-count>None selected</span>
        </div>

        <div class="bulk-bar-right">
            <button type="submit" class="btn btn-sm" data-requires-selection disabled>Print labels</button>
            <button type="submit" class="btn btn-sm" data-requires-selection disabled
                    formaction="<?= e(url('/assets/print')) ?>">Print list</button>
        </div>

        <div class="table-wrap">
            <table class="table table-assets">
                <thead>
                <tr>
                    <th scope="col" class="col-check"><span class="sr-only">Select</span></th>
                    <th scope="col">Asset</th>
                    <th scope="col">Category</th>
                    <th scope="col">Location</th>
                    <th scope="col">Status</th>
                    <th scope="col">Condition</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $asset): ?>
                    <tr class="<?= $asset['status'] === 'Retired' ? 'row-muted' : '' ?>">
                        <td class="col-check">
                            <label class="checkbox checkbox-bare">
                                <input type="checkbox" name="ids[]" value="<?= (int) $asset['id'] ?>">
                                <span class="sr-only">Select <?= e($asset['asset_tag']) ?></span>
                            </label>
                        </td>
                        <td>
                            <a class="asset-link asset-link-media" href="<?= e(url('/assets/' . $asset['id'])) ?>">
                                <?php $thumb = $photos[(int) $asset['id']] ?? null; ?>
                                <?php if ($thumb !== null): ?>
                                    <img class="asset-thumb"
                                         src="<?= e(url('/assets/' . $asset['id'] . '/photos/' . $thumb['id'] . '?size=thumb')) ?>"
                                         alt="" loading="lazy" decoding="async" width="48" height="48">
                                <?php else: ?>
                                    <span class="asset-thumb asset-thumb-empty" aria-hidden="true"></span>
                                <?php endif; ?>
                                <span class="asset-link-text">
                                    <span class="mono asset-tag"><?= e($asset['asset_tag']) ?></span>
                                    <span class="asset-name"><?= e($asset['name']) ?></span>
                                </span>
                            </a>
                            <div class="cell-sub">
                                <?php if ($asset['parent_asset_id'] !== null): ?>
                                    <span class="badge badge-muted"><?= e($asset['relationship_type'] ?? 'sub-asset') ?></span>
                                    part of <?= e($asset['parent_tag']) ?>
                                <?php endif; ?>
                                <?php if (!empty($asset['manufacturer']) || !empty($asset['model'])): ?>
                                    <?= e(trim((string) $asset['manufacturer'] . ' ' . (string) $asset['model'])) ?>
                                <?php endif; ?>
                                <?php if ((int) $asset['requires_pat'] === 1): ?>
                                    <span class="badge badge-warn">PAT</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= e($asset['category_name'] ?? '—') ?></td>
                        <td>
                            <?= e($asset['location_name'] ?? '—') ?>
                            <?php if (!empty($asset['location_parent_name'])): ?>
                                <div class="cell-sub"><?= e($asset['location_parent_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge status-<?= e(strtolower(str_replace(' ', '-', (string) $asset['status']))) ?>"><?= e($asset['status']) ?></span></td>
                        <td><span class="badge condition-<?= e(strtolower(str_replace(' ', '-', (string) $asset['condition_rating']))) ?>"><?= e($asset['condition_rating']) ?></span></td>
                        <td class="actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('/assets/' . $asset['id'] . '/label')) ?>">Label</a>
                            <?php if (can('assets.edit')): ?>
                                <a class="btn btn-sm" href="<?= e(url('/assets/' . $asset['id'] . '/edit')) ?>">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php
            $base  = url('/assets') . ($queryString !== '' ? '?' . $queryString . '&' : '?');
            $start = max(1, $result['page'] - 2);
            $end   = min($result['pages'], $start + 4);
            ?>
            <?php if ($result['page'] > 1): ?>
                <a class="btn btn-sm" href="<?= e($base . 'page=' . ($result['page'] - 1)) ?>" rel="prev">Previous</a>
            <?php endif; ?>

            <?php for ($p = $start; $p <= $end; $p++): ?>
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
