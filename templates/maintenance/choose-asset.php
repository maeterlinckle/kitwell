<?php
/**
 * Which item was worked on?
 *
 * Unplanned work is recorded against an asset, not against a schedule — there
 * is no schedule, that is the whole point of it. So the flow starts by finding
 * the item, the same way the PAT flow does: scan in a workshop, search when the
 * label is unreadable.
 *
 * @var array<int,array<string,mixed>> $assets
 * @var string $keywords
 */
?>
<div class="page-head">
    <div>
        <h1>Record maintenance</h1>
        <p class="muted">
            For work that was not on a schedule — a repair, a broken part, something you noticed and
            dealt with. Find the item it was done to.
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/maintenance')) ?>">Back to maintenance</a>
</div>

<div class="card">
    <h2>Scan the label</h2>

    <form method="post" action="<?= e(url('/scan')) ?>" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="maintenance">
        <div class="field">
            <label class="sr-only" for="code">Asset tag</label>
            <div class="input-with-button">
                <input class="input input-scan mono" type="text" id="code" name="code" autofocus
                       autocomplete="off" autocapitalize="characters" spellcheck="false"
                       placeholder="e.g. AST-0001" enterkeyhint="go">
                <?= partial('partials/scan-button', ['target' => 'code', 'submit' => true]) ?>
                <button class="btn btn-primary" type="submit">Find</button>
            </div>
            <p class="field-hint">A USB scanner works here directly — it types the tag and presses Enter.</p>
        </div>
    </form>
</div>

<div class="card">
    <h2>Or find it in the register</h2>

    <form method="get" action="<?= e(url('/maintenance/log')) ?>" class="filter-bar">
        <div class="field field-inline">
            <label class="sr-only" for="q">Search</label>
            <input class="input" type="search" id="q" name="q" placeholder="Tag, name, serial, manufacturer…"
                   value="<?= e($keywords) ?>">
        </div>
        <button class="btn" type="submit">Search</button>
        <?php if ($keywords !== ''): ?>
            <a class="btn btn-ghost" href="<?= e(url('/maintenance/log')) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($assets === []): ?>
        <p class="empty">
            <?= $keywords === ''
                ? 'There are no assets in the register yet.'
                : 'Nothing matches “' . e($keywords) . '”.' ?>
        </p>
    <?php else: ?>
        <?php if ($keywords === ''): ?>
            <p class="field-hint">Anything in maintenance now, then whatever was worked on most recently.</p>
        <?php endif; ?>

        <ul class="link-list">
            <?php foreach ($assets as $item): ?>
                <li>
                    <a href="<?= e(url('/assets/' . (int) $item['id'] . '/maintenance/log')) ?>">
                        <span class="mono"><?= e($item['asset_tag']) ?></span>
                        · <?= e($item['name']) ?>
                        <?php if (!empty($item['location_name'])): ?>
                            <span class="muted"><?= e($item['location_name']) ?></span>
                        <?php endif; ?>
                        <?php if (($item['status'] ?? '') === 'In Maintenance'): ?>
                            <span class="badge badge-warn">In maintenance</span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
