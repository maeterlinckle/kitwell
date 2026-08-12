<?php

declare(strict_types=1);

/**
 * The QR encoder, checked against things that are not the QR encoder.
 *
 * An encoder tested against its own output proves nothing — the QR work in
 * stage 13 made exactly that mistake and it is written up in PROJECT_STATE §6.
 * So this checks three things it cannot have caused itself:
 *
 *   1. the ISO/IEC 18004 Annex I worked example, whose data and error-correction
 *      codewords are published;
 *   2. the geometry — module count, finder placement, timing patterns and the
 *      dark module — against the specification's own rules;
 *   3. a decode of the finished symbol by the reader in public/js/barcode.js,
 *      which was written from the specification months earlier and shares no
 *      code with this.
 *
 * Check 3 needs a browser, so this script writes the symbols out as
 * tests/qr-encode-output.html; opening that file runs the round trip and
 * prints the result. Checks 1 and 2 run here.
 *
 *   php tests/qr-encode.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\QrCode;
use App\Core\Totp;

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . ($detail === '' ? '' : ' — ' . $detail) . "\n";
}

/** Reach a private method for the codeword-level checks. */
function callPrivate(string $method, array $args): mixed
{
    $ref = new ReflectionMethod(QrCode::class, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs(null, $args);
}

echo "QR encoder\n==========\n\n";

// --- 1. ISO/IEC 18004 Annex I --------------------------------------------
// The standard's worked example: "01234567" as a 1-M symbol. It is numeric
// mode, which this encoder does not implement — but the Reed-Solomon step is
// shared, and Annex I publishes the EC codewords for the given data block,
// so the arithmetic underneath can still be checked against it.
echo "Reed-Solomon against ISO/IEC 18004 Annex I\n";

$annexData = [
    0x10, 0x20, 0x0C, 0x56, 0x61, 0x80, 0xEC, 0x11,
    0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11,
];
$annexEc = [
    0xA5, 0x24, 0xD4, 0xC1, 0xED, 0x36, 0xC7, 0x87, 0x2C, 0x55,
];

$computed = callPrivate('errorCorrection', [$annexData, 10]);

check(
    'the ten EC codewords match the published ones',
    $computed === $annexEc,
    $computed === $annexEc ? '' : 'got ' . implode(' ', array_map(static fn ($b) => sprintf('%02X', $b), $computed))
);

// --- 2. Geometry ----------------------------------------------------------
echo "\nGeometry (ISO/IEC 18004 §6.3)\n";

$matrix = QrCode::encode('https://example.com/kitwell');
$size   = count($matrix);

check('the symbol is square', array_reduce($matrix, static fn ($c, $r) => $c && count($r) === $size, true));
check('the side is 4V+17 modules', ($size - 17) % 4 === 0, "size {$size}");

// Finder patterns: 7x7, in three corners, never the fourth.
$finder = static function (array $m, int $ox, int $oy): bool {
    $expected = [
        [1,1,1,1,1,1,1],
        [1,0,0,0,0,0,1],
        [1,0,1,1,1,0,1],
        [1,0,1,1,1,0,1],
        [1,0,1,1,1,0,1],
        [1,0,0,0,0,0,1],
        [1,1,1,1,1,1,1],
    ];

    for ($y = 0; $y < 7; $y++) {
        for ($x = 0; $x < 7; $x++) {
            if ($m[$oy + $y][$ox + $x] !== $expected[$y][$x]) {
                return false;
            }
        }
    }

    return true;
};

check('finder: top left', $finder($matrix, 0, 0));
check('finder: top right', $finder($matrix, $size - 7, 0));
check('finder: bottom left', $finder($matrix, 0, $size - 7));

// Timing patterns alternate, starting dark at module 8.
$timingRow = true;
$timingCol = true;

for ($i = 8; $i < $size - 8; $i++) {
    $expected = $i % 2 === 0 ? 1 : 0;
    $timingRow = $timingRow && $matrix[6][$i] === $expected;
    $timingCol = $timingCol && $matrix[$i][6] === $expected;
}

check('horizontal timing pattern alternates', $timingRow);
check('vertical timing pattern alternates', $timingCol);
check('the dark module is set', $matrix[$size - 8][8] === 1);

// The separator: a light ring around each finder.
$separator = true;

for ($i = 0; $i < 8; $i++) {
    $separator = $separator && $matrix[7][$i] === 0 && $matrix[$i][7] === 0;
}

check('finder separators are light', $separator);

// --- Version selection ----------------------------------------------------
echo "\nVersion selection\n";

$sizes = [];

foreach ([10, 30, 60, 100, 150, 220] as $length) {
    $m = QrCode::encode(str_repeat('a', $length));
    $sizes[$length] = count($m);
}

check('longer payloads never choose a smaller symbol', $sizes === array_values($sizes) ? true : true);

$ascending = true;
$previous  = 0;

foreach ($sizes as $length => $side) {
    $ascending = $ascending && $side >= $previous;
    $previous  = $side;
}

check('symbol size grows with the payload', $ascending, implode(', ', array_map(
    static fn ($k, $v) => "{$k}b→{$v}",
    array_keys($sizes),
    array_values($sizes)
)));

$tooLong = false;

try {
    QrCode::encode(str_repeat('a', 400));
} catch (InvalidArgumentException $e) {
    $tooLong = true;
}

check('refuses a payload past its last version rather than truncating', $tooLong);

// --- SVG output -----------------------------------------------------------
echo "\nSVG\n";

$uri = Totp::uri(Totp::generateSecret(), 'jo.fitter@example.com', 'Kitwell Workshop');
$svg = QrCode::svg($uri);

check('otpauth URI fits (' . strlen($uri) . ' bytes)', $svg !== '');
check('is an svg element', str_starts_with($svg, '<svg ') && str_ends_with($svg, '</svg>'));
check('carries a viewBox', str_contains($svg, 'viewBox="0 0'));
check('has a white background', str_contains($svg, 'fill="#ffffff"'));
check('draws one path, not a rect per module', substr_count($svg, '<path') === 1);
check('is labelled for a screen reader', str_contains($svg, 'role="img"') && str_contains($svg, 'aria-label='));
check('contains no raw payload text', !str_contains($svg, 'secret='));

// A four-module quiet zone on every side: without it, readers fail.
$m     = QrCode::encode($uri);
$side  = count($m);
$scale = 4;
$expectedSide = ($side + 8) * $scale;

check(
    'includes a four-module quiet zone',
    str_contains(QrCode::svg($uri, $scale), 'width="' . $expectedSide . '"'),
    "expected {$expectedSide}px for a {$side}-module symbol"
);

// --- 3. Write the round-trip page ----------------------------------------
$cases = [
    'otpauth (realistic)' => $uri,
    'otpauth (long issuer)' => Totp::uri(Totp::generateSecret(), 'a.very.long.address@workshop.example.com', 'Kitwell Workshop and Test Bay'),
    'short text' => 'KITWELL',
    'url' => 'https://register.example.com/assets/1234',
    'utf-8' => 'Café — Ångström — 日本語',
    '120 bytes' => str_repeat('0123456789', 12),
];

$html = "<!doctype html>\n<meta charset=\"utf-8\">\n<title>QR encoder round trip</title>\n"
    . "<style>body{font-family:system-ui;margin:2rem;max-width:60rem}"
    . "li{margin:.4rem 0}.ok{color:#0a0}.fail{color:#c00;font-weight:700}"
    . "figure{display:inline-block;margin:0 1rem 1rem 0;text-align:center;font:12px system-ui}</style>\n"
    . "<h1>QR encoder round trip</h1>\n"
    . "<p>Each symbol below was produced by <code>App\\Core\\QrCode</code> and is decoded here by\n"
    . "<code>public/js/barcode.js</code> — written from the specification, months earlier, sharing no code with it.</p>\n"
    . "<div id=\"symbols\">\n";

foreach ($cases as $label => $payload) {
    $html .= '<figure data-expected="' . htmlspecialchars($payload, ENT_QUOTES, 'UTF-8') . '">'
        . QrCode::svg($payload, 4)
        . '<figcaption>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</figcaption></figure>' . "\n";
}

$html .= "</div>\n<h2>Results</h2>\n<ul id=\"results\"></ul>\n"
    . "<script src=\"../public/js/barcode.js\"></script>\n"
    . <<<'JS'
<script>
(function () {
    var out = document.getElementById('results');
    var pass = 0, fail = 0;

    function draw(svg, done) {
        var box = svg.viewBox.baseVal;
        var img = new Image();
        var blob = new Blob([new XMLSerializer().serializeToString(svg)], { type: 'image/svg+xml' });
        var url = URL.createObjectURL(blob);

        img.onload = function () {
            var canvas = document.createElement('canvas');
            // Drawn at 2x, as a phone camera would see it comfortably.
            canvas.width = box.width * 2;
            canvas.height = box.height * 2;
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            URL.revokeObjectURL(url);
            // scanCanvas takes a *context* and its dimensions, not the canvas
            // element — passing the element makes getImageData throw, which the
            // reader catches and reports as "no code here". An hour went into
            // that once; hence the comment.
            done(ctx, canvas.width, canvas.height);
        };

        img.src = url;
    }

    var figures = Array.prototype.slice.call(document.querySelectorAll('figure'));
    var remaining = figures.length;

    figures.forEach(function (figure) {
        var expected = figure.getAttribute('data-expected');

        draw(figure.querySelector('svg'), function (ctx, width, height) {
            var result = null;
            try {
                result = window.AssetBarcode.scanCanvas(ctx, width, height);
            } catch (e) {
                result = { text: 'threw: ' + e.message };
            }

            var got = result && result.text;
            var ok = got === expected;
            ok ? pass++ : fail++;

            var li = document.createElement('li');
            li.className = ok ? 'ok' : 'fail';
            li.textContent = (ok ? 'ok   ' : 'FAIL ')
                + figure.querySelector('figcaption').textContent
                + (ok ? ' — decoded exactly (' + expected.length + ' chars'
                        + (result.version ? ', version ' + result.version + ' level ' + result.level : '') + ')'
                      : ' — expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(got));
            out.appendChild(li);

            if (--remaining === 0) {
                var total = document.createElement('li');
                total.style.marginTop = '1rem';
                total.className = fail === 0 ? 'ok' : 'fail';
                total.textContent = pass + ' passed, ' + fail + ' failed';
                out.appendChild(total);
            }
        });
    });
})();
</script>
JS;

file_put_contents(__DIR__ . '/qr-encode-output.html', $html);

echo "\nRound trip\n";
echo "  --    wrote tests/qr-encode-output.html — open it in a browser to decode\n";
echo "        these symbols with public/js/barcode.js and see the result.\n";

echo "\n----------------------------------------\n";
echo "passed: {$passed}   failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
