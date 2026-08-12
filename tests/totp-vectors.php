<?php

declare(strict_types=1);

/**
 * TOTP against the specifications' own numbers.
 *
 * The point of this file is that it checks against values published by somebody
 * else. An implementation tested only against itself agrees with itself; these
 * vectors come from RFC 4226 Appendix D and RFC 6238 Appendix B, and a
 * disagreement means this code is wrong rather than the test.
 *
 *   php tests/totp-vectors.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Totp;

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . ($detail === '' ? '' : ' — ' . $detail) . "\n";
}

echo "TOTP / HOTP verification\n========================\n\n";

// --- Base32 round trip, RFC 4648 §10 -------------------------------------
echo "Base32 (RFC 4648 test vectors)\n";

$base32Vectors = [
    ''       => '',
    'f'      => 'MY',
    'fo'     => 'MZXQ',
    'foo'    => 'MZXW6',
    'foob'   => 'MZXW6YQ',
    'fooba'  => 'MZXW6YTB',
    'foobar' => 'MZXW6YTBOI',
];

foreach ($base32Vectors as $plain => $expected) {
    $encoded = Totp::base32Encode($plain);
    check(
        "encode('{$plain}')",
        $encoded === $expected,
        $encoded === $expected ? '' : "got {$encoded}, expected {$expected}"
    );
}

foreach ($base32Vectors as $plain => $encoded) {
    if ($encoded === '') {
        continue;
    }

    check("decode('{$encoded}')", Totp::base32Decode($encoded) === $plain);
}

// Retyped by a human: lower case, spaces, and the padding other tools add.
check('decode tolerates spacing, case and padding', Totp::base32Decode('mzxw 6ytb oi==') === 'foobar');
check('decode refuses an out-of-alphabet character', Totp::base32Decode('MZXW6YTB01') === '');

// --- HOTP, RFC 4226 Appendix D -------------------------------------------
// The published secret is the ASCII string "12345678901234567890".
echo "\nHOTP (RFC 4226 Appendix D, secret \"12345678901234567890\")\n";

$hotpSecret = Totp::base32Encode('12345678901234567890');

$hotpVectors = [
    0 => '755224', 1 => '287082', 2 => '359152', 3 => '969429', 4 => '338314',
    5 => '254676', 6 => '287922', 7 => '162583', 8 => '399871', 9 => '520489',
];

foreach ($hotpVectors as $counter => $expected) {
    // TOTP at time = counter * period is HOTP at that counter.
    $code = Totp::codeAt($hotpSecret, $counter * 30);

    check("counter {$counter}", $code === $expected, $code === $expected ? '' : "got {$code}, expected {$expected}");
}

// --- TOTP, RFC 6238 Appendix B -------------------------------------------
// Only the SHA-1 rows: this implementation is SHA-1 only, on purpose (see the
// class docblock — every authenticator app assumes it).
echo "\nTOTP (RFC 6238 Appendix B, SHA-1 rows)\n";

$totpVectors = [
    59          => '94287082',
    1111111109  => '07081804',
    1111111111  => '14050471',
    1234567890  => '89005924',
    2000000000  => '69279037',
    20000000000 => '65353130',
];

foreach ($totpVectors as $time => $expected8) {
    // The RFC prints eight digits; this implementation emits the six every
    // authenticator app shows, which are the low six of the same number.
    $expected = substr($expected8, -6);
    $code     = Totp::codeAt($hotpSecret, $time);

    check("t = {$time}", $code === $expected, $code === $expected ? '' : "got {$code}, expected {$expected}");
}

// --- verify() -------------------------------------------------------------
echo "\nverify()\n";

$secret = Totp::generateSecret();
$now    = 1_700_000_000;

check('accepts the code for now', Totp::verify($secret, Totp::codeAt($secret, $now), $now));
check('accepts the previous step', Totp::verify($secret, Totp::codeAt($secret, $now - 30), $now));
check('accepts the next step', Totp::verify($secret, Totp::codeAt($secret, $now + 30), $now));
check('refuses two steps back', !Totp::verify($secret, Totp::codeAt($secret, $now - 60), $now));
check('refuses two steps forward', !Totp::verify($secret, Totp::codeAt($secret, $now + 60), $now));
check('refuses another secret’s code', !Totp::verify($secret, Totp::codeAt(Totp::generateSecret(), $now), $now));
check('refuses a five-digit code', !Totp::verify($secret, '12345', $now));
check('refuses a seven-digit code', !Totp::verify($secret, '1234567', $now));
check('refuses letters', !Totp::verify($secret, 'abcdef', $now));
check('refuses an empty code', !Totp::verify($secret, '', $now));
check('tolerates a space in the middle', Totp::verify($secret, substr(Totp::codeAt($secret, $now), 0, 3) . ' ' . substr(Totp::codeAt($secret, $now), 3), $now));
check('a secret is 32 base32 characters (20 bytes)', strlen(Totp::generateSecret()) === 32);
check('two secrets differ', Totp::generateSecret() !== Totp::generateSecret());

// A brute-force sanity check: over a day of steps, how often does a wrong
// secret's code happen to match? It should be about 3 in a million per try.
$collisions = 0;
$other      = Totp::generateSecret();

for ($i = 0; $i < 2000; $i++) {
    if (Totp::verify($secret, Totp::codeAt($other, $now + $i * 30), $now + $i * 30)) {
        $collisions++;
    }
}

check('a wrong secret rarely collides', $collisions <= 1, "{$collisions} in 2000");

// --- The otpauth URI ------------------------------------------------------
echo "\notpauth:// URI\n";

$uri = Totp::uri('JBSWY3DPEHPK3PXP', 'jo@example.com', 'Kitwell Workshop');

check('scheme and type', str_starts_with($uri, 'otpauth://totp/'));
check('issuer prefixes the label', str_contains($uri, 'Kitwell%20Workshop:jo%40example.com'));
check('carries the secret', str_contains($uri, 'secret=JBSWY3DPEHPK3PXP'));
check('carries the issuer parameter', str_contains($uri, 'issuer=Kitwell%20Workshop'));
check('states the algorithm', str_contains($uri, 'algorithm=SHA1'));
check('states 6 digits', str_contains($uri, 'digits=6'));
check('states a 30 second period', str_contains($uri, 'period=30'));

echo "\n----------------------------------------\n";
echo "passed: {$passed}   failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
