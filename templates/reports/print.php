<?php

use App\Models\Setting;
use App\Reports\Report;

/**
 * Print view of a report: no filters, no navigation, just the figures and the
 * table, with a header saying what this is and when it was produced.
 *
 * @var Report $report
 * @var array<int,array<string,mixed>> $rows
 * @var array<int,array{label:string,value:string|int,tone?:string}> $summary
 * @var string $subtitle
 */
$organisation = Setting::get('organisation_name', '');
?>
<div class="report-print">
    <header class="report-print-head">
        <?php if ($organisation !== null && $organisation !== ''): ?>
            <p class="report-print-org"><?= e($organisation) ?></p>
        <?php endif; ?>
        <h1><?= e($report->name()) ?></h1>
        <p class="muted"><?= e($subtitle) ?></p>
        <p class="muted report-print-meta">
            Produced <?= e(format_datetime(date('Y-m-d H:i:s'))) ?>
            by <?= e(auth_user()['name'] ?? '') ?>
        </p>
    </header>

    <?php if ($summary !== []): ?>
        <ul class="report-print-summary">
            <?php foreach ($summary as $item): ?>
                <li><strong><?= e((string) $item['value']) ?></strong> <?= e($item['label']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <p><?= e($report->emptyMessage()) ?></p>
    <?php else: ?>
        <?= partial('partials/report-table', ['report' => $report, 'rows' => $rows, 'linked' => false]) ?>
    <?php endif; ?>
</div>
