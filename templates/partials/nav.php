<?php
/**
 * Responsive navigation. Items are filtered by permission, so a user only ever
 * sees what their role can actually reach.
 */
$user = auth_user();

// 'built' marks sections that exist yet. Unbuilt ones are shown greyed out so
// the shape of the system is visible without handing anyone a 404.
$links = [
    // Hidden from borrowers: the dashboard only redirects them to My loans,
    // so showing both would be two links to the same page.
    ['label' => 'Dashboard',   'href' => '/',            'permission' => null,               'built' => true,
     'unless_all' => ['assets.view', 'loans.view', 'maintenance.view', 'pat.view']],
    ['label' => 'Assets',      'href' => '/assets',      'permission' => 'assets.view',      'built' => true],
    ['label' => 'Maintenance', 'href' => '/maintenance', 'permission' => 'maintenance.view', 'built' => true],
    ['label' => 'PAT',         'href' => '/pat',         'permission' => 'pat.view',         'built' => true],
    ['label' => 'Loans',       'href' => '/loans',       'permission' => 'loans.view',       'built' => true],
    ['label' => 'My loans',    'href' => '/my-loans',    'permission' => 'loans.view_own',   'built' => true, 'unless' => 'loans.view'],
    ['label' => 'Borrowers',   'href' => '/borrowers',   'permission' => 'borrowers.view',   'built' => true],
    ['label' => 'Reports',     'href' => '/reports',     'permission' => 'reports.view',     'built' => true],
];

$adminLinks = [
    ['label' => 'Import',       'href' => '/import',           'permission' => 'assets.create'],
    ['label' => 'Categories',   'href' => '/admin/categories', 'permission' => 'categories.manage'],
    ['label' => 'Locations',    'href' => '/admin/locations',  'permission' => 'locations.manage'],
    ['label' => 'Users',        'href' => '/admin/users',      'permission' => 'users.view'],
    ['label' => 'Roles',        'href' => '/admin/roles',      'permission' => 'roles.manage'],
    ['label' => 'Settings',     'href' => '/admin/settings',   'permission' => 'settings.manage'],
    ['label' => 'Activity log', 'href' => '/admin/activity',   'permission' => 'audit.view'],
];

$visible = array_filter($links, static function (array $link): bool {
    if (isset($link['unless']) && can((string) $link['unless'])) {
        return false;
    }

    // Hide when the user holds none of the listed permissions.
    if (isset($link['unless_all']) && !can_any(...$link['unless_all'])) {
        return false;
    }

    return $link['permission'] === null || can((string) $link['permission']);
});

$visibleAdmin = array_filter($adminLinks, static fn (array $link): bool => can((string) $link['permission']));
?>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= e(url('/')) ?>">
            <span class="brand-mark" aria-hidden="true">AR</span>
            <span class="brand-name"><?= e($appName ?? 'Asset Register') ?></span>
        </a>

        <?php if (can('assets.view')): ?>
            <a class="btn btn-primary btn-scan" href="<?= e(url('/scan')) ?>" title="Scan a barcode">
                <span class="scan-icon" aria-hidden="true"></span>
                <span class="btn-label">Scan</span>
            </a>
        <?php endif; ?>

        <button type="button" class="nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="primary-nav">
            <span class="nav-toggle-bars" aria-hidden="true"><span></span><span></span><span></span></span>
            <span class="sr-only">Menu</span>
        </button>

        <nav id="primary-nav" class="primary-nav" data-nav aria-label="Main">
            <ul class="nav-list">
                <?php foreach ($visible as $link): ?>
                    <li>
                        <?php if ($link['built']): ?>
                            <a href="<?= e(url($link['href'])) ?>"
                               class="nav-link<?= is_active_path($link['href']) ? ' is-active' : '' ?>"
                                <?= is_active_path($link['href']) ? 'aria-current="page"' : '' ?>>
                                <?= e($link['label']) ?>
                            </a>
                        <?php else: ?>
                            <span class="nav-link is-pending" aria-disabled="true">
                                <?= e($link['label']) ?>
                                <span class="badge badge-muted">Soon</span>
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>

                <?php if ($visibleAdmin !== []): ?>
                    <li class="nav-divider" aria-hidden="true"></li>
                    <?php foreach ($visibleAdmin as $link): ?>
                        <li>
                            <a href="<?= e(url($link['href'])) ?>"
                               class="nav-link<?= is_active_path($link['href']) ? ' is-active' : '' ?>"
                                <?= is_active_path($link['href']) ? 'aria-current="page"' : '' ?>>
                                <?= e($link['label']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>

            <div class="nav-account">
                <button type="button" class="btn btn-ghost btn-icon" data-theme-toggle title="Switch between light and dark">
                    <span class="theme-icon" aria-hidden="true"></span>
                    <span class="btn-label" data-theme-label>Dark mode</span>
                </button>

                <a class="nav-link nav-user" href="<?= e(url('/profile')) ?>">
                    <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) ($user['name'] ?? '?'), 0, 1))) ?></span>
                    <span class="nav-user-text">
                        <span class="nav-user-name"><?= e($user['name'] ?? '') ?></span>
                        <span class="nav-user-role"><?= e($user['role_name'] ?? '') ?></span>
                    </span>
                </a>

                <form method="post" action="<?= e(url('/logout')) ?>" class="nav-logout">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost">Sign out</button>
                </form>
            </div>
        </nav>
    </div>
</header>
