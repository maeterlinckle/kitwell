<?php
/**
 * The PAT status banner: the "at a glance" answer for one asset.
 *
 * @var array<string,mixed> $asset
 * @var array<string,mixed>|null $status
 * @var bool $actions       show the buttons (the PAT history page has its own)
 * @var bool $hideIfCurrent render nothing at all when the test is in date
 */
$actions       = (bool) ($actions ?? true);
$hideIfCurrent = (bool) ($hideIfCurrent ?? false);

$requiresPat = (int) $asset['requires_pat'] === 1;
$state       = $status['pat_status'] ?? ($requiresPat ? 'Never tested' : 'Not required');
$days        = $status['days_until_due'] ?? null;

/* A banner that says "everything is fine" is noise on a page that has a PAT
   section of its own; a banner only earns its place when it needs acting on.
   "Fine" means in date, not required, or retired — everything else, including
   a failed test, is something the person standing at the machine must see. */
if ($hideIfCurrent && in_array($state, ['Current', 'Not required', 'Retired'], true)) {
    return;
}

$tone = match ($state) {
    'Current'        => 'ok',
    'Due soon'       => 'warn',
    'Overdue',
    'Failed',
    'Never tested'   => 'danger',
    default          => 'muted',
};

$headline = match ($state) {
    'Current'        => 'In date',
    'Due soon'       => 'Retest due soon',
    'Overdue'        => 'Retest overdue',
    'Failed'         => 'Last test failed',
    'Never tested'   => 'Never tested',
    'No retest date' => 'No retest date set',
    'Retired'        => 'Asset retired',
    default          => 'PAT not required',
};
?>
<div class="pat-status pat-status-<?= e($tone) ?>">
    <div class="pat-status-main">
        <span class="pat-status-label">PAT</span>
        <span class="pat-status-headline"><?= e($headline) ?></span>

        <?php if ($requiresPat && !empty($status['retest_due_date'])): ?>
            <span class="pat-status-detail">
                Due <?= e(format_date($status['retest_due_date'])) ?>
                <?php if ($days !== null): ?>
                    <?php $d = (int) $days; ?>
                    (<?= $d < 0
                        ? abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' ago'
                        : ($d === 0 ? 'today' : 'in ' . (int) $d . ' day' . ($d === 1 ? '' : 's')) ?>)
                <?php endif; ?>
            </span>
        <?php endif; ?>

        <?php if ($requiresPat && !empty($status['test_date'])): ?>
            <span class="pat-status-detail muted">
                Last tested <?= e(format_date($status['test_date'])) ?>
                <?php if (!empty($status['appliance_class'])): ?>· <?= e($status['appliance_class']) ?><?php endif; ?>
                <?php if (!empty($status['pat_label_serial'])): ?>· label <span class="mono"><?= e($status['pat_label_serial']) ?></span><?php endif; ?>
            </span>
        <?php elseif ($requiresPat): ?>
            <span class="pat-status-detail muted">No test has been recorded for this asset.</span>
        <?php else: ?>
            <span class="pat-status-detail muted">
                This asset is not flagged as needing portable appliance testing.
                <?php if ((int) ($status['test_count'] ?? 0) > 0): ?>
                    It has <?= (int) $status['test_count'] ?> historic test record(s).
                <?php endif; ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($actions): ?>
    <div class="pat-status-actions">
        <?php if ($requiresPat && can('pat.manage')): ?>
            <a class="btn btn-sm btn-primary" href="<?= e(url('/pat/create?asset=' . $asset['id'])) ?>">Record test</a>
        <?php endif; ?>

        <?php if (can('pat.view') && (int) ($status['test_count'] ?? 0) > 0): ?>
            <a class="btn btn-sm" href="<?= e(url('/assets/' . $asset['id'] . '/pat')) ?>">
                History (<?= (int) $status['test_count'] ?>)
            </a>
        <?php endif; ?>

        <?php if (can('assets.edit')): ?>
            <form method="post" action="<?= e(url('/assets/' . $asset['id'] . '/pat/toggle')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-ghost"
                        data-confirm="<?= $requiresPat
                            ? 'Stop requiring PAT for this asset? Its test history is kept.'
                            : 'Flag this asset as requiring PAT testing?' ?>">
                    <?= $requiresPat ? 'Not required' : 'Requires PAT' ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
