<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\Asset;
use App\Models\Hire;

/**
 * Quick scan, reachable from every page.
 *
 * Three input routes, all landing in the same place:
 *   - the device camera (native BarcodeDetector, or our own Code 128 reader)
 *   - a USB barcode scanner, which behaves as a keyboard
 *   - typing the tag by hand
 */
final class ScanController extends Controller
{
    private const MODES = ['view', 'checkout', 'return'];

    public function index(): void
    {
        $mode = (string) Request::query('mode', 'view');

        if (!in_array($mode, self::MODES, true)) {
            $mode = 'view';
        }

        $this->view('scan/index', [
            'pageTitle' => 'Scan',
            'mode'      => $mode,
        ]);
    }

    /**
     * JSON lookup used by the scanner as soon as it reads a code.
     *
     * Returns everything the page needs to decide what to offer next, so a
     * scan does not need a second round trip.
     */
    public function lookup(): void
    {
        $code = trim((string) Request::query('code', ''));

        if ($code === '') {
            Response::json(['found' => false, 'message' => 'No code was scanned.'], 400);
        }

        if (mb_strlen($code) > 64) {
            Response::json(['found' => false, 'message' => 'That code is too long to be an asset tag.'], 400);
        }

        $asset = Asset::findByTag($code);

        if ($asset === null) {
            Response::json([
                'found'   => false,
                'code'    => $code,
                'message' => 'No asset matches ' . $code . '.',
            ]);
        }

        $assetId  = (int) $asset['id'];
        $openHire = Hire::openForAsset($assetId);
        $blocked  = Hire::blockedReason($asset);

        Response::json([
            'found' => true,
            'code'  => $code,
            'asset' => [
                'id'        => $assetId,
                'tag'       => $asset['asset_tag'],
                'name'      => $asset['name'],
                'status'    => $asset['status'],
                'condition' => $asset['condition_rating'],
                'location'  => $asset['location_name'],
                'url'       => url('/assets/' . $assetId),
            ],
            'hire' => $openHire === null ? null : [
                'id'          => (int) $openHire['id'],
                'reference'   => $openHire['reference'],
                'hirer'    => $openHire['hirer_name'],
                'due'         => format_date($openHire['due_back_date']),
                'overdue'     => $openHire['effective_status'] === 'Overdue',
                'return_url'  => url('/hires/' . (int) $openHire['id'] . '/return'),
            ],
            'can' => [
                'checkout' => Auth::can('hires.create') && $blocked === null,
                'return'   => Auth::can('hires.return') && $openHire !== null,
            ],
            'blocked'      => $blocked,
            'checkout_url' => url('/hires/checkout?asset=' . $assetId),
        ]);
    }

    /**
     * Non-JS fallback: the same lookup as a plain form post, so a USB scanner
     * plus Enter works even with JavaScript unavailable.
     */
    public function go(): void
    {
        $code = trim((string) Request::post('code', ''));
        $mode = (string) Request::post('mode', 'view');

        if (!in_array($mode, self::MODES, true)) {
            $mode = 'view';
        }

        if ($code === '') {
            Flash::error('Enter or scan an asset tag.');
            Response::redirect('/scan?mode=' . $mode);
        }

        $asset = Asset::findByTag($code);

        if ($asset === null) {
            Flash::error('No asset matches “' . $code . '”. Check the tag and try again.');
            Response::redirect('/scan?mode=' . $mode);
        }

        $assetId = (int) $asset['id'];

        if ($mode === 'checkout' && Auth::can('hires.create')) {
            $blocked = Hire::blockedReason($asset);

            if ($blocked !== null) {
                Flash::error($blocked);
                Response::redirect('/assets/' . $assetId);
            }

            Response::redirect('/hires/checkout?asset=' . $assetId);
        }

        if ($mode === 'return' && Auth::can('hires.return')) {
            $openHire = Hire::openForAsset($assetId);

            if ($openHire === null) {
                Flash::warning($asset['asset_tag'] . ' is not currently out on hire.');
                Response::redirect('/assets/' . $assetId);
            }

            Response::redirect('/hires/' . (int) $openHire['id'] . '/return');
        }

        Response::redirect('/assets/' . $assetId);
    }
}
