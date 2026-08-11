<?php

use App\Core\Upload;
use App\Models\MaintenanceLog;

/**
 * Everything attached to one maintenance completion: the photos taken at the
 * time, and the paperwork that came with it.
 *
 * Both are optional and most records have neither, so this renders nothing at
 * all rather than an empty heading.
 *
 * @var array<string,mixed> $log
 */
$logId     = (int) $log['id'];
$photos    = MaintenanceLog::photos($logId);
$documents = MaintenanceLog::documents($logId);

if ($photos === [] && $documents === []) {
    return;
}
?>
<?php if ($photos !== []): ?>
    <ul class="photo-grid photo-grid-compact">
        <?php foreach ($photos as $photo): ?>
            <?php $src = url('/maintenance/logs/' . $logId . '/photos/' . $photo['id']); ?>
            <li class="photo-tile">
                <a class="photo-link" href="<?= e($src) ?>"
                   data-lightbox
                   data-caption="<?= e($photo['caption'] ?? '') ?>"
                   data-meta="<?= e(format_date($log['performed_on']) . ' · ' . ($photo['uploaded_by_name'] ?? 'unknown')) ?>">
                    <img src="<?= e($src) ?>" alt="Photo from maintenance on <?= e(format_date($log['performed_on'])) ?>"
                         loading="lazy" decoding="async">
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($documents !== []): ?>
    <ul class="file-list file-list-compact">
        <?php foreach ($documents as $document): ?>
            <?php $href = url('/maintenance/logs/' . $logId . '/documents/' . $document['id']); ?>
            <li class="file-item">
                <span class="file-icon" aria-hidden="true">PDF</span>
                <span class="file-body">
                    <a class="file-title" href="<?= e($href) ?>" target="_blank" rel="noopener">
                        <?= e($document['title']) ?>
                    </a>
                    <span class="file-meta muted">
                        <?= e(Upload::formatBytes((int) $document['file_size_bytes'])) ?>
                        · attached <?= e(format_date($document['created_at'])) ?>
                        <?php if (!empty($document['uploaded_by_name'])): ?>by <?= e($document['uploaded_by_name']) ?><?php endif; ?>
                    </span>
                </span>
                <span class="file-actions">
                    <a class="btn btn-sm" href="<?= e($href . '?download=1') ?>">Download</a>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
