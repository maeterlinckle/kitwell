<?php
/**
 * The camera/gallery input pair.
 *
 * Two separate inputs on purpose: on a phone, "Take photo" opens the camera
 * straight away, while "Choose files" opens the gallery for photos taken
 * earlier. One combined input makes the phone ask every time.
 *
 * Extracted from the asset photo form so the maintenance evidence section is
 * literally the same control rather than a second one that behaves almost the
 * same. The behaviour — preview thumbnails, per-file size against the server's
 * own limit, and clearing whichever input was not used — comes from the
 * [data-photo-input] handler in app.js, which needs [data-photo-form] and
 * data-max-bytes on the surrounding form.
 *
 * @var string $name        Field name, e.g. 'photos[]'
 * @var bool   $primary     Style the camera button as the primary action
 */
$name    = (string) ($name ?? 'photos[]');
$primary = (bool) ($primary ?? true);
?>
<div class="photo-inputs">
    <label class="btn btn-file<?= $primary ? ' btn-primary' : '' ?>">
        <span>Take photo</span>
        <input type="file" name="<?= e($name) ?>" accept="image/*" capture="environment" multiple
               data-photo-input hidden>
    </label>

    <label class="btn btn-file">
        <span>Choose files</span>
        <input type="file" name="<?= e($name) ?>"
               accept="image/jpeg,image/png,image/webp,image/heic,image/heif,image/*"
               multiple data-photo-input hidden>
    </label>
</div>
