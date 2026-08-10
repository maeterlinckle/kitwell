<?php

declare(strict_types=1);

/*
 * Route table.
 *
 * Middleware is declared per route: 'guest', 'auth', 'csrf', 'can:<permission>'
 * and 'canany:<a>,<b>'. Every state-changing route carries 'csrf'.
 */

use App\Controllers\Admin\ActivityController;
use App\Controllers\Admin\CategoryController;
use App\Controllers\Admin\LocationController;
use App\Controllers\Admin\RoleController;
use App\Controllers\Admin\EmailController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\UserController;
use App\Controllers\CalendarController;
use App\Controllers\AssetController;
use App\Controllers\AssetCopyController;
use App\Controllers\AssetExportController;
use App\Controllers\ImportController;
use App\Controllers\AuthController;
use App\Controllers\HirerController;
use App\Controllers\DashboardController;
use App\Controllers\LabelController;
use App\Controllers\HireController;
use App\Controllers\MyHiresController;
use App\Controllers\ScanController;
use App\Controllers\MaintenanceController;
use App\Controllers\ManualController;
use App\Controllers\PatController;
use App\Controllers\PhotoController;
use App\Controllers\ProfileController;
use App\Controllers\ReportController;
use App\Core\Router;

$router = new Router();

// --- Public ---------------------------------------------------------------
$router->get('/login',  [AuthController::class, 'showLogin'], ['guest'], 'login');
$router->post('/login', [AuthController::class, 'login'],     ['guest', 'csrf']);
$router->post('/logout', [AuthController::class, 'logout'],   ['auth', 'csrf'], 'logout');

$router->get('/health', [DashboardController::class, 'health']);

// Personal calendar subscription. Deliberately outside 'auth': a calendar
// client cannot sign in, so the 64-character token in the path is the
// credential, and what the feed contains is decided by the token owner's own
// permissions. See App\Services\CalendarFeed.
$router->get('/calendar/{token:[a-f0-9]+}.ics', [CalendarController::class, 'feed']);

// --- Signed in ------------------------------------------------------------
$router->group(['auth'], static function (Router $router): void {
    $router->get('/', [DashboardController::class, 'index'], [], 'dashboard');

    $router->get('/profile', [ProfileController::class, 'edit'], [], 'profile');
    $router->post('/profile', [ProfileController::class, 'update'], ['csrf']);
    $router->post('/profile/password', [ProfileController::class, 'updatePassword'], ['csrf']);

    // Personal, not administrative: a user manages only their own feed token,
    // and there is no route by which anyone can reach somebody else's.
    $router->get('/profile/calendar',         [CalendarController::class, 'show'], [], 'profile.calendar');
    $router->post('/profile/calendar',        [CalendarController::class, 'regenerate'], ['csrf']);
    $router->post('/profile/calendar/revoke', [CalendarController::class, 'revoke'], ['csrf']);
});

// --- Assets ---------------------------------------------------------------
// Static segments are registered before the {id} routes so that /assets/create
// and /assets/labels are never swallowed by the wildcard.
$router->group(['auth'], static function (Router $router): void {
    $router->get('/assets',        [AssetController::class, 'index'],  ['can:assets.view'], 'assets');
    $router->get('/assets/create', [AssetController::class, 'create'], ['can:assets.create']);
    $router->post('/assets',       [AssetController::class, 'store'],  ['can:assets.create', 'csrf']);

    $router->get('/assets/labels', [LabelController::class, 'sheet'], ['can:assets.view']);
    $router->get('/assets/export', [AssetExportController::class, 'export'], ['can:assets.export']);

    $router->get('/assets/{id:\d+}',            [AssetController::class, 'show'],    ['can:assets.view']);
    $router->get('/assets/{id:\d+}/edit',       [AssetController::class, 'edit'],    ['can:assets.edit']);
    $router->post('/assets/{id:\d+}',           [AssetController::class, 'update'],  ['can:assets.edit', 'csrf']);
    $router->post('/assets/{id:\d+}/archive',   [AssetController::class, 'archive'], ['can:assets.edit', 'csrf']);
    $router->post('/assets/{id:\d+}/restore',   [AssetController::class, 'restore'], ['can:assets.edit', 'csrf']);
    $router->post('/assets/{id:\d+}/delete',    [AssetController::class, 'destroy'], ['can:assets.delete', 'csrf']);

    // Barcode label for a single asset.
    $router->get('/assets/{id:\d+}/label', [LabelController::class, 'single'], ['can:assets.view']);

    // Copy workflows.
    $router->get('/assets/{id:\d+}/copy',   [AssetCopyController::class, 'copyForm'],    ['can:assets.create']);
    $router->post('/assets/{id:\d+}/copy',  [AssetCopyController::class, 'storeCopies'], ['can:assets.create', 'csrf']);
    $router->get('/assets/{id:\d+}/apply',  [AssetCopyController::class, 'applyForm'],   ['can:assets.edit']);
    $router->post('/assets/{id:\d+}/apply', [AssetCopyController::class, 'applyStore'],  ['can:assets.edit', 'csrf']);

    // Manuals (PDF).
    $router->post('/assets/{id:\d+}/manuals', [ManualController::class, 'store'], ['can:media.manual.upload', 'csrf']);
    $router->get('/assets/{id:\d+}/manuals/{manualId:\d+}', [ManualController::class, 'show'], ['can:assets.view']);
    $router->post('/assets/{id:\d+}/manuals/{manualId:\d+}/delete', [ManualController::class, 'destroy'], ['can:media.manual.delete', 'csrf']);

    // Condition photos.
    $router->get('/assets/{id:\d+}/photos',  [PhotoController::class, 'index'], ['can:assets.view']);
    $router->post('/assets/{id:\d+}/photos', [PhotoController::class, 'store'], ['can:media.photo.upload', 'csrf']);
    $router->get('/assets/{id:\d+}/photos/{photoId:\d+}',              [PhotoController::class, 'show'],        ['can:assets.view']);
    $router->post('/assets/{id:\d+}/photos/{photoId:\d+}',             [PhotoController::class, 'update'],      ['can:media.photo.upload', 'csrf']);
    $router->post('/assets/{id:\d+}/photos/{photoId:\d+}/primary',     [PhotoController::class, 'makePrimary'], ['can:media.photo.upload', 'csrf']);
    $router->post('/assets/{id:\d+}/photos/{photoId:\d+}/delete',      [PhotoController::class, 'destroy'],     ['can:media.photo.delete', 'csrf']);
});

// --- Maintenance ----------------------------------------------------------
$router->group(['auth'], static function (Router $router): void {
    $router->get('/maintenance',         [MaintenanceController::class, 'index'],   ['can:maintenance.view'], 'maintenance');
    $router->get('/maintenance/history', [MaintenanceController::class, 'history'], ['can:maintenance.view']);
    $router->get('/maintenance/create',  [MaintenanceController::class, 'create'],  ['can:maintenance.manage']);
    $router->post('/maintenance',        [MaintenanceController::class, 'store'],   ['can:maintenance.manage', 'csrf']);

    $router->get('/maintenance/{id:\d+}',             [MaintenanceController::class, 'show'],    ['can:maintenance.view']);
    $router->get('/maintenance/{id:\d+}/edit',        [MaintenanceController::class, 'edit'],    ['can:maintenance.manage']);
    $router->post('/maintenance/{id:\d+}',            [MaintenanceController::class, 'update'],  ['can:maintenance.manage', 'csrf']);
    $router->post('/maintenance/{id:\d+}/delete',     [MaintenanceController::class, 'destroy'], ['can:maintenance.manage', 'csrf']);

    // Completing a scheduled job.
    $router->get('/maintenance/{id:\d+}/complete',  [MaintenanceController::class, 'completeForm'], ['can:maintenance.complete']);
    $router->post('/maintenance/{id:\d+}/complete', [MaintenanceController::class, 'complete'],     ['can:maintenance.complete', 'csrf']);

    // Unplanned work, logged straight onto an asset.
    $router->get('/assets/{assetId:\d+}/maintenance/log',  [MaintenanceController::class, 'logForm'], ['can:maintenance.complete']);
    $router->post('/assets/{assetId:\d+}/maintenance/log', [MaintenanceController::class, 'log'],     ['can:maintenance.complete', 'csrf']);

    // Photos attached to a completion.
    $router->get('/maintenance/logs/{logId:\d+}/photos/{photoId:\d+}', [MaintenanceController::class, 'photo'], ['can:maintenance.view']);
});

// --- PAT testing ----------------------------------------------------------
$router->group(['auth'], static function (Router $router): void {
    $router->get('/pat',        [PatController::class, 'index'],  ['can:pat.view'], 'pat');
    $router->get('/pat/create', [PatController::class, 'create'], ['can:pat.manage']);
    $router->post('/pat',       [PatController::class, 'store'],  ['can:pat.manage', 'csrf']);

    $router->get('/pat/{id:\d+}',           [PatController::class, 'show'],    ['can:pat.view']);
    $router->get('/pat/{id:\d+}/edit',      [PatController::class, 'edit'],    ['can:pat.manage']);
    $router->post('/pat/{id:\d+}',          [PatController::class, 'update'],  ['can:pat.manage', 'csrf']);
    $router->post('/pat/{id:\d+}/delete',   [PatController::class, 'destroy'], ['can:pat.delete', 'csrf']);

    // Per-asset history, and the requires-PAT toggle.
    $router->get('/assets/{assetId:\d+}/pat', [PatController::class, 'history'], ['can:pat.view']);
    $router->post('/assets/{assetId:\d+}/pat/toggle', [PatController::class, 'toggleRequirement'], ['can:assets.edit', 'csrf']);
});

// --- Hires and hirers ------------------------------------------------------
$router->group(['auth'], static function (Router $router): void {
    $router->get('/hires',          [HireController::class, 'index'],        ['can:hires.view'], 'hires');
    $router->get('/hires/checkout', [HireController::class, 'checkoutForm'], ['can:hires.create']);
    $router->post('/hires/checkout',[HireController::class, 'checkout'],     ['can:hires.create', 'csrf']);

    $router->get('/hires/{id:\d+}',           [HireController::class, 'show'],       ['can:hires.view']);
    $router->get('/hires/{id:\d+}/return',    [HireController::class, 'returnForm'], ['can:hires.return']);
    $router->post('/hires/{id:\d+}/return',   [HireController::class, 'returnHire'], ['can:hires.return', 'csrf']);
    $router->post('/hires/{id:\d+}/extend',   [HireController::class, 'extend'],     ['can:hires.manage', 'csrf']);
    $router->post('/hires/{id:\d+}/email',    [HireController::class, 'emailReminder'], ['can:email.send', 'csrf']);
    $router->get('/hires/{hireId:\d+}/photos/{photoId:\d+}', [HireController::class, 'photo'], ['can:hires.view']);

    // Hirers
    $router->get('/hirers',                 [HirerController::class, 'index'],   ['can:hirers.view'], 'hirers');
    $router->get('/hirers/create',          [HirerController::class, 'create'],  ['can:hirers.manage']);
    $router->post('/hirers',                [HirerController::class, 'store'],   ['can:hirers.manage', 'csrf']);
    $router->get('/hirers/{id:\d+}',        [HirerController::class, 'show'],    ['can:hirers.view']);
    $router->get('/hirers/{id:\d+}/edit',   [HirerController::class, 'edit'],    ['can:hirers.manage']);
    $router->post('/hirers/{id:\d+}',       [HirerController::class, 'update'],  ['can:hirers.manage', 'csrf']);
    $router->post('/hirers/{id:\d+}/delete',[HirerController::class, 'destroy'], ['can:hirers.manage', 'csrf']);
    $router->post('/hirers/{id:\d+}/email',  [HirerController::class, 'emailHires'], ['can:email.send', 'csrf']);

    // Quick scan, reachable from anywhere.
    $router->get('/scan',        [ScanController::class, 'index'],  ['can:assets.view'], 'scan');
    $router->get('/scan/lookup', [ScanController::class, 'lookup'], ['can:assets.view']);
    $router->post('/scan',       [ScanController::class, 'go'],     ['can:assets.view', 'csrf']);
});

// --- CSV import -----------------------------------------------------------
// Two generic routes plus a template download serve every importer.
$router->group(['auth'], static function (Router $router): void {
    $router->get('/import', [ImportController::class, 'index'], ['canany:assets.create,pat.manage'], 'import');
    $router->get('/import/{key:[a-z0-9-]+}', [ImportController::class, 'show'], ['canany:assets.create,pat.manage']);
    $router->get('/import/{key:[a-z0-9-]+}/template', [ImportController::class, 'template'], ['canany:assets.create,pat.manage']);
    $router->post('/import/{key:[a-z0-9-]+}/preview', [ImportController::class, 'preview'], ['canany:assets.create,pat.manage', 'csrf']);
    $router->post('/import/{key:[a-z0-9-]+}/commit', [ImportController::class, 'commit'], ['canany:assets.create,pat.manage', 'csrf']);
});

// --- Reports --------------------------------------------------------------
// Two routes serve every report; the registry supplies the rest.
$router->group(['auth'], static function (Router $router): void {
    $router->get('/reports',                 [ReportController::class, 'index'], ['can:reports.view'], 'reports');
    $router->get('/reports/{key:[a-z0-9-]+}',[ReportController::class, 'show'],  ['can:reports.view']);
});

// --- Hirer self-service ------------------------------------------------
// Auth only: every query is scoped to the hirer record linked to the
// signed-in user, so there is nothing here that is not already theirs.
$router->group(['auth'], static function (Router $router): void {
    $router->get('/my-hires',                  [MyHiresController::class, 'index'], [], 'my-hires');
    $router->get('/my-hires/{hireId:\d+}',     [MyHiresController::class, 'show']);
    $router->get('/my-hires/{hireId:\d+}/photo', [MyHiresController::class, 'photo']);
    $router->get('/my-hires/{hireId:\d+}/manuals/{manualId:\d+}', [MyHiresController::class, 'manual']);
});

// --- Administration -------------------------------------------------------
$router->group(['auth'], static function (Router $router): void {
    // Users
    $router->get('/admin/users',                    [UserController::class, 'index'],  ['can:users.view'], 'admin.users');
    $router->get('/admin/users/create',             [UserController::class, 'create'], ['can:users.manage']);
    $router->post('/admin/users',                   [UserController::class, 'store'],  ['can:users.manage', 'csrf']);
    $router->get('/admin/users/{id:\d+}/edit',      [UserController::class, 'edit'],   ['can:users.manage']);
    $router->post('/admin/users/{id:\d+}',          [UserController::class, 'update'], ['can:users.manage', 'csrf']);
    $router->post('/admin/users/{id:\d+}/password', [UserController::class, 'resetPassword'], ['can:users.manage', 'csrf']);
    $router->post('/admin/users/{id:\d+}/status',   [UserController::class, 'toggleActive'],  ['can:users.manage', 'csrf']);

    // Roles and permissions
    $router->get('/admin/roles',                [RoleController::class, 'index'],  ['can:roles.manage'], 'admin.roles');
    $router->get('/admin/roles/{id:\d+}/edit',  [RoleController::class, 'edit'],   ['can:roles.manage']);
    $router->post('/admin/roles/{id:\d+}',      [RoleController::class, 'update'], ['can:roles.manage', 'csrf']);

    // Reference data
    $router->get('/admin/categories',                 [CategoryController::class, 'index'],   ['can:categories.manage'], 'admin.categories');
    $router->post('/admin/categories',                [CategoryController::class, 'store'],   ['can:categories.manage', 'csrf']);
    $router->post('/admin/categories/{id:\d+}',       [CategoryController::class, 'update'],  ['can:categories.manage', 'csrf']);
    $router->post('/admin/categories/{id:\d+}/delete',[CategoryController::class, 'destroy'], ['can:categories.manage', 'csrf']);

    $router->get('/admin/locations',                 [LocationController::class, 'index'],   ['can:locations.manage'], 'admin.locations');
    $router->post('/admin/locations',                [LocationController::class, 'store'],   ['can:locations.manage', 'csrf']);
    $router->post('/admin/locations/{id:\d+}',       [LocationController::class, 'update'],  ['can:locations.manage', 'csrf']);
    $router->post('/admin/locations/{id:\d+}/delete',[LocationController::class, 'destroy'], ['can:locations.manage', 'csrf']);

    // Application settings
    $router->get('/admin/settings',  [SettingsController::class, 'edit'],   ['can:settings.manage'], 'admin.settings');
    $router->post('/admin/settings', [SettingsController::class, 'update'], ['can:settings.manage', 'csrf']);

    // Email: the SMTP connection, reminders, templates and the send log.
    // One nav entry, four pages — Settings is set up once and visited rarely,
    // so the sub-pages live inside it rather than cluttering the menu.
    $router->get('/admin/email',            [EmailController::class, 'index'],   ['can:email.manage'], 'admin.email');
    $router->post('/admin/email',           [EmailController::class, 'update'],  ['can:email.manage', 'csrf']);
    $router->post('/admin/email/test',      [EmailController::class, 'test'],    ['can:email.manage', 'csrf']);

    $router->get('/admin/email/reminders',  [EmailController::class, 'reminders'],       ['can:email.manage']);
    $router->post('/admin/email/reminders', [EmailController::class, 'updateReminders'], ['can:email.manage', 'csrf']);
    $router->post('/admin/email/reminders/run', [EmailController::class, 'runReminders'],['can:email.manage', 'csrf']);

    $router->get('/admin/email/log',        [EmailController::class, 'log'],     ['can:email.manage']);

    // Templates last: /admin/email/templates must be registered before the
    // {key} route so the list is never swallowed by the wildcard.
    $router->get('/admin/email/templates',  [EmailController::class, 'templates'], ['can:email.manage']);
    $router->get('/admin/email/templates/{key:[a-z0-9_]+}',        [EmailController::class, 'editTemplate'],   ['can:email.manage']);
    $router->post('/admin/email/templates/{key:[a-z0-9_]+}',       [EmailController::class, 'updateTemplate'], ['can:email.manage', 'csrf']);
    $router->post('/admin/email/templates/{key:[a-z0-9_]+}/reset', [EmailController::class, 'resetTemplate'],  ['can:email.manage', 'csrf']);

    // Audit trail
    $router->get('/admin/activity', [ActivityController::class, 'index'], ['can:audit.view'], 'admin.activity');
});

return $router;
