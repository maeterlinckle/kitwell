<?php
/**
 * The right-hand end of a tree row: what uses this entry, and what you can do
 * to it. Split out of reference-tree so a branch and a leaf render exactly the
 * same controls in exactly the same place — two copies would drift.
 *
 * Delete is a button rather than a link because it changes something, and it is
 * disabled rather than hidden when the entry is in use: "why can I not delete
 * this?" is a better question to be able to answer than "where did the button
 * go?". The server refuses it either way.
 *
 * @var array<string,mixed> $item
 * @var string $base
 * @var string $noun
 * @var string $usage
 * @var int    $count
 * @var bool   $inactive
 */
$id = (int) $item['id'];
?>
<span class="tree-meta">
    <?php if (!empty($item['code'])): ?>
        <span class="badge badge-muted mono"><?= e((string) $item['code']) ?></span>
    <?php endif; ?>

    <?php if ($inactive): ?>
        <span class="badge badge-muted">Inactive</span>
    <?php endif; ?>

    <?php if ($count > 0): ?>
        <a class="tree-count" href="<?= e(url('/assets?' . $usage . '=' . $id)) ?>"><?= (int) $count ?> asset<?= $count === 1 ? '' : 's' ?></a>
    <?php else: ?>
        <span class="tree-count muted">—</span>
    <?php endif; ?>
</span>

<span class="tree-actions">
    <a class="btn btn-xs btn-ghost" href="<?= e(url($base . '/create?parent=' . $id)) ?>"
       title="Add something inside <?= e($item['name']) ?>">Add inside</a>

    <a class="btn btn-xs" href="<?= e(url($base . '/' . $id . '/edit')) ?>">Edit</a>

    <form method="post" action="<?= e(url($base . '/' . $id . '/delete')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-xs btn-ghost" <?= $count > 0 ? 'disabled' : '' ?>
                title="<?= $count > 0
                    ? 'In use by ' . (int) $count . ' asset(s) — move those first, or make it inactive'
                    : 'Delete this ' . e($noun) ?>"
                data-confirm="Delete “<?= e($item['name']) ?>”?">Delete</button>
    </form>
</span>
