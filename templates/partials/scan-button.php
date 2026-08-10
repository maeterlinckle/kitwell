<?php
/**
 * A camera scan button attached to a barcode / asset-tag field.
 *
 * One line wherever a user would type or paste a tag:
 *
 *     <?= partial('partials/scan-button', ['target' => 'asset_tag']) ?>
 *     <?= partial('partials/scan-button', ['target' => 'q', 'submit' => true]) ?>
 *
 * Wrap the input and this button in <div class="input-with-scan"> so they sit
 * together. The behaviour lives in public/js/scanner.js, which reuses the one
 * Code 128 decoder rather than shipping a second.
 *
 * @var string $target  id of the input to fill
 * @var bool   $submit  submit the field's form after a successful scan
 * @var string $label   visible button text
 */

$target = (string) ($target ?? '');
$submit = (bool) ($submit ?? false);
$label  = (string) ($label ?? 'Scan');

if ($target === '') {
    return;
}

// scanner.js is not in the base layout — most pages have no barcode field and
// should not carry the decoder. Emit it once per response, on demand.
$needsScript = empty($GLOBALS['__scan_button_script']);
$GLOBALS['__scan_button_script'] = true;
?>
<?php if ($needsScript): ?>
    <script src="<?= e(asset_url('js/scanner.js')) ?>" defer></script>
<?php endif; ?>
<?php /* Hidden until scanner.js enables it: without JavaScript there is no
         camera, and a dead button is worse than no button. Typing the tag and
         USB scanners both work regardless. */ ?>
<button type="button" class="btn btn-scan" data-scan-for="<?= e($target) ?>"
        <?= $submit ? 'data-scan-submit="1"' : '' ?>
        hidden title="Scan a barcode with the camera"
        aria-label="Scan a barcode into this field">
    <svg class="btn-scan-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"
         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/>
        <path d="M7 8v8M10 8v8M13.5 8v8M17 8v8"/>
    </svg>
    <span><?= e($label) ?></span>
</button>
