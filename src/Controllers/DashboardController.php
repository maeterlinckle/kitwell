<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\FaultReport;
use App\Models\Hire;
use App\Models\MaintenanceSchedule;
use App\Models\PatRecord;

final class DashboardController extends Controller
{
    public function index(): void
    {
        // A hirer has no dashboard to speak of — send them straight to the
        // only thing they can see.
        if (!Auth::can('assets.view') && !Auth::can('hires.view') && Auth::can('hires.view_own')) {
            Response::redirect('/my-hires');
        }

        // Foundation-stage dashboard: enough to prove auth, roles and the schema
        // are wired up. The full asset views arrive with the asset UI.
        $stats = [];

        if (Auth::can('assets.view')) {
            $stats['assets'] = [
                'total'          => (int) Database::scalar('SELECT COUNT(*) FROM assets WHERE parent_asset_id IS NULL'),
                'sub_assets'     => (int) Database::scalar('SELECT COUNT(*) FROM assets WHERE parent_asset_id IS NOT NULL'),
                'in_stock'       => (int) Database::scalar("SELECT COUNT(*) FROM assets WHERE status = 'In Stock'"),
                'on_hire'        => (int) Database::scalar("SELECT COUNT(*) FROM assets WHERE status = 'On Hire'"),
                'in_maintenance' => (int) Database::scalar("SELECT COUNT(*) FROM assets WHERE status = 'In Maintenance'"),
            ];

            // The same query the faulty-assets report runs, so the tile and the
            // list behind it cannot disagree about how many there are.
            $stats['faults'] = FaultReport::summary();
        }

        if (Auth::can('maintenance.view')) {
            // One shared definition of "overdue" and "due soon" — the same
            // method backs the maintenance list and the reports module.
            $stats['maintenance'] = MaintenanceSchedule::summary();
        }

        if (Auth::can('pat.view')) {
            // Same summary the PAT register and reports use, so the numbers
            // on every screen agree.
            $stats['pat'] = PatRecord::summary();
        }

        if (Auth::can('hires.view')) {
            Hire::refreshOverdue();
            $stats['hires'] = Hire::summary();
        }

        $this->view('dashboard/index', [
            'pageTitle' => 'Dashboard',
            'stats'     => $stats,
            'activity'  => Auth::can('audit.view') ? ActivityLog::recent(8) : [],
        ]);
    }

    /** Lightweight endpoint for uptime checks and reverse-proxy probes. */
    public function health(): void
    {
        try {
            Database::scalar('SELECT 1');
            $database = 'ok';
        } catch (\Throwable) {
            $database = 'error';
        }

        Response::json([
            'status'   => $database === 'ok' ? 'ok' : 'degraded',
            'database' => $database,
            'time'     => date('c'),
        ], $database === 'ok' ? 200 : 503);
    }
}
