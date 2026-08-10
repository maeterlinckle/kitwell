<?php
/**
 * Which item is being tested?
 *
 * The guided flow shows the appliance's fixed values at every step, so it needs
 * to know the asset before it can start. Scanning is the fast path in a
 * workshop; the list is there for when the label is unreadable.
 *
 * @var array<int,array<string,mixed>> $assets
 * @var array<string,string> $errors
 */
?>
<div class="page-head">
    <div>
        <h1>Record a PAT test</h1>
        <p class="muted">Scan or choose the item you are testing.</p>
    </div>
</div>

<div class="card">
    <h2>Scan the label</h2>

    <form method="post" action="<?= e(url('/scan')) ?>" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="view">
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
    <h2>Or pick from the register</h2>

    <?php if ($assets === []): ?>
        <p class="muted">
            Nothing is flagged as needing PAT yet. Turn on <strong>Requires PAT</strong> on an asset,
            or record a test from the asset's own page.
        </p>
    <?php else: ?>
        <ul class="link-list">
            <?php foreach ($assets as $item): ?>
                <li>
                    <a href="<?= e(url('/pat/create?asset=' . (int) $item['id'])) ?>">
                        <span class="mono"><?= e($item['asset_tag']) ?></span>
                        · <?= e($item['name']) ?>
                        <?php if (($item['appliance_class'] ?? null) === null): ?>
                            <span class="badge badge-warn">No appliance class</span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
