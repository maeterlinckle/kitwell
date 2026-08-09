<?php

/**
 * Apply selected fields from one asset onto other existing assets.
 *
 * @var array<string,mixed> $source
 * @var array<string,string> $fields
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} $result
 * @var array<string,mixed> $filters
 * @var array<int,array<string,mixed>> $categories
 * @var int  $manualCount
 * @var bool $suggestOnly
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */
$selected = $old !== [] && isset($old['fields']) ? (array) $old['fields'] : [];

$display = static function (string $field) use ($source): string {
    $value = $source[$field] ?? null;

    if ($value === null || $value === '') {
        return '— (blank)';
    }

    return match ($field) {
        'category_id'  => (string) ($source['category_name'] ?? '—'),
        'location_id'  => (string) ($source['location_name'] ?? '—'),
        'requires_pat', 'is_loanable' => ((int) $value === 1 ? 'Yes' : 'No'),
        'purchase_cost', 'current_value' => format_money($value),
        'purchase_date', 'warranty_expires_on' => format_date((string) $value),
        default => str_limit((string) $value, 70),
    };
};
?>
<div class="page-head">
    <div>
        <h1>Copy details to other assets</h1>
        <p class="muted">
            Taking details from <a href="<?= e(url('/assets/' . $source['id'])) ?>"><span class="mono"><?= e($source['asset_tag']) ?></span></a>
            — <?= e($source['name']) ?>
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/assets/' . $source['id'])) ?>">Cancel</a>
</div>

<div class="flash flash-info">
    <span class="flash-text">
        Only the fields you tick are written to the assets you select. Everything else on those
        assets is left exactly as it is.
    </span>
</div>

<form method="post" action="<?= e(url('/assets/' . $source['id'] . '/apply')) ?>" class="form form-wide" novalidate>
    <?= csrf_field() ?>

    <div class="card">
        <h2>1. Which details?</h2>
        <?php if (isset($errors['fields'])): ?><p class="field-error"><?= e($errors['fields']) ?></p><?php endif; ?>

        <div class="table-wrap">
            <table class="table table-compact copy-table">
                <thead>
                <tr>
                    <th scope="col" class="col-check">Copy</th>
                    <th scope="col">Field</th>
                    <th scope="col">Value that will be applied</th>
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
                            <input type="checkbox" name="copy_manuals" value="1" <?= $manualCount === 0 ? 'disabled' : '' ?>>
                            <span class="sr-only">Copy manuals</span>
                        </label>
                    </td>
                    <th scope="row">Manuals (PDF)</th>
                    <td class="muted">
                        <?= $manualCount > 0
                            ? (int) $manualCount . ' document' . ($manualCount === 1 ? '' : 's') . ' — added to each selected asset, skipping any it already has'
                            : 'None attached' ?>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>2. Which assets?</h2>
            <span class="muted" data-selected-count>None selected</span>
        </div>

        <?php if (isset($errors['ids'])): ?><p class="field-error"><?= e($errors['ids']) ?></p><?php endif; ?>

        <?php if ($suggestOnly && $filters['q'] !== ''): ?>
            <p class="muted">
                Showing assets matching <strong><?= e($filters['q']) ?></strong> — the same make and model as the source.
                <a href="<?= e(url('/assets/' . $source['id'] . '/apply?all=1')) ?>">Search all assets instead</a>.
            </p>
        <?php endif; ?>

        <div class="search-row target-search">
            <label class="sr-only" for="target-q">Filter candidate assets</label>
            <input class="input input-search" type="search" id="target-q" name="q" form="target-filter"
                   placeholder="Search by tag, name, model…" value="<?= e($filters['q']) ?>">
            <button class="btn" type="submit" form="target-filter">Search</button>
        </div>

        <?php if ($result['rows'] === []): ?>
            <p class="muted">No other assets matched. Try a different search.</p>
        <?php else: ?>
            <div data-selectable>
                <label class="checkbox checkbox-compact">
                    <input type="checkbox" data-select-all>
                    <span>Select all <?= count($result['rows']) ?> shown</span>
                </label>

                <div class="table-wrap">
                    <table class="table table-compact">
                        <thead>
                        <tr>
                            <th scope="col" class="col-check"><span class="sr-only">Select</span></th>
                            <th scope="col">Asset</th>
                            <th scope="col">Make &amp; model</th>
                            <th scope="col">Location</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($result['rows'] as $candidate): ?>
                            <tr>
                                <td class="col-check">
                                    <label class="checkbox checkbox-bare">
                                        <input type="checkbox" name="ids[]" value="<?= (int) $candidate['id'] ?>"
                                               data-target-box>
                                        <span class="sr-only">Select <?= e($candidate['asset_tag']) ?></span>
                                    </label>
                                </td>
                                <td>
                                    <span class="mono asset-tag"><?= e($candidate['asset_tag']) ?></span>
                                    <span class="asset-name"><?= e($candidate['name']) ?></span>
                                </td>
                                <td class="muted"><?= e(trim((string) $candidate['manufacturer'] . ' ' . (string) $candidate['model'])) ?: '—' ?></td>
                                <td class="muted"><?= e($candidate['location_name'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($result['total'] > count($result['rows'])): ?>
                    <p class="field-hint">
                        Showing <?= count($result['rows']) ?> of <?= (int) $result['total'] ?> matches — narrow the search to see the rest.
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="form-actions sticky-actions">
        <button type="submit" class="btn btn-primary btn-lg" data-requires-selection disabled>Apply to selected assets</button>
        <a class="btn btn-ghost" href="<?= e(url('/assets/' . $source['id'])) ?>">Cancel</a>
    </div>
</form>

<!-- Separate GET form so filtering the candidate list never submits the apply form. -->
<form method="get" action="<?= e(url('/assets/' . $source['id'] . '/apply')) ?>" id="target-filter" class="hidden-form">
    <input type="hidden" name="all" value="1">
</form>
