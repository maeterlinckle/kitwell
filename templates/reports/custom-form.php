<?php

use App\Reports\DataSource;

/**
 * Define a saved report.
 *
 * Two steps on one page. Until a data source is chosen there is nothing
 * sensible to offer — the filters and the columns both belong to it — so the
 * page asks that first and reloads with the rest. That is a GET with a `source`
 * parameter rather than JavaScript, so it works with the keyboard, the back
 * button and a browser with scripting off, like everything else here.
 *
 * @var array<string,mixed>|null      $definition
 * @var array<string,DataSource>      $sources
 * @var DataSource|null               $source
 * @var array<string,string>          $errors
 * @var array<string,mixed>           $old
 */
$isEdit = $definition !== null;
$action = $isEdit ? url('/reports/custom/' . (int) $definition['id']) : url('/reports/custom');

$savedFilters = $isEdit ? (array) $definition['filters'] : [];
$savedColumns = $isEdit ? (array) $definition['columns'] : ($source !== null ? $source->defaultColumns : []);

if (array_key_exists('columns', $old) && is_array($old['columns'])) {
    $savedColumns = array_map('strval', $old['columns']);
}

if (array_key_exists('filters', $old) && is_array($old['filters'])) {
    $savedFilters = $old['filters'];
}

$value = static function (string $field, string $default = '') use ($old, $definition): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    if ($definition !== null && ($definition[$field] ?? null) !== null) {
        return (string) $definition[$field];
    }

    return $default;
};

/** The stored value for one filter, whatever shape it is. */
$filterValue = static fn (string $key): mixed => $savedFilters[$key] ?? null;
?>
<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit report' : 'New report' ?></h1>
        <p class="muted">
            <?= $isEdit
                ? 'Saved reports appear on the Reports page beside the built-in ones and behave the same way.'
                : 'Pick what to report on, narrow it down, and choose the columns. It is then a report like any other — filterable by nobody, printable and exportable by everybody who can see the data.' ?>
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url($isEdit ? '/reports/' . $definition['report_key'] : '/reports')) ?>">Cancel</a>
</div>

<?php if ($source === null): ?>
    <?php /* Step one. Nothing else on this form means anything yet. */ ?>
    <div class="card">
        <h2>What should it report on?</h2>
        <p class="muted">
            Each of these is a view the application already has. A report built on one can filter it
            exactly as its own list page does, and can never reach further than that page could.
        </p>

        <?php if (isset($errors['data_source'])): ?>
            <p class="field-error"><?= e($errors['data_source']) ?></p>
        <?php endif; ?>

        <div class="card-grid">
            <?php foreach ($sources as $option): ?>
                <a class="card report-card" href="<?= e(url('/reports/custom/create?source=' . $option->key)) ?>">
                    <h3><?= e($option->label) ?></h3>
                    <p class="muted"><?= e($option->description) ?></p>
                    <span class="report-card-go">Build on this →</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php else: ?>
    <form method="post" action="<?= e($action) ?>" class="form form-wide" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="data_source" value="<?= e($source->key) ?>">

        <div class="card">
            <div class="card-head">
                <h2>Name</h2>
                <span class="badge badge-role">Reporting on <?= e($source->label) ?></span>
            </div>

            <div class="field">
                <label class="label" for="name">Report name</label>
                <input class="input<?= isset($errors['name']) ? ' has-error' : '' ?>" type="text" id="name"
                       name="name" maxlength="120" required value="<?= e($value('name')) ?>"
                       placeholder="e.g. Bay 2 testers overdue for PAT">
                <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="description">Description <span class="optional">(optional)</span></label>
                <textarea class="input" id="description" name="description" rows="2" maxlength="500"
                          placeholder="What this is for, and who asked for it."><?= e($value('description')) ?></textarea>
                <p class="field-hint">
                    Shown on the Reports page. Left blank, the criteria below are described instead.
                </p>
            </div>

            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="is_active" value="1"
                        <?= (!$isEdit || (int) $definition['is_active'] === 1) ? 'checked' : '' ?>>
                    <span>List it on the Reports page
                        <span class="field-hint">Untick to keep the definition without offering it. Nothing is deleted.</span>
                    </span>
                </label>
            </div>
        </div>

        <div class="card">
            <h2>Narrow it down <span class="optional">(all optional)</span></h2>
            <p class="muted">
                These are <?= e(lcfirst($source->label)) ?>' own filters — the same ones its list page offers,
                handled by the same code. Anything left blank is not applied.
            </p>

            <?php foreach ($source->filters() as $key => $filter): ?>
                <?php $type = (string) ($filter['type'] ?? 'text'); ?>
                <?php $current = $filterValue($key); ?>
                <div class="field">
                    <?php if ($type === 'multi'): ?>
                        <span class="label"><?= e($filter['label']) ?></span>
                        <div class="check-grid">
                            <?php foreach ((array) $filter['options'] as $optionValue => $optionLabel): ?>
                                <label class="checkbox">
                                    <input type="checkbox" name="filters[<?= e($key) ?>][]" value="<?= e((string) $optionValue) ?>"
                                        <?= in_array((string) $optionValue, array_map('strval', (array) $current), true) ? 'checked' : '' ?>>
                                    <span><?= e($optionLabel) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($type === 'select'): ?>
                        <label class="label" for="filter-<?= e($key) ?>"><?= e($filter['label']) ?></label>
                        <select class="input" id="filter-<?= e($key) ?>" name="filters[<?= e($key) ?>]">
                            <?php foreach ((array) $filter['options'] as $optionValue => $optionLabel): ?>
                                <option value="<?= e((string) $optionValue) ?>"
                                    <?= (string) $current === (string) $optionValue ? 'selected' : '' ?>>
                                    <?= e($optionLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($type === 'bool'): ?>
                        <label class="checkbox">
                            <input type="checkbox" name="filters[<?= e($key) ?>]" value="1"
                                <?= $current !== null && $current !== '' ? 'checked' : '' ?>>
                            <span><?= e($filter['label']) ?></span>
                        </label>
                    <?php elseif ($type === 'date'): ?>
                        <label class="label" for="filter-<?= e($key) ?>"><?= e($filter['label']) ?></label>
                        <input class="input" type="date" id="filter-<?= e($key) ?>"
                               name="filters[<?= e($key) ?>]" value="<?= e((string) ($current ?? '')) ?>">
                    <?php elseif ($type === 'number'): ?>
                        <label class="label" for="filter-<?= e($key) ?>"><?= e($filter['label']) ?></label>
                        <input class="input" type="number" id="filter-<?= e($key) ?>" min="0" max="3650" step="1"
                               name="filters[<?= e($key) ?>]" value="<?= e((string) ($current ?? '')) ?>">
                    <?php else: ?>
                        <label class="label" for="filter-<?= e($key) ?>"><?= e($filter['label']) ?></label>
                        <input class="input" type="text" id="filter-<?= e($key) ?>" maxlength="120"
                               name="filters[<?= e($key) ?>]" value="<?= e((string) ($current ?? '')) ?>">
                    <?php endif; ?>

                    <?php if (!empty($filter['hint'])): ?>
                        <p class="field-hint"><?= e($filter['hint']) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <h2>Columns</h2>
            <p class="muted">
                Shown in the order below, on screen and in the CSV — not the order you tick them.
            </p>

            <?php if (isset($errors['columns'])): ?>
                <p class="field-error"><?= e($errors['columns']) ?></p>
            <?php endif; ?>

            <div class="check-grid">
                <?php foreach ($source->columns() as $columnKey => $column): ?>
                    <label class="checkbox">
                        <input type="checkbox" name="columns[]" value="<?= e($columnKey) ?>"
                            <?= in_array($columnKey, $savedColumns, true) ? 'checked' : '' ?>>
                        <span><?= e($column['label']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <h2>Order</h2>

            <div class="field-row">
                <div class="field">
                    <label class="label" for="sort_column">Sort by</label>
                    <select class="input<?= isset($errors['sort_column']) ? ' has-error' : '' ?>"
                            id="sort_column" name="sort_column">
                        <option value="">However the data comes back</option>
                        <?php foreach ($source->columns() as $columnKey => $column): ?>
                            <option value="<?= e($columnKey) ?>" <?= $value('sort_column') === $columnKey ? 'selected' : '' ?>>
                                <?= e($column['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">Must be one of the columns you ticked above.</p>
                    <?php if (isset($errors['sort_column'])): ?>
                        <p class="field-error"><?= e($errors['sort_column']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="label" for="sort_direction">Direction</label>
                    <select class="input" id="sort_direction" name="sort_direction">
                        <option value="asc" <?= $value('sort_direction', 'asc') === 'asc' ? 'selected' : '' ?>>
                            A→Z, earliest, smallest first
                        </option>
                        <option value="desc" <?= $value('sort_direction', 'asc') === 'desc' ? 'selected' : '' ?>>
                            Z→A, latest, largest first
                        </option>
                    </select>
                    <p class="field-hint">Rows with nothing in the sort column always come last.</p>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg"><?= $isEdit ? 'Save changes' : 'Create report' ?></button>
            <a class="btn btn-ghost" href="<?= e(url($isEdit ? '/reports/' . $definition['report_key'] : '/reports')) ?>">Cancel</a>
        </div>
    </form>

    <?php if ($isEdit): ?>
        <div class="card danger-card">
            <h2>Delete</h2>
            <p class="muted">
                Deletes the definition only. Nothing it reported on is touched — a report is a way of
                looking at the register, not part of it.
            </p>
            <form method="post" action="<?= e(url('/reports/custom/' . (int) $definition['id'] . '/delete')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger"
                        data-confirm="Delete the “<?= e($definition['name']) ?>” report?">
                    Delete this report
                </button>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>
