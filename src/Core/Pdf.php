<?php

declare(strict_types=1);

namespace App\Core;

/**
 * A small PDF writer.
 *
 * First-party for the same reason the Code 128 and QR encoders are: the one
 * runtime dependency this application has is PHPMailer, it still runs from a
 * plain file copy with no Composer anywhere, and a document generator is a
 * bounded problem. What is here is what a printed record needs — the three
 * Helvetica faces, wrapped text, rules, boxes and photographs — and nothing
 * else.
 *
 * Co-ordinates are given **from the top of the page**, which is how a document
 * is laid out in the head; the PDF's own origin at the bottom-left is the last
 * thing converted, in point().
 *
 * The standard fourteen fonts need no embedding, so a document carries no font
 * data and every reader already has the metrics. The width tables below are
 * those metrics: they are what makes wrapping land in the right place.
 */
final class Pdf
{
    public const A4_WIDTH  = 595.28;
    public const A4_HEIGHT = 841.89;

    public const REGULAR = 'regular';
    public const BOLD    = 'bold';
    public const ITALIC  = 'italic';

    private const FONTS = [
        self::REGULAR => ['F1', 'Helvetica'],
        self::BOLD    => ['F2', 'Helvetica-Bold'],
        self::ITALIC  => ['F3', 'Helvetica-Oblique'],
    ];

    private float $width;
    private float $height;
    private float $margin;

    /** @var array<int,string> Each page's content stream, built as it is drawn. */
    private array $pages = [];

    private int $page = -1;

    /** Cursor, measured down from the top of the page. */
    private float $cursor = 0.0;

    private string $style = self::REGULAR;
    private float $size   = 10.0;

    /** @var array<string,array{0:string,1:int,2:int,3:string,4:string}> name => [data, w, h, colourspace, filter] */
    private array $images = [];

    /** @var callable|null fn(self $pdf, int $page, int $total): void */
    private $footer = null;

    /** @var callable|null fn(self $pdf): void — draws the masthead on every new page */
    private $onPage = null;

    public function __construct(
        float $width = self::A4_WIDTH,
        float $height = self::A4_HEIGHT,
        float $margin = 48.0
    ) {
        $this->width  = $width;
        $this->height = $height;
        $this->margin = $margin;
    }

    // -- Geometry -----------------------------------------------------------

    public function width(): float
    {
        return $this->width;
    }

    public function height(): float
    {
        return $this->height;
    }

    public function margin(): float
    {
        return $this->margin;
    }

    public function left(): float
    {
        return $this->margin;
    }

    public function right(): float
    {
        return $this->width - $this->margin;
    }

    public function contentWidth(): float
    {
        return $this->width - (2 * $this->margin);
    }

    /** The last line that may be drawn on before the footer's own band. */
    public function bottom(): float
    {
        return $this->height - $this->margin - 24.0;
    }

    public function y(): float
    {
        return $this->cursor;
    }

    public function setY(float $y): void
    {
        $this->cursor = $y;
    }

    public function moveDown(float $amount): void
    {
        $this->cursor += $amount;
    }

    /** Would this much vertical space still fit on the current page? */
    public function fits(float $needed): bool
    {
        return ($this->cursor + $needed) <= $this->bottom();
    }

    /** Start a new page unless this much space is already available. */
    public function ensure(float $needed): void
    {
        if ($this->page < 0 || !$this->fits($needed)) {
            $this->addPage();
        }
    }

    // -- Pages --------------------------------------------------------------

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->page    = count($this->pages) - 1;
        $this->cursor  = $this->margin;

        // Whatever tops every page is drawn here rather than by the caller,
        // because ensure() starts pages of its own accord halfway through a
        // paragraph and a masthead the caller has to remember is a masthead
        // that will be missing from page four.
        if ($this->onPage !== null) {
            ($this->onPage)($this);
        }
    }

    /** @param callable $onPage fn(self $pdf): void */
    public function setOnPage(callable $onPage): void
    {
        $this->onPage = $onPage;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** @param callable $footer fn(self $pdf, int $page, int $total): void */
    public function setFooter(callable $footer): void
    {
        $this->footer = $footer;
    }

    // -- Text ---------------------------------------------------------------

    public function setFont(string $style, float $size): void
    {
        $this->style = isset(self::FONTS[$style]) ? $style : self::REGULAR;
        $this->size  = $size;
    }

    public function fontSize(): float
    {
        return $this->size;
    }

    /**
     * One line of text, with its baseline at $y from the top.
     *
     * @param array{0:float,1:float,2:float}|null $colour RGB, each 0–1
     */
    public function text(string $text, float $x, float $y, ?array $colour = null): void
    {
        if ($text === '') {
            return;
        }

        [$resource] = self::FONTS[$this->style];

        $this->write(sprintf(
            "BT %s %s %s rg /%s %s Tf 1 0 0 1 %s %s Tm (%s) Tj ET\n",
            self::num($colour[0] ?? 0.0),
            self::num($colour[1] ?? 0.0),
            self::num($colour[2] ?? 0.0),
            $resource,
            self::num($this->size),
            self::num($x),
            self::num($this->point($y)),
            self::escape($text)
        ));
    }

    /** The same, ending at $right rather than starting at $x. */
    public function textRight(string $text, float $right, float $y, ?array $colour = null): void
    {
        $this->text($text, $right - $this->stringWidth($text, $this->style, $this->size), $y, $colour);
    }

    public function textCentred(string $text, float $centre, float $y, ?array $colour = null): void
    {
        $this->text($text, $centre - ($this->stringWidth($text, $this->style, $this->size) / 2), $y, $colour);
    }

    /**
     * Flow text into a column, breaking pages as it goes.
     *
     * Returns the cursor to just below the last line written.
     *
     * @param array{0:float,1:float,2:float}|null $colour
     */
    public function paragraph(
        string $text,
        float $x,
        float $columnWidth,
        float $leading = 1.35,
        ?array $colour = null
    ): void {
        $lineHeight = $this->size * $leading;

        foreach ($this->wrap($text, $columnWidth, $this->style, $this->size) as $line) {
            $this->ensure($lineHeight);
            $this->text($line, $x, $this->cursor + $this->size, $colour);
            $this->cursor += $lineHeight;
        }
    }

    /**
     * Break text into lines that fit a column.
     *
     * Existing line breaks are kept — a note typed with paragraphs should read
     * as one — and a single word longer than the column is split rather than
     * left to run off the edge.
     *
     * @return array<int,string>
     */
    public function wrap(string $text, float $columnWidth, string $style, float $size): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $text) ?: [''] as $source) {
            $words = preg_split('/ +/', trim($source)) ?: [];
            $line  = '';

            if ($words === [] || $words === ['']) {
                $lines[] = '';
                continue;
            }

            foreach ($words as $word) {
                foreach ($this->splitLongWord($word, $columnWidth, $style, $size) as $piece) {
                    $candidate = $line === '' ? $piece : $line . ' ' . $piece;

                    if ($this->stringWidth($candidate, $style, $size) <= $columnWidth) {
                        $line = $candidate;
                        continue;
                    }

                    if ($line !== '') {
                        $lines[] = $line;
                    }

                    $line = $piece;
                }
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /** @return array<int,string> */
    private function splitLongWord(string $word, float $columnWidth, string $style, float $size): array
    {
        if ($this->stringWidth($word, $style, $size) <= $columnWidth) {
            return [$word];
        }

        $pieces  = [];
        $current = '';

        foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($current !== '' && $this->stringWidth($current . $character, $style, $size) > $columnWidth) {
                $pieces[] = $current;
                $current  = '';
            }

            $current .= $character;
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /** Text shortened with an ellipsis until it fits. */
    public function fit(string $text, float $columnWidth, string $style, float $size): string
    {
        if ($this->stringWidth($text, $style, $size) <= $columnWidth) {
            return $text;
        }

        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out        = '';

        foreach ($characters as $character) {
            if ($this->stringWidth($out . $character . '…', $style, $size) > $columnWidth) {
                break;
            }

            $out .= $character;
        }

        return rtrim($out) . '…';
    }

    /** The width of a string in points at a given size. */
    public function stringWidth(string $text, string $style, float $size): float
    {
        $widths = $style === self::BOLD ? self::widthsBold() : self::widthsRegular();
        $total  = 0;

        foreach (str_split(self::toWinAnsi($text)) as $character) {
            $total += $widths[ord($character)] ?? 556;
        }

        return ($total / 1000) * $size;
    }

    // -- Rules and boxes -----------------------------------------------------

    /** @param array{0:float,1:float,2:float} $colour */
    public function line(float $x1, float $y1, float $x2, float $y2, float $thickness = 0.6, array $colour = [0.8, 0.8, 0.8]): void
    {
        $this->write(sprintf(
            "%s %s %s RG %s w %s %s m %s %s l S\n",
            self::num($colour[0]),
            self::num($colour[1]),
            self::num($colour[2]),
            self::num($thickness),
            self::num($x1),
            self::num($this->point($y1)),
            self::num($x2),
            self::num($this->point($y2))
        ));
    }

    /** A filled rectangle, given its top-left corner. */
    public function fillRect(float $x, float $y, float $w, float $h, array $colour): void
    {
        $this->write(sprintf(
            "%s %s %s rg %s %s %s %s re f\n",
            self::num($colour[0]),
            self::num($colour[1]),
            self::num($colour[2]),
            self::num($x),
            self::num($this->point($y + $h)),
            self::num($w),
            self::num($h)
        ));
    }

    public function strokeRect(float $x, float $y, float $w, float $h, float $thickness = 0.6, array $colour = [0.8, 0.8, 0.8]): void
    {
        $this->write(sprintf(
            "%s %s %s RG %s w %s %s %s %s re S\n",
            self::num($colour[0]),
            self::num($colour[1]),
            self::num($colour[2]),
            self::num($thickness),
            self::num($x),
            self::num($this->point($y + $h)),
            self::num($w),
            self::num($h)
        ));
    }

    // -- Images --------------------------------------------------------------

    /**
     * The pixel size of an image, or null if it cannot be read.
     *
     * @return array{0:int,1:int}|null
     */
    public function imageSize(string $path): ?array
    {
        $info = @getimagesize($path);

        return $info === false ? null : [(int) $info[0], (int) $info[1]];
    }

    /**
     * Place an image, given its top-left corner and the box to fill.
     *
     * JPEG data goes in untouched; anything else is re-encoded through GD,
     * which this application already treats as optional. Without GD a PNG
     * simply does not appear — the document is still correct, and losing a
     * photograph is a better outcome than losing the record.
     */
    public function image(string $path, float $x, float $y, float $w, float $h): bool
    {
        $prepared = $this->prepareImage($path);

        if ($prepared === null) {
            return false;
        }

        [$name] = $prepared;

        $this->write(sprintf(
            "q %s 0 0 %s %s %s cm /%s Do Q\n",
            self::num($w),
            self::num($h),
            self::num($x),
            self::num($this->point($y + $h)),
            $name
        ));

        return true;
    }

    /** @return array{0:string}|null */
    private function prepareImage(string $path): ?array
    {
        $key = 'Im' . substr(sha1($path), 0, 12);

        if (isset($this->images[$key])) {
            return [$key];
        }

        $info = @getimagesize($path);

        if ($info === false) {
            return null;
        }

        $mime = (string) ($info['mime'] ?? '');

        if ($mime === 'image/jpeg') {
            $data = @file_get_contents($path);

            if ($data === false) {
                return null;
            }

            $colourSpace = match ((int) ($info['channels'] ?? 3)) {
                1       => '/DeviceGray',
                4       => '/DeviceCMYK',
                default => '/DeviceRGB',
            };

            $this->images[$key] = [$data, (int) $info[0], (int) $info[1], $colourSpace, '/DCTDecode'];

            return [$key];
        }

        $data = self::transcodeToJpeg($path);

        if ($data === null) {
            return null;
        }

        $this->images[$key] = [$data, (int) $info[0], (int) $info[1], '/DeviceRGB', '/DCTDecode'];

        return [$key];
    }

    /** Re-encode anything GD can open as a JPEG, or null when it cannot. */
    private static function transcodeToJpeg(string $path): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $image = @imagecreatefromstring($raw);

        if ($image === false) {
            return null;
        }

        // Transparency has to land on something. White is what the paper is.
        $flat = @imagecreatetruecolor(imagesx($image), imagesy($image));

        if ($flat !== false) {
            @imagefill($flat, 0, 0, (int) @imagecolorallocate($flat, 255, 255, 255));
            @imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            imagedestroy($image);
            $image = $flat;
        }

        ob_start();
        $ok = @imagejpeg($image, null, 88);
        $data = (string) ob_get_clean();

        imagedestroy($image);

        return ($ok && $data !== '') ? $data : null;
    }

    // -- Assembly ------------------------------------------------------------

    /** The finished document. */
    public function output(): string
    {
        if ($this->pages === []) {
            $this->addPage();
        }

        $this->drawFooters();

        $objects   = [];
        $pageCount = count($this->pages);

        // 1 catalogue, 2 pages node, 3–5 fonts, then a content stream and a
        // page object per page, then one object per image.
        $firstContent = 6;
        $firstPage    = $firstContent + $pageCount;
        $firstImage   = $firstPage + $pageCount;

        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = ($firstPage + $i) . ' 0 R';
        }

        $imageNames = array_keys($this->images);
        $xobjects   = [];

        foreach ($imageNames as $index => $name) {
            $xobjects[] = '/' . $name . ' ' . ($firstImage + $index) . ' 0 R';
        }

        $resources = '<< /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >>'
            . ($xobjects === [] ? '' : ' /XObject << ' . implode(' ', $xobjects) . ' >>')
            . ' >>';

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount
            . ' /MediaBox [0 0 ' . self::num($this->width) . ' ' . self::num($this->height) . ']'
            . ' /Resources ' . $resources . ' >>';

        $fontIndex = 3;
        foreach (self::FONTS as [, $base]) {
            $objects[$fontIndex++] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $base
                . ' /Encoding /WinAnsiEncoding >>';
        }

        foreach ($this->pages as $index => $content) {
            $objects[$firstContent + $index] = self::stream($content);
            $objects[$firstPage + $index]    = '<< /Type /Page /Parent 2 0 R /Contents '
                . ($firstContent + $index) . ' 0 R >>';
        }

        foreach ($imageNames as $index => $name) {
            [$data, $w, $h, $colourSpace, $filter] = $this->images[$name];

            $objects[$firstImage + $index] = '<< /Type /XObject /Subtype /Image'
                . ' /Width ' . $w . ' /Height ' . $h
                . ' /ColorSpace ' . $colourSpace
                . ' /BitsPerComponent 8 /Filter ' . $filter
                . ' /Length ' . strlen($data) . ' >>'
                . "\nstream\n" . $data . "\nendstream";
        }

        return self::assemble($objects);
    }

    /** @param array<int,string> $objects 1-indexed and contiguous */
    private static function assemble(array $objects): string
    {
        ksort($objects);

        $pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $count      = count($objects) + 1;
        $xrefOffset = strlen($pdf);

        $pdf .= "xref\n0 " . $count . "\n0000000000 65535 f \n";

        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }

        $pdf .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    /** A content stream, compressed when zlib is available. */
    private static function stream(string $content): string
    {
        if (function_exists('gzcompress')) {
            $compressed = @gzcompress($content, 6);

            if (is_string($compressed) && $compressed !== '') {
                return '<< /Length ' . strlen($compressed) . " /Filter /FlateDecode >>\nstream\n"
                    . $compressed . "\nendstream";
            }
        }

        return '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
    }

    /** Draw each page's footer now that the total is known. */
    private function drawFooters(): void
    {
        if ($this->footer === null) {
            return;
        }

        $total    = count($this->pages);
        $original = $this->page;

        for ($i = 0; $i < $total; $i++) {
            $this->page = $i;
            ($this->footer)($this, $i + 1, $total);
        }

        $this->page = $original;
    }

    // -- Primitives ----------------------------------------------------------

    private function write(string $chunk): void
    {
        if ($this->page < 0) {
            $this->addPage();
        }

        $this->pages[$this->page] .= $chunk;
    }

    /** A y measured from the top, as the PDF's own y from the bottom. */
    private function point(float $y): float
    {
        return $this->height - $y;
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * A PHP string as a PDF literal string.
     *
     * WinAnsi is what the fonts are declared with, so the text is converted to
     * it first; a character with no place in that encoding becomes a question
     * mark rather than a byte the reader will misdraw.
     */
    private static function escape(string $text): string
    {
        return str_replace(
            ['\\', '(', ')', "\r"],
            ['\\\\', '\\(', '\\)', ''],
            self::toWinAnsi($text)
        );
    }

    private static function toWinAnsi(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        if (is_string($converted) && $converted !== '') {
            return $converted;
        }

        return (string) @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    // -- Metrics -------------------------------------------------------------
    //
    // Helvetica's own advance widths, in 1/1000 em, indexed by WinAnsi code.
    // Helvetica-Oblique shares Helvetica's, which is why there are two tables
    // and three fonts. Codes not listed fall back to the width of "n", and the
    // accented Latin letters above 191 genuinely do have their base letter's
    // width in this family, so they are mapped rather than listed.

    /** @return array<int,int> */
    private static function widthsRegular(): array
    {
        static $widths = null;

        return $widths ??= self::buildWidths(
            '278 278 355 556 556 889 667 191 333 333 389 584 278 333 278 278'
            . ' 556 556 556 556 556 556 556 556 556 556 278 278 584 584 584 556'
            . ' 1015 667 667 722 722 667 611 778 722 278 500 667 556 833 722 778'
            . ' 667 778 722 667 611 722 667 944 667 667 611 278 278 278 469 556'
            . ' 333 556 556 500 556 556 278 556 556 222 222 500 222 833 556 556'
            . ' 556 556 333 500 278 556 500 722 500 500 500 334 260 334 584',
            ['128' => 556, '145' => 222, '146' => 222, '147' => 333, '148' => 333,
             '149' => 350, '150' => 556, '151' => 1000, '160' => 278, '163' => 556,
             '169' => 737, '176' => 400, '183' => 278, '215' => 584, '247' => 584],
            556
        );
    }

    /** @return array<int,int> */
    private static function widthsBold(): array
    {
        static $widths = null;

        return $widths ??= self::buildWidths(
            '278 333 474 556 556 889 722 238 333 333 389 584 278 333 278 278'
            . ' 556 556 556 556 556 556 556 556 556 556 333 333 584 584 584 611'
            . ' 975 722 722 722 722 667 611 778 722 278 556 722 611 833 722 778'
            . ' 667 778 722 667 611 722 667 944 667 667 611 333 278 333 584 556'
            . ' 333 556 611 556 611 556 333 611 611 278 278 556 278 889 611 611'
            . ' 611 611 389 556 333 611 556 778 556 556 500 389 280 389 584',
            ['128' => 556, '145' => 278, '146' => 278, '147' => 500, '148' => 500,
             '149' => 350, '150' => 556, '151' => 1000, '160' => 278, '163' => 556,
             '169' => 737, '176' => 400, '183' => 278, '215' => 584, '247' => 584],
            611
        );
    }

    /**
     * Expand a table written for codes 32–126 to the whole byte range.
     *
     * @param array<string,int> $extras
     * @return array<int,int>
     */
    private static function buildWidths(string $ascii, array $extras, int $fallback): array
    {
        $widths = array_fill(0, 256, $fallback);
        $code   = 32;

        foreach (preg_split('/\s+/', trim($ascii)) ?: [] as $value) {
            $widths[$code++] = (int) $value;
        }

        // Accented Latin letters carry their base letter's advance in this
        // family, so the map is a transliteration rather than a second table.
        $bases = 'AAAAAAECEEEEIIIIDNOOOOOxOUUUUYPBaaaaaaeceeeeiiiidnooooo/ouuuuypy';

        for ($i = 192; $i <= 255; $i++) {
            $base = $bases[$i - 192];

            if ($base !== 'x' && $base !== '/') {
                $widths[$i] = $widths[ord($base)];
            }
        }

        foreach ($extras as $code => $value) {
            $widths[(int) $code] = $value;
        }

        return $widths;
    }
}
