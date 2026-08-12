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
    <?php if (can('reports.manage')): ?>
        <a class="btn btn-primary" href="<?= e(url('/reports/custom/create')) ?>">New report</a>
    <?php endif; ?>
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
                <?php /* A saved report is an ordinary card, with one extra line
                         saying it was somebody's idea rather than shipped —
                         which matters when its numbers disagree with a
                         built-in and you are working out why. */ ?>
                <a class="card report-card" href="<?= e(url('/reports/' . $report->key())) ?>">
                    <h3><?= e($report->name()) ?></h3>
                    <p class="muted"><?= e($report->description()) ?></p>
                    <?php if ($report->isCustom()): ?>
                        <span class="badge badge-muted">Saved report</span>
                    <?php endif; ?>
                    <span class="report-card-go">Open report →</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (can('reports.manage')): ?>
    <div class="card">
        <h2>Saved reports</h2>
        <p class="muted">
            A saved report is a question you have asked before: a data source, the filters you would
            have set by hand, and the columns you actually wanted. It opens, prints and exports
            exactly like a built-in one, and shows only what your role already lets you see.
        </p>
        <div class="form-actions">
            <a class="btn" href="<?= e(url('/reports/custom/create')) ?>">Create a report</a>
        </div>
    </div>
<?php endif; ?>
