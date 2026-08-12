<?php

declare(strict_types=1);

namespace App\Core;

/**
 * A QR encoder, emitting inline SVG.
 *
 * Why write one: the same reasons `Barcode` is a Code 128 encoder rather than a
 * package. The Content-Security-Policy allows no off-origin scripts, so a
 * client-side library is out; SVG needs no GD and prints at any resolution; and
 * an image on the enrolment page cannot be a request to somebody else's server,
 * because the thing being drawn *is the secret*.
 *
 * **A deliberate subset**, and it says so rather than pretending otherwise:
 *
 *   - byte mode only (an `otpauth://` URI is mixed case and punctuation, so
 *     alphanumeric mode does not apply)
 *   - error-correction level M
 *   - versions 1-13, which reach 293 bytes — several times the ~120 of the
 *     longest URI this application produces. encode() refuses anything longer
 *     rather than silently truncating.
 *
 * Anything outside that throws, because a QR code that is *nearly* right is
 * worse than none: it scans, and enrols a secret that will never match.
 *
 * Verified in tests/qr-encode.php two ways that do not share this code: the
 * ISO/IEC 18004 Annex I worked example, and a round trip through the
 * independently written decoder in public/js/barcode.js.
 */
final class QrCode
{
    /** Total codewords, then EC codewords per block and the block layout, level M. */
    private const VERSIONS = [
        //           total data+ec, ec per block, blocks in group 1, blocks in group 2
        1  => ['total' => 26,   'ec' => 10, 'g1' => 1,  'g2' => 0],
        2  => ['total' => 44,   'ec' => 16, 'g1' => 1,  'g2' => 0],
        3  => ['total' => 70,   'ec' => 26, 'g1' => 1,  'g2' => 0],
        4  => ['total' => 100,  'ec' => 18, 'g1' => 2,  'g2' => 0],
        5  => ['total' => 134,  'ec' => 24, 'g1' => 2,  'g2' => 0],
        6  => ['total' => 172,  'ec' => 16, 'g1' => 4,  'g2' => 0],
        7  => ['total' => 196,  'ec' => 18, 'g1' => 4,  'g2' => 0],
        8  => ['total' => 242,  'ec' => 22, 'g1' => 2,  'g2' => 2],
        9  => ['total' => 292,  'ec' => 22, 'g1' => 3,  'g2' => 2],
        10 => ['total' => 346,  'ec' => 26, 'g1' => 4,  'g2' => 1],
        11 => ['total' => 404,  'ec' => 30, 'g1' => 1,  'g2' => 4],
        12 => ['total' => 466,  'ec' => 22, 'g1' => 6,  'g2' => 2],
        13 => ['total' => 532,  'ec' => 22, 'g1' => 8,  'g2' => 1],
    ];

    /** Where the alignment pattern centres go, per version (ISO 18004 table E.1). */
    private const ALIGNMENT = [
        1  => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6  => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46],
        10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62],
    ];

    /** Format information for level M and each mask, already BCH-encoded and XOR'd. */
    private const FORMAT_BITS = [
        0 => 0x5412, 1 => 0x5125, 2 => 0x5E7C, 3 => 0x5B4B,
        4 => 0x45F9, 5 => 0x40CE, 6 => 0x4F97, 7 => 0x4AA0,
    ];

    /**
     * The whole thing, as an `<svg>` element.
     *
     * @param int $moduleSize Pixels per module. 4 is comfortably scannable on a
     *                        screen; the SVG scales, but a whole number keeps
     *                        the modules crisp.
     */
    public static function svg(string $text, int $moduleSize = 4, string $label = 'QR code'): string
    {
        $matrix = self::encode($text);
        $size   = count($matrix);

        // Four modules of quiet zone: the specification's minimum, and readers
        // genuinely fail without it.
        $quiet = 4;
        $side  = ($size + $quiet * 2) * $moduleSize;

        // One <path> of many subpaths rather than a rect per module: a version
        // 6 symbol is ~1,800 dark modules, and 1,800 elements is a slow page.
        $path = '';

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix[$y][$x] === 1) {
                    $path .= sprintf(
                        'M%d %dh%dv%dh-%dz',
                        ($x + $quiet) * $moduleSize,
                        ($y + $quiet) * $moduleSize,
                        $moduleSize,
                        $moduleSize,
                        $moduleSize
                    );
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-label="%s">'
            . '<rect width="%d" height="%d" fill="#ffffff"/>'
            . '<path d="%s" fill="#000000"/>'
            . '</svg>',
            $side, $side, $side, $side,
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            $side, $side,
            $path
        );
    }

    /**
     * The module matrix: 1 dark, 0 light.
     *
     * @param int|null $forceMask Testing only. Normally the mask is chosen by
     *                            penalty score; pinning it is what lets
     *                            tests/qr-encode.php compare this output
     *                            module-for-module against an independently
     *                            written encoder, which is the check that
     *                            actually proves the placement.
     * @return array<int,array<int,int>>
     */
    public static function encode(string $text, ?int $forceMask = null): array
    {
        $version = self::versionFor(strlen($text));
        $spec    = self::VERSIONS[$version];

        $dataCodewords  = $spec['total'] - $spec['ec'] * ($spec['g1'] + $spec['g2']);
        $bits           = self::dataBits($text, $version, $dataCodewords);
        $final          = self::interleave($bits, $spec);

        if ($forceMask !== null) {
            return self::place($final, $version, $forceMask);
        }

        $best  = null;
        $bestPenalty = PHP_INT_MAX;

        // All eight masks are tried and the least "penalised" wins, exactly as
        // ISO 18004 §7.8.3 requires. Picking one arbitrarily produces symbols
        // that scan on a good phone and fail on a cheap scanner.
        for ($mask = 0; $mask < 8; $mask++) {
            $matrix  = self::place($final, $version, $mask);
            $penalty = self::penalty($matrix);

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best        = $matrix;
            }
        }

        return $best;
    }

    private static function versionFor(int $length): int
    {
        foreach (self::VERSIONS as $version => $spec) {
            $capacity = $spec['total'] - $spec['ec'] * ($spec['g1'] + $spec['g2']);

            // 4 bits of mode indicator, then the character count (8 bits below
            // version 10, 16 at and above), then the bytes themselves.
            $overhead = 4 + ($version < 10 ? 8 : 16);

            if ($capacity * 8 >= $overhead + $length * 8) {
                return $version;
            }
        }

        throw new \InvalidArgumentException(
            'Too long for this encoder: ' . $length . ' bytes, and it stops at version 13 (level M). '
            . 'Nothing in this application produces a string that long — if something now does, the '
            . 'version tables need extending rather than the limit ignoring.'
        );
    }

    /** Mode indicator, length, payload, terminator, padding — as one bit string. */
    private static function dataBits(string $text, int $version, int $dataCodewords): string
    {
        $countBits = $version < 10 ? 8 : 16;

        $bits = '0100';                                                       // byte mode
        $bits .= str_pad(decbin(strlen($text)), $countBits, '0', STR_PAD_LEFT);

        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $capacity = $dataCodewords * 8;

        // Terminator: up to four zero bits, fewer if there is no room.
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));

        // Pad to a whole codeword, then alternate the two specified pad bytes.
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }

        $pad = ['11101100', '00010001'];
        $i   = 0;

        while (strlen($bits) < $capacity) {
            $bits .= $pad[$i++ % 2];
        }

        return $bits;
    }

    /**
     * Split into blocks, add error correction to each, then interleave.
     *
     * @param array{total:int,ec:int,g1:int,g2:int} $spec
     * @return array<int,int> Codewords in transmission order
     */
    private static function interleave(string $bits, array $spec): array
    {
        $codewords = array_map('bindec', str_split($bits, 8));

        $blocks     = $spec['g1'] + $spec['g2'];
        $shortLen   = intdiv(count($codewords), $blocks);

        $data = [];
        $ec   = [];
        $at   = 0;

        for ($b = 0; $b < $blocks; $b++) {
            // Group 2's blocks each carry one more data codeword than group 1's.
            $length  = $shortLen + ($b >= $spec['g1'] ? 1 : 0);
            $block   = array_slice($codewords, $at, $length);
            $at     += $length;

            $data[] = $block;
            $ec[]   = self::errorCorrection($block, $spec['ec']);
        }

        $out = [];

        // Data first, one codeword from each block in turn; short blocks simply
        // run out and are skipped.
        for ($i = 0; $i < $shortLen + 1; $i++) {
            foreach ($data as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $spec['ec']; $i++) {
            foreach ($ec as $block) {
                $out[] = $block[$i];
            }
        }

        return $out;
    }

    /**
     * Reed-Solomon over GF(256) with the QR primitive polynomial 0x11D.
     *
     * Polynomial long division of the message by the generator; the remainder
     * is the error-correction codewords.
     *
     * @param array<int,int> $data
     * @return array<int,int>
     */
    private static function errorCorrection(array $data, int $ecLength): array
    {
        [$exp, $log] = self::tables();

        // Generator: (x - a^0)(x - a^1)...(x - a^(n-1)), built up term by term.
        $generator = [1];

        for ($i = 0; $i < $ecLength; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);

            foreach ($generator as $j => $coefficient) {
                $next[$j]     ^= $coefficient;
                $next[$j + 1] ^= $coefficient === 0 ? 0 : $exp[($log[$coefficient] + $i) % 255];
            }

            $generator = $next;
        }

        $remainder = array_merge($data, array_fill(0, $ecLength, 0));

        for ($i = 0; $i < count($data); $i++) {
            $lead = $remainder[$i];

            if ($lead === 0) {
                continue;
            }

            $factor = $log[$lead];

            foreach ($generator as $j => $coefficient) {
                if ($coefficient !== 0) {
                    $remainder[$i + $j] ^= $exp[($log[$coefficient] + $factor) % 255];
                }
            }
        }

        return array_slice($remainder, count($data));
    }

    /**
     * Exponent and logarithm tables for GF(256).
     *
     * @return array{0:array<int,int>,1:array<int,int>}
     */
    private static function tables(): array
    {
        static $exp = null;
        static $log = null;

        if ($exp !== null) {
            return [$exp, $log];
        }

        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);

        $value = 1;

        for ($i = 0; $i < 255; $i++) {
            $exp[$i]     = $value;
            $log[$value] = $i;

            $value <<= 1;

            if ($value & 0x100) {
                $value ^= 0x11D;
            }
        }

        // Doubled so callers can index past 255 without a modulo.
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }

        return [$exp, $log];
    }

    /**
     * Function patterns, then the data snaking up and down the columns, then
     * the mask and the format information.
     *
     * @param array<int,int> $codewords
     * @return array<int,array<int,int>>
     */
    private static function place(array $codewords, int $version, int $mask): array
    {
        $size = $version * 4 + 17;

        $matrix   = array_fill(0, $size, array_fill(0, $size, 0));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // Finder patterns and their separators, in three corners.
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$fx, $fy]) {
            for ($y = -1; $y <= 7; $y++) {
                for ($x = -1; $x <= 7; $x++) {
                    $px = $fx + $x;
                    $py = $fy + $y;

                    if ($px < 0 || $py < 0 || $px >= $size || $py >= $size) {
                        continue;
                    }

                    $inRing = ($x >= 0 && $x <= 6 && ($y === 0 || $y === 6))
                        || ($y >= 0 && $y <= 6 && ($x === 0 || $x === 6));
                    $inCore = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;

                    $matrix[$py][$px]   = ($inRing || $inCore) ? 1 : 0;
                    $reserved[$py][$px] = true;
                }
            }
        }

        // Timing patterns: alternating modules along row and column 6.
        for ($i = 8; $i < $size - 8; $i++) {
            $bit = $i % 2 === 0 ? 1 : 0;

            $matrix[6][$i] = $bit;
            $matrix[$i][6] = $bit;
            $reserved[6][$i] = true;
            $reserved[$i][6] = true;
        }

        // Alignment patterns, skipping the three that would collide with a
        // finder.
        $centres = self::ALIGNMENT[$version];

        foreach ($centres as $cy) {
            foreach ($centres as $cx) {
                $nearFinder = ($cx === 6 && $cy === 6)
                    || ($cx === 6 && $cy === $size - 7)
                    || ($cx === $size - 7 && $cy === 6);

                if ($nearFinder) {
                    continue;
                }

                for ($y = -2; $y <= 2; $y++) {
                    for ($x = -2; $x <= 2; $x++) {
                        $matrix[$cy + $y][$cx + $x]   = (max(abs($x), abs($y)) !== 1) ? 1 : 0;
                        $reserved[$cy + $y][$cx + $x] = true;
                    }
                }
            }
        }

        // Version information: two 6x3 blocks, from version 7 up.
        //
        // Easy to forget, because versions 1-6 do not have them and everything
        // works — and then the first payload long enough to need version 7
        // silently stops scanning, because the data was written over the area a
        // reader expects the version in. Reserved here, filled in below.
        if ($version >= 7) {
            for ($i = 0; $i < 18; $i++) {
                $reserved[$size - 11 + $i % 3][intdiv($i, 3)] = true;
                $reserved[intdiv($i, 3)][$size - 11 + $i % 3] = true;
            }
        }

        // The dark module, and the format information areas.
        $matrix[$size - 8][8]   = 1;
        $reserved[$size - 8][8] = true;

        for ($i = 0; $i < 9; $i++) {
            if (!$reserved[8][$i]) { $reserved[8][$i] = true; }
            if (!$reserved[$i][8]) { $reserved[$i][8] = true; }
        }

        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }

        // Data, two modules wide, alternating upward and downward, right to
        // left — skipping the timing column entirely.
        $bits = '';

        foreach ($codewords as $codeword) {
            $bits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }

        $index = 0;
        $up    = true;

        for ($right = $size - 1; $right > 0; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($step = 0; $step < $size; $step++) {
                $y = $up ? $size - 1 - $step : $step;

                foreach ([$right, $right - 1] as $x) {
                    if ($reserved[$y][$x]) {
                        continue;
                    }

                    $bit = $index < strlen($bits) ? (int) $bits[$index] : 0;
                    $index++;

                    $matrix[$y][$x] = $bit ^ (self::mask($mask, $x, $y) ? 1 : 0);
                }
            }

            $up = !$up;
        }

        self::writeFormat($matrix, $mask, $size);
        self::writeVersion($matrix, $version, $size);

        return $matrix;
    }

    /**
     * The 18 version bits, twice, for version 7 and above (ISO 18004 §7.10).
     *
     * Six bits of version number and twelve of BCH(18,6) with generator 0x1F25,
     * with no final XOR — unlike the format information, which has one.
     *
     * Bit i goes to (row size-11 + i mod 3, column i div 3) in the block by the
     * bottom-left finder, and to the transpose of that by the top-right one.
     *
     * @param array<int,array<int,int>> $matrix
     */
    private static function writeVersion(array &$matrix, int $version, int $size): void
    {
        if ($version < 7) {
            return;
        }

        // Polynomial division of version << 12 by the generator; the remainder
        // is the twelve check bits.
        $remainder = $version << 12;

        for ($i = 5; $i >= 0; $i--) {
            if ($remainder & (1 << ($i + 12))) {
                $remainder ^= 0x1F25 << $i;
            }
        }

        $bits = ($version << 12) | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $bit = ($bits >> $i) & 1;

            $matrix[$size - 11 + $i % 3][intdiv($i, 3)] = $bit;
            $matrix[intdiv($i, 3)][$size - 11 + $i % 3] = $bit;
        }
    }

    /** The eight mask conditions, ISO 18004 table 10. */
    private static function mask(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($y + $x) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($y + $x) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => (($y * $x) % 2) + (($y * $x) % 3) === 0,
            6 => ((($y * $x) % 2) + (($y * $x) % 3)) % 2 === 0,
            default => ((($y + $x) % 2) + (($y * $x) % 3)) % 2 === 0,
        };
    }

    /**
     * The 15 format bits, twice, per ISO 18004 §7.9.1.
     *
     * Indexing is written as `[row][column]` throughout this class, and this is
     * the method where getting that backwards costs the most: every coordinate
     * below is a *transpose* of a plausible-looking alternative, the symbol
     * still draws, and it simply never decodes. It cost an afternoon once
     * already in the reader (PROJECT_STATE §6, "a 3x3 transform has two
     * layouts"), so, explicitly:
     *
     *   copy one, around the top-left finder
     *     bits 0-5   column 8, rows 0-5
     *     bit  6     column 8, row 7        (row 6 is the timing pattern)
     *     bit  7     column 8, row 8
     *     bit  8     row 8, column 7
     *     bits 9-14  row 8, columns 5 down to 0
     *
     *   copy two, split between the other two finders, so damage to one corner
     *   does not cost the symbol
     *     bits 0-7   row 8, columns size-1 leftward
     *     bits 8-14  column 8, rows size-7 downward
     *
     * @param array<int,array<int,int>> $matrix
     */
    private static function writeFormat(array &$matrix, int $mask, int $size): void
    {
        $format = self::FORMAT_BITS[$mask];

        for ($i = 0; $i < 15; $i++) {
            $bit = ($format >> $i) & 1;

            if ($i < 6) {
                $matrix[$i][8] = $bit;
            } elseif ($i === 6) {
                $matrix[7][8] = $bit;
            } elseif ($i === 7) {
                $matrix[8][8] = $bit;
            } elseif ($i === 8) {
                $matrix[8][7] = $bit;
            } else {
                $matrix[8][14 - $i] = $bit;
            }

            if ($i < 8) {
                $matrix[8][$size - 1 - $i] = $bit;
            } else {
                $matrix[$size - 15 + $i][8] = $bit;
            }
        }
    }

    /**
     * The four penalty rules of ISO 18004 §7.8.3.1, used to choose a mask.
     *
     * @param array<int,array<int,int>> $matrix
     */
    private static function penalty(array $matrix): int
    {
        $size  = count($matrix);
        $score = 0;

        // Rule 1: runs of five or more of the same colour, in rows and columns.
        for ($i = 0; $i < $size; $i++) {
            foreach ([true, false] as $horizontal) {
                $run     = 1;
                $previous = -1;

                for ($j = 0; $j < $size; $j++) {
                    $value = $horizontal ? $matrix[$i][$j] : $matrix[$j][$i];

                    if ($value === $previous) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $score += 3 + ($run - 5);
                        }

                        $run      = 1;
                        $previous = $value;
                    }
                }

                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
            }
        }

        // Rule 2: 2x2 blocks of one colour.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $v = $matrix[$y][$x];

                if ($v === $matrix[$y][$x + 1] && $v === $matrix[$y + 1][$x] && $v === $matrix[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // Rule 3: the finder-like 1:1:3:1:1 sequence with four light modules
        // on either side — the pattern a reader uses to find the symbol, so it
        // must not appear in the data.
        $patterns = ['10111010000', '00001011101'];

        for ($i = 0; $i < $size; $i++) {
            $row = '';
            $col = '';

            for ($j = 0; $j < $size; $j++) {
                $row .= $matrix[$i][$j];
                $col .= $matrix[$j][$i];
            }

            foreach ($patterns as $pattern) {
                $score += substr_count($row, $pattern) * 40;
                $score += substr_count($col, $pattern) * 40;
            }
        }

        // Rule 4: how far the proportion of dark modules is from half.
        $dark = 0;

        foreach ($matrix as $row) {
            $dark += array_sum($row);
        }

        $percent = ($dark * 100) / ($size * $size);
        $score  += ((int) (abs($percent - 50) / 5)) * 10;

        return $score;
    }
}
