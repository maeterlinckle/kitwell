<?php
/**
 * Responsive navigation.
 *
 * Six top-level destinations, with the rarely-used ones grouped underneath:
 * PAT under Maintenance, Hirers under Hires, and the whole admin area plus the
 * CSV tools under Settings. Everything is filtered by permission, so a user
 * only ever sees what their role can actually reach — and a group with nothing
 * visible in it disappears entirely rather than opening onto an empty list.
 *
 * Desktop and mobile render from the same markup on purpose. A group is a
 * <details> element: on a phone it is an accordion inside the slide-out menu,
 * on a desktop the same element is styled as a drop-down. There is no second
 * structure to keep in step, and it works with JavaScript switched off.
 */
$user = auth_user();

/**
 * A nav entry. 'children' makes it a group; the parent's own href is not a
 * destination in that case, only a label.
 */
$links = [
    // Hidden from hirers: the dashboard only redirects them to My hires, so
    // showing both would be two links to the same page.
    ['label' => 'Dashboard', 'href' => '/', 'permission' => null,
     'unless_all' => ['assets.view', 'hires.view', 'maintenance.view', 'pat.view']],

    ['label' => 'Assets', 'href' => '/assets', 'permission' => 'assets.view'],

    // The doing comes before the reading: recording work and sending an item
    // out are the errands people arrive with, so each group leads with its
    // action rather than with its list.
    ['label' => 'Maintenance', 'href' => '/maintenance', 'permission' => null, 'children' => [
        ['label' => 'Add maintenance', 'href' => '/maintenance/log', 'permission' => 'maintenance.complete'],
        ['label' => 'Schedules',       'href' => '/maintenance',     'permission' => 'maintenance.view'],
        ['label' => 'PAT records',     'href' => '/pat',             'permission' => 'pat.view'],
    ]],

    ['label' => 'Hires', 'href' => '/hires', 'permission' => null, 'children' => [
        ['label' => 'Check out',         'href' => '/hires/checkout', 'permission' => 'hires.create'],
        ['label' => 'Current & history', 'href' => '/hires',          'permission' => 'hires.view'],
        ['label' => 'Hirers',            'href' => '/hirers',         'permission' => 'hirers.view'],
    ]],

    // A hirer sees only their own, and has no group to nest it under.
    ['label' => 'My hires', 'href' => '/my-hires', 'permission' => 'hires.view_own', 'unless' => 'hires.view'],

    ['label' => 'Reports', 'href' => '/reports', 'permission' => 'reports.view'],

    // Everything occasional lives here: set up once, visited rarely. The CSV
    // tools are here rather than in the day-to-day flow for the same reason.
    ['label' => 'Settings', 'href' => '/admin/settings', 'permission' => null, 'children' => [
        ['label' => 'Users',        'href' => '/admin/users',      'permission' => 'users.view'],
        ['label' => 'Roles',        'href' => '/admin/roles',      'permission' => 'roles.manage'],
        ['label' => 'Categories',   'href' => '/admin/categories', 'permission' => 'categories.manage'],
        ['label' => 'Locations',    'href' => '/admin/locations',  'permission' => 'locations.manage'],
        ['label' => 'Email',        'href' => '/admin/email',      'permission' => 'email.manage'],
        ['label' => 'Activity log', 'href' => '/admin/activity',   'permission' => 'audit.view'],
        ['label' => 'Import CSV',   'href' => '/import',           'permission' => 'assets.create'],
        ['label' => 'Export assets','href' => '/assets/export',    'permission' => 'assets.export'],
        ['label' => 'Application settings', 'href' => '/admin/settings', 'permission' => 'settings.manage'],
    ]],
];

/** Can this user see this entry at all? */
$allowed = static function (array $link): bool {
    if (isset($link['unless']) && can((string) $link['unless'])) {
        return false;
    }

    if (isset($link['unless_all']) && !can_any(...$link['unless_all'])) {
        return false;
    }

    return $link['permission'] === null || can((string) $link['permission']);
};

// Resolve groups: drop the children a user cannot see, then drop the group if
// nothing is left in it.
$visible = [];

foreach ($links as $link) {
    if (!$allowed($link)) {
        continue;
    }

    if (isset($link['children'])) {
        $link['children'] = array_values(array_filter($link['children'], $allowed));

        if ($link['children'] === []) {
            continue;
        }
    }

    $visible[] = $link;
}

/** Is the current page this entry, or anything inside it? */
$groupIsOpen = static function (array $link): bool {
    foreach ($link['children'] ?? [] as $child) {
        if (is_active_path($child['href'])) {
            return true;
        }
    }

    return false;
};
?>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= e(url('/')) ?>">
            <span class="brand-mark" aria-hidden="true">AR</span>
            <span class="brand-name"><?= e($appName ?? 'Asset Register') ?></span>
        </a>

        <nav id="primary-nav" class="primary-nav" data-nav aria-label="Main">
            <ul class="nav-list">
                <?php foreach ($visible as $link): ?>
                    <li class="<?= isset($link['children']) ? 'nav-item nav-item-group' : 'nav-item' ?>">
                        <?php if (!isset($link['children'])): ?>
                            <a href="<?= e(url($link['href'])) ?>"
                               class="nav-link<?= is_active_path($link['href']) ? ' is-active' : '' ?>"
                                <?= is_active_path($link['href']) ? 'aria-current="page"' : '' ?>>
                                <?= e($link['label']) ?>
                            </a>
                        <?php else: ?>
                            <?php /* `open` marks the section you are in. On a phone that
                                     expands the accordion, which is the point. On a
                                     desktop the panel is an overlay, so it would sit on
                                     top of the page you just navigated to —
                                     data-nav-autoopen lets the stylesheet keep it shut
                                     there until you actually reach for it. */ ?>
                            <details class="nav-group" data-nav-group
                                <?= $groupIsOpen($link) ? 'open data-nav-autoopen' : '' ?>>
                                <summary class="nav-link nav-group-toggle<?= $groupIsOpen($link) ? ' is-active' : '' ?>">
                                    <span><?= e($link['label']) ?></span>
                                    <span class="nav-caret" aria-hidden="true"></span>
                                </summary>
                                <ul class="nav-sublist">
                                    <?php foreach ($link['children'] as $child): ?>
                                        <li>
                                            <a href="<?= e(url($child['href'])) ?>"
                                               class="nav-link nav-sublink<?= is_active_path($child['href']) ? ' is-active' : '' ?>"
                                                <?= is_active_path($child['href']) ? 'aria-current="page"' : '' ?>>
                                                <?= e($child['label']) ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="nav-account">
                <button type="button" class="btn btn-ghost btn-icon" data-theme-toggle title="Switch between light and dark">
                    <span class="theme-icon" aria-hidden="true"></span>
                    <span class="btn-label" data-theme-label>Dark mode</span>
                </button>

                <?php /* The account menu. Personal settings only — the calendar
                         feed is one person's own subscription link, so it
                         belongs here rather than in the admin area. Same
                         <details> mechanics as the main nav groups, so it
                         accordions on a phone and drops down on a desktop
                         without a second structure. */ ?>
                <?php $accountOpen = is_active_path('/profile'); ?>
                <details class="nav-group nav-account-group" data-nav-group
                    <?= $accountOpen ? 'open data-nav-autoopen' : '' ?>>
                    <summary class="nav-link nav-user nav-group-toggle<?= $accountOpen ? ' is-active' : '' ?>">
                        <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) ($user['name'] ?? '?'), 0, 1))) ?></span>
                        <span class="nav-user-text">
                            <span class="nav-user-name"><?= e($user['name'] ?? '') ?></span>
                            <span class="nav-user-role"><?= e($user['role_name'] ?? '') ?></span>
                        </span>
                        <span class="nav-caret" aria-hidden="true"></span>
                    </summary>
                    <ul class="nav-sublist">
                        <?php /* On a desktop the bar shows only the avatar, so the
                                 menu is where you confirm who you are signed in as. */ ?>
                        <li class="nav-account-identity">
                            <strong><?= e($user['name'] ?? '') ?></strong>
                            <span><?= e($user['role_name'] ?? '') ?></span>
                        </li>
                        <li>
                            <a href="<?= e(url('/profile')) ?>"
                               class="nav-link nav-sublink<?= is_active_path('/profile') && !is_active_path('/profile/calendar') ? ' is-active' : '' ?>">My account</a>
                        </li>
                        <li>
                            <a href="<?= e(url('/profile/calendar')) ?>"
                               class="nav-link nav-sublink<?= is_active_path('/profile/calendar') ? ' is-active' : '' ?>">Calendar feed</a>
                        </li>
                    </ul>
                </details>

                <form method="post" action="<?= e(url('/logout')) ?>" class="nav-logout">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost">Sign out</button>
                </form>
            </div>
        </nav>

        <?php /* Quick actions, pinned right on both desktop and mobile. Scanning
                 is a primary workshop action, not a menu destination, so it stays
                 out of the menu and out of the hamburger. */ ?>
        <div class="header-actions">
            <?php if (can('assets.view')): ?>
                <a class="scan-action" href="<?= e(url('/scan')) ?>" title="Scan a barcode" aria-label="Scan a barcode">
                    <span class="scan-icon" aria-hidden="true"></span>
                    <span class="scan-action-label">Scan</span>
                </a>
            <?php endif; ?>

            <button type="button" class="nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="primary-nav">
                <span class="nav-toggle-bars" aria-hidden="true"><span></span><span></span><span></span></span>
                <span class="sr-only">Menu</span>
            </button>
        </div>
    </div>
</header>
