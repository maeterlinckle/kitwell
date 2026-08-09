<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Asset;
use App\Models\Setting;

/**
 * Printable Code 128 barcode labels — one asset, or a sheet of many.
 */
final class LabelController extends Controller
{
    public function single(string $id): void
    {
        $asset = Asset::find((int) $id);

        if ($asset === null) {
            $this->notFound();
        }

        $copies = max(1, min(60, (int) Request::query('copies', 1)));

        View::render('assets/labels', [
            'pageTitle'    => 'Label · ' . $asset['asset_tag'],
            'assets'       => array_fill(0, $copies, $asset),
            'size'         => self::size(),
            'showName'     => Setting::bool('label_show_name', true),
            'showLocation' => Setting::bool('label_show_location', true),
            'organisation' => Setting::get('organisation_name', ''),
            'backUrl'      => '/assets/' . (int) $id,
        ], 'layouts/print');
    }

    /**
     * A sheet of labels. Assets come either from an explicit id list (after a
     * batch copy, or from ticking rows in the register) or from the current
     * search filters.
     */
    public function sheet(): void
    {
        // Accepts either ids=1,2,3 (from a redirect) or ids[]=1&ids[]=2 (from
        // ticking rows in the register).
        $raw = Request::query('ids', '');
        $ids = is_array($raw)
            ? array_map('intval', $raw)
            : array_map('intval', explode(',', (string) $raw));

        $ids = array_values(array_filter($ids));

        $assets = $ids !== []
            ? Asset::byIds($ids)
            : Asset::byIds(Asset::searchIds(AssetController::filtersFromRequest(), 200));

        if ($assets === []) {
            $this->notFound('No assets were selected for printing.');
        }

        View::render('assets/labels', [
            'pageTitle'    => 'Labels (' . count($assets) . ')',
            'assets'       => $assets,
            'size'         => self::size(),
            'showName'     => Setting::bool('label_show_name', true),
            'showLocation' => Setting::bool('label_show_location', true),
            'organisation' => Setting::get('organisation_name', ''),
            'backUrl'      => '/assets',
        ], 'layouts/print');
    }

    /** Label size presets, in millimetres. */
    private static function size(): string
    {
        $size = (string) Request::query('size', 'medium');

        return in_array($size, ['small', 'medium', 'large'], true) ? $size : 'medium';
    }
}
