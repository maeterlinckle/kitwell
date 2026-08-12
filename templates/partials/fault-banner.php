<?php
/**
 * The fault an asset is currently faulty because of.
 *
 * Shown across the top of the asset page whenever the status is Faulty — the
 * same place, and the same shape, as the "on hire" banner and the PAT warning,
 * because they answer the same question: what is going on with this thing right
 * now, and what should I do about it?
 *
 * Rendered only for the *latest* report. Earlier ones are history, and history
 * lives on the history page.
 *
 * @var array<string,mixed>            $asset
 * @var array<string,mixed>|null       $fault   Latest fault_reports row
 * @var array<int,array<string,mixed>> $photos  Photos on that report
 */
$assetId = (int) $asset['id'];
$photos  = $photos ?? [];
?>
<div class="fault-banner">
    <div class="fault-banner-main">
        <span class="fault-banner-label">Faulty</span>

        <?php if ($fault === null): ?>
            <?php /* Status set by hand rather than through the form — editing
                     the asset, or an import. Worth saying so plainly: an
                     unexplained fault is the one most worth chasing, and
                     pretending there is a description would be worse. */ ?>
            <p class="fault-banner-description">No fault report on record.</p>
            <span class="fault-banner-detail">
                This asset's status was set to Faulty without a report being filed, so there is
                nothing on record about what is wrong with it.
            </span>
        <?php else: ?>
            <p class="badge-row">
                <span class="badge urgency-<?= e(strtolower((string) $fault['urgency'])) ?>">
                    <?= e($fault['urgency']) ?> urgency
                </span>
                <span class="badge badge-muted">
                    Noticed <?= e(format_date((string) $fault['faulty_on'])) ?>
                </span>
            </p>

            <p class="fault-banner-description"><?= e((string) $fault['description']) ?></p>

            <span class="fault-banner-detail">
                Reported by <?= e((string) $fault['reported_by_name']) ?>
                on <?= e(format_datetime((string) $fault['created_at'])) ?>
                · condition recorded as <?= e((string) $fault['condition_rating']) ?>
                · responsible:
                <?= partial('partials/assignee', \App\Models\Asset::responsibleParts($asset) + ['none' => 'nobody']) ?>
            </span>

            <?php if ($photos !== []): ?>
                <ul class="fault-thumbs">
                    <?php foreach ($photos as $photo): ?>
                        <li>
                            <a href="<?= e(url('/faults/' . (int) $fault['id'] . '/photos/' . (int) $photo['id'])) ?>"
                               target="_blank" rel="noopener">
                                <img src="<?= e(url('/faults/' . (int) $fault['id'] . '/photos/' . (int) $photo['id'])) ?>"
                                     alt="Photo of the reported fault" loading="lazy">
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="fault-banner-actions">
        <a class="btn btn-sm" href="<?= e(url('/assets/' . $assetId . '/faults')) ?>">Fault history</a>
        <?php if (can('maintenance.complete')): ?>
            <a class="btn btn-sm btn-primary" href="<?= e(url('/assets/' . $assetId . '/maintenance/log')) ?>">Record a repair</a>
        <?php endif; ?>
        <?php if (can('assets.edit')): ?>
            <?php /* The way back out. There is no "resolve this fault" button
                     because there is no separate open/closed flag to clear —
                     the asset stops being faulty when its status changes, and
                     that is the only place the rest of the application looks. */ ?>
            <a class="btn btn-sm" href="<?= e(url('/assets/' . $assetId . '/edit')) ?>">Change status</a>
        <?php endif; ?>
    </div>
</div>
