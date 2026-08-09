<?php

use App\Reports\Report;

/**
 * @var array<string,array<string,Report>> $grouped
 */
$total = array_sum(array_map('count', $grouped));
?>
<div class="page-head">
    <div>
        <h1>Reports</h1>
        <p class="muted">
            <?= (int) $total ?> report<?= $total === 1 ? '' : 's' ?> available to you.
            Each can be filtered, printed, and exported to CSV.
        </p>
    </div>
</div>

<?php if ($grouped === []): ?>
    <div class="card empty-state">
        <h2>No reports available</h2>
        <p class="muted">Your role does not include access to any of the reports.</p>
    </div>
<?php else: ?>
    <?php foreach ($grouped as $group => $reports): ?>
        <h2 class="section-title"><?= e($group) ?></h2>
        <div class="card-grid">
            <?php foreach ($reports as $report): ?>
                <a class="card report-card" href="<?= e(url('/reports/' . $report->key())) ?>">
                    <h3><?= e($report->name()) ?></h3>
                    <p class="muted"><?= e($report->description()) ?></p>
                    <span class="report-card-go">Open report →</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
