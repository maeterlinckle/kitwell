<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Upload;
use App\Models\Asset;
use App\Models\AssetManual;
use App\Models\AssetPhoto;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\PatRecord;

/**
 * The borrower self-service portal.
 *
 * Deliberately a separate surface from the asset controllers. A borrower never
 * reaches AssetController, so there is no route by which they could see the
 * register, financial fields, internal notes, maintenance or the full PAT
 * history — the restriction is structural rather than a list of things
 * remembered to be hidden.
 *
 * Everything is scoped through the borrower record linked to the signed-in
 * user. No borrower record, no data.
 */
final class MyLoansController extends Controller
{
    /**
     * Fields a borrower is allowed to see about an asset they hold.
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
        $borrower = self::borrowerForCurrentUser();

        if ($borrower === null) {
            $this->view('my-loans/unlinked', ['pageTitle' => 'My loans']);

            return;
        }

        Loan::refreshOverdue();

        $loans     = Loan::forBorrower((int) $borrower['id']);
        $open      = array_values(array_filter($loans, static fn (array $l): bool => $l['returned_at'] === null));
        $returned  = array_values(array_filter($loans, static fn (array $l): bool => $l['returned_at'] !== null));

        // One query for the thumbnails of everything they hold.
        $photos = AssetPhoto::primaryForMany(
            array_map(static fn (array $l): int => (int) $l['asset_id'], $open)
        );

        $this->view('my-loans/index', [
            'pageTitle' => 'My loans',
            'borrower'  => $borrower,
            'openLoans' => $open,
            'pastLoans' => array_slice($returned, 0, 10),
            'photos'    => $photos,
        ]);
    }

    /** Read-only detail for one item the borrower currently holds. */
    public function show(string $loanId): void
    {
        [$borrower, $loan] = $this->requireOwnLoan((int) $loanId);

        $assetId = (int) $loan['asset_id'];
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

        $this->view('my-loans/show', [
            'pageTitle' => $asset['asset_tag'] . ' · ' . $asset['name'],
            'borrower'  => $borrower,
            'loan'      => $loan,
            'asset'     => self::visibleAsset($asset),
            'manuals'   => AssetManual::forAsset($assetId),
            'photo'     => AssetPhoto::primaryFor($assetId),
            'pat'       => $pat,
        ]);
    }

    /** The asset's main photo, scoped to a loan the borrower holds. */
    public function photo(string $loanId): void
    {
        [, $loan] = $this->requireOwnLoan((int) $loanId);

        $photo = AssetPhoto::primaryFor((int) $loan['asset_id']);

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
    public function manual(string $loanId, string $manualId): void
    {
        [, $loan] = $this->requireOwnLoan((int) $loanId);

        $manual = AssetManual::find((int) $manualId);

        if ($manual === null || (int) $manual['asset_id'] !== (int) $loan['asset_id']) {
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
     * Resolve the signed-in user's borrower record and one of their loans,
     * or stop with a 404 that reveals nothing about other people's loans.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function requireOwnLoan(int $loanId): array
    {
        $borrower = self::borrowerForCurrentUser();

        if ($borrower === null) {
            $this->notFound('No loans are linked to your account.');
        }

        $loan = Loan::findForBorrower($loanId, (int) $borrower['id']);

        // Deliberately the same message whether the loan does not exist or
        // belongs to someone else.
        if ($loan === null) {
            $this->notFound('That item is not on loan to you.');
        }

        return [$borrower, $loan];
    }

    /** @return array<string,mixed>|null */
    private static function borrowerForCurrentUser(): ?array
    {
        $userId = Auth::id();

        return $userId === null ? null : Borrower::findByUserId($userId);
    }

    /**
     * Strip an asset down to the fields a borrower may see.
     *
     * @param array<string,mixed> $asset
     * @return array<string,mixed>
     */
    private static function visibleAsset(array $asset): array
    {
        return array_intersect_key($asset, array_flip(self::VISIBLE_ASSET_FIELDS));
    }
}
