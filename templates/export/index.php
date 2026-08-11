<?php
/**
 * The export hub. Shaped like /import on purpose — the two are the same job in
 * opposite directions, and a person looking for one will look for the other in
 * the same place.
 *
 * @var bool $canAssets
 * @var array<string,\App\Reports\Report> $reports
 */
?>
<div class="page-head">
    <div>
        <h1>Export data</h1>
        <p class="muted">Everything that can leave this system as a file, in one place.</p>
    </div>
    <div class="head-actions">
        <?php if (can('assets.create')): ?>
            <a class="btn btn-ghost" href="<?= e(url('/import')) ?>">Import instead</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$canAssets && $reports === []): ?>
    <div class="card empty-state">
        <h2>Nothing to export</h2>
        <p class="muted">Your role does not include exporting the register or viewing reports.</p>
    </div>
<?php else: ?>
    <div class="card-grid">
        <?php if ($canAssets): ?>
            <a class="card report-card" href="<?= e(url('/export/assets')) ?>">
                <h3>Asset register</h3>
                <p class="muted">
                    Every asset, or a filtered or hand-picked subset, with optional PAT, hire and
                    maintenance columns. The same shape the importer accepts.
                </p>
                <span class="report-card-go">Start export →</span>
            </a>
        <?php endif; ?>

        <?php foreach ($reports as $report): ?>
            <a class="card report-card" href="<?= e(url('/reports/' . $report->key())) ?>">
                <h3><?= e($report->name()) ?></h3>
                <p class="muted"><?= e($report->description()) ?></p>
                <span class="report-card-go">Open report →</span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2>How exporting works</h2>

    <ul class="import-notes">
        <li>
            <strong>Everything comes out as CSV</strong>, which opens in Excel, Numbers,
            LibreOffice and Google Sheets without any conversion.
        </li>
        <li>
            <strong>The register export matches the import format.</strong> The core columns are the
            ones the importer reads, so a file can be exported, edited in a spreadsheet and fed
            straight back in. The optional extra columns — latest PAT, current hire, next
            maintenance — are appended after them and are ignored on re-import, because they are
            derived from other records rather than fields of the asset.
        </li>
        <li>
            <strong>A report exports what it is showing.</strong> Set the report's filters first,
            then use its Download CSV button: the file is the rows on screen, not the whole table.
        </li>
        <li>
            <strong>Every export is recorded</strong> in the activity log with who ran it, when, and
            how many rows left the system.
        </li>
        <li>
            <strong>Exports contain personal data.</strong> Hirer names and contact details appear in
            hire columns and hire reports, so treat the file the way you would treat the register
            itself.
        </li>
    </ul>
</div>
