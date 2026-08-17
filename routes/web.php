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
use App\Controllers\Admin\TeamController;
use App\Controllers\Admin\ApiKeyController;
use App\Controllers\Admin\AssetTemplateController;
use App\Controllers\Admin\UserController;
use App\Controllers\Api\MetaController;
use App\Controllers\Api\ResourceController;
use App\Controllers\CalendarController;
use App\Controllers\AssetController;
use App\Controllers\AssetCopyController;
use App\Controllers\AssetExportController;
use App\Controllers\ExportController;
use App\Controllers\FaultController;
use App\Controllers\HelpController;
use App\Controllers\ImportController;
use App\Controllers\MediaController;
use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CustomReportController;
use App\Controllers\BrandingController;
use App\Controllers\HirerController;
use App\Controllers\DashboardController;
use App\Controllers\LabelController;
use App\Controllers\HireController;
use App\Controllers\MyHiresController;
use App\Controllers\ScanController;
use App\Controllers\SecurityController;
use App\Controllers\TwoFactorController;
use App\Controllers\MaintenanceController;
use App\Controllers\PatController;
use App\Controllers\PhotoController;
use App\Controllers\ProfileController;
use App\Controllers\ReportController;
use App\Controllers\RoutineController;
use App\Controllers\RoutineRunController;
use App\Core\Router;

$router = new Router();

// --- Public ---------------------------------------------------------------
$router->get('/login',  [AuthController::class, 'showLogin'], ['guest'], 'login');
$router->post('/login', [AuthController::class, 'login'],     ['guest', 'csrf']);
$router->post('/logout', [AuthController::class, 'logout'],   ['auth', 'csrf'], 'logout');

$router->get('/health', [DashboardController::class, 'health']);

// Accepting an invitation, and resetting a forgotten password. Open by
// necessity — the whole point is that the person cannot sign in yet — so the
// 64-hex token in the path is the credential, exactly as it is for the calendar
// feed. See App\Controllers\AccountController for the rules that follow from
// that: one use, an expiry, no answer that reveals whether an address exists,
// and the sign-in throttle shared rather than side-stepped.
$router->get('/invite/{token:[a-f0-9]+}',  [AccountController::class, 'showInvite'],   ['guest']);
$router->post('/invite/{token:[a-f0-9]+}', [AccountController::class, 'acceptInvite'], ['guest', 'csrf']);

$router->get('/forgot-password',  [AccountController::class, 'showForgot'], ['guest'], 'forgot-password');
$router->post('/forgot-password', [AccountController::class, 'sendReset'],  ['guest', 'csrf']);

$router->get('/reset-password/{token:[a-f0-9]+}',  [AccountController::class, 'showReset'],     ['guest']);
$router->post('/reset-password/{token:[a-f0-9]+}', [AccountController::class, 'resetPassword'], ['guest', 'csrf']);

// The second factor. In the `guest` group because there is genuinely no session
// yet: Auth::attempt() stops at the password and leaves a pending state behind,
// and nothing here is reachable without one. See App\Services\TwoFactor.
$router->get('/two-factor',          [TwoFactorController::class, 'challenge'],  ['guest'], 'two-factor');
$router->post('/two-factor',         [TwoFactorController::class, 'verify'],     ['guest', 'csrf']);
$router->post('/two-factor/resend',  [TwoFactorController::class, 'resend'],     ['guest', 'csrf']);
$router->post('/two-factor/cancel',  [TwoFactorController::class, 'cancel'],     ['guest', 'csrf']);
$router->get('/two-factor/setup',    [TwoFactorController::class, 'setup'],      ['guest']);
$router->post('/two-factor/setup',   [TwoFactorController::class, 'setupEmail'], ['guest', 'csrf']);

// The logo, outside 'auth' because the sign-in page shows it and nobody has a
// session at that point. It takes no id, reads one of two settings and returns
// an image an administrator chose to publish. See App\Controllers\BrandingController.
$router->get('/branding/logo/{variant:light|dark}', [BrandingController::class, 'logo']);

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
    // Two-factor, trusted devices and backup codes: a user's own, and only
    // their own. See App\Controllers\SecurityController.
    $router->get('/profile/security',                [SecurityController::class, 'index'], [], 'profile.security');
    $router->get('/profile/security/totp',           [SecurityController::class, 'startTotp']);
    $router->post('/profile/security/totp',          [SecurityController::class, 'confirmTotp'], ['csrf']);
    $router->post('/profile/security/email',         [SecurityController::class, 'enableEmail'], ['csrf']);
    $router->post('/profile/security/disable',       [SecurityController::class, 'disable'], ['csrf']);
    $router->post('/profile/security/backup-codes',  [SecurityController::class, 'regenerateBackupCodes'], ['csrf']);
    $router->post('/profile/security/devices/{id:\d+}/forget', [SecurityController::class, 'forgetDevice'], ['csrf']);
    $router->post('/profile/security/devices/forget-all',      [SecurityController::class, 'forgetAllDevices'], ['csrf']);

    $router->get('/profile/calendar',         [CalendarController::class, 'show'], [], 'profile.calendar');
    $router->post('/profile/calendar',        [CalendarController::class, 'regenerate'], ['csrf']);
    $router->post('/profile/calendar/revoke', [CalendarController::class, 'revoke'], ['csrf']);

    // Documentation. No permission of its own: every signed-in user reaches it
    // from Help in the menu, and it describes the application rather than
    // exposing anything from the register.
    $router->get('/help', [HelpController::class, 'index'], [], 'help');
    $router->get('/help/{page:[A-Za-z0-9][A-Za-z0-9-]*}', [HelpController::class, 'show']);
});

// --- Assets ---------------------------------------------------------------
// Static segments are registered before the {id} routes so that /assets/create
// and /assets/labels are never swallowed by the wildcard.
$router->group(['auth'], static function (Router $router): void {
    $router->get('/assets',        [AssetController::class, 'index'],  ['can:assets.view'], 'assets');
    $router->get('/assets/create', [AssetController::class, 'create'], ['can:assets.create']);
    $router->post('/assets',       [AssetController::class, 'store'],  ['can:assets.create', 'csrf']);

    $router->get('/assets/labels', [LabelController::class, 'sheet'], ['can:assets.view']);
    $router->get('/assets/print',  [AssetController::class, 'printList'], ['can:assets.view']);
    $router->get('/assets/export', [AssetExportController::class, 'export'], ['can:assets.export']);

    $router->get('/assets/{id:\d+}',            [AssetController::class, 'show'],    ['can:assets.view']);
    $router->get('/assets/{id:\d+}/edit',       [AssetController::class, 'edit'],    ['can:assets.edit']);
    $router->post('/assets/{id:\d+}',           [AssetController::class, 'update'],  ['can:assets.edit', 'csrf']);
    $router->post('/assets/{id:\d+}/archive',   [AssetController::class, 'archive'], ['can:assets.edit', 'csrf']);
    $router->post('/assets/{id:\d+}/restore',   [AssetController::class, 'restore'], ['can:assets.edit', 'csrf']);
    $router->post('/assets/{id:\d+}/delete',    [AssetController::class, 'destroy'], ['can:assets.delete', 'csrf']);

    // Barcode label for a single asset, and the full record as a document.
    $router->get('/assets/{id:\d+}/label', [LabelController::class, 'single'], ['can:assets.view']);
    $router->get('/assets/{id:\d+}/print', [AssetController::class, 'printOne'], ['can:assets.view']);

    // Copy workflows.
    $router->get('/assets/{id:\d+}/copy',   [AssetCopyController::class, 'copyForm'],    ['can:assets.create']);
    $router->post('/assets/{id:\d+}/copy',  [AssetCopyController::class, 'storeCopies'], ['can:assets.create', 'csrf']);
    $router->get('/assets/{id:\d+}/apply',  [AssetCopyController::class, 'applyForm'],   ['can:assets.edit']);
    $router->post('/assets/{id:\d+}/apply', [AssetCopyController::class, 'applyStore'],  ['can:assets.edit', 'csrf']);

    // Manuals (PDF).
    // The shared media library. A photo or document held once and attached to
    // as many assets as need it — see App\Models\MediaLibrary. Condition
    // photos are not library items and keep their own routes above.
    $router->get('/media',            [MediaController::class, 'index'],  ['can:assets.view'], 'media');
    $router->get('/media/search',     [MediaController::class, 'search'], ['can:assets.view']);
    $router->post('/media',           [MediaController::class, 'store'],  ['canany:media.photo.upload,media.manual.upload', 'csrf']);
    $router->get('/media/{id:\d+}',   [MediaController::class, 'show'],   ['can:assets.view']);
    $router->get('/media/{id:\d+}/thumbnail', [MediaController::class, 'thumbnail'], ['can:assets.view']);
    $router->post('/media/{id:\d+}/delete',   [MediaController::class, 'destroy'],   ['can:media.manual.delete', 'csrf']);

    $router->post('/assets/{id:\d+}/media',        [MediaController::class, 'attach'], ['can:assets.edit', 'csrf']);
    $router->post('/assets/{id:\d+}/media/upload', [MediaController::class, 'upload'], ['can:assets.edit', 'csrf']);
    $router->post('/assets/{id:\d+}/media/{mediaId:\d+}/detach', [MediaController::class, 'detach'], ['can:assets.edit', 'csrf']);

    // Faults. Reporting one is its own permission, not assets.edit: saying
    // "this is broken" is something the person holding the broken thing does,
    // and it need not come with the right to rewrite the record. Reading the
    // history is part of seeing the asset.
    $router->get('/assets/{assetId:\d+}/faults',        [FaultController::class, 'history'], ['can:assets.view']);
    $router->get('/assets/{assetId:\d+}/faults/report', [FaultController::class, 'create'],  ['can:faults.report']);
    $router->post('/assets/{assetId:\d+}/faults',       [FaultController::class, 'store'],   ['can:faults.report', 'csrf']);

    // Evidence, streamed through PHP like every other upload — the files live
    // outside the document root.
    $router->get('/faults/{reportId:\d+}/photos/{photoId:\d+}', [FaultController::class, 'photo'], ['can:assets.view']);

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

    // Unplanned work: no schedule exists, so the flow starts by finding the
    // asset. Registered before the {id} routes for the same reason as the rest.
    $router->get('/maintenance/log', [MaintenanceController::class, 'logChooser'], ['can:maintenance.complete']);

    $router->get('/maintenance/create',  [MaintenanceController::class, 'create'],  ['can:maintenance.manage']);
    $router->post('/maintenance',        [MaintenanceController::class, 'store'],   ['can:maintenance.manage', 'csrf']);

    // Routines: the structured procedures a technician fills in.
    //
    // Reading one needs only `maintenance.view`; changing one needs
    // `routines.manage`, which by default only an Administrator holds.
    // Designing a procedure and following it are different jobs, and a
    // technician who can record work must not thereby be able to rewrite what
    // the work asks. Registered before the {id} routes, as everywhere.
    $router->get('/maintenance/routines',        [RoutineController::class, 'index'],  ['can:maintenance.view'], 'routines');
    $router->get('/maintenance/routines/create', [RoutineController::class, 'create'], ['can:routines.manage']);
    $router->post('/maintenance/routines',       [RoutineController::class, 'store'],  ['can:routines.manage', 'csrf']);

    $router->get('/maintenance/routines/{id:\d+}',         [RoutineController::class, 'show'],    ['can:maintenance.view']);
    $router->get('/maintenance/routines/{id:\d+}/preview', [RoutineController::class, 'preview'], ['can:maintenance.view']);
    $router->get('/maintenance/routines/{id:\d+}/edit',    [RoutineController::class, 'edit'],    ['can:routines.manage']);

    $router->post('/maintenance/routines/{id:\d+}',             [RoutineController::class, 'update'],     ['can:routines.manage', 'csrf']);
    $router->post('/maintenance/routines/{id:\d+}/status',      [RoutineController::class, 'setStatus'],  ['can:routines.manage', 'csrf']);
    $router->post('/maintenance/routines/{id:\d+}/new-version', [RoutineController::class, 'newVersion'], ['can:routines.manage', 'csrf']);
    $router->post('/maintenance/routines/{id:\d+}/publish',     [RoutineController::class, 'publish'],    ['can:routines.manage', 'csrf']);
    $router->post('/maintenance/routines/{id:\d+}/discard',     [RoutineController::class, 'discard'],    ['can:routines.manage', 'csrf']);

    $router->post('/maintenance/routines/{id:\d+}/pages',                 [RoutineController::class, 'addPage'],  ['can:routines.manage', 'csrf']);
    $router->post('/maintenance/routines/{id:\d+}/pages/{pageId:\d+}',    [RoutineController::class, 'savePage'], ['can:routines.manage', 'csrf']);

    // A routine that was carried out. Reading one is part of reading the
    // maintenance history, so it needs no permission of its own.
    $router->get('/maintenance/completions/{id:\d+}',                    [RoutineRunController::class, 'show'], ['can:maintenance.view']);
    $router->get('/maintenance/completions/{id:\d+}/pdf',                [RoutineRunController::class, 'pdf'],  ['can:maintenance.view']);
    $router->get('/maintenance/completions/{id:\d+}/files/{fileId:\d+}', [RoutineRunController::class, 'file'], ['can:maintenance.view']);

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

    // Running a routine against an asset, with or without a schedule behind
    // it. Carrying one out is `maintenance.complete`, the same permission as
    // logging any other work.
    $router->get('/assets/{assetId:\d+}/routines', [RoutineRunController::class, 'choose'], ['can:maintenance.complete']);
    $router->get('/assets/{assetId:\d+}/routines/{routineId:\d+}/run',  [RoutineRunController::class, 'run'],   ['can:maintenance.complete']);
    $router->post('/assets/{assetId:\d+}/routines/{routineId:\d+}/run', [RoutineRunController::class, 'store'], ['can:maintenance.complete', 'csrf']);

    // Correcting a record after the fact. Recording work needs
    // `maintenance.complete`; rewriting what a record says is a bigger thing,
    // and every edit is written to the activity log.
    $router->get('/maintenance/logs/{logId:\d+}/edit', [MaintenanceController::class, 'editLog'],   ['can:maintenance.manage']);
    $router->post('/maintenance/logs/{logId:\d+}',     [MaintenanceController::class, 'updateLog'], ['can:maintenance.manage', 'csrf']);

    // Evidence attached to a completion: photos, and the paperwork behind it.
    $router->get('/maintenance/logs/{logId:\d+}/photos/{photoId:\d+}', [MaintenanceController::class, 'photo'], ['can:maintenance.view']);
    $router->get('/maintenance/logs/{logId:\d+}/documents/{documentId:\d+}', [MaintenanceController::class, 'document'], ['can:maintenance.view']);
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

    // Per-asset history. There is no requires-PAT toggle route any more: the
    // only thing that posted to it was a button on the warning banner, and a
    // warning that offers to switch itself off is the wrong control. The tick
    // box on the asset edit form does the same job, with the same permission.
    $router->get('/assets/{assetId:\d+}/pat', [PatController::class, 'history'], ['can:pat.view']);
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

// --- CSV export -----------------------------------------------------------
// The hub and the two register screens. The file itself is still produced by
// AssetExportController and ReportController, so each format has one definition.
$router->group(['auth'], static function (Router $router): void {
    $router->get('/export', [ExportController::class, 'index'], ['canany:assets.export,reports.view'], 'export');
    $router->get('/export/assets', [ExportController::class, 'assets'], ['can:assets.export']);
    $router->get('/export/assets/select', [ExportController::class, 'assetsSelect'], ['can:assets.export']);
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
    $router->get('/reports', [ReportController::class, 'index'], ['can:reports.view'], 'reports');

    // Defining a saved report. Registered before the {key} route, as everywhere
    // — though these could not be swallowed by it in any case, since {key}
    // matches no slashes. Opening a saved report needs no route of its own: it
    // arrives in the registry as an ordinary Report and goes through 'show'.
    $router->get('/reports/custom/create',            [CustomReportController::class, 'create'],  ['can:reports.manage']);
    $router->post('/reports/custom',                  [CustomReportController::class, 'store'],   ['can:reports.manage', 'csrf']);
    $router->get('/reports/custom/{id:\d+}/edit',     [CustomReportController::class, 'edit'],    ['can:reports.manage']);
    $router->post('/reports/custom/{id:\d+}',         [CustomReportController::class, 'update'],  ['can:reports.manage', 'csrf']);
    $router->post('/reports/custom/{id:\d+}/delete',  [CustomReportController::class, 'destroy'], ['can:reports.manage', 'csrf']);

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

// --- REST API -------------------------------------------------------------
//
// Outside every middleware group on purpose. `auth` redirects to a sign-in page,
// which is the wrong answer to a request carrying an API key; `csrf` protects a
// browser form, which this is not. App\Api\Gate does the equivalent work and
// answers in JSON — and the writes it allows are gated on a key, never on a
// cookie, so there is nothing for a cross-site form post to ride on.
//
// Every path is served by one controller pair: the generic ResourceController
// and the registry behind it. Adding a resource adds no route.
$router->get('/api/v1',              [MetaController::class, 'index']);
$router->get('/api/v1/openapi.json', [MetaController::class, 'openapi']);

// The readable version, for a person in a browser. A normal page, so it is in
// the `auth` group rather than the API's own authentication.
$router->group(['auth'], static function (Router $router): void {
    $router->get('/api/docs', [MetaController::class, 'docs'], [], 'api.docs');
});

$router->get('/api/v1/{resource:[a-z0-9-]+}',              [ResourceController::class, 'index']);
$router->post('/api/v1/{resource:[a-z0-9-]+}',             [ResourceController::class, 'store']);
$router->get('/api/v1/{resource:[a-z0-9-]+}/{id:\d+}',     [ResourceController::class, 'show']);
$router->patch('/api/v1/{resource:[a-z0-9-]+}/{id:\d+}',   [ResourceController::class, 'update']);
$router->put('/api/v1/{resource:[a-z0-9-]+}/{id:\d+}',     [ResourceController::class, 'update']);
$router->delete('/api/v1/{resource:[a-z0-9-]+}/{id:\d+}',  [ResourceController::class, 'destroy']);

// --- Administration -------------------------------------------------------
$router->group(['auth'], static function (Router $router): void {
    // Users
    $router->get('/admin/users',                    [UserController::class, 'index'],  ['can:users.view'], 'admin.users');
    $router->get('/admin/users/create',             [UserController::class, 'create'], ['can:users.manage']);
    $router->post('/admin/users',                   [UserController::class, 'store'],  ['can:users.manage', 'csrf']);
    $router->get('/admin/users/{id:\d+}/edit',      [UserController::class, 'edit'],   ['can:users.manage']);
    $router->post('/admin/users/{id:\d+}',          [UserController::class, 'update'], ['can:users.manage', 'csrf']);
    $router->post('/admin/users/{id:\d+}/password', [UserController::class, 'resetPassword'], ['can:users.manage', 'csrf']);
    $router->post('/admin/users/{id:\d+}/invite',   [UserController::class, 'invite'],        ['can:users.manage', 'csrf']);

    // The lost-phone path: an administrator removes somebody's second factor.
    // A removal, not a reset — see SecurityController::adminReset().
    $router->post('/admin/users/{id:\d+}/two-factor/reset', [SecurityController::class, 'adminReset'], ['can:users.manage', 'csrf']);
    $router->post('/admin/users/{id:\d+}/status',   [UserController::class, 'toggleActive'],  ['can:users.manage', 'csrf']);

    // Roles and permissions
    $router->get('/admin/roles',                [RoleController::class, 'index'],  ['can:roles.manage'], 'admin.roles');
    $router->get('/admin/roles/create',         [RoleController::class, 'create'], ['can:roles.manage']);
    $router->post('/admin/roles',               [RoleController::class, 'store'],  ['can:roles.manage', 'csrf']);
    $router->get('/admin/roles/{id:\d+}/edit',  [RoleController::class, 'edit'],   ['can:roles.manage']);
    $router->post('/admin/roles/{id:\d+}',      [RoleController::class, 'update'], ['can:roles.manage', 'csrf']);

    // Teams: the groups maintenance can be assigned to.
    $router->get('/admin/teams',                [TeamController::class, 'index'],  ['can:teams.manage'], 'admin.teams');
    $router->get('/admin/teams/create',         [TeamController::class, 'create'], ['can:teams.manage']);
    $router->post('/admin/teams',               [TeamController::class, 'store'],  ['can:teams.manage', 'csrf']);
    $router->get('/admin/teams/{id:\d+}/edit',  [TeamController::class, 'edit'],   ['can:teams.manage']);
    $router->post('/admin/teams/{id:\d+}',      [TeamController::class, 'update'], ['can:teams.manage', 'csrf']);
    $router->post('/admin/teams/{id:\d+}/status', [TeamController::class, 'toggleActive'], ['can:teams.manage', 'csrf']);
    $router->post('/admin/teams/{id:\d+}/members', [TeamController::class, 'addMember'], ['can:teams.manage', 'csrf']);
    $router->post('/admin/teams/{id:\d+}/members/{userId:\d+}/remove', [TeamController::class, 'removeMember'], ['can:teams.manage', 'csrf']);

    // Reference data
    // The static /create must be registered before the {id} routes, as everywhere.
    $router->get('/admin/categories',                 [CategoryController::class, 'index'],   ['can:categories.manage'], 'admin.categories');
    $router->get('/admin/categories/create',          [CategoryController::class, 'create'],  ['can:categories.manage']);
    $router->post('/admin/categories',                [CategoryController::class, 'store'],   ['can:categories.manage', 'csrf']);
    $router->get('/admin/categories/{id:\d+}/edit',   [CategoryController::class, 'edit'],    ['can:categories.manage']);
    $router->post('/admin/categories/{id:\d+}',       [CategoryController::class, 'update'],  ['can:categories.manage', 'csrf']);
    $router->post('/admin/categories/{id:\d+}/delete',[CategoryController::class, 'destroy'], ['can:categories.manage', 'csrf']);

    $router->get('/admin/locations',                 [LocationController::class, 'index'],   ['can:locations.manage'], 'admin.locations');
    $router->get('/admin/locations/create',          [LocationController::class, 'create'],  ['can:locations.manage']);
    $router->post('/admin/locations',                [LocationController::class, 'store'],   ['can:locations.manage', 'csrf']);
    $router->get('/admin/locations/{id:\d+}/edit',   [LocationController::class, 'edit'],    ['can:locations.manage']);
    $router->post('/admin/locations/{id:\d+}',       [LocationController::class, 'update'],  ['can:locations.manage', 'csrf']);
    $router->post('/admin/locations/{id:\d+}/delete',[LocationController::class, 'destroy'], ['can:locations.manage', 'csrf']);

    // Application settings
    $router->get('/admin/settings',  [SettingsController::class, 'edit'],   ['can:settings.manage'], 'admin.settings');
    $router->post('/admin/settings', [SettingsController::class, 'update'], ['can:settings.manage', 'csrf']);

    // Branding. Separate from the settings form because an upload has nothing
    // to do with the other fields and removal is its own action.
    $router->post('/admin/settings/logo', [SettingsController::class, 'updateLogo'], ['can:settings.manage', 'csrf']);
    $router->post('/admin/settings/logo/{variant:light|dark}/remove', [SettingsController::class, 'removeLogo'], ['can:settings.manage', 'csrf']);

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

    // API keys. Its own permission rather than settings.manage: issuing a
    // credential that acts as somebody is the same kind of act as creating
    // their account.
    $router->get('/admin/api',                      [ApiKeyController::class, 'index'],          ['can:api.manage'], 'admin.api');
    $router->post('/admin/api/settings',            [ApiKeyController::class, 'updateSettings'], ['can:api.manage', 'csrf']);
    $router->post('/admin/api/keys',                [ApiKeyController::class, 'store'],          ['can:api.manage', 'csrf']);
    $router->post('/admin/api/keys/{id:\d+}/revoke',[ApiKeyController::class, 'revoke'],         ['can:api.manage', 'csrf']);
    $router->post('/admin/api/keys/{id:\d+}/delete',[ApiKeyController::class, 'destroy'],        ['can:api.manage', 'csrf']);

    // Asset templates: the starting points the Add asset form can offer.
    // Reference data an operation maintains for itself, like categories, so
    // Administrator and Manager / Staff both hold `templates.manage`.
    $router->get('/admin/templates',            [AssetTemplateController::class, 'index'],  ['can:templates.manage'], 'admin.templates');
    $router->get('/admin/templates/create',     [AssetTemplateController::class, 'create'], ['can:templates.manage']);
    $router->post('/admin/templates',           [AssetTemplateController::class, 'store'],  ['can:templates.manage', 'csrf']);
    $router->get('/admin/templates/{id:\d+}/edit', [AssetTemplateController::class, 'edit'],   ['can:templates.manage']);
    $router->post('/admin/templates/{id:\d+}',     [AssetTemplateController::class, 'update'], ['can:templates.manage', 'csrf']);
    $router->post('/admin/templates/{id:\d+}/delete', [AssetTemplateController::class, 'destroy'], ['can:templates.manage', 'csrf']);
    $router->post('/admin/templates/{id:\d+}/media',        [AssetTemplateController::class, 'attach'], ['can:templates.manage', 'csrf']);
    $router->post('/admin/templates/{id:\d+}/media/upload', [AssetTemplateController::class, 'upload'], ['can:templates.manage', 'csrf']);
    $router->post('/admin/templates/{id:\d+}/media/{mediaId:\d+}/detach', [AssetTemplateController::class, 'detach'], ['can:templates.manage', 'csrf']);

    // Audit trail
    $router->get('/admin/activity', [ActivityController::class, 'index'], ['can:audit.view'], 'admin.activity');
});

return $router;
