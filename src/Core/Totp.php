<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Time-based one-time passwords: RFC 6238, built on the HOTP of RFC 4226.
 *
 * Small enough to write and verify against the specifications' own test
 * vectors, which is exactly why it is written rather than pulled in — the
 * algorithm is forty lines and the published vectors make it provable. See
 * tests/totp-vectors.php.
 *
 * The defaults are the ones every authenticator app assumes: SHA-1, 6 digits,
 * a 30-second step. They are constants rather than parameters on purpose. A
 * TOTP secret is only useful if the app the user scans it into agrees about all
 * three, and "configurable" here would mean codes that quietly never match.
 * (SHA-1 in HMAC is not the broken use of SHA-1: collision resistance is not
 * what HOTP relies on. RFC 6238 still specifies it, and Google Authenticator
 * still only implements it.)
 */
final class Totp
{
    public const DIGITS      = 6;
    public const PERIOD      = 30;
    private const ALGORITHM  = 'sha1';

    /**
     * How many steps either side of "now" are accepted.
     *
     * One step: a code is good for its own 30 seconds plus the 30 before it.
     * That covers the phone and the server disagreeing by a few seconds, and
     * the user who starts typing at second 29 — without widening the window a
     * guess has to land in more than it has to be.
     */
    private const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh secret: 20 bytes, the length RFC 4226 recommends for SHA-1. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * The code for one moment in time.
     *
     * @param string $secret Base32, as shown to the user
     */
    public static function codeAt(string $secret, int $timestamp): string
    {
        return self::hotp(self::base32Decode($secret), intdiv($timestamp, self::PERIOD));
    }

    /**
     * Is this the code for now (or for the step either side of it)?
     *
     * Compared with hash_equals so the check does not leak, digit by digit, how
     * much of a guess was right.
     */
    public static function verify(string $secret, string $code, ?int $now = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return false;
        }

        $key = self::base32Decode($secret);

        if ($key === '') {
            return false;
        }

        $counter = intdiv($now ?? time(), self::PERIOD);
        $valid   = false;

        // Every candidate is checked even after a match: returning early would
        // make a code from the previous step measurably faster to reject than
        // one from the next.
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            if (hash_equals(self::hotp($key, $counter + $offset), $code)) {
                $valid = true;
            }
        }

        return $valid;
    }

    /**
     * HOTP: RFC 4226 §5.3.
     *
     * HMAC the counter as an 8-byte big-endian integer, then "dynamic
     * truncation" — the low nibble of the last byte picks where in the digest
     * to read four bytes from, the top bit of those is masked off so the
     * number is positive whatever the platform's integer width, and the
     * remainder modulo 10^digits is the code.
     */
    private static function hotp(string $key, int $counter): string
    {
        // pack('J') is 64-bit big-endian, which is what the specification asks
        // for; a negative counter cannot arise here because time() is positive.
        $hash = hash_hmac(self::ALGORITHM, pack('J', $counter), $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The `otpauth://` URI an authenticator app expects, per Google's
     * key-uri-format. The issuer appears twice — as a label prefix and as a
     * parameter — because different apps read different ones, and an account
     * that shows up as a bare email address in a list of thirty is not much
     * help to anybody.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'
            . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?' . http_build_query([
                'secret'    => $secret,
                'issuer'    => $issuer,
                'algorithm' => strtoupper(self::ALGORITHM),
                'digits'    => self::DIGITS,
                'period'    => self::PERIOD,
            ], '', '&', PHP_QUERY_RFC3986);
    }

    /** The secret in the groups of four that every app prints it in. */
    public static function formatSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    // -- Base32 (RFC 4648, no padding) --------------------------------------

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        // Whole groups of five bits only; the remainder is padding, and this
        // application never emits '=' because no authenticator app wants it.
        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }

            $out .= self::BASE32[bindec($chunk)];
        }

        return $out;
    }

    public static function base32Decode(string $base32): string
    {
        // Spacing and hyphens are how a secret is *displayed*, and '=' is what
        // other encoders pad with: none of that is data, so it comes out.
        //
        // Nothing else does. Stripping any unrecognised character would quietly
        // turn a mistyped secret — 0 for O, 1 for I, which is the exact confusion
        // base32 leaves those characters out to avoid — into a shorter, valid,
        // wrong key, and the only symptom would be codes that never match.
        // Refusing it says what actually happened.
        $base32 = strtoupper(preg_replace('/[\s\-=]/', '', $base32) ?? '');

        if ($base32 === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($base32) as $char) {
            $index = strpos(self::BASE32, $char);

            if ($index === false) {
                return '';
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
