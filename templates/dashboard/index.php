<?php
/**
 * At-a-glance counts, each linking through to the report behind it.
 *
 * @var array<string,mixed> $stats
 * @var array<int,array<string,mixed>> $activity
 */
$user = auth_user();

/**
 * One tile. $href is a report URL wherever a report covers the figure, so the
 * number and the detail behind it always come from the same query.
 */
$tile = static function (string $value, string $label, ?string $href, string $tone = ''): string {
    $classes = 'stat-card' . ($tone !== '' ? ' stat-' . $tone : '') . ($href !== null ? ' stat-link' : '');

    $inner = '<span class="stat-value">' . e($value) . '</span>'
        . '<span class="stat-label">' . e($label) . '</span>';

    return $href === null
        ? '<div class="' . $classes . '">' . $inner . '</div>'
        : '<a class="' . $classes . '" href="' . e(url($href)) . '">' . $inner . '</a>';
};

$canReport = can('reports.view');
?>
<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <p class="muted">Signed in as <?= e($user['name']) ?> — <?= e($user['role_name']) ?>.</p>
    </div>
    <?php if ($canReport): ?>
        <a class="btn" href="<?= e(url('/reports')) ?>">All reports</a>
    <?php endif; ?>
</div>

<?php /* The errands first, the figures second. Almost everyone arriving here is
         on their way to scan something or book something out; the counts are
         what you read when nothing in particular has brought you. */ ?>
<?php if (can('assets.view')): ?>
    <div class="card quick-actions">
        <h2>Quick actions</h2>
        <div class="quick-action-row">
            <a class="btn btn-primary btn-lg" href="<?= e(url('/scan')) ?>">Scan an asset</a>
            <?php if (can('hires.create')): ?>
                <a class="btn btn-lg" href="<?= e(url('/scan?mode=checkout')) ?>">Check out</a>
            <?php endif; ?>
            <?php if (can('hires.return')): ?>
                <a class="btn btn-lg" href="<?= e(url('/scan?mode=return')) ?>">Book in</a>
            <?php endif; ?>
            <?php if (can('assets.create')): ?>
                <a class="btn btn-lg" href="<?= e(url('/assets/create')) ?>">Add asset</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($stats['assets'])): ?>
    <h2 class="section-title">Assets</h2>
    <div class="stat-grid">
        <?= $tile(number_format((int) $stats['assets']['total']), 'Assets registered',
            $canReport ? '/reports/all-assets?type=top' : '/assets') ?>

        <?= $tile(number_format((int) $stats['assets']['sub_assets']), 'Sub-assets & accessories',
            $canReport ? '/reports/all-assets?type=sub' : '/assets?type=sub') ?>

        <?= $tile(number_format((int) $stats['assets']['in_stock']), 'In stock',
            $canReport ? '/reports/all-assets?status=In+Stock' : '/assets?status%5B%5D=In+Stock') ?>

        <?= $tile(number_format((int) $stats['assets']['on_hire']), 'On hire',
            can('hires.view') && $canReport ? '/reports/assets-on-hire' : '/assets?status%5B%5D=On+Hire',
            ((int) $stats['assets']['on_hire']) > 0 ? 'info' : '') ?>

        <?= $tile(number_format((int) $stats['assets']['in_maintenance']), 'In maintenance',
            $canReport ? '/reports/all-assets?status=In+Maintenance' : '/assets?status%5B%5D=In+Maintenance',
            ((int) $stats['assets']['in_maintenance']) > 0 ? 'warn' : '') ?>
    </div>
<?php endif; ?>

<?php if (isset($stats['maintenance']) || isset($stats['pat']) || isset($stats['hires'])): ?>
    <h2 class="section-title">Needs attention</h2>
    <div class="stat-grid">
        <?php if (isset($stats['maintenance'])): ?>
            <?= $tile((string) (int) $stats['maintenance']['overdue'], 'Maintenance overdue',
                $canReport ? '/reports/maintenance-due?window=overdue' : '/maintenance?status%5B%5D=Overdue',
                ((int) $stats['maintenance']['overdue']) > 0 ? 'danger' : '') ?>

            <?= $tile((string) (int) $stats['maintenance']['due_soon'],
                'Maintenance due in ' . (int) $stats['maintenance']['due_days'] . ' days',
                $canReport ? '/reports/maintenance-due?window=soon' : '/maintenance?status%5B%5D=Due+soon',
                ((int) $stats['maintenance']['due_soon']) > 0 ? 'warn' : '') ?>
        <?php endif; ?>

        <?php if (isset($stats['pat'])): ?>
            <?= $tile((string) (int) $stats['pat']['overdue'], 'PAT retest overdue',
                $canReport ? '/reports/pat-due?window=overdue' : '/pat?status%5B%5D=Overdue',
                ((int) $stats['pat']['overdue']) > 0 ? 'danger' : '') ?>

            <?= $tile((string) (int) $stats['pat']['due_soon'],
                'PAT due in ' . (int) $stats['pat']['due_days'] . ' days',
                $canReport ? '/reports/pat-due?window=soon' : '/pat?status%5B%5D=Due+soon',
                ((int) $stats['pat']['due_soon']) > 0 ? 'warn' : '') ?>

            <?= $tile((string) ((int) $stats['pat']['failed'] + (int) $stats['pat']['never_tested']),
                'PAT failed or never tested',
                $canReport ? '/reports/pat-due?window=attention' : '/pat?status%5B%5D=Failed',
                ((int) $stats['pat']['failed'] + (int) $stats['pat']['never_tested']) > 0 ? 'danger' : '') ?>
        <?php endif; ?>

        <?php if (isset($stats['hires'])): ?>
            <?= $tile((string) (int) $stats['hires']['out'], 'Items out on hire',
                $canReport ? '/reports/assets-on-hire' : '/hires?status%5B%5D=Out', 'info') ?>

            <?= $tile((string) (int) $stats['hires']['overdue'], 'Returns overdue',
                $canReport ? '/reports/hires-due-back?window=overdue' : '/hires?status%5B%5D=Overdue',
                ((int) $stats['hires']['overdue']) > 0 ? 'danger' : '') ?>

            <?= $tile((string) (int) $stats['hires']['due_soon'],
                'Due back within ' . (int) $stats['hires']['due_days'] . ' day' . ($stats['hires']['due_days'] === 1 ? '' : 's'),
                $canReport ? '/reports/hires-due-back?window=soon' : '/hires',
                ((int) $stats['hires']['due_soon']) > 0 ? 'warn' : '') ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($activity !== []): ?>
    <h2 class="section-title">Recent activity</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th scope="col">When</th>
                <th scope="col">Who</th>
                <th scope="col">Action</th>
                <th scope="col">Detail</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($activity as $entry): ?>
                <tr>
                    <td class="nowrap"><?= e(format_datetime($entry['created_at'])) ?></td>
                    <td><?= e($entry['user_name']) ?></td>
                    <td><span class="badge"><?= e($entry['action']) ?></span></td>
                    <td><?= e($entry['description']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
