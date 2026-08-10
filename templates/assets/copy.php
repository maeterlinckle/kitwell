<?php

use App\Models\Asset;

/**
 * Duplicate an asset into one or more new ones.
 *
 * @var array<string,mixed> $source
 * @var array<string,string> $fields
 * @var array<int,string> $defaults
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,array<string,mixed>> $locations
 * @var array<int,array<string,mixed>> $parents
 * @var int $manualCount
 * @var array<int,string> $nextTags
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$selected = $old !== [] && isset($old['fields']) ? (array) $old['fields'] : $defaults;

/** Human-readable current value of a source field, for the preview column. */
$display = static function (string $field) use ($source): string {
    $value = $source[$field] ?? null;

    if ($value === null || $value === '') {
        return '—';
    }

    return match ($field) {
        'category_id'  => (string) ($source['category_name'] ?? '—'),
        'location_id'  => (string) ($source['location_name'] ?? '—'),
        'requires_pat', 'is_hireable' => ((int) $value === 1 ? 'Yes' : 'No'),
        'purchase_cost', 'current_value' => format_money($value),
        'purchase_date', 'warranty_expires_on' => format_date((string) $value),
        default => str_limit((string) $value, 70),
    };
};
?>
<div class="page-head">
    <div>
        <h1>Copy asset</h1>
        <p class="muted">
            Creating new assets based on <a href="<?= e(url('/assets/' . $source['id'])) ?>"><span class="mono"><?= e($source['asset_tag']) ?></span></a>
            — <?= e($source['name']) ?>
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/assets/' . $source['id'])) ?>">Cancel</a>
</div>

<form method="post" action="<?= e(url('/assets/' . $source['id'] . '/copy')) ?>" class="form form-wide" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <h2>How many?</h2>

        <div class="field-row">
            <div class="field">
                <label class="label" for="quantity">Number of copies</label>
                <input class="input<?= isset($errors['quantity']) ? ' has-error' : '' ?>" type="number" id="quantity"
                       name="quantity" min="1" max="50" step="1" inputmode="numeric"
                       value="<?= e(old($old, 'quantity', '1')) ?>" required>
                <p class="field-hint">Each copy gets its own generated tag — next up: <span class="mono"><?= e(implode(', ', $nextTags)) ?>…</span></p>
                <?php if (isset($errors['quantity'])): ?><p class="field-error"><?= e($errors['quantity']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="name">Name for the new asset(s)</label>
                <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name" name="name"
                       maxlength="191" required value="<?= e(old($old, 'name', (string) $source['name'])) ?>">
                <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="location_id">Location for the copies</label>
                <select class="input" id="location_id" name="location_id">
                    <option value="">Not set</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= (int) $location['id'] ?>"
                            <?= (string) old($old, 'location_id', (string) ($source['location_id'] ?? '')) === (string) $location['id'] ? 'selected' : '' ?>>
                            <?= e($location['display_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">Often different from the original — set it once here rather than editing each copy.</p>
            </div>

            <div class="field">
                <label class="label" for="serial_number">Serial number <span class="optional">(single copy only)</span></label>
                <input class="input mono" type="text" id="serial_number" name="serial_number" maxlength="191"
                       autocapitalize="characters" spellcheck="false" value="<?= e(old($old, 'serial_number')) ?>">
                <p class="field-hint">Serials identify one physical item, so batches of more than one are always left blank.</p>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>What to copy</h2>
        <p class="muted">
            Tick the details to carry over. The asset tag, barcode, status, photos, PAT results,
            maintenance and hire history are never copied — they belong to the original item.
        </p>

        <div class="table-wrap">
            <table class="table table-compact copy-table">
                <thead>
                <tr>
                    <th scope="col" class="col-check">Copy</th>
                    <th scope="col">Field</th>
                    <th scope="col">Value on <?= e($source['asset_tag']) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($fields as $field => $label): ?>
                    <tr>
                        <td class="col-check">
                            <label class="checkbox checkbox-bare">
                                <input type="checkbox" name="fields[]" value="<?= e($field) ?>"
                                    <?= in_array($field, $selected, true) ? 'checked' : '' ?>>
                                <span class="sr-only">Copy <?= e($label) ?></span>
                            </label>
                        </td>
                        <th scope="row"><?= e($label) ?></th>
                        <td class="muted"><?= e($display($field)) ?></td>
                    </tr>
                <?php endforeach; ?>

                <tr>
                    <td class="col-check">
                        <label class="checkbox checkbox-bare">
                            <input type="checkbox" name="copy_manuals" value="1" <?= $manualCount > 0 ? 'checked' : '' ?> <?= $manualCount === 0 ? 'disabled' : '' ?>>
                            <span class="sr-only">Copy manuals</span>
                        </label>
                    </td>
                    <th scope="row">Manuals (PDF)</th>
                    <td class="muted"><?= $manualCount > 0 ? (int) $manualCount . ' document' . ($manualCount === 1 ? '' : 's') . ' — copied to each new asset' : 'None attached' ?></td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Attach the copies to an asset <span class="optional">(optional)</span></h2>
        <div class="field-row">
            <div class="field">
                <label class="label" for="parent_asset_id">Parent asset</label>
                <select class="input" id="parent_asset_id" name="parent_asset_id">
                    <option value="">Not attached</option>
                    <?php foreach ($parents as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= old($old, 'parent_asset_id') === (string) $option['id'] ? 'selected' : '' ?>>
                            <?= e($option['asset_tag'] . ' — ' . str_limit((string) $option['name'], 60)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label" for="relationship_type">Relationship</label>
                <select class="input" id="relationship_type" name="relationship_type">
                    <?php foreach (Asset::RELATIONSHIPS as $relationship): ?>
                        <option value="<?= e($relationship) ?>" <?= old($old, 'relationship_type', 'sub-asset') === $relationship ? 'selected' : '' ?>>
                            <?= e(ucfirst(str_replace('-', ' ', $relationship))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="form-actions sticky-actions">
        <button type="submit" class="btn btn-primary btn-lg">Create copies</button>
        <a class="btn btn-ghost" href="<?= e(url('/assets/' . $source['id'])) ?>">Cancel</a>
    </div>
</form>
