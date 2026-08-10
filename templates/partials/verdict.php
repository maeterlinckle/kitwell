<?php
/**
 * A pass / fail choice.
 *
 * Radio inputs, so it works with no JavaScript, is reachable from a keyboard
 * and is announced properly by a screen reader. Styled as two large targets —
 * this gets tapped on a phone, in a workshop, sometimes with gloves on.
 *
 * A partial rather than a closure returning a string: everything printed here
 * goes through e(), which tests/escape-audit.php can see and prove.
 *
 * @var string $name     field name, e.g. visual_plug_pass
 * @var string $current  the currently chosen value ('1', '0' or '')
 * @var string $error    validation message for this field, if any
 */
$name    = (string) ($name ?? '');
$current = (string) ($current ?? '');
$error   = (string) ($error ?? '');

if ($name === '') {
    return;
}
?>
<div class="verdict" role="group">
    <?php foreach ([['1', 'Pass', 'verdict-pass'], ['0', 'Fail', 'verdict-fail']] as [$val, $label, $cls]): ?>
        <input type="radio" class="verdict-input"
               id="<?= e($name . '_' . $val) ?>"
               name="<?= e($name) ?>"
               value="<?= e($val) ?>"
               <?= $current === $val ? 'checked' : '' ?>>
        <label class="verdict-label <?= e($cls) ?>" for="<?= e($name . '_' . $val) ?>"><?= e($label) ?></label>
    <?php endforeach; ?>
</div>
<?php if ($error !== ''): ?>
    <p class="field-error"><?= e($error) ?></p>
<?php endif; ?>
