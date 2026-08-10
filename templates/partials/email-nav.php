<?php
/**
 * Sub-navigation for the four Email pages.
 *
 * These are one nav entry under Settings rather than four, so the tabs live on
 * the page instead of in the menu.
 *
 * @var string $section smtp | reminders | templates | log
 */
$tabs = [
    'smtp'      => ['label' => 'Connection', 'href' => '/admin/email'],
    'reminders' => ['label' => 'Reminders',  'href' => '/admin/email/reminders'],
    'templates' => ['label' => 'Templates',  'href' => '/admin/email/templates'],
    'log'       => ['label' => 'Log',        'href' => '/admin/email/log'],
];
?>
<nav class="subnav" aria-label="Email settings">
    <?php foreach ($tabs as $key => $tab): ?>
        <a class="subnav-link<?= ($section ?? '') === $key ? ' is-active' : '' ?>"
           href="<?= e(url($tab['href'])) ?>"
            <?= ($section ?? '') === $key ? 'aria-current="page"' : '' ?>><?= e($tab['label']) ?></a>
    <?php endforeach; ?>
</nav>
