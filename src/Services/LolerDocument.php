<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Pdf;
use App\Models\LolerExamination;
use App\Models\Setting;

/**
 * A LOLER report of thorough examination as a document.
 *
 * Built to fit one page for the ordinary case — an examination that found
 * nothing, or one or two defects — because that is what gets filed, signed and
 * handed over. It flows onto a second page rather than dropping anything: a
 * report missing a Schedule 1 item is not a report.
 *
 * The layout is two columns of identification over a full-width findings
 * block, which is the shape every printed LOLER report takes, and each block
 * is captioned with the Schedule 1 paragraph it answers so the document can be
 * checked against the regulation rather than against a memory of it.
 *
 * The closing statement is deliberate. Regulation 9 puts the examination in
 * the hands of a competent person and regulation 10 makes the report theirs;
 * this application records and prints what they concluded and certifies
 * nothing itself.
 */
final class LolerDocument
{
    private const INK   = [0.12, 0.14, 0.17];
    private const MUTED = [0.42, 0.45, 0.50];
    private const RULE  = [0.82, 0.85, 0.88];
    private const BAND  = [0.94, 0.95, 0.965];
    private const PASS  = [0.11, 0.45, 0.24];
    private const FAIL  = [0.65, 0.14, 0.14];
    private const WARN  = [0.60, 0.40, 0.05];

    /**
     * @param array<string,mixed> $examination
     * @param array<int,array<string,mixed>> $defects
     */
    public static function build(array $examination, array $defects): string
    {
        $pdf = new Pdf(Pdf::A4_WIDTH, Pdf::A4_HEIGHT, 40.0);

        $pdf->setOnPage(static function (Pdf $pdf) use ($examination): void {
            self::masthead($pdf, $examination);
        });

        $pdf->setFooter(static function (Pdf $pdf, int $page, int $total) use ($examination): void {
            self::footer($pdf, $page, $total, $examination);
        });

        $pdf->addPage();

        self::verdictBanner($pdf, $examination);
        self::identification($pdf, $examination);
        self::examination($pdf, $examination);
        self::findings($pdf, $examination, $defects);
        self::signOff($pdf, $examination);

        return $pdf->output();
    }

    /** @param array<string,mixed> $examination */
    public static function filename(array $examination): string
    {
        $name = sprintf(
            'LOLER-%s-%s.pdf',
            (string) $examination['asset_tag'],
            date('Y-m-d', strtotime((string) $examination['examined_on']))
        );

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'loler.pdf';

        return trim((string) $safe, '-');
    }

    // -- Furniture -----------------------------------------------------------

    /** @param array<string,mixed> $examination */
    private static function masthead(Pdf $pdf, array $examination): void
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
                $scale  = min(96.0 / $size[0], 30.0 / $size[1]);
                $width  = $size[0] * $scale;
                $height = $size[1] * $scale;

                if ($pdf->image($logo, $textLeft, $top + ((30.0 - $height) / 2), $width, $height)) {
                    $textLeft += $width + 12.0;
                }
            }
        }

        $pdf->setFont(Pdf::BOLD, 12.0);
        $pdf->text($organisation, $textLeft, $top + 11.0, self::INK);

        $pdf->setFont(Pdf::REGULAR, 7.5);
        $pdf->text('In-house report of thorough examination', $textLeft, $top + 22.0, self::MUTED);

        $pdf->setFont(Pdf::BOLD, 11.0);
        $pdf->textRight('REPORT OF THOROUGH EXAMINATION', $pdf->right(), $top + 11.0, self::INK);

        $pdf->setFont(Pdf::REGULAR, 7.5);
        $pdf->textRight(
            'Lifting Operations and Lifting Equipment Regulations 1998, reg. 9 and Schedule 1',
            $pdf->right(),
            $top + 22.0,
            self::MUTED
        );

        $pdf->line($pdf->left(), $top + 32.0, $pdf->right(), $top + 32.0, 0.9, self::RULE);
        $pdf->setY($top + 44.0);
    }

    /** @param array<string,mixed> $examination */
    private static function footer(Pdf $pdf, int $page, int $total, array $examination): void
    {
        $y = $pdf->height() - $pdf->margin() + 6.0;

        $pdf->line($pdf->left(), $y - 10.0, $pdf->right(), $y - 10.0, 0.6, self::RULE);

        $pdf->setFont(Pdf::REGULAR, 7.0);
        $pdf->text(
            sprintf(
                '%s · report %d · examined %s',
                (string) $examination['asset_tag'],
                (int) $examination['id'],
                format_date((string) $examination['examined_on'])
            ),
            $pdf->left(),
            $y,
            self::MUTED
        );

        $pdf->textRight(sprintf('Page %d of %d', $page, $total), $pdf->right(), $y, self::MUTED);
    }

    // -- Blocks ---------------------------------------------------------------

    /**
     * The one line somebody reads first: may this equipment be used or not.
     *
     * @param array<string,mixed> $examination
     */
    private static function verdictBanner(Pdf $pdf, array $examination): void
    {
        $danger  = (int) $examination['danger_count'] > 0;
        $defects = (int) $examination['defect_count'] > 0;

        $colour = $danger ? self::FAIL : ($defects ? self::WARN : self::PASS);
        $height = 26.0;

        $pdf->fillRect($pdf->left(), $pdf->y(), $pdf->contentWidth(), $height, [0.97, 0.97, 0.98]);
        $pdf->fillRect($pdf->left(), $pdf->y(), 4.0, $height, $colour);

        $pdf->setFont(Pdf::BOLD, 10.5);
        $pdf->text(strtoupper(LolerExamination::verdict($examination)), $pdf->left() + 12.0, $pdf->y() + 12.0, $colour);

        $pdf->setFont(Pdf::REGULAR, 8.0);
        $pdf->text(
            $danger
                ? 'The equipment must not be used before the defect is rectified (reg. 10(3)(a)).'
                : ($defects
                    ? 'Defects to be remedied by the dates given below (reg. 10(3)(b)).'
                    : 'No defect was found at this examination.'),
            $pdf->left() + 12.0,
            $pdf->y() + 22.0,
            self::MUTED
        );

        $pdf->setFont(Pdf::BOLD, 9.0);
        $pdf->textRight(
            'Next examination by ' . format_date((string) $examination['next_examination_date']),
            $pdf->right() - 10.0,
            $pdf->y() + 12.0,
            self::INK
        );

        $pdf->setFont(Pdf::REGULAR, 7.5);
        $pdf->textRight('Schedule 1(8)(d)', $pdf->right() - 10.0, $pdf->y() + 22.0, self::MUTED);

        $pdf->moveDown($height + 12.0);
    }

    /** @param array<string,mixed> $examination */
    private static function identification(Pdf $pdf, array $examination): void
    {
        $columnWidth = ($pdf->contentWidth() - 18.0) / 2;
        $rightX      = $pdf->left() + $columnWidth + 18.0;

        self::bandedHeading($pdf, 'The equipment  —  Schedule 1(3) and (5)', 'Who and where  —  Schedule 1(1) and (2)', $columnWidth, $rightX);

        $top = $pdf->y();

        $swl = $examination['swl'] === null
            ? 'Not recorded'
            : rtrim(rtrim(number_format((float) $examination['swl'], 3, '.', ','), '0'), '.')
                . ' ' . (string) $examination['swl_unit']
                . (empty($examination['swl_configuration']) ? '' : ' (' . (string) $examination['swl_configuration'] . ')');

        $manufacture = (int) $examination['manufacture_unknown'] === 1
            ? 'Not known or not marked'
            : ($examination['date_of_manufacture'] === null
                ? 'Not recorded'
                : format_date((string) $examination['date_of_manufacture']));

        $left = [
            ['Equipment', trim((string) $examination['asset_tag'] . '  ' . (string) $examination['asset_name'])],
            ['Make and model', trim((string) ($examination['manufacturer'] ?? '') . ' ' . (string) ($examination['model'] ?? ''))],
            ['Type', LolerExamination::typeLabel((string) $examination['loler_type'])],
            ['Serial number', (string) ($examination['serial_number'] ?? 'Not recorded')],
            ['Date of manufacture', $manufacture],
            ['Safe working load', $swl],
            ['Examination interval', $examination['interval_months'] === null
                ? 'Not recorded' : ((int) $examination['interval_months'] . ' months')],
        ];

        $right = [
            ['Employer the examination was made for', trim((string) $examination['employer_name'] . "\n" . (string) $examination['employer_address'])],
            ['Premises where examined', (string) $examination['examination_address']],
        ];

        if (!empty($examination['owner_name']) || !empty($examination['owner_address'])) {
            $right[] = [
                'Owner, or hired/leased from',
                trim((string) ($examination['owner_name'] ?? '') . "\n" . (string) ($examination['owner_address'] ?? '')),
            ];
        }

        $leftBottom  = self::pairs($pdf, $left, $pdf->left(), $top, $columnWidth);
        $rightBottom = self::pairs($pdf, $right, $rightX, $top, $columnWidth);

        $pdf->setY(max($leftBottom, $rightBottom) + 8.0);
    }

    /** @param array<string,mixed> $examination */
    private static function examination(Pdf $pdf, array $examination): void
    {
        $pdf->ensure(70.0);

        self::band($pdf, 'The examination  —  Schedule 1(4), (6), (7) and (8)(e)');

        $columnWidth = ($pdf->contentWidth() - 18.0) / 2;
        $rightX      = $pdf->left() + $columnWidth + 18.0;
        $top         = $pdf->y();

        $left = [
            ['Carried out', LolerExamination::BASES[(string) $examination['examination_basis']]
                ?? (string) $examination['examination_basis']],
            ['Date of last thorough examination', $examination['previous_examination_date'] === null
                ? 'None on record' : format_date((string) $examination['previous_examination_date'])],
            ['Date of this examination', format_date((string) $examination['examined_on'])],
        ];

        $right = [];

        if ((int) $examination['is_first_examination'] === 1) {
            $right[] = [
                'First examination',
                'The first thorough examination after installation, or after assembly at a new site or in a new location. '
                . ((int) $examination['installed_correctly'] === 1
                    ? 'It has been installed correctly and would be safe to operate.'
                    : 'Not reported as correctly installed.'),
            ];
        }

        $right[] = [
            'Testing',
            (int) $examination['testing_carried_out'] === 1
                ? (string) $examination['test_particulars']
                : 'This examination did not include testing.',
        ];

        $right[] = [
            'Safe to operate',
            (int) $examination['safe_to_operate'] === 1
                ? 'In the opinion of the competent person named below, this equipment would be safe to operate.'
                : 'This equipment is NOT reported as safe to operate.',
        ];

        $leftBottom  = self::pairs($pdf, $left, $pdf->left(), $top, $columnWidth);
        $rightBottom = self::pairs($pdf, $right, $rightX, $top, $columnWidth);

        $pdf->setY(max($leftBottom, $rightBottom) + 8.0);
    }

    /**
     * @param array<string,mixed> $examination
     * @param array<int,array<string,mixed>> $defects
     */
    private static function findings(Pdf $pdf, array $examination, array $defects): void
    {
        $pdf->ensure(60.0);

        self::band($pdf, 'Defects  —  Schedule 1(8)(a), (b) and (c)');

        if ($defects === []) {
            $pdf->setFont(Pdf::BOLD, 9.5);
            $pdf->text('None.', $pdf->left(), $pdf->y() + 10.0, self::PASS);

            $pdf->setFont(Pdf::REGULAR, 9.0);
            $pdf->text(
                'No part was found to have a defect which is or could become a danger to persons.',
                $pdf->left() + 32.0,
                $pdf->y() + 10.0,
                self::INK
            );

            $pdf->moveDown(22.0);

            return;
        }

        foreach ($defects as $index => $defect) {
            $isDanger = (string) $defect['category'] === 'danger';
            $meta     = LolerExamination::DEFECT_CATEGORIES[(string) $defect['category']] ?? null;

            $pdf->ensure(50.0);

            $top = $pdf->y();

            $pdf->setFont(Pdf::BOLD, 9.0);
            $pdf->text(
                sprintf('%d.  %s', $index + 1, (string) $defect['part_identified']),
                $pdf->left(),
                $top + 9.0,
                self::INK
            );

            $pdf->setFont(Pdf::BOLD, 8.0);
            $pdf->textRight(
                strtoupper((string) ($meta['short'] ?? $defect['category'])),
                $pdf->right(),
                $top + 9.0,
                $isDanger ? self::FAIL : self::WARN
            );

            $pdf->setY($top + 14.0);

            $pdf->setFont(Pdf::REGULAR, 9.0);
            $pdf->paragraph((string) $defect['description'], $pdf->left() + 14.0, $pdf->contentWidth() - 14.0, 1.3, self::INK);

            if ($defect['becomes_danger_by'] !== null) {
                $pdf->setFont(Pdf::BOLD, 8.5);
                $pdf->paragraph(
                    'Could become a danger by ' . format_date((string) $defect['becomes_danger_by'])
                    . ' — must not be used after that date until rectified.',
                    $pdf->left() + 14.0,
                    $pdf->contentWidth() - 14.0,
                    1.3,
                    self::WARN
                );
            }

            if ($defect['remedy'] !== null) {
                $pdf->setFont(Pdf::REGULAR, 8.5);
                $pdf->paragraph(
                    'Remedy required: ' . (string) $defect['remedy'],
                    $pdf->left() + 14.0,
                    $pdf->contentWidth() - 14.0,
                    1.3,
                    self::MUTED
                );
            }

            if ((int) $defect['serious_injury_risk'] === 1) {
                $pdf->setFont(Pdf::BOLD, 8.5);
                $pdf->paragraph(
                    'This defect involves an existing or imminent risk of serious personal injury.'
                    . ' A copy of this report must be sent to the relevant enforcing authority'
                    . ' (reg. 10(1)(c)).',
                    $pdf->left() + 14.0,
                    $pdf->contentWidth() - 14.0,
                    1.3,
                    self::FAIL
                );
            }

            $pdf->moveDown(4.0);
            $pdf->line($pdf->left(), $pdf->y(), $pdf->right(), $pdf->y(), 0.4, self::RULE);
            $pdf->moveDown(6.0);
        }
    }

    /** @param array<string,mixed> $examination */
    private static function signOff(Pdf $pdf, array $examination): void
    {
        // The competent person, the authentication and the closing statement
        // are one block: a report whose last page carries only a disclaimer
        // reads as though somebody forgot to sign it.
        $pdf->ensure(140.0);
        $pdf->moveDown(4.0);

        self::band($pdf, 'The competent person  —  Schedule 1(9), (10) and (11)');

        $columnWidth = ($pdf->contentWidth() - 18.0) / 2;
        $rightX      = $pdf->left() + $columnWidth + 18.0;
        $top         = $pdf->y();

        $employment = (int) $examination['examiner_self_employed'] === 1
            ? 'Self-employed'
            : trim((string) ($examination['examiner_employer_name'] ?? 'Not recorded') . "\n"
                . (string) ($examination['examiner_employer_address'] ?? ''));

        $left = [
            ['Person making the report', (string) $examination['examiner_name']],
            ['Qualifications', (string) ($examination['examiner_qualifications'] ?? 'Not recorded')],
            ['Employed by', $employment],
        ];

        $right = [
            ['Authenticated by', (string) $examination['authenticated_name']],
            ['Authenticated at', format_datetime((string) $examination['authenticated_at'])],
            ['Date of this report', format_date((string) $examination['reported_on'])],
        ];

        $leftBottom  = self::pairs($pdf, $left, $pdf->left(), $top, $columnWidth);
        $rightBottom = self::pairs($pdf, $right, $rightX, $top, $columnWidth);

        $pdf->setY(max($leftBottom, $rightBottom) + 6.0);

        // Regulation 10(1)(b) allows "signature or equally secure means"; the
        // rule is still there for a site that wants ink on paper as well.
        $signatureTop = $pdf->y();

        $pdf->setFont(Pdf::BOLD, 7.0);
        $pdf->text('SIGNATURE', $pdf->left(), $signatureTop + 7.0, self::MUTED);
        $pdf->text('DATE', $rightX, $signatureTop + 7.0, self::MUTED);

        $pdf->line($pdf->left(), $signatureTop + 26.0, $pdf->left() + $columnWidth, $signatureTop + 26.0, 0.6, self::RULE);
        $pdf->line($rightX, $signatureTop + 26.0, $pdf->right(), $signatureTop + 26.0, 0.6, self::RULE);

        $pdf->setY($signatureTop + 36.0);

        $pdf->setFont(Pdf::ITALIC, 7.5);
        $pdf->paragraph(
            'This report was authenticated by the named account signed in to '
            . (string) config('app.full_name', 'Kitwell by Junction')
            . '. Regulation 10(1)(b) permits authentication by such equally secure means in place of'
            . ' a signature. The examination, the conclusions in this report and the duties that follow from them'
            . ' under regulations 10(1) and 10(3) are those of the competent person named above.'
            . ' This software records and presents that report; it does not carry out an examination,'
            . ' exercise professional judgement, or certify compliance with LOLER.',
            $pdf->left(),
            $pdf->contentWidth(),
            1.35,
            self::MUTED
        );
    }

    // -- Primitives -----------------------------------------------------------

    private static function band(Pdf $pdf, string $title): void
    {
        $pdf->fillRect($pdf->left(), $pdf->y(), $pdf->contentWidth(), 16.0, self::BAND);
        $pdf->setFont(Pdf::BOLD, 8.0);
        $pdf->text(strtoupper($title), $pdf->left() + 6.0, $pdf->y() + 11.0, self::INK);
        $pdf->moveDown(22.0);
    }

    private static function bandedHeading(Pdf $pdf, string $left, string $right, float $columnWidth, float $rightX): void
    {
        $pdf->fillRect($pdf->left(), $pdf->y(), $columnWidth, 16.0, self::BAND);
        $pdf->fillRect($rightX, $pdf->y(), $columnWidth, 16.0, self::BAND);

        $pdf->setFont(Pdf::BOLD, 8.0);
        $pdf->text(strtoupper($left), $pdf->left() + 6.0, $pdf->y() + 11.0, self::INK);
        $pdf->text(strtoupper($right), $rightX + 6.0, $pdf->y() + 11.0, self::INK);

        $pdf->moveDown(22.0);
    }

    /**
     * A label-and-value list in one column, returning the y it finished at.
     *
     * Both columns start from the same y and the taller decides where the block
     * ends, so a short left-hand column does not drag the right one up with it.
     *
     * @param array<int,array{0:string,1:string}> $rows
     */
    private static function pairs(Pdf $pdf, array $rows, float $x, float $top, float $width): float
    {
        $y = $top;

        foreach ($rows as [$label, $value]) {
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $pdf->setFont(Pdf::BOLD, 7.0);
            $pdf->text(strtoupper($label), $x, $y + 6.0, self::MUTED);

            $pdf->setFont(Pdf::REGULAR, 9.0);
            $lines = $pdf->wrap($value, $width, Pdf::REGULAR, 9.0);

            foreach ($lines as $index => $line) {
                $pdf->text($line, $x, $y + 17.0 + ($index * 10.5), self::INK);
            }

            $y += 21.0 + ((count($lines) - 1) * 10.5);
        }

        return $y;
    }
}
