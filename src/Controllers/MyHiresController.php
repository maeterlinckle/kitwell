<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Upload;
use App\Models\Asset;
use App\Models\AssetManual;
use App\Models\AssetPhoto;
use App\Models\Hirer;
use App\Models\Hire;
use App\Models\PatRecord;

/**
 * The hirer self-service portal.
 *
 * Deliberately a separate surface from the asset controllers. A hirer never
 * reaches AssetController, so there is no route by which they could see the
 * register, financial fields, internal notes, maintenance or the full PAT
 * history — the restriction is structural rather than a list of things
 * remembered to be hidden.
 *
 * Everything is scoped through the hirer record linked to the signed-in
 * user. No hirer record, no data.
 */
final class MyHiresController extends Controller
{
    /**
     * Fields a hirer is allowed to see about an asset they hold.
     *
     * An allow-list, not a deny-list: a new column added to `assets` in future
     * cannot leak here by accident.
     */
    private const VISIBLE_ASSET_FIELDS = [
        'id', 'asset_tag', 'name', 'description', 'condition_rating',
        'manufacturer', 'model', 'manufacturer_url', 'requires_pat',
    ];

    public function index(): void
    {
        $hirer = self::hirerForCurrentUser();

        if ($hirer === null) {
            $this->view('my-hires/unlinked', ['pageTitle' => 'My hires']);

            return;
        }

        Hire::refreshOverdue();

        $hires     = Hire::forHirer((int) $hirer['id']);
        $open      = array_values(array_filter($hires, static fn (array $l): bool => $l['returned_at'] === null));
        $returned  = array_values(array_filter($hires, static fn (array $l): bool => $l['returned_at'] !== null));

        // One query for the thumbnails of everything they hold.
        $photos = AssetPhoto::primaryForMany(
            array_map(static fn (array $l): int => (int) $l['asset_id'], $open)
        );

        $this->view('my-hires/index', [
            'pageTitle' => 'My hires',
            'hirer'  => $hirer,
            'openHires' => $open,
            'pastHires' => array_slice($returned, 0, 10),
            'photos'    => $photos,
        ]);
    }

    /** Read-only detail for one item the hirer currently holds. */
    public function show(string $hireId): void
    {
        [$hirer, $hire] = $this->requireOwnHire((int) $hireId);

        $assetId = (int) $hire['asset_id'];
        $asset   = Asset::find($assetId);

        if ($asset === null) {
            $this->notFound();
        }

        // Latest PAT result only — never the history, and only when the item
        // is actually subject to testing.
        $pat = null;
        if ((int) $asset['requires_pat'] === 1) {
            $status = PatRecord::statusForAsset($assetId);

            if ($status !== null && $status['latest_record_id'] !== null) {
                $pat = [
                    'result'      => $status['overall_result'],
                    'test_date'   => $status['test_date'],
                    'retest_due'  => $status['retest_due_date'],
                    'status'      => $status['pat_status'],
                ];
            }
        }

        $this->view('my-hires/show', [
            'pageTitle' => $asset['asset_tag'] . ' · ' . $asset['name'],
            'hirer'  => $hirer,
            'hire'      => $hire,
            'asset'     => self::visibleAsset($asset),
            'manuals'   => AssetManual::forAsset($assetId),
            'photo'     => AssetPhoto::primaryFor($assetId),
            'pat'       => $pat,
        ]);
    }

    /** The asset's main photo, scoped to a hire the hirer holds. */
    public function photo(string $hireId): void
    {
        [, $hire] = $this->requireOwnHire((int) $hireId);

        $photo = AssetPhoto::primaryFor((int) $hire['asset_id']);

        if ($photo === null) {
            $this->notFound('No photo is available for this item.');
        }

        $wantsThumb = Request::query('size') === 'thumb';
        $relative   = ($wantsThumb && !empty($photo['thumbnail_path']))
            ? (string) $photo['thumbnail_path']
            : (string) $photo['file_path'];

        $path = Upload::absolutePath($relative) ?? Upload::absolutePath((string) $photo['file_path']);

        if ($path === null) {
            $this->notFound('The image file is missing from the server.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . (string) $photo['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=2592000');
        header_remove('Pragma');

        readfile($path);
        exit;
    }

    /** A manual for an item they hold — view in the browser or download. */
    public function manual(string $hireId, string $manualId): void
    {
        [, $hire] = $this->requireOwnHire((int) $hireId);

        $manual = AssetManual::find((int) $manualId);

        if ($manual === null || (int) $manual['asset_id'] !== (int) $hire['asset_id']) {
            $this->notFound('That document is not available for this item.');
        }

        $path = Upload::absolutePath((string) $manual['file_path']);

        if ($path === null) {
            $this->notFound('The file for this manual is missing from the server.');
        }

        $download = Request::query('download') === '1';
        $filename = Upload::displayName((string) ($manual['original_filename'] ?: $manual['title'] . '.pdf'));

        if (!str_ends_with(strtolower($filename), '.pdf')) {
            $filename .= '.pdf';
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Length: ' . (string) filesize($path));
        header(sprintf('Content-Disposition: %s; filename="%s"', $download ? 'attachment' : 'inline', str_replace('"', '', $filename)));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=600');
        header_remove('Pragma');

        readfile($path);
        exit;
    }

    /**
     * Resolve the signed-in user's hirer record and one of their hires,
     * or stop with a 404 that reveals nothing about other people's hires.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function requireOwnHire(int $hireId): array
    {
        $hirer = self::hirerForCurrentUser();

        if ($hirer === null) {
            $this->notFound('No hires are linked to your account.');
        }

        $hire = Hire::findForHirer($hireId, (int) $hirer['id']);

        // Deliberately the same message whether the hire does not exist or
        // belongs to someone else.
        if ($hire === null) {
            $this->notFound('That item is not on hire to you.');
        }

        return [$hirer, $hire];
    }

    /** @return array<string,mixed>|null */
    private static function hirerForCurrentUser(): ?array
    {
        $userId = Auth::id();

        return $userId === null ? null : Hirer::findByUserId($userId);
    }

    /**
     * Strip an asset down to the fields a hirer may see.
     *
     * @param array<string,mixed> $asset
     * @return array<string,mixed>
     */
    private static function visibleAsset(array $asset): array
    {
        return array_intersect_key($asset, array_flip(self::VISIBLE_ASSET_FIELDS));
    }
}
