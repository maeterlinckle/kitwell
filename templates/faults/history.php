<?php

use App\Models\Asset;

/**
 * Every fault ever reported on one asset.
 *
 * The asset page carries the current fault; this is the rest, in the same
 * spirit as the PAT history page. An item that has been reported faulty four
 * times in a year is worth retiring, and that is only visible from here.
 *
 * @var array<string,mixed>                  $asset
 * @var array<int,array<string,mixed>>       $reports
 * @var array<int,array<int,array<string,mixed>>> $photos  Keyed by report id
 */
$id = (int) $asset['id'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow mono"><?= e($asset['asset_tag']) ?></p>
        <h1>Fault history</h1>
        <p class="muted">
            <?= e($asset['name']) ?> —
            <?= count($reports) === 1 ? '1 report' : count($reports) . ' reports' ?> on record.
        </p>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e(url('/assets/' . $id)) ?>">Back to asset</a>
        <?php if (can('faults.report') && $asset['status'] !== 'Retired'): ?>
            <a class="btn btn-danger" href="<?= e(url('/assets/' . $id . '/faults/report')) ?>">Report a fault</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Reports</h2>
        <span class="badge status-<?= e(strtolower(str_replace(' ', '-', (string) $asset['status']))) ?>">
            <?= e($asset['status']) ?>
        </span>
    </div>

    <?php if ($reports === []): ?>
        <p class="empty">No fault has ever been reported on this asset.</p>
    <?php else: ?>
        <?php foreach ($reports as $report): ?>
            <?php $reportId = (int) $report['id']; ?>
            <div class="fault-entry">
                <p class="badge-row">
                    <span class="badge urgency-<?= e(strtolower((string) $report['urgency'])) ?>">
                        <?= e($report['urgency']) ?>
                    </span>
                    <span class="badge badge-muted">Noticed <?= e(format_date((string) $report['faulty_on'])) ?></span>
                    <span class="badge condition-<?= e(strtolower(str_replace(' ', '-', (string) $report['condition_rating']))) ?>">
                        <?= e($report['condition_rating']) ?>
                    </span>
                </p>

                <p class="fault-entry-text"><?= e((string) $report['description']) ?></p>

                <p class="cell-sub muted">
                    Reported by <?= e((string) $report['reported_by_name']) ?>
                    on <?= e(format_datetime((string) $report['created_at'])) ?>
                </p>

                <?php if (($photos[$reportId] ?? []) !== []): ?>
                    <ul class="fault-thumbs">
                        <?php foreach ($photos[$reportId] as $photo): ?>
                            <li>
                                <a href="<?= e(url('/faults/' . $reportId . '/photos/' . (int) $photo['id'])) ?>"
                                   target="_blank" rel="noopener">
                                    <img src="<?= e(url('/faults/' . $reportId . '/photos/' . (int) $photo['id'])) ?>"
                                         alt="Photo of the fault reported on <?= e(format_date((string) $report['faulty_on'])) ?>"
                                         loading="lazy">
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Who is responsible</h2>
    <p>
        <?= partial('partials/assignee', Asset::responsibleParts($asset) + ['none' => 'Unassigned']) ?>
        —
        <?php if (empty($asset['responsible_user_id']) && empty($asset['responsible_team_id'])): ?>
            no email goes out when this asset is reported faulty.
        <?php else: ?>
            emailed immediately on each report, and again in the faulty-asset digest while it
            stays faulty.
        <?php endif; ?>
        <?php if (can('assets.edit')): ?>
            <a href="<?= e(url('/assets/' . $id . '/edit')) ?>">Change it</a>.
        <?php endif; ?>
    </p>
</div>
