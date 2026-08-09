<?php

use App\Imports\Importer;

/**
 * @var array<string,Importer> $importers
 */
?>
<div class="page-head">
    <div>
        <h1>Import data</h1>
        <p class="muted">Bring existing records in from a spreadsheet. Nothing is written until you have seen a preview.</p>
    </div>
    <?php if (can('assets.export')): ?>
        <a class="btn btn-ghost" href="<?= e(url('/assets')) ?>">Export instead</a>
    <?php endif; ?>
</div>

<div class="card-grid">
    <?php foreach ($importers as $importer): ?>
        <a class="card report-card" href="<?= e(url('/import/' . $importer->key())) ?>">
            <h3><?= e($importer->name()) ?></h3>
            <p class="muted"><?= e($importer->description()) ?></p>
            <span class="report-card-go">Start import →</span>
        </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <h2>How importing works</h2>
    <ol class="import-steps">
        <li><strong>Download the template</strong> for what you are importing, and fill it in — or use your own spreadsheet, as long as the headings match.</li>
        <li><strong>Upload it.</strong> Every row is checked and you get a preview showing exactly what would happen.</li>
        <li><strong>Confirm.</strong> Good rows are created; rows with an error are skipped and listed, so one bad line never stops the rest.</li>
    </ol>
    <p class="muted">Every import is recorded in the activity log: who ran it, which file, and how many rows.</p>
</div>
