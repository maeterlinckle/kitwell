<?php
/**
 * The register as a document: a compact, scannable list rather than the full
 * record of item 13. Six columns, because that is what fits across a page and
 * still reads at arm's length on a clipboard.
 *
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,mixed> $filters
 * @var bool $picked
 */
$described = [];

if (($filters['q'] ?? '') !== '') {
    $described[] = 'matching “' . $filters['q'] . '”';
}
foreach ((array) ($filters['status'] ?? []) as $status) {
    $described[] = strtolower((string) $status);
}
foreach ((array) ($filters['condition'] ?? []) as $condition) {
    $described[] = 'condition ' . strtolower((string) $condition);
}
if (!empty($filters['include_archived'])) {
    $described[] = 'including retired';
}

$subtitle = $picked
    ? count($rows) . ' hand-picked asset' . (count($rows) === 1 ? '' : 's')
    : count($rows) . ' asset' . (count($rows) === 1 ? '' : 's')
        . ($described === [] ? ' — the whole register' : ', ' . implode(', ', $described));
?>
<article class="print-doc">
    <?= partial('partials/print-header', [
        'title'    => 'Asset list',
        'subtitle' => $subtitle,
    ]) ?>

    <?php if ($rows === []): ?>
        <p class="muted">Nothing matched, so there is nothing to print.</p>
    <?php else: ?>
        <table class="print-table print-table-list">
            <thead>
            <tr>
                <th>Tag</th>
                <th>Name</th>
                <th>Category</th>
                <th>Location</th>
                <th>Condition</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="mono nowrap"><?= e($row['asset_tag']) ?></td>
                    <td><?= e($row['name']) ?></td>
                    <td><?= e((string) ($row['category_name'] ?? '—')) ?></td>
                    <td><?= e((string) ($row['location_name'] ?? '—')) ?></td>
                    <td><?= e($row['condition_rating']) ?></td>
                    <td><?= e($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <footer class="print-foot muted">
        <?= e(config('app.product', 'Kitwell')) ?> — <?= e(config('app.product_tagline', 'Asset Management')) ?>
        · by <?= e(config('app.vendor', 'Junction Inc Ltd')) ?>
    </footer>
</article>
