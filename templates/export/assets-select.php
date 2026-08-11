<?php

use App\Controllers\AssetController;

/**
 * Hand-pick the assets to export.
 *
 * Its own page rather than a panel on the export screen: choosing rows needs a
 * searchable, paginated list, and that is a whole screen's worth of furniture.
 *
 * @var array<string,mixed> $filters
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int} $result
 * @var array<int,array<string,mixed>> $rows
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,array<string,mixed>> $locations
 * @var array<string,array<string,mixed>> $extras
 */
$queryString = AssetController::queryString($filters);
$selected    = (array) ($filters['extras'] ?? []);
?>
<div class="page-head">
    <div>
        <h1>Choose assets to export</h1>
        <p class="muted">
            Tick the ones you want. <?= number_format($result['total']) ?> asset<?= $result['total'] === 1 ? '' : 's' ?>
            match your search<?= $result['pages'] > 1 ? ', shown ' . count($rows) . ' at a time' : '' ?>.
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/export/assets' . ($queryString !== '' ? '?' . $queryString : ''))) ?>">Back</a>
</div>

<form method="get" action="<?= e(url('/export/assets/select')) ?>" class="card filter-card">
    <div class="search-row">
        <label class="sr-only" for="q">Search assets</label>
        <input class="input input-search" type="search" id="q" name="q" enterkeyhint="search"
               placeholder="Search tag, name, serial…" value="<?= e((string) $filters['q']) ?>" autocomplete="off">
        <?= partial('partials/scan-button', ['target' => 'q', 'submit' => true]) ?>
        <button class="btn btn-primary" type="submit">Search</button>
    </div>
</form>

<?php if ($rows === []): ?>
    <div class="card empty-state">
        <h2>Nothing matched</h2>
        <p class="muted">Try a shorter search term, or go back and export by filter instead.</p>
    </div>
<?php else: ?>
    <?php /* The ticked ids go to the same export endpoint the filtered download
             uses; ids simply take precedence over filters there. */ ?>
    <form method="get" action="<?= e(url('/assets/export')) ?>" class="bulk-bar" data-selectable>
        <?php foreach ($selected as $extra): ?>
            <input type="hidden" name="extras[]" value="<?= e((string) $extra) ?>">
        <?php endforeach; ?>

        <div class="bulk-bar-left">
            <label class="checkbox checkbox-compact">
                <input type="checkbox" data-select-all>
                <span>Select all on this page</span>
            </label>
            <span class="muted" data-selected-count>None selected</span>
        </div>

        <div class="bulk-bar-right">
            <button type="submit" class="btn btn-primary btn-sm" data-requires-selection disabled>Export selected</button>
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
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="col-check">
                            <label class="checkbox checkbox-bare">
                                <input type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>" data-select-item>
                                <span class="sr-only">Select <?= e($row['asset_tag']) ?></span>
                            </label>
                        </td>
                        <td>
                            <span class="mono asset-tag"><?= e($row['asset_tag']) ?></span>
                            <span class="asset-name"><?= e(str_limit((string) $row['name'], 48)) ?></span>
                        </td>
                        <td><?= e((string) ($row['category_name'] ?? '—')) ?></td>
                        <td><?= e((string) ($row['location_name'] ?? '—')) ?></td>
                        <td><span class="badge status-<?= e(strtolower(str_replace(' ', '-', (string) $row['status']))) ?>"><?= e($row['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php
            $base  = url('/export/assets/select') . ($queryString !== '' ? '?' . $queryString . '&' : '?');
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

    <?php /* Ticks live on one page at a time — a plain form posts only what is
             in the DOM. Say so rather than let someone lose a selection. */ ?>
    <?php if ($result['pages'] > 1): ?>
        <p class="muted">Selections apply to the page you are on. To export more than one page at a time, go back and export by filter.</p>
    <?php endif; ?>
<?php endif; ?>
