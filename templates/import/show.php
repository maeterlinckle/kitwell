<?php

use App\Imports\Importer;

/**
 * Upload form plus the documented column format.
 *
 * @var Importer $importer
 * @var int $maxMb
 * @var int $maxRows
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('/import')) ?>">Import</a></p>
        <h1><?= e($importer->name()) ?></h1>
        <p class="muted"><?= e($importer->description()) ?></p>
    </div>
    <a class="btn" href="<?= e(url('/import/' . $importer->key() . '/template')) ?>">Download template</a>
</div>

<?php if ($importer->notes() !== []): ?>
    <div class="card notice-card">
        <h2>Before you start</h2>
        <ul class="import-notes">
            <?php foreach ($importer->notes() as $note): ?>
                <li><?= e($note) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/import/' . $importer->key() . '/preview')) ?>"
      enctype="multipart/form-data" class="form form-wide card" novalidate>
    <?= csrf_field() ?>

    <h2>Upload your file</h2>

    <div class="field">
        <label class="label" for="file">CSV file</label>
        <input class="input" type="file" id="file" name="file" accept=".csv,text/csv,text/plain" required>
        <p class="field-hint">
            Up to <?= (int) $maxMb ?> MB and <?= number_format($maxRows) ?> rows.
            Commas, semicolons and tabs are all understood, as is a file saved from Excel.
        </p>
    </div>

    <?php foreach ($importer->optionDefinitions() as $key => $option): ?>
        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="<?= e($key) ?>" value="1" <?= !empty($option['default']) ? 'checked' : '' ?>>
                <span>
                    <?= e($option['label']) ?>
                    <?php if (!empty($option['description'])): ?>
                        <span class="field-hint"><?= e($option['description']) ?></span>
                    <?php endif; ?>
                </span>
            </label>
        </div>
    <?php endforeach; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Check the file</button>
        <a class="btn btn-ghost" href="<?= e(url('/import')) ?>">Cancel</a>
    </div>
</form>

<div class="card">
    <h2>Column format</h2>
    <p class="muted">
        Headings are matched loosely — case, spaces and underscores are ignored, and the
        alternatives listed below are all accepted. Column order does not matter, and any
        column you do not need can be left out entirely.
    </p>

    <div class="table-wrap">
        <table class="table table-compact">
            <thead>
            <tr>
                <th scope="col">Column</th>
                <th scope="col">Required</th>
                <th scope="col">Notes</th>
                <th scope="col">Also accepted as</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($importer->columns() as $key => $definition): ?>
                <tr>
                    <th scope="row"><?= e($definition['label']) ?></th>
                    <td>
                        <?php if (!empty($definition['ignore'])): ?>
                            <span class="badge badge-muted">Ignored</span>
                        <?php elseif (!empty($definition['required'])): ?>
                            <span class="badge badge-warn">Required</span>
                        <?php else: ?>
                            <span class="muted">Optional</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($definition['description'] ?? '') ?></td>
                    <td class="muted">
                        <?= e(implode(', ', (array) ($definition['aliases'] ?? []))) ?: '—' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
