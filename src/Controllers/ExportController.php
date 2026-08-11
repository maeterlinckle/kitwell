<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Reports\ReportRegistry;

/**
 * The export hub.
 *
 * Deliberately shaped like /import: exporting is an occasional job done
 * deliberately, not a button you meet while browsing the register. Everything
 * that leaves this application as a file is listed in one place, so "how do I
 * get the data out" has one answer.
 *
 * The controller only *presents* exports; the files themselves are still
 * produced by the code that owns the data — AssetExportController for the
 * register, ReportController for the report CSVs — so there is one definition
 * of each format.
 */
final class ExportController extends Controller
{
    public function index(): void
    {
        $this->view('export/index', [
            'pageTitle' => 'Export data',
            'canAssets' => Auth::can('assets.export'),
            'reports'   => Auth::can('reports.view') ? ReportRegistry::all() : [],
        ]);
    }

    /** Options for the register export: which rows, and which extra columns. */
    public function assets(): void
    {
        Auth::authorize('assets.export');

        $filters = AssetController::filtersFromRequest();

        $this->view('export/assets', [
            'pageTitle'  => 'Export the asset register',
            'filters'    => $filters,
            'categories' => Category::all(),
            'locations'  => Location::all(),
            // One row is enough to learn how many there are.
            'total'      => Asset::search($filters, 1, 1)['total'],
            'extras'     => AssetExportController::extraGroups(),
        ]);
    }

    /**
     * Pick individual assets to export.
     *
     * Its own page, because choosing rows and choosing columns are two
     * different jobs and putting both on one screen makes neither clear.
     */
    public function assetsSelect(): void
    {
        Auth::authorize('assets.export');

        $filters = AssetController::filtersFromRequest();
        $page    = max(1, (int) Request::query('page', 1));
        $result  = Asset::search($filters, $page, 50);

        $this->view('export/assets-select', [
            'pageTitle'  => 'Choose assets to export',
            'filters'    => $filters,
            'result'     => $result,
            'rows'       => $result['rows'],
            'categories' => Category::all(),
            'locations'  => Location::all(),
            'extras'     => AssetExportController::extraGroups(),
        ]);
    }
}
