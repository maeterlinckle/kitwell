<?php

use App\Reports\Report;

/**
 * One report: filters, headline figures, table, exports.
 *
 * Nothing here is specific to any report — it is all read from the report's
 * own declarations.
 *
 * @var Report $report
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,mixed> $filters
 * @var array<int,array{label:string,value:string|int,tone?:string}> $summary
 * @var string $subtitle
 * @var string $queryString
 */
$definitions = $report->filterDefinitions();
$exportQuery = $queryString === '' ? 'format=csv' : $queryString . '&format=csv';
$printQuery  = $queryString === '' ? 'format=print' : $queryString . '&format=print';
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('/reports')) ?>">Reports</a></p>
        <h1><?= e($report->name()) ?></h1>
        <p class="muted"><?= e($subtitle) ?></p>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e(url('/reports/' . $report->key() . '?' . $printQuery)) ?>">Print</a>
        <?php if (can($report->exportPermission())): ?>
            <a class="btn btn-primary" href="<?= e(url('/reports/' . $report->key() . '?' . $exportQuery)) ?>">Export CSV</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($summary !== []): ?>
    <div class="stat-grid">
        <?php foreach ($summary as $item): ?>
            <div class="stat-card <?= !empty($item['tone']) ? 'stat-' . e($item['tone']) : '' ?>">
                <span class="stat-value"><?= e((string) $item['value']) ?></span>
                <span class="stat-label"><?= e($item['label']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($definitions !== []): ?>
    <form method="get" action="<?= e(url('/reports/' . $report->key())) ?>" class="card filter-card">
        <div class="filter-grid">
            <?php foreach ($definitions as $key => $definition): ?>
                <?php $type = (string) ($definition['type'] ?? 'search'); ?>

                <?php if ($type === 'checkbox'): ?>
                    <div class="field">
                        <span class="label"><?= e($definition['label']) ?></span>
                        <label class="checkbox checkbox-compact">
                            <input type="checkbox" name="<?= e($key) ?>" value="1" <?= !empty($filters[$key]) ? 'checked' : '' ?>>
                            <span><?= e($definition['label']) ?></span>
                        </label>
                    </div>
                <?php elseif ($type === 'select'): ?>
                    <div class="field">
                        <label class="label" for="filter-<?= e($key) ?>"><?= e($definition['label']) ?></label>
                        <select class="input" id="filter-<?= e($key) ?>" name="<?= e($key) ?>">
                            <?php foreach ((array) ($definition['options'] ?? []) as $value => $label): ?>
                                <option value="<?= e((string) $value) ?>" <?= (string) ($filters[$key] ?? '') === (string) $value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php elseif ($type === 'date'): ?>
                    <div class="field">
                        <label class="label" for="filter-<?= e($key) ?>"><?= e($definition['label']) ?></label>
                        <input class="input" type="date" id="filter-<?= e($key) ?>" name="<?= e($key) ?>"
                               value="<?= e((string) ($filters[$key] ?? '')) ?>">
                    </div>
                <?php else: ?>
                    <div class="field">
                        <label class="label" for="filter-<?= e($key) ?>"><?= e($definition['label']) ?></label>
                        <input class="input" type="search" id="filter-<?= e($key) ?>" name="<?= e($key) ?>"
                               placeholder="<?= e($definition['placeholder'] ?? '') ?>"
                               value="<?= e((string) ($filters[$key] ?? '')) ?>">
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Apply</button>
            <a class="btn btn-ghost" href="<?= e(url('/reports/' . $report->key())) ?>">Reset</a>
        </div>
    </form>
<?php endif; ?>

<?php if ($rows === []): ?>
    <div class="card empty-state">
        <h2>Nothing to show</h2>
        <p class="muted"><?= e($report->emptyMessage()) ?></p>
    </div>
<?php else: ?>
    <?= partial('partials/report-table', ['report' => $report, 'rows' => $rows, 'linked' => true]) ?>

    <p class="muted report-footnote">
        <?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?> ·
        generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?>
    </p>
<?php endif; ?>
