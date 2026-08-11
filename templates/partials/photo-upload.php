<?php
/**
 * Photo upload form. Shared by the asset detail page and the photo timeline.
 *
 * The camera/gallery pair itself lives in partials/photo-inputs, because the
 * maintenance evidence section uses the same control.
 *
 * @var array<string,mixed> $asset
 */
$maxMb = (int) (config('uploads.max_photo_bytes') / 1048576);
?>
<form method="post" action="<?= e(url('/assets/' . $asset['id'] . '/photos')) ?>"
      enctype="multipart/form-data" class="upload-form photo-upload"
      data-photo-form data-max-bytes="<?= (int) config('uploads.max_photo_bytes') ?>">
    <?= csrf_field() ?>

    <?= partial('partials/photo-inputs', ['name' => 'photos[]', 'primary' => true]) ?>

    <p class="field-hint">
        JPEG, PNG or WebP (and HEIC from an iPhone), up to <?= (int) $maxMb ?> MB each.
        Large photos are rotated the right way up and scaled down automatically.
    </p>

    <div class="photo-preview" data-photo-preview hidden></div>

    <div class="field-row photo-meta" data-photo-meta hidden>
        <div class="field">
            <label class="label" for="caption">Caption <span class="optional">(optional)</span></label>
            <input class="input" type="text" id="caption" name="caption" maxlength="255"
                   placeholder="e.g. Chipped guard, cable fraying at the plug">
        </div>

        <div class="field">
            <label class="label" for="taken_on">Date taken <span class="optional">(optional)</span></label>
            <input class="input" type="date" id="taken_on" name="taken_on" max="<?= e(date('Y-m-d')) ?>">
            <p class="field-hint">Leave blank to use the camera's date, or today.</p>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary" data-photo-submit hidden>Upload photos</button>
    </div>
</form>
