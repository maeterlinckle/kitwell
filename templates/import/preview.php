<?php

use App\Core\CsvReader;
use App\Imports\Importer;

/**
 * The preview: exactly what would happen, before anything is written.
 *
 * @var Importer $importer
 * @var string $filename
 * @var array<string,mixed> $options
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,int> $counts
 * @var CsvReader|null $reader
 */
$ok       = $counts[Importer::STATUS_OK] ?? 0;
$warned   = $counts[Importer::STATUS_WARNING] ?? 0;
$errored  = $counts[Importer::STATUS_ERROR] ?? 0;
$willAdd  = $ok + $warned;
$columns  = $importer->previewColumns();
$labels   = $importer->columns();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('/import/' . $importer->key())) ?>">Import <?= e(strtolower($importer->name())) ?></a></p>
        <h1>Preview</h1>
        <p class="muted"><?= e($filename) ?> · <?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?> read</p>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card <?= $willAdd > 0 ? 'stat-info' : '' ?>">
        <span class="stat-value"><?= (int) $willAdd ?></span>
        <span class="stat-label">Will be imported</span>
    </div>
    <div class="stat-card <?= $warned > 0 ? 'stat-warn' : '' ?>">
        <span class="stat-value"><?= (int) $warned ?></span>
        <span class="stat-label">With warnings</span>
    </div>
    <div class="stat-card <?= $errored > 0 ? 'stat-danger' : '' ?>">
        <span class="stat-value"><?= (int) $errored ?></span>
        <span class="stat-label">Will be skipped</span>
    </div>
</div>

<?php if ($reader !== null && $reader->unknownHeadings() !== []): ?>
    <div class="flash flash-warning">
        <span class="flash-text">
            Column<?= count($reader->unknownHeadings()) === 1 ? '' : 's' ?> not recognised and ignored:
            <strong><?= e(implode(', ', $reader->unknownHeadings())) ?></strong>.
            Check the spelling if that is not what you expected.
        </span>
    </div>
<?php endif; ?>

<?php if ($reader !== null && $reader->wasTruncated()): ?>
    <div class="flash flash-warning">
        <span class="flash-text">
            Only the first <?= number_format(CsvReader::MAX_ROWS) ?> rows were read. Split the file and import the rest separately.
        </span>
    </div>
<?php endif; ?>

<?php if ($errored > 0): ?>
    <div class="flash flash-error">
        <span class="flash-text">
            <?= (int) $errored ?> row<?= $errored === 1 ? '' : 's' ?> will be skipped. The rest will still be imported —
            fix those rows and upload them again afterwards if you need to.
        </span>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/import/' . $importer->key() . '/commit')) ?>" class="card">
    <?= csrf_field() ?>

    <div class="preview-actions">
        <div>
            <h2>Ready to import <?= (int) $willAdd ?> row<?= $willAdd === 1 ? '' : 's' ?></h2>
            <p class="muted">Nothing has been written yet.</p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= e(url('/import/' . $importer->key())) ?>">Start again</a>
            <button type="submit" class="btn btn-primary btn-lg" <?= $willAdd === 0 ? 'disabled' : '' ?>
                    data-confirm="Import <?= (int) $willAdd ?> row(s)?">
                Import <?= (int) $willAdd ?> row<?= $willAdd === 1 ? '' : 's' ?>
            </button>
        </div>
    </div>
</form>

<div class="table-wrap">
    <table class="table table-compact table-preview">
        <thead>
        <tr>
            <th scope="col">Line</th>
            <th scope="col">Status</th>
            <?php foreach ($columns as $column): ?>
                <th scope="col"><?= e($labels[$column]['label'] ?? $column) ?></th>
            <?php endforeach; ?>
            <th scope="col">Notes</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr class="preview-<?= e($row['status']) ?>">
                <td class="mono"><?= e($row['line']) ?></td>
                <td>
                    <?php if ($row['status'] === Importer::STATUS_ERROR): ?>
                        <span class="badge badge-danger">Skip</span>
                    <?php elseif ($row['status'] === Importer::STATUS_WARNING): ?>
                        <span class="badge badge-warn">Import</span>
                    <?php else: ?>
                        <span class="badge badge-ok">Import</span>
                    <?php endif; ?>
                </td>

                <?php foreach ($columns as $column): ?>
                    <?php
                    // Show the value that would be stored where we have one,
                    // otherwise what was in the file.
                    $value = $row['data'][$column]
                        ?? $row['data'][$column . '_id']
                        ?? $row['raw'][$column]
                        ?? '';

                    if (is_bool($value)) {
                        $value = $value ? 'Yes' : 'No';
                    }

                    if ($column === 'requires_pat') {
                        $value = ((int) ($row['data']['requires_pat'] ?? 0) === 1) ? 'Yes' : 'No';
                    }
                    ?>
                    <td><?= e(str_limit((string) $value, 40)) ?></td>
                <?php endforeach; ?>

                <td class="preview-notes">
                    <?php foreach ($row['errors'] as $message): ?>
                        <span class="preview-error"><?= e($message) ?></span>
                    <?php endforeach; ?>
                    <?php foreach ($row['warnings'] as $message): ?>
                        <span class="preview-warning"><?= e($message) ?></span>
                    <?php endforeach; ?>
                    <?php if ($row['errors'] === [] && $row['warnings'] === []): ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
