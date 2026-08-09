<?php

use App\Models\Asset;

/**
 * Book an item back in.
 *
 * @var array<string,mixed> $loan
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$overdue = $loan['effective_status'] === 'Overdue';
?>
<div class="page-head">
    <div>
        <h1>Book in</h1>
        <p class="muted">
            <a href="<?= e(url('/assets/' . $loan['asset_id'])) ?>"><span class="mono"><?= e($loan['asset_tag']) ?></span></a>
            — <?= e($loan['asset_name']) ?> · out with <?= e($loan['borrower_name']) ?>
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/loans/' . $loan['id'])) ?>">Cancel</a>
</div>

<?php if ($overdue): ?>
    <div class="flash flash-warning">
        <span class="flash-text">
            This loan was due back on <?= e(format_date($loan['due_back_date'])) ?> —
            <?= abs((int) $loan['days_until_due']) ?> day<?= abs((int) $loan['days_until_due']) === 1 ? '' : 's' ?> ago.
        </span>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/loans/' . $loan['id'] . '/return')) ?>" enctype="multipart/form-data" class="form form-wide" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <h2>Return</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="returned_on">Date returned</label>
                <input class="input<?= isset($errors['returned_on']) ? ' has-error' : '' ?>" type="date"
                       id="returned_on" name="returned_on" required max="<?= e(date('Y-m-d')) ?>"
                       value="<?= e(old($old, 'returned_on', date('Y-m-d'))) ?>">
                <?php if (isset($errors['returned_on'])): ?><p class="field-error"><?= e($errors['returned_on']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="condition_in">Condition on return</label>
                <select class="input" id="condition_in" name="condition_in">
                    <option value="">Unchanged (<?= e($loan['asset_condition']) ?>)</option>
                    <?php foreach (Asset::CONDITIONS as $condition): ?>
                        <option value="<?= e($condition) ?>" <?= old($old, 'condition_in') === $condition ? 'selected' : '' ?>>
                            <?= e($condition) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">
                    Went out as <strong><?= e($loan['condition_out'] ?? 'not recorded') ?></strong>.
                    Setting this updates the asset's condition.
                </p>
            </div>
        </div>

        <div class="field">
            <label class="label" for="returned_condition_notes">Condition notes <span class="optional">(optional)</span></label>
            <textarea class="input" id="returned_condition_notes" name="returned_condition_notes" rows="3" maxlength="5000"
                      placeholder="Anything different about the item now compared with when it went out."><?= e(old($old, 'returned_condition_notes')) ?></textarea>
        </div>

        <div class="field">
            <label class="label" for="photos">Photos on return <span class="optional">(optional)</span></label>
            <input class="input" type="file" id="photos" name="photos[]" accept="image/*" multiple>
            <p class="field-hint">Kept against this loan, so the state it came back in is on record.</p>
        </div>

        <div class="field">
            <span class="label">Where does it go now?</span>
            <div class="radio-cards radio-cards-inline">
                <label class="radio-card">
                    <input type="radio" name="asset_status" value="In Stock"
                        <?= old($old, 'asset_status', 'In Stock') === 'In Stock' ? 'checked' : '' ?>>
                    <span><strong>Back in stock</strong><span class="muted">Available to loan again.</span></span>
                </label>

                <label class="radio-card">
                    <input type="radio" name="asset_status" value="In Maintenance"
                        <?= old($old, 'asset_status') === 'In Maintenance' ? 'checked' : '' ?>>
                    <span><strong>Into maintenance</strong><span class="muted">Came back needing attention.</span></span>
                </label>
            </div>
        </div>
    </div>

    <div class="form-actions sticky-actions">
        <button type="submit" class="btn btn-primary btn-lg">Book in</button>
        <button type="submit" name="and_scan_next" value="1" class="btn btn-lg">Book in &amp; scan next</button>
        <a class="btn btn-ghost" href="<?= e(url('/loans/' . $loan['id'])) ?>">Cancel</a>
    </div>
</form>
