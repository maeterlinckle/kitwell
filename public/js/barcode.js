/* ==========================================================================
   Barcode and QR decoding.

   Three symbologies, one entry point:

     - Code 128, which is what this application prints (src/Core/Barcode.php).
     - Code 39, still the default on a lot of older label printers and on
       equipment that arrived with an asset tag already on it.
     - QR, which is what is on the plate of most machinery bought this decade.

   This exists because the browser's own BarcodeDetector API does not.  Chrome
   and Edge on Android have it; Safari — which is every iPhone — does not, and
   an iPhone in a workshop is the normal case, not the edge case.  Where the
   native detector is present scanner.js uses it and none of this runs.

   No third-party library: the Content-Security-Policy only allows scripts
   from this origin, and a barcode reader is not worth a vendored blob nobody
   can audit.  The Code 128 table below is the mirror image of the encoder in
   src/Core/Barcode.php.

   The QR half is a compact implementation of ISO/IEC 18004: locate the three
   finder patterns, work out the grid, read the format information, undo the
   data mask, de-interleave the blocks, Reed-Solomon correct them and parse the
   bit stream.  It is the standard algorithm, laid out in that order below.

   Verified by tests/barcode-decode.html, which renders known values through an
   independent encoder and checks they come back.
   ========================================================================== */
(function (global) {
    'use strict';

    /* ======================================================================
       Code 128
       ====================================================================== */

    // Bar/space widths for values 0-106; identical to the PHP encoder's table.
    var C128_PATTERNS = [
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
        '211214', '211232', '2331112'
    ];

    var START_A = 103, START_B = 104, START_C = 105, STOP = 106;

    var C128_LOOKUP = (function () {
        var map = {}, i;
        for (i = 0; i < C128_PATTERNS.length; i++) {
            map[C128_PATTERNS[i]] = i;
        }
        return map;
    })();

    /**
     * Turn a run of bar/space widths into a symbol value.
     * Widths are normalised against the module width so any print scale works.
     */
    function symbolFromWidths(widths, moduleWidth) {
        var key = '', i, units;

        for (i = 0; i < widths.length; i++) {
            units = Math.round(widths[i] / moduleWidth);
            if (units < 1 || units > 4) return -1;
            key += units;
        }

        var value = C128_LOOKUP[key];
        return value === undefined ? -1 : value;
    }

    function decodeCode128(runs) {
        var start, k, startWidth, moduleWidth, decoded;

        for (start = 0; start < runs.length - 18; start++) {
            if (!runs[start].dark) continue;

            // The start symbol is 11 modules across 6 runs.
            startWidth = 0;
            for (k = 0; k < 6; k++) {
                if (start + k >= runs.length) return null;
                startWidth += runs[start + k].width;
            }

            moduleWidth = startWidth / 11;
            if (moduleWidth < 0.7) continue;

            decoded = decodeC128From(runs, start, moduleWidth);
            if (decoded !== null) return decoded;
        }

        return null;
    }

    function decodeC128From(runs, start, moduleWidth) {
        var values = [];
        var index = start;
        var widths, value, stopWidths, span, m, k;

        while (index + 6 <= runs.length && values.length < 64) {
            widths = [];
            for (k = 0; k < 6; k++) {
                widths.push(runs[index + k].width);
            }

            value = symbolFromWidths(widths, moduleWidth);

            if (value === -1) {
                // The stop pattern is seven runs wide.
                if (index + 7 <= runs.length) {
                    stopWidths = widths.concat([runs[index + 6].width]);
                    if (symbolFromWidths(stopWidths, moduleWidth) === STOP) {
                        return finishCode128(values);
                    }
                }
                return null;
            }

            if (value === STOP) return finishCode128(values);

            values.push(value);
            index += 6;

            // Re-estimate the module width as we go, so a slightly skewed or
            // curved label does not drift out of tolerance.
            span = 0;
            for (m = start; m < index; m++) span += runs[m].width;
            moduleWidth = span / (11 * values.length);
        }

        return null;
    }

    /** Verify the checksum and turn symbol values into text. */
    function finishCode128(values) {
        if (values.length < 3) return null;

        var startValue = values[0];
        if (startValue !== START_A && startValue !== START_B && startValue !== START_C) return null;

        var checkValue = values[values.length - 1];
        var data = values.slice(1, values.length - 1);
        var sum = startValue;
        var i, j, value, character;

        for (i = 0; i < data.length; i++) {
            sum += data[i] * (i + 1);
        }

        if (sum % 103 !== checkValue) return null;

        var text = '';
        var mode = startValue;

        for (j = 0; j < data.length; j++) {
            value = data[j];

            // Code set switches. Anything else non-printable is not something
            // this application prints, so bail rather than guess.
            if (value === 99) { mode = START_C; continue; }
            if (value === 100) { mode = START_B; continue; }
            if (value === 101) { mode = START_A; continue; }

            if (mode === START_C) {
                if (value > 99) return null;
                text += (value < 10 ? '0' : '') + value;
            } else if (mode === START_A) {
                // Set A puts the control characters at 64-95. We never print
                // them and cannot look one up, so treat them as a bad read.
                if (value > 63) return null;
                character = value + 32;
                text += String.fromCharCode(character);
            } else {
                if (value > 94) return null;
                text += String.fromCharCode(value + 32);
            }
        }

        return text === '' ? null : text;
    }

    /* ======================================================================
       Code 39

       Nine elements per character — five bars, four spaces — of which exactly
       three are wide.  There is no mandatory check digit, which makes a false
       read possible in a way Code 128 is not; the guards against that are the
       quiet zones either side, the strict wide/narrow ratio test, and the rule
       in scanLuma1D that the same value must appear on two scan lines before
       it is believed.
       ====================================================================== */

    var C39_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%';

    // One bit per element, most significant first, set = wide.
    var C39_ENCODINGS = [
        0x034, 0x121, 0x061, 0x160, 0x031, 0x130, 0x070, 0x025, 0x124, 0x064, // 0-9
        0x109, 0x049, 0x148, 0x019, 0x118, 0x058, 0x00D, 0x10C, 0x04C, 0x01C, // A-J
        0x103, 0x043, 0x142, 0x013, 0x112, 0x052, 0x007, 0x106, 0x046, 0x016, // K-T
        0x181, 0x0C1, 0x1C0, 0x091, 0x190, 0x0D0, 0x085, 0x184, 0x0C4, 0x0A8, // U-$
        0x0A2, 0x08A, 0x02A                                                    // /, +, %
    ];

    var C39_ASTERISK = 0x094;

    /**
     * Classify nine element widths as narrow or wide.
     *
     * Rather than guess a threshold, raise the narrow ceiling one measured
     * width at a time until exactly three elements sit above it — which is the
     * defining property of a Code 39 character.
     *
     * @return {number} nine-bit pattern, or -1
     */
    function code39Pattern(counters) {
        var numCounters = counters.length;
        var maxNarrow = 0;
        var wideCount, totalWide, pattern, minCounter, i, counter;

        do {
            minCounter = Infinity;
            for (i = 0; i < numCounters; i++) {
                counter = counters[i];
                if (counter < minCounter && counter > maxNarrow) minCounter = counter;
            }

            if (minCounter === Infinity) return -1;
            maxNarrow = minCounter;

            wideCount = 0;
            totalWide = 0;
            pattern = 0;

            for (i = 0; i < numCounters; i++) {
                counter = counters[i];
                if (counter > maxNarrow) {
                    pattern |= 1 << (numCounters - 1 - i);
                    wideCount++;
                    totalWide += counter;
                }
            }

            if (wideCount === 3) {
                // A wide element is nominally two to three times a narrow one.
                // If any single "wide" is at least half of all of them put
                // together the widths are too lopsided to trust.
                for (i = 0; i < numCounters && wideCount > 0; i++) {
                    counter = counters[i];
                    if (counter > maxNarrow) {
                        wideCount--;
                        if ((counter * 2) >= totalWide) return -1;
                    }
                }
                return pattern;
            }
        } while (wideCount > 3);

        return -1;
    }

    function code39Char(pattern) {
        var i;
        for (i = 0; i < C39_ENCODINGS.length; i++) {
            if (C39_ENCODINGS[i] === pattern) return C39_ALPHABET.charAt(i);
        }
        return null;
    }

    function countersFrom(runs, start) {
        var counters = [], k;
        for (k = 0; k < 9; k++) {
            counters.push(runs[start + k].width);
        }
        return counters;
    }

    function code39Width(counters) {
        var total = 0, i;
        for (i = 0; i < counters.length; i++) total += counters[i];
        return total;
    }

    function decodeCode39(runs) {
        var start, counters, width, text, character, index, pattern;

        for (start = 0; start + 9 <= runs.length; start++) {
            if (!runs[start].dark) continue;

            counters = countersFrom(runs, start);
            if (code39Pattern(counters) !== C39_ASTERISK) continue;

            // A leading quiet zone of at least half the start character. Without
            // this test, any three-wide-element run inside a denser barcode
            // reads as a start pattern.
            width = code39Width(counters);
            if (start === 0 || runs[start - 1].dark || runs[start - 1].width * 2 < width) continue;

            text = '';
            index = start + 10; // the start character, then its inter-character gap

            while (index + 9 <= runs.length) {
                counters = countersFrom(runs, index);
                pattern = code39Pattern(counters);

                if (pattern === -1) break;

                if (pattern === C39_ASTERISK) {
                    // Trailing quiet zone, or the end of the sampled line.
                    var after = index + 9;
                    if (after < runs.length
                        && (runs[after].dark || runs[after].width * 2 < code39Width(counters))) {
                        break;
                    }

                    // Two characters is the shortest we will believe. A single
                    // character between two asterisks is far more likely to be
                    // noise than a real label.
                    return text.length >= 2 ? text : null;
                }

                character = code39Char(pattern);
                if (character === null) break;

                text += character;
                if (text.length > 64) break;

                index += 10;
            }
        }

        return null;
    }

    /* ======================================================================
       One-dimensional scanning
       ====================================================================== */

    /**
     * Run-length encode a line of luminance samples into bar/space widths.
     */
    function runsFromSamples(samples) {
        var min = 255, max = 0, i;

        for (i = 0; i < samples.length; i++) {
            if (samples[i] < min) min = samples[i];
            if (samples[i] > max) max = samples[i];
        }

        // Too little contrast to be a barcode.
        if (max - min < 40) return null;

        var threshold = (min + max) / 2;
        var runs = [];
        var runStart = 0;
        var isDark = samples[0] < threshold;
        var dark;

        for (i = 1; i < samples.length; i++) {
            dark = samples[i] < threshold;
            if (dark !== isDark) {
                runs.push({ dark: isDark, width: i - runStart });
                runStart = i;
                isDark = dark;
            }
        }
        runs.push({ dark: isDark, width: samples.length - runStart });

        // A Code 128 symbol needs at least start + data + check + stop.
        return runs.length < 20 ? null : runs;
    }

    /** Reject anything with control characters — it cannot be an asset tag. */
    function printable(text) {
        return text !== null && /^[\x20-\x7E]+$/.test(text);
    }

    /**
     * Decode one horizontal line of luminance samples.
     * Returns { text, format }, or null.
     */
    function scanLine(samples) {
        var runs = runsFromSamples(samples);
        if (runs === null) return null;

        var reversed = runs.slice().reverse();
        var both = [runs, reversed];
        var i, text;

        // Code 128 first: it carries a checksum, so a hit is close to certain.
        for (i = 0; i < 2; i++) {
            text = decodeCode128(both[i]);
            if (printable(text)) return { text: text, format: 'code_128' };
        }

        for (i = 0; i < 2; i++) {
            text = decodeCode39(both[i]);
            if (printable(text)) return { text: text, format: 'code_39' };
        }

        return null;
    }

    /** Back-compatible wrapper: the decoded string, or null. */
    function decodeLine(samples) {
        var result = scanLine(samples);
        return result === null ? null : result.text;
    }

    /**
     * Look for a 1D barcode by sampling horizontal lines across the frame.
     * Several lines, because one may cross a smudge, a fold or a reflection.
     */
    function scanLuma1D(luma, width, height) {
        var lines = 21;
        var seen = {};
        var n, y, result;

        for (n = 1; n <= lines; n++) {
            y = Math.floor((height * n) / (lines + 1));
            result = scanLine(luma.subarray(y * width, (y * width) + width));

            if (result === null) continue;
            if (result.format !== 'code_39') return result;

            // Code 39 has no checksum. Believe it on the second sighting: a
            // real barcode is tall enough to cross more than one scan line,
            // and noise almost never repeats itself exactly.
            if (seen[result.text]) return result;
            seen[result.text] = true;
        }

        return null;
    }

    /* ======================================================================
       QR: the tables from ISO/IEC 18004

       Everything here is data from the standard. The block table is the one
       place a transcription slip would hide, so tests/barcode-decode.html
       checks every entry against the module count worked out from the geometry
       — two independent routes to the same number.
       ====================================================================== */

    // Row/column centres of the alignment patterns, by version.
    var ALIGNMENT = [
        [], [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34],
        [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50], [6, 30, 54], [6, 32, 58],
        [6, 34, 62], [6, 26, 46, 66], [6, 26, 48, 70], [6, 26, 50, 74], [6, 30, 54, 78],
        [6, 30, 56, 82], [6, 30, 58, 86], [6, 34, 62, 90], [6, 28, 50, 72, 94],
        [6, 26, 50, 74, 98], [6, 30, 54, 78, 102], [6, 28, 54, 80, 106], [6, 32, 58, 84, 110],
        [6, 30, 58, 86, 114], [6, 34, 62, 90, 118], [6, 26, 50, 74, 98, 122],
        [6, 30, 54, 78, 102, 126], [6, 26, 52, 78, 104, 130], [6, 30, 56, 82, 108, 134],
        [6, 34, 60, 86, 112, 138], [6, 30, 58, 86, 114, 142], [6, 34, 62, 90, 118, 146],
        [6, 30, 54, 78, 102, 126, 150], [6, 24, 50, 76, 102, 128, 154],
        [6, 28, 54, 80, 106, 132, 158], [6, 32, 58, 84, 110, 136, 162],
        [6, 26, 54, 82, 110, 138, 166], [6, 30, 58, 86, 114, 142, 170]
    ];

    /**
     * Error-correction blocks, indexed [version][level] where level is
     * 0=L 1=M 2=Q 3=H, as [ecCodewordsPerBlock, blocks1, data1, blocks2, data2].
     * A version with a single block group has zeroes in the last two slots.
     */
    var EC_BLOCKS = [
        null,
        [[7, 1, 19, 0, 0], [10, 1, 16, 0, 0], [13, 1, 13, 0, 0], [17, 1, 9, 0, 0]],
        [[10, 1, 34, 0, 0], [16, 1, 28, 0, 0], [22, 1, 22, 0, 0], [28, 1, 16, 0, 0]],
        [[15, 1, 55, 0, 0], [26, 1, 44, 0, 0], [18, 2, 17, 0, 0], [22, 2, 13, 0, 0]],
        [[20, 1, 80, 0, 0], [18, 2, 32, 0, 0], [26, 2, 24, 0, 0], [16, 4, 9, 0, 0]],
        [[26, 1, 108, 0, 0], [24, 2, 43, 0, 0], [18, 2, 15, 2, 16], [22, 2, 11, 2, 12]],
        [[18, 2, 68, 0, 0], [16, 4, 27, 0, 0], [24, 4, 19, 0, 0], [28, 4, 15, 0, 0]],
        [[20, 2, 78, 0, 0], [18, 4, 31, 0, 0], [18, 2, 14, 4, 15], [26, 4, 13, 1, 14]],
        [[24, 2, 97, 0, 0], [22, 2, 38, 2, 39], [22, 4, 18, 2, 19], [26, 4, 14, 2, 15]],
        [[30, 2, 116, 0, 0], [22, 3, 36, 2, 37], [20, 4, 16, 4, 17], [24, 4, 12, 4, 13]],
        [[18, 2, 68, 2, 69], [26, 4, 43, 1, 44], [24, 6, 19, 2, 20], [28, 6, 15, 2, 16]],
        [[20, 4, 81, 0, 0], [30, 1, 50, 4, 51], [28, 4, 22, 4, 23], [24, 3, 12, 8, 13]],
        [[24, 2, 92, 2, 93], [22, 6, 36, 2, 37], [26, 4, 20, 6, 21], [28, 7, 14, 4, 15]],
        [[26, 4, 107, 0, 0], [22, 8, 37, 1, 38], [24, 8, 20, 4, 21], [22, 12, 11, 4, 12]],
        [[30, 3, 115, 1, 116], [24, 4, 40, 5, 41], [20, 11, 16, 5, 17], [24, 11, 12, 5, 13]],
        [[22, 5, 87, 1, 88], [24, 5, 41, 5, 42], [30, 5, 24, 7, 25], [24, 11, 12, 7, 13]],
        [[24, 5, 98, 1, 99], [28, 7, 45, 3, 46], [24, 15, 19, 2, 20], [30, 3, 15, 13, 16]],
        [[28, 1, 107, 5, 108], [28, 10, 46, 1, 47], [28, 1, 22, 15, 23], [28, 2, 14, 17, 15]],
        [[30, 5, 120, 1, 121], [26, 9, 43, 4, 44], [28, 17, 22, 1, 23], [28, 2, 14, 19, 15]],
        [[28, 3, 113, 4, 114], [26, 3, 44, 11, 45], [26, 17, 21, 4, 22], [26, 9, 13, 16, 14]],
        [[28, 3, 107, 5, 108], [26, 3, 41, 13, 42], [30, 15, 24, 5, 25], [28, 15, 15, 10, 16]],
        [[28, 4, 116, 4, 117], [26, 17, 42, 0, 0], [28, 17, 22, 6, 23], [30, 19, 16, 6, 17]],
        [[28, 2, 111, 7, 112], [28, 17, 46, 0, 0], [30, 7, 24, 16, 25], [24, 34, 13, 0, 0]],
        [[30, 4, 121, 5, 122], [28, 4, 47, 14, 48], [30, 11, 24, 14, 25], [30, 16, 15, 14, 16]],
        [[30, 6, 117, 4, 118], [28, 6, 45, 14, 46], [30, 11, 24, 16, 25], [30, 30, 16, 2, 17]],
        [[26, 8, 106, 4, 107], [28, 8, 47, 13, 48], [30, 7, 24, 22, 25], [30, 22, 15, 13, 16]],
        [[28, 10, 114, 2, 115], [28, 19, 46, 4, 47], [28, 28, 22, 6, 23], [30, 33, 16, 4, 17]],
        [[30, 8, 122, 4, 123], [28, 22, 45, 3, 46], [30, 8, 23, 26, 24], [30, 12, 15, 28, 16]],
        [[30, 3, 117, 10, 118], [28, 3, 45, 23, 46], [30, 4, 24, 31, 25], [30, 11, 15, 31, 16]],
        [[30, 7, 116, 7, 117], [28, 21, 45, 7, 46], [30, 1, 23, 37, 24], [30, 19, 15, 26, 16]],
        [[30, 5, 115, 10, 116], [28, 19, 47, 10, 48], [30, 15, 24, 25, 25], [30, 23, 15, 25, 16]],
        [[30, 13, 115, 3, 116], [28, 2, 46, 29, 47], [30, 42, 24, 1, 25], [30, 23, 15, 28, 16]],
        [[30, 17, 115, 0, 0], [28, 10, 46, 23, 47], [30, 10, 24, 35, 25], [30, 19, 15, 35, 16]],
        [[30, 17, 115, 1, 116], [28, 14, 46, 21, 47], [30, 29, 24, 19, 25], [30, 11, 15, 46, 16]],
        [[30, 13, 115, 6, 116], [28, 14, 46, 23, 47], [30, 44, 24, 7, 25], [30, 59, 16, 1, 17]],
        [[30, 12, 121, 7, 122], [28, 12, 47, 26, 48], [30, 39, 24, 14, 25], [30, 22, 15, 41, 16]],
        [[30, 6, 121, 14, 122], [28, 6, 47, 34, 48], [30, 46, 24, 10, 25], [30, 2, 15, 64, 16]],
        [[30, 17, 122, 4, 123], [28, 29, 46, 14, 47], [30, 49, 24, 10, 25], [30, 24, 15, 46, 16]],
        [[30, 4, 122, 18, 123], [28, 13, 46, 32, 47], [30, 48, 24, 14, 25], [30, 42, 15, 32, 16]],
        [[30, 20, 117, 4, 118], [28, 40, 47, 7, 48], [30, 43, 24, 22, 25], [30, 10, 15, 67, 16]],
        [[30, 19, 118, 6, 119], [28, 18, 47, 31, 48], [30, 34, 24, 34, 25], [30, 20, 15, 61, 16]]
    ];

    // Format information encodes the level as 00=M 01=L 10=H 11=Q. Everything
    // downstream wants the 0=L 1=M 2=Q 3=H ordering of the block table above.
    var LEVEL_FROM_FORMAT = [1, 0, 3, 2];
    var LEVEL_NAMES = ['L', 'M', 'Q', 'H'];

    /** The eight data masks, by pattern number. row = i, column = j. */
    var MASKS = [
        function (i, j) { return ((i + j) % 2) === 0; },
        function (i) { return (i % 2) === 0; },
        function (i, j) { return (j % 3) === 0; },
        function (i, j) { return ((i + j) % 3) === 0; },
        function (i, j) { return ((Math.floor(i / 2) + Math.floor(j / 3)) % 2) === 0; },
        function (i, j) { return (((i * j) % 2) + ((i * j) % 3)) === 0; },
        function (i, j) { return ((((i * j) % 2) + ((i * j) % 3)) % 2) === 0; },
        function (i, j) { return ((((i + j) % 2) + ((i * j) % 3)) % 2) === 0; }
    ];

    var ALPHANUMERIC = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    /* ======================================================================
       QR: GF(256) and Reed-Solomon

       The field is GF(2^8) with the QR primitive polynomial 0x11D, and the
       generator roots start at a^0.
       ====================================================================== */

    var GF_EXP = new Uint8Array(256);
    var GF_LOG = new Uint8Array(256);

    (function () {
        var x = 1, i;
        for (i = 0; i < 255; i++) {
            GF_EXP[i] = x;
            GF_LOG[x] = i;
            x <<= 1;
            if (x & 0x100) x ^= 0x11D;
        }
        GF_EXP[255] = GF_EXP[0];
    })();

    function gfMul(a, b) {
        if (a === 0 || b === 0) return 0;
        return GF_EXP[(GF_LOG[a] + GF_LOG[b]) % 255];
    }

    function gfDiv(a, b) {
        if (a === 0) return 0;
        return GF_EXP[(GF_LOG[a] + 255 - GF_LOG[b]) % 255];
    }

    function gfInverse(a) {
        return GF_EXP[(255 - GF_LOG[a]) % 255];
    }

    function gfPow(a, power) {
        if (a === 0) return 0;
        var p = (GF_LOG[a] * power) % 255;
        if (p < 0) p += 255;
        return GF_EXP[p];
    }

    // Polynomials are plain arrays, highest degree first.

    function polyAdd(p, q) {
        var length = Math.max(p.length, q.length);
        var r = new Array(length);
        var i;

        for (i = 0; i < length; i++) r[i] = 0;
        for (i = 0; i < p.length; i++) r[i + length - p.length] ^= p[i];
        for (i = 0; i < q.length; i++) r[i + length - q.length] ^= q[i];

        return r;
    }

    function polyMul(p, q) {
        var r = new Array(p.length + q.length - 1);
        var i, j;

        for (i = 0; i < r.length; i++) r[i] = 0;
        for (i = 0; i < p.length; i++) {
            for (j = 0; j < q.length; j++) {
                r[i + j] ^= gfMul(p[i], q[j]);
            }
        }

        return r;
    }

    function polyScale(p, x) {
        var r = new Array(p.length), i;
        for (i = 0; i < p.length; i++) r[i] = gfMul(p[i], x);
        return r;
    }

    function polyEval(p, x) {
        var y = p[0], i;
        for (i = 1; i < p.length; i++) y = gfMul(y, x) ^ p[i];
        return y;
    }

    /** Syndromes, with a leading zero so the Forney step lines up. */
    function syndromes(message, ecCount) {
        var s = [0], i;
        for (i = 0; i < ecCount; i++) s.push(polyEval(message, gfPow(2, i)));
        return s;
    }

    function allZero(values) {
        var i;
        for (i = 0; i < values.length; i++) {
            if (values[i] !== 0) return false;
        }
        return true;
    }

    /** Berlekamp-Massey. Returns the error locator polynomial, or null. */
    function errorLocator(synd, ecCount) {
        var loc = [1];
        var old = [1];
        var shift = synd.length - ecCount;
        var i, j, delta, scaled;

        for (i = 0; i < ecCount; i++) {
            delta = synd[i + shift];
            for (j = 1; j < loc.length; j++) {
                delta ^= gfMul(loc[loc.length - 1 - j], synd[i + shift - j]);
            }

            old = old.concat([0]);

            if (delta !== 0) {
                if (old.length > loc.length) {
                    scaled = polyScale(old, delta);
                    old = polyScale(loc, gfInverse(delta));
                    loc = scaled;
                }
                loc = polyAdd(loc, polyScale(old, delta));
            }
        }

        while (loc.length > 0 && loc[0] === 0) loc.shift();

        var errors = loc.length - 1;
        if (errors * 2 > ecCount) return null;

        return loc;
    }

    /** Chien search: the positions of the errors, or null. */
    function errorPositions(locator, length) {
        var errors = locator.length - 1;
        var positions = [];
        var i;

        for (i = 0; i < length; i++) {
            if (polyEval(locator, gfPow(2, i)) === 0) {
                positions.push(length - 1 - i);
            }
        }

        return positions.length === errors ? positions : null;
    }

    /**
     * Forney: work out the magnitude of each error and undo it.
     *
     * Writing X for the locator value at an error position, the syndromes are
     * S_j = sum of Y·X^j, so W(z) = S(z)·L(z) truncated to the degree of L
     * collapses at z = 1/X to Y times the product of (1 - Xj/X) over the other
     * positions. Because the generator roots start at a^0 there is no leading
     * power of X to put back afterwards.
     *
     * Polynomials here are lowest degree first, unlike the rest of the file;
     * convolution does not care, but the evaluation does.
     */
    function correct(message, synd, positions) {
        var length = message.length;
        var i, j, k;

        // The locator value at each error position.
        var x = [];
        for (i = 0; i < positions.length; i++) {
            x.push(gfPow(2, length - 1 - positions[i]));
        }

        // L(z) = product of (1 + X·z). Subtraction is addition here.
        var lambda = [1];
        for (i = 0; i < x.length; i++) lambda = polyMul(lambda, [1, x[i]]);

        // W(z) = S(z)·L(z), keeping only the terms below L's degree.
        var s = synd.slice(1);
        var omega = [];
        var sum;

        for (k = 0; k < x.length; k++) {
            sum = 0;
            for (j = 0; j <= k && j < s.length; j++) {
                if ((k - j) < lambda.length) sum ^= gfMul(s[j], lambda[k - j]);
            }
            omega.push(sum);
        }

        var magnitudes = new Array(length);
        for (i = 0; i < length; i++) magnitudes[i] = 0;

        var inverse, numerator, denominator, power;

        for (i = 0; i < x.length; i++) {
            inverse = gfInverse(x[i]);

            numerator = 0;
            power = 1;
            for (k = 0; k < omega.length; k++) {
                numerator ^= gfMul(omega[k], power);
                power = gfMul(power, inverse);
            }

            denominator = 1;
            for (j = 0; j < x.length; j++) {
                if (j !== i) denominator = gfMul(denominator, 1 ^ gfMul(x[j], inverse));
            }

            if (denominator === 0) return null;

            magnitudes[positions[i]] = gfDiv(numerator, denominator);
        }

        var out = new Array(length);
        for (i = 0; i < length; i++) out[i] = message[i] ^ magnitudes[i];

        return out;
    }

    /**
     * Correct one block. Returns the corrected codewords, or null if the
     * damage is beyond the error-correction level's capacity — better a
     * missed read than a confidently wrong one.
     */
    function reedSolomon(codewords, ecCount) {
        var synd = syndromes(codewords, ecCount);
        if (allZero(synd)) return codewords;

        var locator = errorLocator(synd, ecCount);
        if (locator === null) return null;

        var positions = errorPositions(locator.slice().reverse(), codewords.length);
        if (positions === null) return null;

        var corrected = correct(codewords, synd, positions);
        if (corrected === null) return null;

        // The final word: if the syndromes are not all zero now, the
        // "correction" was a guess that happened to satisfy the algebra.
        return allZero(syndromes(corrected, ecCount)) ? corrected : null;
    }

    /* ======================================================================
       QR: binarising the frame

       A single global threshold works for a printed label under even light and
       falls apart under a window or a bench lamp, which is where these get
       used. This is the block-based approach: an 8x8 grid of local thresholds,
       each smoothed against its neighbours so a block of plain white paper
       does not turn into noise.
       ====================================================================== */

    var BLOCK = 8;

    function binarise(luma, width, height, bits) {
        var subWidth = (width + BLOCK - 1) >> 3;
        var subHeight = (height + BLOCK - 1) >> 3;
        var i;

        if (width < 40 || height < 40) {
            // Too small to divide up; a global threshold is all there is.
            var min = 255, max = 0;
            for (i = 0; i < luma.length; i++) {
                if (luma[i] < min) min = luma[i];
                if (luma[i] > max) max = luma[i];
            }
            var level = (min + max) >> 1;
            for (i = 0; i < luma.length; i++) bits[i] = luma[i] < level ? 1 : 0;
            return;
        }

        var points = new Int32Array(subWidth * subHeight);
        var x, y, xx, yy, offset, pixel, sum, blockMin, blockMax, average, neighbours;

        for (y = 0; y < subHeight; y++) {
            for (x = 0; x < subWidth; x++) {
                var top = Math.min(y * BLOCK, height - BLOCK);
                var left = Math.min(x * BLOCK, width - BLOCK);

                sum = 0;
                blockMin = 255;
                blockMax = 0;

                for (yy = 0; yy < BLOCK; yy++) {
                    offset = ((top + yy) * width) + left;
                    for (xx = 0; xx < BLOCK; xx++) {
                        pixel = luma[offset + xx];
                        sum += pixel;
                        if (pixel < blockMin) blockMin = pixel;
                        if (pixel > blockMax) blockMax = pixel;
                    }
                }

                average = sum >> 6;

                if (blockMax - blockMin <= 24) {
                    // Almost no contrast: the block is all paper or all ink.
                    // Assume paper unless the neighbours say otherwise.
                    average = blockMin >> 1;

                    if (y > 0 && x > 0) {
                        neighbours = (points[((y - 1) * subWidth) + x]
                            + (2 * points[(y * subWidth) + x - 1])
                            + points[((y - 1) * subWidth) + x - 1]) >> 2;

                        if (blockMin < neighbours) average = neighbours;
                    }
                }

                points[(y * subWidth) + x] = average;
            }
        }

        var z, row, level2;

        for (y = 0; y < subHeight; y++) {
            var topY = Math.min(y * BLOCK, height - BLOCK);
            var clampY = Math.max(2, Math.min(y, subHeight - 3));

            for (x = 0; x < subWidth; x++) {
                var leftX = Math.min(x * BLOCK, width - BLOCK);
                var clampX = Math.max(2, Math.min(x, subWidth - 3));

                sum = 0;
                for (z = -2; z <= 2; z++) {
                    row = (clampY + z) * subWidth;
                    sum += points[row + clampX - 2] + points[row + clampX - 1] + points[row + clampX]
                        + points[row + clampX + 1] + points[row + clampX + 2];
                }

                level2 = sum / 25;

                for (yy = 0; yy < BLOCK; yy++) {
                    offset = ((topY + yy) * width) + leftX;
                    for (xx = 0; xx < BLOCK; xx++) {
                        bits[offset + xx] = luma[offset + xx] <= level2 ? 1 : 0;
                    }
                }
            }
        }
    }

    /* ======================================================================
       QR: finding the three finder patterns

       The finder is the 1:1:3:1:1 dark/light/dark/light/dark bullseye in three
       corners. Scan rows for that ratio, then confirm each hit by measuring
       the same ratio vertically and horizontally through its centre.
       ====================================================================== */

    function foundCross(state) {
        var total = state[0] + state[1] + state[2] + state[3] + state[4];
        if (total < 7) return false;

        var module = total / 7;
        var variance = module / 2;

        return Math.abs(module - state[0]) < variance
            && Math.abs(module - state[1]) < variance
            && Math.abs((3 * module) - state[2]) < (3 * variance)
            && Math.abs(module - state[3]) < variance
            && Math.abs(module - state[4]) < variance;
    }

    function centreFromEnd(state, end) {
        return end - state[4] - state[3] - (state[2] / 2);
    }

    function crossCheckVertical(bits, width, height, startY, centreX, maxCount, originalTotal) {
        var state = [0, 0, 0, 0, 0];
        var y = startY;
        var offset = centreX;

        while (y >= 0 && bits[(y * width) + offset]) { state[2]++; y--; }
        if (y < 0) return -1;
        while (y >= 0 && !bits[(y * width) + offset] && state[1] <= maxCount) { state[1]++; y--; }
        if (y < 0 || state[1] > maxCount) return -1;
        while (y >= 0 && bits[(y * width) + offset] && state[0] <= maxCount) { state[0]++; y--; }
        if (state[0] > maxCount) return -1;

        y = startY + 1;
        while (y < height && bits[(y * width) + offset]) { state[2]++; y++; }
        if (y === height) return -1;
        while (y < height && !bits[(y * width) + offset] && state[3] < maxCount) { state[3]++; y++; }
        if (y === height || state[3] >= maxCount) return -1;
        while (y < height && bits[(y * width) + offset] && state[4] < maxCount) { state[4]++; y++; }
        if (state[4] >= maxCount) return -1;

        var total = state[0] + state[1] + state[2] + state[3] + state[4];
        if ((5 * Math.abs(total - originalTotal)) >= (2 * originalTotal)) return -1;

        return foundCross(state) ? centreFromEnd(state, y) : -1;
    }

    function crossCheckHorizontal(bits, width, centreY, startX, maxCount, originalTotal) {
        var state = [0, 0, 0, 0, 0];
        var x = startX;
        var row = centreY * width;

        while (x >= 0 && bits[row + x]) { state[2]++; x--; }
        if (x < 0) return -1;
        while (x >= 0 && !bits[row + x] && state[1] <= maxCount) { state[1]++; x--; }
        if (x < 0 || state[1] > maxCount) return -1;
        while (x >= 0 && bits[row + x] && state[0] <= maxCount) { state[0]++; x--; }
        if (state[0] > maxCount) return -1;

        x = startX + 1;
        while (x < width && bits[row + x]) { state[2]++; x++; }
        if (x === width) return -1;
        while (x < width && !bits[row + x] && state[3] < maxCount) { state[3]++; x++; }
        if (x === width || state[3] >= maxCount) return -1;
        while (x < width && bits[row + x] && state[4] < maxCount) { state[4]++; x++; }
        if (state[4] >= maxCount) return -1;

        var total = state[0] + state[1] + state[2] + state[3] + state[4];
        if ((5 * Math.abs(total - originalTotal)) >= originalTotal) return -1;

        return foundCross(state) ? centreFromEnd(state, x) : -1;
    }

    function recordCentre(centres, x, y, moduleSize) {
        var i, centre, diff;

        for (i = 0; i < centres.length; i++) {
            centre = centres[i];
            diff = Math.abs(centre.moduleSize - moduleSize);

            if (Math.abs(centre.x - x) <= moduleSize
                && Math.abs(centre.y - y) <= moduleSize
                && (diff <= 1 || diff <= centre.moduleSize)) {
                // Average the sightings; each row through the pattern refines
                // the estimate a little.
                centre.x = ((centre.x * centre.count) + x) / (centre.count + 1);
                centre.y = ((centre.y * centre.count) + y) / (centre.count + 1);
                centre.moduleSize = ((centre.moduleSize * centre.count) + moduleSize) / (centre.count + 1);
                centre.count++;
                return;
            }
        }

        centres.push({ x: x, y: y, moduleSize: moduleSize, count: 1 });
    }

    function findFinderPatterns(bits, width, height) {
        var centres = [];
        var state = [0, 0, 0, 0, 0];
        var skip = Math.max(2, Math.floor(height / 240));
        var y, x, row, currentState, total, module, centreX, centreY, horizontal;

        for (y = skip - 1; y < height; y += skip) {
            state[0] = state[1] = state[2] = state[3] = state[4] = 0;
            currentState = 0;
            row = y * width;

            for (x = 0; x < width; x++) {
                if (bits[row + x]) {
                    if ((currentState & 1) === 1) currentState++;
                    state[currentState]++;
                } else if ((currentState & 1) === 0) {
                    if (currentState === 4) {
                        if (foundCross(state)) {
                            total = state[0] + state[1] + state[2] + state[3] + state[4];
                            module = total / 7;
                            centreX = Math.floor(centreFromEnd(state, x));
                            centreY = crossCheckVertical(bits, width, height, y, centreX,
                                state[2], total);

                            if (centreY >= 0) {
                                horizontal = crossCheckHorizontal(bits, width, Math.floor(centreY),
                                    centreX, state[2], total);

                                if (horizontal >= 0) {
                                    recordCentre(centres, horizontal, centreY, module);
                                }
                            }
                        }

                        // Either way, slide the window on by two runs: the last
                        // two dark/light runs may start the next candidate.
                        state[0] = state[2];
                        state[1] = state[3];
                        state[2] = state[4];
                        state[3] = 1;
                        state[4] = 0;
                        currentState = 3;
                    } else {
                        currentState++;
                        state[currentState]++;
                    }
                } else {
                    state[currentState]++;
                }
            }

            if (currentState === 4 && foundCross(state)) {
                total = state[0] + state[1] + state[2] + state[3] + state[4];
                module = total / 7;
                centreX = Math.floor(centreFromEnd(state, width));
                centreY = crossCheckVertical(bits, width, height, y, centreX, state[2], total);

                if (centreY >= 0) {
                    horizontal = crossCheckHorizontal(bits, width, Math.floor(centreY), centreX,
                        state[2], total);
                    if (horizontal >= 0) recordCentre(centres, horizontal, centreY, module);
                }
            }
        }

        return centres;
    }

    function distance(a, b) {
        var dx = a.x - b.x, dy = a.y - b.y;
        return Math.sqrt((dx * dx) + (dy * dy));
    }

    /**
     * Pick the three centres that actually look like the corners of a QR code:
     * two legs of near-equal length meeting at a near-right angle, with the
     * three module sizes in agreement.
     */
    function bestThree(centres) {
        if (centres.length < 3) return null;

        // Strongest sightings first, and cap the search — a frame with a dozen
        // candidates is a frame full of noise, not a dozen QR codes.
        var sorted = centres.slice().sort(function (a, b) { return b.count - a.count; });
        if (sorted.length > 8) sorted = sorted.slice(0, 8);

        var best = null, bestScore = Infinity;
        var a, b, c, i, j, k;

        for (i = 0; i < sorted.length - 2; i++) {
            for (j = i + 1; j < sorted.length - 1; j++) {
                for (k = j + 1; k < sorted.length; k++) {
                    a = sorted[i];
                    b = sorted[j];
                    c = sorted[k];

                    var score = tripleScore(a, b, c);
                    if (score !== null && score < bestScore) {
                        bestScore = score;
                        best = [a, b, c];
                    }
                }
            }
        }

        return best;
    }

    function tripleScore(a, b, c) {
        var sizes = [a.moduleSize, b.moduleSize, c.moduleSize];
        var mean = (sizes[0] + sizes[1] + sizes[2]) / 3;
        var i, spread = 0;

        if (mean <= 0) return null;

        for (i = 0; i < 3; i++) spread += Math.abs(sizes[i] - mean) / mean;
        if (spread > 1.2) return null;

        // The longest side is the diagonal; the other two are the legs.
        var ab = distance(a, b), bc = distance(b, c), ca = distance(c, a);
        var longest = Math.max(ab, Math.max(bc, ca));
        var legs = (ab + bc + ca) - longest;
        var expected = longest / Math.SQRT2;

        if (longest <= 0) return null;

        // In a square, the two legs are equal and the diagonal is sqrt(2) times
        // one of them. Score how far this triangle is from that.
        var legError = Math.abs((legs / 2) - expected) / expected;
        if (legError > 0.35) return null;

        return spread + legError;
    }

    /**
     * Name the corners. The two furthest apart are the diagonal; the odd one
     * out is the top left. The cross product then says which of the other two
     * is the bottom left, so the grid comes out the right way round.
     */
    function orderCorners(patterns) {
        var a = patterns[0], b = patterns[1], c = patterns[2];
        var ab = distance(a, b), bc = distance(b, c), ca = distance(c, a);
        var topLeft, one, two;

        if (bc >= ab && bc >= ca) {
            topLeft = a; one = b; two = c;
        } else if (ca >= ab && ca >= bc) {
            topLeft = b; one = a; two = c;
        } else {
            topLeft = c; one = a; two = b;
        }

        // The sign of the cross product about the corner says which side of the
        // diagonal each of the other two falls on, and so which is which.
        if (((two.x - topLeft.x) * (one.y - topLeft.y))
            - ((two.y - topLeft.y) * (one.x - topLeft.x)) < 0) {
            var swap = one;
            one = two;
            two = swap;
        }

        return { bottomLeft: one, topLeft: topLeft, topRight: two };
    }

    /* ======================================================================
       QR: the alignment pattern and the sampling grid
       ====================================================================== */

    /**
     * Hunt for the alignment pattern near an estimated position.
     *
     * It is a five-module bullseye, so a cut through its centre reads
     * dark:light:dark:light:dark, one module each. The middle three of those —
     * light, dark, light, in a 1:1:1 ratio — put the centre in the middle of a
     * run rather than on a boundary, which is what the sampling grid needs.
     */
    function findAlignment(bits, width, height, estimateX, estimateY, moduleSize) {
        var radius = Math.ceil(moduleSize * 4);
        var left = Math.max(0, Math.floor(estimateX - radius));
        var right = Math.min(width - 1, Math.ceil(estimateX + radius));
        var top = Math.max(0, Math.floor(estimateY - radius));
        var bottom = Math.min(height - 1, Math.ceil(estimateY + radius));

        if (right - left < moduleSize * 3 || bottom - top < moduleSize * 3) return null;

        var maxCount = Math.ceil(moduleSize * 2);
        var best = null;
        var bestDistance = Infinity;
        var y, x, row, state, currentState;

        function consider(endX, atY) {
            if (!alignmentRatio(state, moduleSize)) return;

            var centreX = endX - state[2] - (state[1] / 2);
            var centreY = crossCheckAlignmentVertical(bits, width, height,
                Math.round(centreX), atY, maxCount, moduleSize);

            if (centreY === null) return;

            var dx = centreX - estimateX;
            var dy = centreY - estimateY;
            var d = (dx * dx) + (dy * dy);

            if (d < bestDistance) {
                bestDistance = d;
                best = { x: centreX, y: centreY };
            }
        }

        for (y = top; y <= bottom; y++) {
            row = y * width;
            state = [0, 0, 0];
            currentState = 0;
            x = left;

            // Burn off any leading light run: it may have started outside the
            // region, so its width means nothing.
            while (x <= right && !bits[row + x]) x++;

            // State 1 is the dark run; states 0 and 2 are the light rings
            // either side of it.
            while (x <= right) {
                if (bits[row + x]) {
                    if (currentState === 1) {
                        state[1]++;
                    } else if (currentState === 2) {
                        consider(x, y);

                        // Re-use the trailing light run as the leading one of
                        // the next candidate rather than starting again.
                        state[0] = state[2];
                        state[1] = 1;
                        state[2] = 0;
                        currentState = 1;
                    } else {
                        currentState = 1;
                        state[1]++;
                    }
                } else {
                    if (currentState === 1) currentState = 2;
                    state[currentState]++;
                }

                x++;
            }
        }

        return best;
    }

    function alignmentRatio(state, moduleSize) {
        var variance = moduleSize / 2;
        return state[0] > 0 && state[1] > 0 && state[2] > 0
            && Math.abs(moduleSize - state[0]) < variance
            && Math.abs(moduleSize - state[1]) < variance
            && Math.abs(moduleSize - state[2]) < variance;
    }

    function crossCheckAlignmentVertical(bits, width, height, x, startY, maxCount, moduleSize) {
        if (x < 0 || x >= width) return null;

        var state = [0, 0, 0];
        var y = startY;

        while (y >= 0 && bits[(y * width) + x] && state[1] <= maxCount) { state[1]++; y--; }
        if (y < 0 || state[1] > maxCount) return null;
        while (y >= 0 && !bits[(y * width) + x] && state[0] <= maxCount) { state[0]++; y--; }
        if (state[0] > maxCount) return null;

        y = startY + 1;
        while (y < height && bits[(y * width) + x] && state[1] <= maxCount) { state[1]++; y++; }
        if (y === height || state[1] > maxCount) return null;
        while (y < height && !bits[(y * width) + x] && state[2] <= maxCount) { state[2]++; y++; }
        if (state[2] > maxCount) return null;

        if (!alignmentRatio(state, moduleSize)) return null;

        return y - state[2] - (state[1] / 2);
    }

    /**
     * The projective transform taking grid coordinates to image coordinates.
     * Three finder centres plus one alignment pattern is enough to pin down a
     * QR photographed at an angle, which is how anyone actually holds a phone.
     *
     * Transforms are nine numbers in row-major order throughout this section.
     */
    function squareToQuadrilateral(x0, y0, x1, y1, x2, y2, x3, y3) {
        var dx3 = x0 - x1 + x2 - x3;
        var dy3 = y0 - y1 + y2 - y3;

        if (dx3 === 0 && dy3 === 0) {
            return [x1 - x0, x2 - x1, x0, y1 - y0, y2 - y1, y0, 0, 0, 1];
        }

        var dx1 = x1 - x2, dx2 = x3 - x2;
        var dy1 = y1 - y2, dy2 = y3 - y2;
        var denominator = (dx1 * dy2) - (dx2 * dy1);

        if (denominator === 0) return null;

        var a13 = ((dx3 * dy2) - (dx2 * dy3)) / denominator;
        var a23 = ((dx1 * dy3) - (dx3 * dy1)) / denominator;

        return [
            x1 - x0 + (a13 * x1), x3 - x0 + (a23 * x3), x0,
            y1 - y0 + (a13 * y1), y3 - y0 + (a23 * y3), y0,
            a13, a23, 1
        ];
    }

    function transformAdjoint(m) {
        return [
            (m[4] * m[8]) - (m[5] * m[7]), (m[2] * m[7]) - (m[1] * m[8]), (m[1] * m[5]) - (m[2] * m[4]),
            (m[5] * m[6]) - (m[3] * m[8]), (m[0] * m[8]) - (m[2] * m[6]), (m[2] * m[3]) - (m[0] * m[5]),
            (m[3] * m[7]) - (m[4] * m[6]), (m[1] * m[6]) - (m[0] * m[7]), (m[0] * m[4]) - (m[1] * m[3])
        ];
    }

    /** Ordinary row-major 3x3 multiplication. */
    function transformTimes(a, b) {
        var out = new Array(9);
        var row, column, sum, k;

        for (row = 0; row < 3; row++) {
            for (column = 0; column < 3; column++) {
                sum = 0;
                for (k = 0; k < 3; k++) sum += a[(row * 3) + k] * b[(k * 3) + column];
                out[(row * 3) + column] = sum;
            }
        }

        return out;
    }

    function quadrilateralToQuadrilateral(
        x0, y0, x1, y1, x2, y2, x3, y3,
        x0p, y0p, x1p, y1p, x2p, y2p, x3p, y3p
    ) {
        var source = squareToQuadrilateral(x0, y0, x1, y1, x2, y2, x3, y3);
        var target = squareToQuadrilateral(x0p, y0p, x1p, y1p, x2p, y2p, x3p, y3p);

        if (source === null || target === null) return null;

        return transformTimes(target, transformAdjoint(source));
    }

    function transformPoint(m, x, y) {
        var denominator = (m[6] * x) + (m[7] * y) + m[8];
        if (denominator === 0) return null;

        return {
            x: ((m[0] * x) + (m[1] * y) + m[2]) / denominator,
            y: ((m[3] * x) + (m[4] * y) + m[5]) / denominator
        };
    }

    /** Read the grid: one sample at the centre of each module. */
    function sampleGrid(bits, width, height, transform, dimension) {
        var matrix = new Uint8Array(dimension * dimension);
        var y, x, point, px, py;

        for (y = 0; y < dimension; y++) {
            for (x = 0; x < dimension; x++) {
                point = transformPoint(transform, x + 0.5, y + 0.5);
                if (point === null) return null;

                px = Math.floor(point.x);
                py = Math.floor(point.y);

                if (px < 0 || py < 0 || px >= width || py >= height) return null;

                matrix[(y * dimension) + x] = bits[(py * width) + px];
            }
        }

        return matrix;
    }

    /* ======================================================================
       QR: reading the matrix
       ====================================================================== */

    /** BCH-correct the 15-bit format information. */
    function decodeFormat(raw) {
        var best = -1, bestDistance = 32, i, distanceTo;

        for (i = 0; i < 32; i++) {
            var candidate = formatBits(i);
            distanceTo = bitCount(raw ^ candidate);

            if (distanceTo < bestDistance) {
                bestDistance = distanceTo;
                best = i;
            } else if (distanceTo === bestDistance) {
                best = -1; // ambiguous
            }
        }

        // BCH(15,5) corrects three bit errors; beyond that a "nearest" match
        // is a coin toss.
        if (best < 0 || bestDistance > 3) return null;

        return {
            level: LEVEL_FROM_FORMAT[(best >> 3) & 0x03],
            mask: best & 0x07
        };
    }

    function formatBits(data) {
        var value = data << 10;
        var i;

        for (i = 4; i >= 0; i--) {
            if (value & (1 << (i + 10))) value ^= 0x537 << i;
        }

        return ((data << 10) | value) ^ 0x5412;
    }

    function versionBits(version) {
        var value = version << 12;
        var i;

        for (i = 5; i >= 0; i--) {
            if (value & (1 << (i + 12))) value ^= 0x1F25 << i;
        }

        return (version << 12) | value;
    }

    function decodeVersion(raw) {
        var best = -1, bestDistance = 32, version, d;

        for (version = 7; version <= 40; version++) {
            d = bitCount(raw ^ versionBits(version));

            if (d < bestDistance) {
                bestDistance = d;
                best = version;
            } else if (d === bestDistance) {
                best = -1;
            }
        }

        return (best > 0 && bestDistance <= 3) ? best : null;
    }

    function bitCount(value) {
        var count = 0;
        while (value) {
            value &= value - 1;
            count++;
        }
        return count;
    }

    /**
     * Mark every module that carries something other than data: the finders and
     * their separators, the timing patterns, the alignment patterns, the format
     * information and — from version 7 — the version information.
     */
    function functionPattern(version, dimension) {
        var map = new Uint8Array(dimension * dimension);
        var i, j;

        function fill(x, y, w, h) {
            var a, b;
            for (b = y; b < y + h; b++) {
                for (a = x; a < x + w; a++) {
                    if (a >= 0 && b >= 0 && a < dimension && b < dimension) {
                        map[(b * dimension) + a] = 1;
                    }
                }
            }
        }

        // Finder patterns with their separators, and the format information.
        fill(0, 0, 9, 9);
        fill(dimension - 8, 0, 8, 9);
        fill(0, dimension - 8, 9, 8);

        // Alignment patterns, except where they would sit on a finder.
        var centres = ALIGNMENT[version];
        var last = centres.length - 1;

        for (i = 0; i <= last; i++) {
            for (j = 0; j <= last; j++) {
                if ((i === 0 && j === 0) || (i === 0 && j === last) || (i === last && j === 0)) {
                    continue;
                }
                fill(centres[j] - 2, centres[i] - 2, 5, 5);
            }
        }

        // Timing patterns.
        fill(6, 9, 1, dimension - 17);
        fill(9, 6, dimension - 17, 1);

        if (version > 6) {
            fill(dimension - 11, 0, 3, 6);
            fill(0, dimension - 11, 6, 3);
        }

        return map;
    }

    function readFormatInformation(matrix, dimension) {
        var i, bits = 0;

        // Copy one: along the top-left finder, skipping the timing column.
        for (i = 0; i <= 5; i++) bits = (bits << 1) | matrix[(8 * dimension) + i];
        bits = (bits << 1) | matrix[(8 * dimension) + 7];
        bits = (bits << 1) | matrix[(8 * dimension) + 8];
        bits = (bits << 1) | matrix[(7 * dimension) + 8];
        for (i = 5; i >= 0; i--) bits = (bits << 1) | matrix[(i * dimension) + 8];

        var format = decodeFormat(bits);
        if (format !== null) return format;

        // Copy two: bottom-left then top-right, read as one 15-bit run.
        bits = 0;
        for (i = dimension - 1; i >= dimension - 7; i--) bits = (bits << 1) | matrix[(i * dimension) + 8];
        for (i = dimension - 8; i < dimension; i++) bits = (bits << 1) | matrix[(8 * dimension) + i];

        return decodeFormat(bits);
    }

    function readVersionInformation(matrix, dimension) {
        var bits = 0, i, j;

        // Bottom left, three modules wide and six tall.
        for (i = 5; i >= 0; i--) {
            for (j = dimension - 9; j >= dimension - 11; j--) {
                bits = (bits << 1) | matrix[(j * dimension) + i];
            }
        }

        return decodeVersion(bits);
    }

    /**
     * Walk the matrix in the standard order — two columns at a time, right to
     * left, alternating up and down — undoing the data mask as we go.
     */
    function readCodewords(matrix, dimension, version, mask) {
        var map = functionPattern(version, dimension);
        var maskFn = MASKS[mask];
        var result = [];
        var currentByte = 0, bitsRead = 0;
        var readingUp = true;
        var j, count, i, col, x, bit;

        for (j = dimension - 1; j > 0; j -= 2) {
            if (j === 6) j--; // the vertical timing pattern carries no data

            for (count = 0; count < dimension; count++) {
                i = readingUp ? dimension - 1 - count : count;

                for (col = 0; col < 2; col++) {
                    x = j - col;

                    if (map[(i * dimension) + x]) continue;

                    bit = matrix[(i * dimension) + x];
                    if (maskFn(i, x)) bit ^= 1;

                    currentByte = (currentByte << 1) | bit;
                    bitsRead++;

                    if (bitsRead === 8) {
                        result.push(currentByte);
                        currentByte = 0;
                        bitsRead = 0;
                    }
                }
            }

            readingUp = !readingUp;
        }

        return result;
    }

    /**
     * Undo the interleaving and correct each block.
     *
     * Codewords are spread across the symbol so that a thumbprint takes a few
     * from every block rather than destroying one outright; this puts them
     * back in order.
     */
    function deinterleave(codewords, version, level) {
        var spec = EC_BLOCKS[version][level];
        var ecCount = spec[0];
        var blocks = [];
        var i, j;

        for (i = 0; i < spec[1]; i++) blocks.push({ data: spec[2], codewords: [] });
        for (i = 0; i < spec[3]; i++) blocks.push({ data: spec[4], codewords: [] });

        var shortest = blocks[0].data;
        var longerFrom = spec[3] > 0 ? spec[1] : blocks.length;
        var total = 0;

        for (i = 0; i < blocks.length; i++) total += blocks[i].data + ecCount;
        if (codewords.length < total) return null;

        var offset = 0;

        for (i = 0; i < shortest; i++) {
            for (j = 0; j < blocks.length; j++) {
                blocks[j].codewords.push(codewords[offset++]);
            }
        }

        for (j = longerFrom; j < blocks.length; j++) {
            blocks[j].codewords.push(codewords[offset++]);
        }

        var maxEc = ecCount;
        for (i = 0; i < maxEc; i++) {
            for (j = 0; j < blocks.length; j++) {
                blocks[j].codewords.push(codewords[offset++]);
            }
        }

        var out = [];

        for (i = 0; i < blocks.length; i++) {
            var corrected = reedSolomon(blocks[i].codewords, ecCount);
            if (corrected === null) return null;

            for (j = 0; j < blocks[i].data; j++) out.push(corrected[j]);
        }

        return out;
    }

    /* ======================================================================
       QR: the bit stream
       ====================================================================== */

    function BitReader(bytes) {
        this.bytes = bytes;
        this.position = 0;
    }

    BitReader.prototype.available = function () {
        return (this.bytes.length * 8) - this.position;
    };

    BitReader.prototype.read = function (count) {
        if (count > this.available()) return -1;

        var value = 0, i, byteIndex, bitIndex;

        for (i = 0; i < count; i++) {
            byteIndex = this.position >> 3;
            bitIndex = 7 - (this.position & 7);
            value = (value << 1) | ((this.bytes[byteIndex] >> bitIndex) & 1);
            this.position++;
        }

        return value;
    };

    function countBits(mode, version) {
        var sizes;

        if (version <= 9) sizes = { 1: 10, 2: 9, 4: 8, 8: 8 };
        else if (version <= 26) sizes = { 1: 12, 2: 11, 4: 16, 8: 10 };
        else sizes = { 1: 14, 2: 13, 4: 16, 8: 12 };

        return sizes[mode];
    }

    function decodeBytes(bytes, encoding) {
        var i, text;

        if (global.TextDecoder) {
            try {
                return new global.TextDecoder(encoding, { fatal: encoding === 'utf-8' })
                    .decode(new Uint8Array(bytes));
            } catch (e) {
                if (encoding === 'utf-8') {
                    // Not valid UTF-8, so it is almost certainly Latin-1.
                    return decodeBytes(bytes, 'iso-8859-1');
                }
            }
        }

        text = '';
        for (i = 0; i < bytes.length; i++) text += String.fromCharCode(bytes[i]);
        return text;
    }

    function parseBitStream(bytes, version) {
        var reader = new BitReader(bytes);
        var text = '';
        var encoding = 'utf-8';
        var mode, count, i, value, bytesOut, first, second, kanji;

        while (reader.available() >= 4) {
            mode = reader.read(4);

            if (mode === 0) break;                       // terminator

            if (mode === 7) {                            // ECI
                value = reader.read(8);
                if (value < 0) return null;

                if ((value & 0x80) !== 0) {
                    // Two- and three-byte ECI designators.
                    if ((value & 0xC0) === 0x80) {
                        if (reader.read(8) < 0) return null;
                        value = 0;
                    } else {
                        if (reader.read(16) < 0) return null;
                        value = 0;
                    }
                }

                if (value === 1 || value === 3) encoding = 'iso-8859-1';
                else if (value === 26) encoding = 'utf-8';
                continue;
            }

            if (mode === 5 || mode === 9) continue;      // FNC1, nothing to emit
            if (mode === 3) {                            // structured append
                // Half a message is worse than none; refuse it.
                return null;
            }

            count = reader.read(countBits(mode, version));
            if (count < 0) return null;

            if (mode === 1) {                            // numeric
                while (count >= 3) {
                    value = reader.read(10);
                    if (value < 0 || value >= 1000) return null;
                    text += pad(value, 3);
                    count -= 3;
                }
                if (count === 2) {
                    value = reader.read(7);
                    if (value < 0 || value >= 100) return null;
                    text += pad(value, 2);
                } else if (count === 1) {
                    value = reader.read(4);
                    if (value < 0 || value >= 10) return null;
                    text += String(value);
                }
            } else if (mode === 2) {                     // alphanumeric
                while (count >= 2) {
                    value = reader.read(11);
                    if (value < 0) return null;
                    text += ALPHANUMERIC.charAt(Math.floor(value / 45))
                        + ALPHANUMERIC.charAt(value % 45);
                    count -= 2;
                }
                if (count === 1) {
                    value = reader.read(6);
                    if (value < 0 || value >= 45) return null;
                    text += ALPHANUMERIC.charAt(value);
                }
            } else if (mode === 4) {                     // byte
                bytesOut = [];
                for (i = 0; i < count; i++) {
                    value = reader.read(8);
                    if (value < 0) return null;
                    bytesOut.push(value);
                }
                text += decodeBytes(bytesOut, encoding);
            } else if (mode === 8) {                     // kanji, Shift-JIS
                bytesOut = [];
                for (i = 0; i < count; i++) {
                    value = reader.read(13);
                    if (value < 0) return null;

                    kanji = ((Math.floor(value / 0xC0)) << 8) | (value % 0xC0);
                    kanji += kanji < 0x1F00 ? 0x8140 : 0xC140;
                    first = (kanji >> 8) & 0xFF;
                    second = kanji & 0xFF;
                    bytesOut.push(first, second);
                }
                text += decodeBytes(bytesOut, 'shift_jis');
            } else {
                return null;                             // a mode we do not know
            }
        }

        return text === '' ? null : text;
    }

    function pad(value, length) {
        var text = String(value);
        while (text.length < length) text = '0' + text;
        return text;
    }

    /* ======================================================================
       QR: putting it together
       ====================================================================== */

    function decodeMatrix(matrix, dimension) {
        var version = (dimension - 17) / 4;

        if (version < 1 || version > 40 || version !== Math.floor(version)) return null;

        var format = readFormatInformation(matrix, dimension);
        if (format === null) return null;

        if (version >= 7) {
            var stated = readVersionInformation(matrix, dimension);
            // The geometry and the version blocks should agree. If they do not,
            // the grid is wrong and anything decoded from it would be fiction.
            if (stated !== null && stated !== version) return null;
        }

        var codewords = readCodewords(matrix, dimension, version, format.mask);
        var data = deinterleave(codewords, version, format.level);
        if (data === null) return null;

        var text = parseBitStream(data, version);
        if (text === null) return null;

        return { text: text, format: 'qr_code', version: version, level: LEVEL_NAMES[format.level] };
    }

    function detectAndDecode(bits, width, height) {
        var centres = findFinderPatterns(bits, width, height);
        var chosen = bestThree(centres);
        if (chosen === null) return null;

        var corners = orderCorners(chosen);
        var topLeft = corners.topLeft;
        var topRight = corners.topRight;
        var bottomLeft = corners.bottomLeft;

        var moduleSize = (topLeft.moduleSize + topRight.moduleSize + bottomLeft.moduleSize) / 3;
        if (moduleSize < 1) return null;

        var across = distance(topLeft, topRight) / moduleSize;
        var down = distance(topLeft, bottomLeft) / moduleSize;
        var dimension = Math.round((across + down) / 2) + 7;

        // A QR is always 4n+1 modules across.
        switch (dimension & 0x03) {
            case 0: dimension++; break;
            case 2: dimension--; break;
            case 3: return null;
            default: break;
        }

        if (dimension < 21 || dimension > 177) return null;

        var version = (dimension - 17) / 4;
        var transform, matrix, result;

        if (version >= 2) {
            // The bottom-right alignment pattern gives the fourth point, and
            // with it a proper perspective correction.
            var modulesBetween = dimension - 7;
            var correction = 1 - (3 / modulesBetween);
            var bottomRightX = topRight.x + bottomLeft.x - topLeft.x;
            var bottomRightY = topRight.y + bottomLeft.y - topLeft.y;
            var estimateX = topLeft.x + (correction * (bottomRightX - topLeft.x));
            var estimateY = topLeft.y + (correction * (bottomRightY - topLeft.y));

            var alignment = findAlignment(bits, width, height, estimateX, estimateY, moduleSize);

            if (alignment !== null) {
                transform = quadrilateralToQuadrilateral(
                    3.5, 3.5, dimension - 3.5, 3.5, dimension - 6.5, dimension - 6.5, 3.5, dimension - 3.5,
                    topLeft.x, topLeft.y, topRight.x, topRight.y,
                    alignment.x, alignment.y, bottomLeft.x, bottomLeft.y
                );

                if (transform !== null) {
                    matrix = sampleGrid(bits, width, height, transform, dimension);
                    if (matrix !== null) {
                        result = decodeMatrix(matrix, dimension);
                        if (result !== null) return result;
                    }
                }
            }
        }

        // No alignment pattern, or it did not decode: fall back to assuming the
        // symbol is flat, which is right for a label scanned square on.
        transform = quadrilateralToQuadrilateral(
            3.5, 3.5, dimension - 3.5, 3.5, dimension - 3.5, dimension - 3.5, 3.5, dimension - 3.5,
            topLeft.x, topLeft.y, topRight.x, topRight.y,
            topRight.x + bottomLeft.x - topLeft.x, topRight.y + bottomLeft.y - topLeft.y,
            bottomLeft.x, bottomLeft.y
        );

        if (transform === null) return null;

        matrix = sampleGrid(bits, width, height, transform, dimension);
        if (matrix === null) return null;

        return decodeMatrix(matrix, dimension);
    }

    /* ======================================================================
       Frame handling
       ====================================================================== */

    var buffers = { width: 0, height: 0, luma: null, bits: null, qrLuma: null, qrBits: null };

    function lumaBuffer(width, height) {
        if (buffers.width !== width || buffers.height !== height || buffers.luma === null) {
            buffers.width = width;
            buffers.height = height;
            buffers.luma = new Uint8Array(width * height);
            buffers.bits = null;
            buffers.qrLuma = null;
            buffers.qrBits = null;
        }
        return buffers.luma;
    }

    function toLuma(pixels, width, height) {
        var luma = lumaBuffer(width, height);
        var count = width * height;
        var i, offset;

        for (i = 0; i < count; i++) {
            offset = i * 4;
            // Rec. 601 luma: good enough, and cheap.
            luma[i] = ((pixels[offset] * 299) + (pixels[offset + 1] * 587)
                + (pixels[offset + 2] * 114)) / 1000;
        }

        return luma;
    }

    /**
     * QR detection is the expensive half, so it runs on a halved copy of the
     * frame when the frame is large. A QR small enough to be lost at half
     * resolution was never going to decode at full resolution either.
     */
    function scanLumaQr(luma, width, height) {
        var scale = width > 800 ? 2 : 1;
        var qrWidth = Math.floor(width / scale);
        var qrHeight = Math.floor(height / scale);
        var source = luma;
        var x, y;

        if (qrWidth < 21 || qrHeight < 21) return null;

        if (scale > 1) {
            if (buffers.qrLuma === null || buffers.qrLuma.length !== qrWidth * qrHeight) {
                buffers.qrLuma = new Uint8Array(qrWidth * qrHeight);
            }
            source = buffers.qrLuma;

            for (y = 0; y < qrHeight; y++) {
                for (x = 0; x < qrWidth; x++) {
                    source[(y * qrWidth) + x] = luma[(y * scale * width) + (x * scale)];
                }
            }
        }

        if (buffers.qrBits === null || buffers.qrBits.length !== qrWidth * qrHeight) {
            buffers.qrBits = new Uint8Array(qrWidth * qrHeight);
        }

        binarise(source, qrWidth, qrHeight, buffers.qrBits);

        return detectAndDecode(buffers.qrBits, qrWidth, qrHeight);
    }

    /**
     * Look for any supported code in a canvas.
     * Returns { text, format }, or null.
     */
    function scanCanvas(context, width, height) {
        if (!width || !height) return null;

        var pixels;

        try {
            pixels = context.getImageData(0, 0, width, height).data;
        } catch (e) {
            // A tainted canvas, or a frame that is not ready yet.
            return null;
        }

        var luma = toLuma(pixels, width, height);
        var result = scanLuma1D(luma, width, height);

        if (result !== null) return result;

        try {
            return scanLumaQr(luma, width, height);
        } catch (e) {
            // A malformed symbol must not take the scanner down with it.
            return null;
        }
    }

    /** Back-compatible wrapper: the decoded string, or null. */
    function decodeCanvas(context, width, height) {
        var result = scanCanvas(context, width, height);
        return result === null ? null : result.text;
    }

    global.AssetBarcode = {
        formats: ['code_128', 'code_39', 'qr_code'],

        decodeCanvas: decodeCanvas,
        scanCanvas: scanCanvas,
        decodeLine: decodeLine,
        scanLine: scanLine,

        // Exposed for tests/barcode-decode.html.
        internals: {
            binarise: binarise,
            detectAndDecode: detectAndDecode,
            decodeMatrix: decodeMatrix,
            findFinderPatterns: findFinderPatterns,
            bestThree: bestThree,
            orderCorners: orderCorners,
            findAlignment: findAlignment,
            sampleGrid: sampleGrid,
            readFormatInformation: readFormatInformation,
            readCodewords: readCodewords,
            deinterleave: deinterleave,
            parseBitStream: parseBitStream,
            reedSolomon: reedSolomon,
            gf: { mul: gfMul, div: gfDiv, pow: gfPow, inverse: gfInverse, exp: GF_EXP, log: GF_LOG },
            polyMul: polyMul,
            formatBits: formatBits,
            versionBits: versionBits,
            functionPattern: functionPattern,
            masks: MASKS,
            alignment: ALIGNMENT,
            ecBlocks: EC_BLOCKS,
            alphanumeric: ALPHANUMERIC,
            code39: { alphabet: C39_ALPHABET, encodings: C39_ENCODINGS, asterisk: C39_ASTERISK },
            code128: { patterns: C128_PATTERNS }
        }
    };
})(window);
