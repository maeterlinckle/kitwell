<?php

use App\Models\MaintenanceLog;

/**
 * Photos attached to one maintenance completion.
 *
 * @var array<string,mixed> $log
 */
$photos = MaintenanceLog::photos((int) $log['id']);

if ($photos === []) {
    return;
}
?>
<ul class="photo-grid photo-grid-compact">
    <?php foreach ($photos as $photo): ?>
        <?php $src = url('/maintenance/logs/' . $log['id'] . '/photos/' . $photo['id']); ?>
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
