<?php
/**
 * Categories and locations as a tree.
 *
 * One partial for both, because they are the same shape: a self-nesting
 * reference table whose rows have a name, an active flag, a count of the assets
 * using them, and children.
 *
 * A branch is a `<details>`, so expanding and collapsing needs no JavaScript
 * and uses the same caret the navigation and the asset filters do. Rendering is
 * recursive rather than two-deep on purpose — the table allows any depth, and a
 * list that silently stops drawing at level three would hide data.
 *
 * The row carries only what you scan for: the name, whether anything uses it,
 * and the three things you came to do. Everything else — the description, the
 * short code, the active flag — is on the entry's own page, which is what keeps
 * this readable at fifty rows.
 *
 * @var array<int,array<string,mixed>> $items  Flat rows: id, name, parent_id, is_active, asset_count
 * @var string $base   e.g. '/admin/categories'
 * @var string $noun   e.g. 'category'
 * @var string $usage  What the asset count links to, e.g. 'category'
 */
$byParent = [];

foreach ($items as $item) {
    $byParent[(int) ($item['parent_id'] ?? 0)][] = $item;
}

/**
 * One level of the tree, and everything under it.
 *
 * By reference so it can call itself — the depth is whatever the data has.
 */
$branch = static function (int $parentId, int $depth) use (&$branch, $byParent, $base, $noun, $usage): void {
    $children = $byParent[$parentId] ?? [];

    if ($children === []) {
        return;
    }
    ?>
    <ul class="tree-list">
        <?php foreach ($children as $item): ?>
            <?php
            $id       = (int) $item['id'];
            $hasKids  = isset($byParent[$id]);
            $count    = (int) ($item['asset_count'] ?? 0);
            $inactive = (int) $item['is_active'] !== 1;
            ?>
            <li class="tree-item">
                <?php /* A branch is a <details> so the whole subtree collapses;
                         a leaf is a plain row, because a disclosure control
                         that discloses nothing is a lie. Both render the same
                         row markup, so they line up with each other. */ ?>
                <?php if ($hasKids): ?>
                    <details class="tree-branch" open>
                        <summary class="tree-row<?= $inactive ? ' is-inactive' : '' ?>">
                            <span class="caret" aria-hidden="true"></span>
                            <span class="tree-name"><?= e($item['name']) ?></span>
                            <?= partial('partials/reference-tree-meta', [
                                'item' => $item, 'base' => $base, 'noun' => $noun,
                                'usage' => $usage, 'count' => $count, 'inactive' => $inactive,
                            ]) ?>
                        </summary>

                        <?php $branch($id, $depth + 1); ?>
                    </details>
                <?php else: ?>
                    <div class="tree-row is-leaf<?= $inactive ? ' is-inactive' : '' ?>">
                        <span class="caret caret-placeholder" aria-hidden="true"></span>
                        <span class="tree-name"><?= e($item['name']) ?></span>
                        <?= partial('partials/reference-tree-meta', [
                            'item' => $item, 'base' => $base, 'noun' => $noun,
                            'usage' => $usage, 'count' => $count, 'inactive' => $inactive,
                        ]) ?>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
};

$branch(0, 0);
