<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Code 128 barcode rendering, as inline SVG.
 *
 * SVG rather than a raster image: it needs no GD/Imagick, prints crisply at any
 * printer resolution (which matters — a fuzzy label will not scan), and can be
 * embedded straight into the print view with no extra HTTP request.
 *
 * Code set B is used throughout: it covers all printable ASCII, which is what
 * asset tags contain. Where a tag is all digits and of even length, code set C
 * is used instead to roughly halve the width.
 */
final class Barcode
{
    /**
     * Bar/space widths for Code 128 values 0-106. Each character is six
     * alternating widths (bar, space, bar, space, bar, space); the stop
     * pattern has seven.
     *
     * @var array<int,string>
     */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232', '2331112',
    ];

    private const START_B = 104;
    private const START_C = 105;
    private const STOP    = 106;

    /**
     * Render a value as an SVG barcode.
     *
     * @param float $moduleWidth Width of one narrow bar, in the chosen unit.
     * @param float $height      Bar height, in the chosen unit.
     */
    public static function svg(
        string $value,
        float $moduleWidth = 0.33,
        float $height = 14.0,
        string $unit = 'mm',
        bool $showText = true,
        int $quietZoneModules = 10
    ): string {
        $encoded = self::encode($value);

        $totalModules = 0;
        foreach ($encoded as $pattern) {
            $totalModules += array_sum(array_map('intval', str_split($pattern)));
        }

        $totalModules += $quietZoneModules * 2;

        $width     = $totalModules * $moduleWidth;
        $textSpace = $showText ? max(3.0, $height * 0.28) : 0.0;
        $fullHeight = $height + $textSpace;

        $bars = [];
        $x    = $quietZoneModules * $moduleWidth;

        foreach ($encoded as $pattern) {
            $isBar = true;

            foreach (str_split($pattern) as $widthDigit) {
                $barWidth = ((int) $widthDigit) * $moduleWidth;

                if ($isBar) {
                    $bars[] = sprintf(
                        '<rect x="%s" y="0" width="%s" height="%s"/>',
                        self::num($x),
                        self::num($barWidth),
                        self::num($height)
                    );
                }

                $x    += $barWidth;
                $isBar = !$isBar;
            }
        }

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$s%3$s" height="%2$s%3$s" viewBox="0 0 %1$s %2$s" role="img" aria-label="Barcode %4$s">',
            self::num($width),
            self::num($fullHeight),
            $unit,
            htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
        );

        $svg .= '<rect width="100%" height="100%" fill="#ffffff"/>';
        $svg .= '<g fill="#000000">' . implode('', $bars) . '</g>';

        if ($showText) {
            $svg .= sprintf(
                '<text x="%s" y="%s" font-family="monospace" font-size="%s" text-anchor="middle" fill="#000000" letter-spacing="%s">%s</text>',
                self::num($width / 2),
                self::num($fullHeight - ($textSpace * 0.18)),
                self::num($textSpace * 0.78),
                self::num($moduleWidth * 0.6),
                htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
            );
        }

        return $svg . '</svg>';
    }

    /**
     * Turn a value into the list of bar/space patterns, including the start,
     * check and stop symbols.
     *
     * @return array<int,string>
     */
    public static function encode(string $value): array
    {
        if ($value === '') {
            throw new InvalidArgumentException('Cannot encode an empty barcode value.');
        }

        if (preg_match('/^[\x20-\x7E]+$/', $value) !== 1) {
            throw new InvalidArgumentException('Code 128 can only encode printable ASCII characters.');
        }

        $useC  = ctype_digit($value) && strlen($value) % 2 === 0 && strlen($value) >= 4;
        $codes = [$useC ? self::START_C : self::START_B];

        if ($useC) {
            foreach (str_split($value, 2) as $pair) {
                $codes[] = (int) $pair;
            }
        } else {
            foreach (str_split($value) as $character) {
                $codes[] = ord($character) - 32;
            }
        }

        // Checksum: start value + each subsequent value multiplied by its
        // 1-based position, modulo 103.
        $sum = $codes[0];
        for ($i = 1, $count = count($codes); $i < $count; $i++) {
            $sum += $codes[$i] * $i;
        }

        $codes[] = $sum % 103;
        $codes[] = self::STOP;

        return array_map(static fn (int $code): string => self::PATTERNS[$code], $codes);
    }

    /** Is this value encodable as a Code 128 barcode? */
    public static function isEncodable(string $value): bool
    {
        return $value !== '' && preg_match('/^[\x20-\x7E]+$/', $value) === 1;
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
