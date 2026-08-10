<?php

use App\Models\Asset;
use App\Models\Hirer;

/**
 * Check an asset out.
 *
 * @var array<string,mixed>|null $asset
 * @var string|null $blocked
 * @var array<int,array<string,mixed>> $hirers
 * @var string $defaultDue
 * @var int $defaultDays
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
?>
<div class="page-head">
    <div>
        <h1>Check out</h1>
        <?php if ($asset !== null): ?>
            <p class="muted">
                <a href="<?= e(url('/assets/' . $asset['id'])) ?>"><span class="mono"><?= e($asset['asset_tag']) ?></span></a>
                — <?= e($asset['name']) ?>
            </p>
        <?php endif; ?>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e(url('/scan?mode=checkout')) ?>">Scan an item</a>
        <a class="btn btn-ghost" href="<?= e(url('/hires')) ?>">Cancel</a>
    </div>
</div>

<?php if ($asset !== null && $blocked !== null): ?>
    <div class="flash flash-error">
        <span class="flash-text"><?= e($blocked) ?></span>
    </div>
    <div class="card">
        <p><a class="btn" href="<?= e(url('/assets/' . $asset['id'])) ?>">Open the asset</a></p>
    </div>
    <?php return; ?>
<?php endif; ?>

<?php if ($asset === null): ?>
    <div class="card">
        <h2>Which asset?</h2>
        <p class="muted">Scan the barcode, or type the asset tag. A USB scanner works here directly.</p>

        <form method="post" action="<?= e(url('/scan')) ?>" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="checkout">
            <div class="field">
                <label class="sr-only" for="code">Asset tag</label>
                <div class="input-with-button">
                    <input class="input input-scan mono" type="text" id="code" name="code" autofocus
                           autocomplete="off" autocapitalize="characters" spellcheck="false"
                           placeholder="e.g. AST-0001" enterkeyhint="go">
                    <?= partial('partials/scan-button', ['target' => 'code', 'submit' => true]) ?>
                    <button class="btn btn-primary" type="submit">Find</button>
                </div>
            </div>
        </form>

        <p><a href="<?= e(url('/assets')) ?>">Or browse the register</a></p>
    </div>
    <?php return; ?>
<?php endif; ?>

<form method="post" action="<?= e(url('/hires/checkout')) ?>" enctype="multipart/form-data" class="form form-wide" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">

    <div class="card">
        <h2>Who is taking it?</h2>

        <div class="field">
            <label class="label" for="hirer_id">Hirer</label>
            <select class="input<?= isset($errors['hirer_id']) ? ' has-error' : '' ?>" id="hirer_id" name="hirer_id" required>
                <option value="">Choose a hirer…</option>
                <?php foreach ($hirers as $hirer): ?>
                    <option value="<?= (int) $hirer['id'] ?>" <?= old($old, 'hirer_id') === (string) $hirer['id'] ? 'selected' : '' ?>>
                        <?= e(Hirer::label($hirer)) ?><?= !empty($hirer['reference']) ? ' · ' . e($hirer['reference']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['hirer_id'])): ?><p class="field-error"><?= e($errors['hirer_id']) ?></p><?php endif; ?>
            <?php if (can('hirers.manage')): ?>
                <p class="field-hint"><a href="<?= e(url('/hirers/create')) ?>">Add a new hirer</a></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>When is it back?</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="due_back_date">Due back</label>
                <input class="input<?= isset($errors['due_back_date']) ? ' has-error' : '' ?>" type="date"
                       id="due_back_date" name="due_back_date" required min="<?= e(date('Y-m-d')) ?>"
                       value="<?= e(old($old, 'due_back_date', $defaultDue)) ?>">
                <p class="field-hint">Defaults to <?= (int) $defaultDays ?> days from today.</p>
                <?php if (isset($errors['due_back_date'])): ?><p class="field-error"><?= e($errors['due_back_date']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="condition_out">Condition going out</label>
                <select class="input" id="condition_out" name="condition_out">
                    <option value="">Use the recorded condition (<?= e($asset['condition_rating']) ?>)</option>
                    <?php foreach (Asset::CONDITIONS as $condition): ?>
                        <option value="<?= e($condition) ?>" <?= old($old, 'condition_out') === $condition ? 'selected' : '' ?>>
                            <?= e($condition) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="purpose">Purpose <span class="optional">(optional)</span></label>
                <input class="input" type="text" id="purpose" name="purpose" maxlength="255"
                       placeholder="e.g. Second fix at the Eastway site" value="<?= e(old($old, 'purpose')) ?>">
            </div>

            <div class="field">
                <label class="label" for="hire_charge">Hire charge (<?= e(config('app.currency_symbol', '£')) ?>) <span class="optional">(optional)</span></label>
                <input class="input" type="number" id="hire_charge" name="hire_charge" step="0.01" min="0"
                       inputmode="decimal" value="<?= e(old($old, 'hire_charge')) ?>">
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Condition record <span class="optional">(optional)</span></h2>
        <p class="muted">A photo now makes any argument about damage on return a short one.</p>

        <div class="field">
            <label class="label" for="photos">Photos going out</label>
            <input class="input" type="file" id="photos" name="photos[]" accept="image/*" multiple>
        </div>

        <div class="field">
            <label class="label" for="notes">Notes <span class="optional">(optional)</span></label>
            <textarea class="input" id="notes" name="notes" rows="3" maxlength="5000"><?= e(old($old, 'notes')) ?></textarea>
        </div>
    </div>

    <div class="form-actions sticky-actions">
        <button type="submit" class="btn btn-primary btn-lg">Check out</button>
        <button type="submit" name="and_scan_next" value="1" class="btn btn-lg">Check out &amp; scan next</button>
        <a class="btn btn-ghost" href="<?= e(url('/assets/' . $asset['id'])) ?>">Cancel</a>
    </div>
</form>
