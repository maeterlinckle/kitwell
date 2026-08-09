<?php
/**
 * Grid of condition photos, newest first.
 *
 * @var array<int,array<string,mixed>> $photos
 * @var array<string,mixed> $asset
 * @var bool $showActions
 */
$showActions = $showActions ?? false;
?>
<ul class="photo-grid">
    <?php foreach ($photos as $photo): ?>
        <?php
        $src   = url('/assets/' . $asset['id'] . '/photos/' . $photo['id'] . '?size=thumb');
        $full  = url('/assets/' . $asset['id'] . '/photos/' . $photo['id']);
        $when  = format_date($photo['recorded_at']);
        $alt   = $photo['caption'] !== null && $photo['caption'] !== ''
            ? (string) $photo['caption']
            : 'Condition photo of ' . $asset['name'] . ' taken ' . $when;
        ?>
        <li class="photo-tile <?= (int) $photo['is_primary'] === 1 ? 'is-primary' : '' ?>">
            <a class="photo-link" href="<?= e($full) ?>"
               data-lightbox
               data-caption="<?= e($photo['caption'] ?? '') ?>"
               data-meta="<?= e($when . ' · ' . ($photo['uploaded_by_name'] ?? 'unknown')) ?>">
                <img src="<?= e($src) ?>" alt="<?= e($alt) ?>" loading="lazy" decoding="async"
                    <?= $photo['width_px'] !== null ? 'width="' . (int) $photo['width_px'] . '" height="' . (int) $photo['height_px'] . '"' : '' ?>>
                <?php if ((int) $photo['is_primary'] === 1): ?>
                    <span class="photo-flag">Main</span>
                <?php endif; ?>
            </a>

            <div class="photo-caption">
                <span class="photo-date"><?= e($when) ?></span>
                <?php if (!empty($photo['caption'])): ?>
                    <span class="photo-text"><?= e($photo['caption']) ?></span>
                <?php endif; ?>
                <span class="photo-by muted"><?= e($photo['uploaded_by_name'] ?? 'Unknown') ?></span>
            </div>

            <?php if ($showActions): ?>
                <div class="photo-actions">
                    <?php if (can('media.photo.upload') && (int) $photo['is_primary'] !== 1): ?>
                        <form method="post" action="<?= e(url('/assets/' . $asset['id'] . '/photos/' . $photo['id'] . '/primary')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-ghost">Set as main</button>
                        </form>
                    <?php endif; ?>

                    <?php if (can('media.photo.delete')): ?>
                        <form method="post" action="<?= e(url('/assets/' . $asset['id'] . '/photos/' . $photo['id'] . '/delete')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-ghost"
                                    data-confirm="Delete this photo? The file is removed from the server.">Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
