<?php
/**
 * One recorded pass/fail verdict, for reading back a test.
 *
 * NULL is "not recorded", which is genuinely different from a fail: tests
 * imported from a spreadsheet, and everything predating the guided flow, never
 * captured a per-check verdict. Showing those as "Fail" would be a lie.
 *
 * @var mixed $value  1, 0, or null
 */
$value = $value ?? null;
?>
<?php if ($value === null || $value === ''): ?>
    <span class="muted">not recorded</span>
<?php elseif ((int) $value === 1): ?>
    <span class="reading-pass">Pass</span>
<?php else: ?>
    <span class="reading-fail">Fail</span>
<?php endif; ?>
