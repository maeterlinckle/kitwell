<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Pdf;
use App\Core\Upload;
use App\Models\MaintenanceRoutine;
use App\Models\RoutineCompletion;
use App\Models\Setting;

/**
 * A completed routine as a document.
 *
 * This is meant to be read by somebody who has never seen the application —
 * a client, an inspector, a folder in a filing cabinet — so it carries the
 * masthead the printed views already use, says plainly what was done to what
 * and when, and prints every question beside its answer in the order they were
 * asked.
 *
 * Layout is deliberate rather than incidental: a fixed question column so the
 * answers line up down the page, a hairline under each row, section bands for
 * the routine's own pages, and photographs at a size somebody can actually
 * judge. The version number appears in the header and again in the footer,
 * because the whole point of versioning is lost if a printed copy does not say
 * which edition it followed.
 */
final class RoutineDocument
{
    private const INK    = [0.12, 0.14, 0.17];
    private const MUTED  = [0.42, 0.45, 0.50];
    private const RULE   = [0.85, 0.87, 0.90];
    private const BAND   = [0.94, 0.95, 0.965];
    private const PASS   = [0.11, 0.45, 0.24];
    private const FAIL   = [0.65, 0.14, 0.14];

    /** Width of the question column, as a share of the text width. */
    private const QUESTION_SHARE = 0.42;

    /** @param array<string,mixed> $completion */
    public static function build(array $completion): string
    {
        $pdf = new Pdf(Pdf::A4_WIDTH, Pdf::A4_HEIGHT, 48.0);

        $pages     = MaintenanceRoutine::structure((int) $completion['version_id']);
        $responses = RoutineCompletion::responses((int) $completion['id']);
        $files     = RoutineCompletion::files((int) $completion['id']);
        $byPage    = MaintenanceRoutine::isPageBatched($completion)
            ? RoutineCompletion::pageCompletions((int) $completion['id'])
            : [];

        $title = sprintf(
            '%s — v%d',
            (string) $completion['routine_name'],
            (int) $completion['version_number']
        );

        $pdf->setOnPage(static function (Pdf $pdf) use ($title): void {
            self::masthead($pdf, $title);
        });

        $pdf->setFooter(static function (Pdf $pdf, int $page, int $total) use ($completion): void {
            self::footer($pdf, $page, $total, $completion);
        });

        $pdf->addPage();

        self::summary($pdf, $completion);

        foreach ($pages as $page) {
            self::section($pdf, $page, $responses, $files, (int) $completion['id'], $byPage[(int) $page['id']] ?? null);
        }

        self::signOff($pdf, $completion);

        return $pdf->output();
    }

    /** @param array<string,mixed> $completion */
    public static function filename(array $completion): string
    {
        $name = sprintf(
            '%s-%s-v%d-%s.pdf',
            (string) $completion['asset_tag'],
            (string) $completion['routine_name'],
            (int) $completion['version_number'],
            date('Y-m-d', strtotime((string) $completion['completed_at']))
        );

        // A download name reaches a filesystem, so it keeps to what every
        // filesystem accepts rather than whatever the routine happens to be
        // called.
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'routine.pdf';

        return trim((string) $safe, '-');
    }

    // -- Furniture -----------------------------------------------------------

    private static function masthead(Pdf $pdf, string $title): void
    {
        $top          = $pdf->margin();
        $organisation = trim((string) (Setting::get('organisation_name') ?? ''));

        if ($organisation === '') {
            $organisation = (string) config('app.name', 'Kitwell');
        }

        $textLeft = $pdf->left();
        $logo     = Branding::printablePath();

        if ($logo !== null) {
            $size = $pdf->imageSize($logo);

            if ($size !== null && $size[0] > 0 && $size[1] > 0) {
                // Fitted inside a fixed box so a tall logo and a wide one both
                // sit on the same baseline as the text beside them.
                $boxWidth  = 108.0;
                $boxHeight = 34.0;
                $scale     = min($boxWidth / $size[0], $boxHeight / $size[1]);
                $width     = $size[0] * $scale;
                $height    = $size[1] * $scale;

                if ($pdf->image($logo, $textLeft, $top + (($boxHeight - $height) / 2), $width, $height)) {
                    $textLeft += $width + 14.0;
                }
            }
        }

        $pdf->setFont(Pdf::BOLD, 13.0);
        $pdf->text($organisation, $textLeft, $top + 13.0, self::INK);

        $pdf->setFont(Pdf::REGULAR, 8.0);
        $pdf->text(
            (string) config('app.full_name', 'Kitwell by Junction') . ' — maintenance record',
            $textLeft,
            $top + 26.0,
            self::MUTED
        );

        $pdf->setFont(Pdf::BOLD, 9.5);
        $pdf->textRight($pdf->fit($title, 260.0, Pdf::BOLD, 9.5), $pdf->right(), $top + 13.0, self::INK);

        $pdf->setFont(Pdf::REGULAR, 8.0);
        $pdf->textRight('Issued ' . format_datetime(date('Y-m-d H:i:s')), $pdf->right(), $top + 26.0, self::MUTED);

        $pdf->line($pdf->left(), $top + 40.0, $pdf->right(), $top + 40.0, 0.8, self::RULE);
        $pdf->setY($top + 56.0);
    }

    /** @param array<string,mixed> $completion */
    private static function footer(Pdf $pdf, int $page, int $total, array $completion): void
    {
        $y = $pdf->height() - $pdf->margin() + 6.0;

        $pdf->line($pdf->left(), $y - 10.0, $pdf->right(), $y - 10.0, 0.6, self::RULE);

        $pdf->setFont(Pdf::REGULAR, 7.5);
        $pdf->text(
            sprintf(
                '%s · %s v%d · record %d',
                (string) $completion['asset_tag'],
                (string) $completion['routine_name'],
                (int) $completion['version_number'],
                (int) $completion['id']
            ),
            $pdf->left(),
            $y,
            self::MUTED
        );

        $pdf->textRight(sprintf('Page %d of %d', $page, $total), $pdf->right(), $y, self::MUTED);
    }

    // -- Blocks --------------------------------------------------------------

    /** @param array<string,mixed> $completion */
    private static function summary(Pdf $pdf, array $completion): void
    {
        $pdf->setFont(Pdf::BOLD, 17.0);
        $pdf->text((string) $completion['routine_name'], $pdf->left(), $pdf->y() + 16.0, self::INK);
        $pdf->moveDown(24.0);

        $pdf->setFont(Pdf::REGULAR, 9.5);
        $pdf->text(
            sprintf(
                'Version %d, published %s',
                (int) $completion['version_number'],
                $completion['version_published_at'] === null
                    ? 'unpublished'
                    : format_date((string) $completion['version_published_at'])
            ),
            $pdf->left(),
            $pdf->y() + 9.0,
            self::MUTED
        );
        $pdf->moveDown(16.0);

        if (trim((string) ($completion['routine_description'] ?? '')) !== '') {
            $pdf->setFont(Pdf::REGULAR, 9.5);
            $pdf->paragraph((string) $completion['routine_description'], $pdf->left(), $pdf->contentWidth(), 1.35, self::MUTED);
            $pdf->moveDown(8.0);
        }

        $columnWidth = ($pdf->contentWidth() - 24.0) / 2;
        $rightX      = $pdf->left() + $columnWidth + 24.0;
        $top         = $pdf->y();

        $left = [
            ['Asset',    trim((string) $completion['asset_tag'] . ' — ' . (string) $completion['asset_name'], ' —')],
            ['Serial',   (string) ($completion['serial_number'] ?? '')],
            ['Make and model', trim((string) ($completion['manufacturer'] ?? '') . ' ' . (string) ($completion['model'] ?? ''))],
            ['Category', (string) ($completion['category_name'] ?? '')],
            ['Location', (string) ($completion['location_name'] ?? '')],
        ];

        $right = [
            ['Carried out', $completion['performed_on'] !== null
                ? format_date((string) $completion['performed_on'])
                : format_date((string) $completion['completed_at'])],
            ['Recorded by', (string) ($completion['completed_by_name'] ?? 'Unknown')],
            ['Result',      (string) ($completion['result'] ?? '')],
            ['Scheduled job', (string) ($completion['schedule_title'] ?? 'Run directly against the asset')],
            ['Completed at', format_datetime((string) $completion['completed_at'])],
        ];

        $leftBottom  = self::detailList($pdf, $left, $pdf->left(), $top, $columnWidth);
        $rightBottom = self::detailList($pdf, $right, $rightX, $top, $columnWidth);

        $pdf->setY(max($leftBottom, $rightBottom) + 10.0);

        $pdf->line($pdf->left(), $pdf->y(), $pdf->right(), $pdf->y(), 0.8, self::RULE);
        $pdf->moveDown(14.0);
    }

    /**
     * A label-and-value list in one column, returning the y it finished at.
     *
     * Both columns are drawn from the same starting y and the taller one
     * decides where the block ends, so an asset with no serial number does not
     * pull the right-hand column up with it.
     *
     * @param array<int,array{0:string,1:string}> $rows
     */
    private static function detailList(Pdf $pdf, array $rows, float $x, float $top, float $width): float
    {
        $y = $top;

        foreach ($rows as [$label, $value]) {
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $pdf->setFont(Pdf::BOLD, 7.5);
            $pdf->text(strtoupper($label), $x, $y + 7.0, self::MUTED);

            $pdf->setFont(Pdf::REGULAR, 10.0);

            $lines = $pdf->wrap($value, $width, Pdf::REGULAR, 10.0);

            foreach ($lines as $index => $line) {
                $pdf->text($line, $x, $y + 18.0 + ($index * 11.5), self::INK);
            }

            $y += 20.0 + ((count($lines) - 1) * 11.5);
        }

        return $y;
    }

    /**
     * One page of the routine: its heading band, then a row per step.
     *
     * @param array<string,mixed> $page
     * @param array<int,array<string,mixed>> $responses keyed by step id
     * @param array<int,array<int,array<string,mixed>>> $files keyed by step id
     */
    private static function section(Pdf $pdf, array $page, array $responses, array $files, int $completionId, ?array $completedBy = null): void
    {
        // A heading with nothing under it at the foot of a page is worse than
        // a slightly short page, so the band only lands where a row can follow.
        $pdf->ensure(70.0);

        $pdf->fillRect($pdf->left(), $pdf->y(), $pdf->contentWidth(), 22.0, self::BAND);
        $pdf->setFont(Pdf::BOLD, 10.5);
        $pdf->text((string) $page['title'], $pdf->left() + 8.0, $pdf->y() + 15.0, self::INK);

        // A page-batched run is completed a page at a time, so the band that
        // names the page is where that name belongs.
        if ($completedBy !== null) {
            $pdf->setFont(Pdf::REGULAR, 8.0);
            $pdf->textRight(
                trim(((string) ($completedBy['name'] ?? '')) . '  ' . format_datetime((string) $completedBy['at'])),
                $pdf->right() - 8.0,
                $pdf->y() + 15.0,
                self::MUTED
            );
        }

        $pdf->moveDown(26.0);

        if (trim((string) ($page['description'] ?? '')) !== '') {
            $pdf->setFont(Pdf::ITALIC, 8.5);
            $pdf->paragraph((string) $page['description'], $pdf->left(), $pdf->contentWidth(), 1.35, self::MUTED);
            $pdf->moveDown(8.0);
        }

        foreach ((array) $page['steps'] as $step) {
            self::step($pdf, $step, $responses[(int) $step['id']] ?? null, $files[(int) $step['id']] ?? [], $completionId);
        }

        $pdf->moveDown(6.0);
    }

    /**
     * One question and its answer, side by side.
     *
     * @param array<string,mixed> $step
     * @param array<string,mixed>|null $response
     * @param array<int,array<string,mixed>> $stepFiles
     */
    private static function step(Pdf $pdf, array $step, ?array $response, array $stepFiles, int $completionId): void
    {
        $questionWidth = $pdf->contentWidth() * self::QUESTION_SHARE;
        $answerX       = $pdf->left() + $questionWidth + 16.0;
        $answerWidth   = $pdf->right() - $answerX;

        $label = (string) $step['label']
            . ((int) $step['is_required'] === 1 ? ' *' : '');

        $help = trim((string) ($step['help_text'] ?? ''));

        $questionLines = $pdf->wrap($label, $questionWidth, Pdf::BOLD, 9.5);
        $helpLines     = $help === '' ? [] : $pdf->wrap($help, $questionWidth, Pdf::ITALIC, 8.0);

        $answer      = RoutineCompletion::answer($step, $response);
        $answerLines = self::answerLines($pdf, $step, $answer, $stepFiles, $answerWidth);

        $questionHeight = (count($questionLines) * 12.5) + (count($helpLines) * 10.0);
        $answerHeight   = count($answerLines) * 12.5;
        $rowHeight      = max($questionHeight, $answerHeight, 18.0) + 10.0;

        $pdf->ensure($rowHeight + 6.0);

        $top = $pdf->y();

        $pdf->setFont(Pdf::BOLD, 9.5);
        foreach ($questionLines as $index => $line) {
            $pdf->text($line, $pdf->left(), $top + 10.0 + ($index * 12.5), self::INK);
        }

        $pdf->setFont(Pdf::ITALIC, 8.0);
        foreach ($helpLines as $index => $line) {
            $pdf->text($line, $pdf->left(), $top + 10.0 + (count($questionLines) * 12.5) + ($index * 10.0), self::MUTED);
        }

        foreach ($answerLines as $index => [$text, $font, $size, $colour]) {
            $pdf->setFont($font, $size);
            $pdf->text($text, $answerX, $top + 10.0 + ($index * 12.5), $colour);
        }

        $pdf->setY($top + $rowHeight);

        self::photographs($pdf, $stepFiles, $answerX, $answerWidth, $completionId);

        $pdf->line($pdf->left(), $pdf->y(), $pdf->right(), $pdf->y(), 0.5, self::RULE);
        $pdf->moveDown(8.0);
    }

    /**
     * The answer as drawable lines, each with the face it should be set in.
     *
     * An unanswered optional step says so in its own words rather than leaving
     * a blank a reader has to interpret.
     *
     * @param array<int,string>|string|null $answer
     * @param array<int,array<string,mixed>> $stepFiles
     * @return array<int,array{0:string,1:string,2:float,3:array{0:float,1:float,2:float}}>
     */
    private static function answerLines(Pdf $pdf, array $step, array|string|null $answer, array $stepFiles, float $width): array
    {
        $type = (string) $step['field_type'];

        if (in_array($type, MaintenanceRoutine::FILE_TYPES, true)) {
            if ($stepFiles === []) {
                return [['Nothing attached', Pdf::ITALIC, 9.5, self::MUTED]];
            }

            $lines = [];

            foreach ($stepFiles as $file) {
                $lines[] = [
                    sprintf(
                        '%s (%s)',
                        Upload::displayName((string) ($file['original_filename'] ?: 'attachment')),
                        Upload::formatBytes((int) $file['file_size_bytes'])
                    ),
                    Pdf::REGULAR,
                    9.5,
                    self::INK,
                ];
            }

            return $lines;
        }

        if ($answer === null) {
            return [['Not answered', Pdf::ITALIC, 9.5, self::MUTED]];
        }

        if (is_array($answer)) {
            $lines = [];

            foreach ($answer as $choice) {
                foreach ($pdf->wrap('• ' . $choice, $width, Pdf::REGULAR, 9.5) as $line) {
                    $lines[] = [$line, Pdf::REGULAR, 9.5, self::INK];
                }
            }

            return $lines;
        }

        // Yes and no are the two answers a reader scans a compliance record
        // for, so they are the two that get a colour.
        $colour = match ($type === 'boolean' ? $answer : '') {
            'Yes'   => self::PASS,
            'No'    => self::FAIL,
            default => self::INK,
        };

        $font = $type === 'boolean' ? Pdf::BOLD : Pdf::REGULAR;

        $lines = [];

        foreach ($pdf->wrap($answer, $width, $font, 9.5) as $line) {
            $lines[] = [$line, $font, 9.5, $colour];
        }

        return $lines;
    }

    /**
     * Photographs captured against a step, three to a row.
     *
     * Sized so a scratch or a meter reading is actually legible on paper — a
     * thumbnail nobody can read is a thumbnail that need not have been
     * printed. Documents are named rather than embedded: a PDF inside a PDF is
     * not something every reader will open, and they are one click away in the
     * record itself.
     *
     * @param array<int,array<string,mixed>> $stepFiles
     */
    private static function photographs(Pdf $pdf, array $stepFiles, float $x, float $width, int $completionId): void
    {
        $photos = array_values(array_filter(
            $stepFiles,
            static fn (array $file): bool => $file['file_kind'] === 'photo'
        ));

        if ($photos === []) {
            return;
        }

        // As few columns as there are photographs, up to three. One photograph
        // of a data plate or a damaged guard is the whole point of the step, so
        // it gets the column rather than a third of it.
        $perRow    = max(1, min(3, count($photos)));
        $gap       = 8.0;
        $tileWidth = ($width - ($gap * ($perRow - 1))) / $perRow;
        $column    = 0;
        $rowTop    = $pdf->y();
        $rowHeight = 0.0;

        foreach ($photos as $photo) {
            $path = Upload::absolutePath((string) $photo['file_path']);

            if ($path === null) {
                continue;
            }

            $size = $pdf->imageSize($path);

            if ($size === null || $size[0] < 1 || $size[1] < 1) {
                continue;
            }

            // Fitted inside the tile, so a portrait photograph is not stretched
            // and a panorama does not push the row past the foot of the page.
            $scale  = min($tileWidth / $size[0], 170.0 / $size[1]);
            $drawn  = $size[0] * $scale;
            $height = $size[1] * $scale;

            if ($column === 0) {
                $pdf->ensure($height + 12.0);
                $rowTop    = $pdf->y();
                $rowHeight = 0.0;
            }

            $tileX = $x + ($column * ($tileWidth + $gap));

            if ($pdf->image($path, $tileX, $rowTop, $drawn, $height)) {
                $pdf->strokeRect($tileX, $rowTop, $drawn, $height, 0.5, self::RULE);
                $rowHeight = max($rowHeight, $height);
            }

            $column++;

            if ($column >= $perRow) {
                $column = 0;
                $pdf->setY($rowTop + $rowHeight + $gap);
            }
        }

        if ($column > 0) {
            $pdf->setY($rowTop + $rowHeight + $gap);
        }

        $pdf->moveDown(2.0);
    }

    /** @param array<string,mixed> $completion */
    private static function signOff(Pdf $pdf, array $completion): void
    {
        $notes = trim((string) ($completion['log_notes'] ?? ''));

        if ($notes !== '') {
            $pdf->ensure(60.0);
            $pdf->moveDown(10.0);

            $pdf->setFont(Pdf::BOLD, 8.0);
            $pdf->text('NOTES', $pdf->left(), $pdf->y() + 8.0, self::MUTED);
            $pdf->moveDown(16.0);

            $pdf->setFont(Pdf::REGULAR, 9.5);
            $pdf->paragraph($notes, $pdf->left(), $pdf->contentWidth(), 1.35, self::INK);
            $pdf->moveDown(14.0);
        }

        // The rule, the signature lines and the note under them are one block:
        // a last page carrying nothing but the closing sentence is the sort of
        // thing a reader notices before anything else on the document. What is
        // reserved is 10 of lead-in, 70 down to the signature rules and two
        // lines of note. A third line lands in the 20pt of clearance bottom()
        // already keeps above the footer rule, which is what that clearance is
        // there for.
        $pdf->ensure(95.0);
        $pdf->moveDown(10.0);

        $pdf->line($pdf->left(), $pdf->y(), $pdf->right(), $pdf->y(), 0.8, self::RULE);
        $pdf->moveDown(18.0);

        $columnWidth = ($pdf->contentWidth() - 30.0) / 2;
        $top         = $pdf->y();

        $pdf->setFont(Pdf::BOLD, 7.5);
        $pdf->text('RECORDED BY', $pdf->left(), $top + 7.0, self::MUTED);
        $pdf->text('SIGNATURE', $pdf->left() + $columnWidth + 30.0, $top + 7.0, self::MUTED);

        $pdf->setFont(Pdf::REGULAR, 10.0);
        $pdf->text((string) ($completion['completed_by_name'] ?? 'Unknown'), $pdf->left(), $top + 24.0, self::INK);

        $pdf->line($pdf->left(), $top + 30.0, $pdf->left() + $columnWidth, $top + 30.0, 0.6, self::RULE);
        $pdf->line(
            $pdf->left() + $columnWidth + 30.0,
            $top + 30.0,
            $pdf->right(),
            $top + 30.0,
            0.6,
            self::RULE
        );

        $pdf->setY($top + 42.0);

        $pdf->setFont(Pdf::ITALIC, 8.0);
        $pdf->paragraph(
            'This record was produced from the maintenance history held in '
            . (string) config('app.full_name', 'Kitwell by Junction')
            . '. It shows version ' . (int) $completion['version_number']
            . ' of the routine, which is the edition that was in force when the work was carried out.',
            $pdf->left(),
            $pdf->contentWidth(),
            1.35,
            self::MUTED
        );
    }
}
