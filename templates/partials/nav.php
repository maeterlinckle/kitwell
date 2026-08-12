<?php
/**
 * Responsive navigation.
 *
 * Five top-level destinations, with the rarely-used ones grouped underneath:
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
    // No Dashboard entry: the logo in the top-left is already the way home, and
    // a menu item pointing at the same page is a menu item's worth of width
    // spent saying it twice. (A hirer never saw one anyway — the dashboard only
    // redirects them to My hires.)
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
        ['label' => 'Teams',        'href' => '/admin/teams',      'permission' => 'teams.manage'],
        ['label' => 'Categories',   'href' => '/admin/categories', 'permission' => 'categories.manage'],
        ['label' => 'Locations',    'href' => '/admin/locations',  'permission' => 'locations.manage'],
        ['label' => 'Email',        'href' => '/admin/email',      'permission' => 'email.manage'],
        ['label' => 'API keys',     'href' => '/admin/api',        'permission' => 'api.manage'],
        ['label' => 'Activity log', 'href' => '/admin/activity',   'permission' => 'audit.view'],
        ['label' => 'Import data',  'href' => '/import',           'permission' => 'assets.create'],
        ['label' => 'Export data',  'href' => '/export',           'permission' => 'assets.export'],
        ['label' => 'Application settings', 'href' => '/admin/settings', 'permission' => 'settings.manage'],
    ]],
];

/** Can this user see this entry at all? */
$allowed = static function (array $link): bool {
    if (isset($link['unless']) && can((string) $link['unless'])) {
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

/**
 * Which child of a group is the page you are actually on — at most one.
 *
 * Not `is_active_path()` per child. These menus nest one item inside another:
 * "Add maintenance" is /maintenance/log and "Schedules" is /maintenance, so on
 * the first of those the prefix rule matched both and the bar showed two
 * current pages. `active_path()` picks the longest match, which is the one the
 * user is really looking at.
 *
 * @return string|null The active child's href
 */
$activeChild = static function (array $link): ?string {
    return active_path($link['children'] === [] ? [] : array_column($link['children'], 'href'));
};
?>
<header class="site-header">
    <div class="container header-inner">
        <?php /* Not itself a link: the logo inside carries the link home, and
                 the wordmark beside it is a heading sized to sit level with the
                 menu items. Wrapping both made the whole lockup one large
                 target, which is why the name had to look like a control. */ ?>
        <div class="brand">
            <?= partial('partials/brand', [
                'appName'  => $appName ?? config('app.name', 'Asset Register'),
                'homeHref' => '/',
            ]) ?>
        </div>

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
                            <?php $active = $activeChild($link); ?>
                            <details class="nav-group" data-nav-group
                                <?= $active !== null ? 'open data-nav-autoopen' : '' ?>>
                                <summary class="nav-link nav-group-toggle<?= $active !== null ? ' is-active' : '' ?>">
                                    <span><?= e($link['label']) ?></span>
                                    <span class="caret" aria-hidden="true"></span>
                                </summary>
                                <ul class="nav-sublist">
                                    <?php foreach ($link['children'] as $child): ?>
                                        <?php $isCurrent = $child['href'] === $active; ?>
                                        <li>
                                            <a href="<?= e(url($child['href'])) ?>"
                                               class="nav-link nav-sublink<?= $isCurrent ? ' is-active' : '' ?>"
                                                <?= $isCurrent ? 'aria-current="page"' : '' ?>>
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
                <?php /* Same "most specific wins" rule as the menu groups. This
                         list had the sibling problem too, and used to work
                         around it by hand with a `&& !is_active_path(...)`. */ ?>
                <?php $accountActive = active_path(['/profile', '/profile/security', '/profile/calendar']); ?>
                <?php $accountOpen   = $accountActive !== null; ?>
                <details class="nav-group nav-account-group" data-nav-group
                    <?= $accountOpen ? 'open data-nav-autoopen' : '' ?>>
                    <summary class="nav-link nav-user nav-group-toggle<?= $accountOpen ? ' is-active' : '' ?>">
                        <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) ($user['name'] ?? '?'), 0, 1))) ?></span>
                        <span class="nav-user-text">
                            <span class="nav-user-name"><?= e($user['name'] ?? '') ?></span>
                            <span class="nav-user-role"><?= e($user['role_name'] ?? '') ?></span>
                        </span>
                        <span class="caret" aria-hidden="true"></span>
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
                               class="nav-link nav-sublink<?= $accountActive === '/profile' ? ' is-active' : '' ?>">My account</a>
                        </li>
                        <li>
                            <a href="<?= e(url('/profile/security')) ?>"
                               class="nav-link nav-sublink<?= $accountActive === '/profile/security' ? ' is-active' : '' ?>">Security</a>
                        </li>
                        <li>
                            <a href="<?= e(url('/profile/calendar')) ?>"
                               class="nav-link nav-sublink<?= $accountActive === '/profile/calendar' ? ' is-active' : '' ?>">Calendar feed</a>
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
