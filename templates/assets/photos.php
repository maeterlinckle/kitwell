<?php
/**
 * Full photo history for one asset, grouped by month, newest first.
 *
 * @var array<string,mixed> $asset
 * @var array<int,array<string,mixed>> $photos
 * @var array<string,array<int,array<string,mixed>>> $byMonth
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow mono"><?= e($asset['asset_tag']) ?></p>
        <h1>Condition photos</h1>
        <p class="muted">
            <?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?> of <?= e($asset['name']) ?><?php
            if ($photos !== []) {
                $oldest = end($photos);
                echo ', from ' . e(format_date($oldest['recorded_at'])) . ' to ' . e(format_date($photos[0]['recorded_at']));
            }
            ?>.
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/assets/' . $asset['id'])) ?>">Back to asset</a>
</div>

<?php if (can('media.photo.upload')): ?>
    <div class="card">
        <h2>Add photos</h2>
        <?= partial('partials/photo-upload', ['asset' => $asset]) ?>
    </div>
<?php endif; ?>

<?php if ($photos === []): ?>
    <div class="card empty-state">
        <h2>No photos yet</h2>
        <p class="muted">
            Photos added here build a dated record of this asset's condition — useful when
            something comes back from a hire in a different state than it went out.
        </p>
    </div>
<?php else: ?>
    <?php foreach ($byMonth as $month => $monthPhotos): ?>
        <h2 class="section-title"><?= e($month) ?> <span class="count-pill"><?= count($monthPhotos) ?></span></h2>
        <?= partial('partials/photo-gallery', [
            'asset'       => $asset,
            'photos'      => $monthPhotos,
            'showActions' => true,
        ]) ?>
    <?php endforeach; ?>

    <?php if (can('media.photo.upload')): ?>
        <div class="card">
            <h2>Edit captions and dates</h2>
            <p class="muted">Correct a caption, or set the date a photo actually represents if it differs from when it was uploaded.</p>

            <div class="photo-edit-list">
                <?php foreach ($photos as $photo): ?>
                    <form method="post" action="<?= e(url('/assets/' . $asset['id'] . '/photos/' . $photo['id'])) ?>" class="photo-edit-row">
                        <?= csrf_field() ?>

                        <img class="photo-edit-thumb"
                             src="<?= e(url('/assets/' . $asset['id'] . '/photos/' . $photo['id'] . '?size=thumb')) ?>"
                             alt="" loading="lazy" decoding="async">

                        <div class="field">
                            <label class="label" for="caption-<?= (int) $photo['id'] ?>">Caption</label>
                            <input class="input" type="text" id="caption-<?= (int) $photo['id'] ?>" name="caption"
                                   maxlength="255" value="<?= e($photo['caption']) ?>">
                        </div>

                        <div class="field">
                            <label class="label" for="taken-<?= (int) $photo['id'] ?>">Date taken</label>
                            <input class="input" type="date" id="taken-<?= (int) $photo['id'] ?>" name="taken_on"
                                   max="<?= e(date('Y-m-d')) ?>"
                                   value="<?= e(date('Y-m-d', (int) strtotime((string) $photo['recorded_at']))) ?>">
                        </div>

                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
