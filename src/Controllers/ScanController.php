<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\Asset;
use App\Models\Hire;
use App\Models\RoutineCompletion;

/**
 * Quick scan, reachable from every page.
 *
 * Three input routes, all landing in the same place:
 *   - the device camera (native BarcodeDetector, or our own reader in
 *     public/js/barcode.js: Code 128, Code 39 and QR)
 *   - a USB barcode scanner, which behaves as a keyboard
 *   - typing the tag by hand
 *
 * A code is looked up against both the asset tag and the barcode field, so a
 * Code 39 or QR label that arrived on the equipment can be recorded against
 * the asset and then scanned like any other.
 */
final class ScanController extends Controller
{
    private const MODES = ['view', 'checkout', 'return', 'maintenance', 'routine', 'pat', 'new'];

    public function index(): void
    {
        $mode = (string) Request::query('mode', 'view');

        if (!in_array($mode, self::MODES, true)) {
            $mode = 'view';
        }

        // The asset a New-asset scan collided with, so the page can offer a
        // way straight to it rather than just saying no.
        $takenId = (int) Request::query('taken', 0);

        $this->view('scan/index', [
            'pageTitle' => 'Scan',
            'mode'      => $mode,
            'taken'     => $takenId > 0 ? Asset::find($takenId) : null,
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
            // A tag nothing answers to is the good case when the errand is
            // registering something, so the page is told where that goes.
            Response::json([
                'found'   => false,
                'code'    => $code,
                'message' => 'No asset matches ' . $code . '.',
                'can'     => ['create' => Auth::can('assets.create')],
                'create_url' => url('/assets/create?tag=' . rawurlencode($code)),
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
                'checkout'    => Auth::can('hires.create') && $blocked === null,
                'return'      => Auth::can('hires.return') && $openHire !== null,
                'maintenance' => Auth::can('maintenance.complete'),
                'routine'     => Auth::can('maintenance.complete'),
                'pat'         => Auth::can('pat.manage'),
            ],
            'blocked'         => $blocked,
            'routine'         => self::routineJson($assetId),
            'edit_url'        => url('/assets/' . $assetId . '/edit'),
            'checkout_url'    => url('/hires/checkout?asset=' . $assetId),
            'maintenance_url' => url('/assets/' . $assetId . '/maintenance/log'),
            'pat_url'         => url('/pat/create?asset=' . $assetId),
        ]);
    }

    /**
     * The same decision, shaped for the scanner's JSON.
     *
     * @return array{url:string,reason:string,completed_on:?string}
     */
    private static function routineJson(int $assetId): array
    {
        $destination = self::routineDestination($assetId);

        return [
            'url'          => url($destination['path']),
            'reason'       => $destination['reason'],
            'completed_on' => $destination['completed_on'] === null
                ? null
                : format_date($destination['completed_on']),
        ];
    }

    /**
     * Where a Routine scan should land.
     *
     * Three cases, in order:
     *   - a run is open on this asset — go to it, because that is the whole
     *     point of scanning at a station;
     *   - a routine was completed on it within the last few days — show that
     *     record, so nobody repeats work that was just done;
     *   - otherwise the maintenance log page, which offers to start a routine.
     *
     * @return array{path:string,reason:string,completion_id:int,completed_on:?string}
     */
    private static function routineDestination(int $assetId): array
    {
        $open = RoutineCompletion::openForAsset($assetId);

        if ($open !== null) {
            return [
                'path'          => '/maintenance/completions/' . (int) $open['id'],
                'reason'        => 'open',
                'completion_id' => (int) $open['id'],
                'completed_on'  => null,
            ];
        }

        $recent = RoutineCompletion::recentForAsset($assetId);

        if ($recent !== null) {
            return [
                'path'          => '/maintenance/completions/' . (int) $recent['id'],
                'reason'        => 'recent',
                'completion_id' => (int) $recent['id'],
                'completed_on'  => (string) $recent['completed_at'],
            ];
        }

        return [
            'path'          => '/assets/' . $assetId . '/maintenance/log',
            'reason'        => 'none',
            'completion_id' => 0,
            'completed_on'  => null,
        ];
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

        // Registering something new: an unused tag is what we want, and a tag
        // already on the shelf has to be refused rather than quietly creating a
        // second asset claiming to be the same item.
        if ($mode === 'new') {
            if (!Auth::can('assets.create')) {
                Flash::error('You do not have permission to add assets.');
                Response::redirect('/scan');
            }

            if ($asset !== null) {
                Flash::error(sprintf(
                    'Asset tag “%s” is already in use by %s. Edit that asset instead of registering it again.',
                    $code,
                    $asset['name']
                ));
                Response::redirect('/scan?mode=new&taken=' . (int) $asset['id']);
            }

            Response::redirect('/assets/create?tag=' . rawurlencode($code));
        }

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

        // Scanning to record a repair lands on the form, not on the asset page.
        // Recording the work is the errand; the asset page is a detour.
        if ($mode === 'maintenance' && Auth::can('maintenance.complete')) {
            Response::redirect('/assets/' . $assetId . '/maintenance/log');
        }

        // A routine scan is the one that has to think. Somebody standing at a
        // station wants their part of the job in front of them, not a menu.
        if ($mode === 'routine' && Auth::can('maintenance.complete')) {
            $destination = self::routineDestination($assetId);

            if ($destination['reason'] === 'recent') {
                Flash::info(sprintf(
                    '%s had a routine completed on %s. Check it before starting the work again.',
                    (string) $asset['asset_tag'],
                    format_date((string) $destination['completed_on'])
                ));
            }

            Response::redirect($destination['path']);
        }

        // Same for a PAT test: the tester has the appliance in their hand.
        if ($mode === 'pat' && Auth::can('pat.manage')) {
            Response::redirect('/pat/create?asset=' . $assetId);
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
