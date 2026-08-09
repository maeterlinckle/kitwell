<?php
/**
 * Photo upload form. Shared by the asset detail page and the photo timeline.
 *
 * Two separate inputs on purpose: on a phone, "Take photo" opens the camera
 * straight away, while "Choose files" opens the gallery for photos taken
 * earlier. One combined input makes the phone ask every time.
 *
 * @var array<string,mixed> $asset
 */
$maxMb = (int) (config('uploads.max_photo_bytes') / 1048576);
?>
<form method="post" action="<?= e(url('/assets/' . $asset['id'] . '/photos')) ?>"
      enctype="multipart/form-data" class="upload-form photo-upload"
      data-photo-form data-max-bytes="<?= (int) config('uploads.max_photo_bytes') ?>">
    <?= csrf_field() ?>

    <div class="photo-inputs">
        <label class="btn btn-primary btn-file">
            <span>Take photo</span>
            <input type="file" name="photos[]" accept="image/*" capture="environment" multiple
                   data-photo-input hidden>
        </label>

        <label class="btn btn-file">
            <span>Choose files</span>
            <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,image/*"
                   multiple data-photo-input hidden>
        </label>
    </div>

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
