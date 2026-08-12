<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Request;

/**
 * "Do not ask again on this computer" — with an end to it.
 *
 * The cookie holds 32 random bytes and the database holds only their SHA-256,
 * so a stolen backup is not a set of working bypasses. A row is accepted only
 * when four things still hold, and any one of them failing means the challenge
 * is put again:
 *
 *   - it has not passed `expires_at` (the outer limit)
 *   - it has been used within the idle window (a laptop last seen six weeks ago
 *     is not the laptop that was trusted)
 *   - the browser still matches (a hash of the user agent)
 *   - the network still matches approximately (see `sameNetwork()`)
 *
 * And it is thrown away outright when the password changes or an administrator
 * deactivates the account, because both of those mean "whoever has that cookie
 * should not be getting in".
 */
final class TrustedDevice
{
    public const COOKIE = 'kw_device';

    public static function days(): int
    {
        return max(1, min(365, Setting::int('trusted_device_days', 30)));
    }

    public static function idleDays(): int
    {
        return max(1, min(self::days(), Setting::int('trusted_device_idle_days', 14)));
    }

    /**
     * Remember this browser, and return the cookie value to set.
     *
     * The label is for the person reading their own list later, not for
     * matching — matching is done on the user-agent hash.
     */
    public static function remember(int $userId): string
    {
        $token = bin2hex(random_bytes(32));

        Database::insert('trusted_devices', [
            'user_id'         => $userId,
            'token_hash'      => hash('sha256', $token),
            'label'           => self::describeBrowser(Request::userAgent()),
            'ip_address'      => Request::ip(),
            'user_agent_hash' => hash('sha256', (string) Request::userAgent()),
            'last_seen_at'    => date('Y-m-d H:i:s'),
            'expires_at'      => date('Y-m-d H:i:s', time() + self::days() * 86400),
        ]);

        return $token;
    }

    /**
     * Is this browser still trusted for this user?
     *
     * Touches `last_seen_at` on a hit, which is what makes the idle window a
     * rolling one rather than a second fixed expiry.
     */
    public static function isTrusted(int $userId, string $token): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return false;
        }

        $row = Database::selectOne(
            'SELECT * FROM trusted_devices WHERE token_hash = ? AND user_id = ?',
            [hash('sha256', $token), $userId]
        );

        if ($row === null) {
            return false;
        }

        $now = time();

        if (strtotime((string) $row['expires_at']) < $now) {
            self::forget((int) $row['id']);

            return false;
        }

        if (strtotime((string) $row['last_seen_at']) < $now - self::idleDays() * 86400) {
            self::forget((int) $row['id']);

            return false;
        }

        if ((string) $row['user_agent_hash'] !== hash('sha256', (string) Request::userAgent())) {
            // A different browser holding this cookie is either a copied cookie
            // or a machine that has changed underneath it. Neither is a reason
            // to keep trusting the row.
            self::forget((int) $row['id']);

            return false;
        }

        if (!self::sameNetwork((string) $row['ip_address'], Request::ip())) {
            self::forget((int) $row['id']);

            return false;
        }

        Database::run('UPDATE trusted_devices SET last_seen_at = NOW() WHERE id = ?', [(int) $row['id']]);

        return true;
    }

    /**
     * Is this close enough to the same place?
     *
     * A /24 for IPv4 and a /64 for IPv6: the same office, the same home
     * broadband, the same phone on the same cell. It is a heuristic and it is
     * meant to be — the alternative, an exact match, re-challenges anybody whose
     * ISP rotates their address overnight, and people who are asked for a code
     * every single morning start looking for ways to turn the feature off.
     *
     * Both are compared as *strings of octets/hextets* rather than parsed, so a
     * malformed or missing address simply fails to match.
     */
    public static function sameNetwork(string $before, string $now): bool
    {
        if ($before === '' || $now === '') {
            return false;
        }

        if ($before === $now) {
            return true;
        }

        if (str_contains($before, ':') !== str_contains($now, ':')) {
            // One IPv4, one IPv6 — often the same machine on a network that
            // offers both, but not something to assume.
            return false;
        }

        if (str_contains($before, ':')) {
            $a = array_slice(explode(':', self::expandIpv6($before)), 0, 4);
            $b = array_slice(explode(':', self::expandIpv6($now)), 0, 4);
        } else {
            $a = array_slice(explode('.', $before), 0, 3);
            $b = array_slice(explode('.', $now), 0, 3);
        }

        return $a === $b;
    }

    /** `::1` and friends written out in full, so the halves line up. */
    private static function expandIpv6(string $address): string
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return $address;
        }

        return implode(':', str_split(bin2hex($packed), 4));
    }

    /** @return array<int,array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        return Database::select(
            'SELECT * FROM trusted_devices
              WHERE user_id = ? AND expires_at > NOW()
              ORDER BY last_seen_at DESC',
            [$userId]
        );
    }

    public static function forget(int $id): void
    {
        Database::run('DELETE FROM trusted_devices WHERE id = ?', [$id]);
    }

    /**
     * Drop every trusted device for one user.
     *
     * Called on a password change, on deactivation, and when 2FA is switched
     * off — the three moments where a cookie issued earlier should stop being
     * worth anything.
     */
    public static function forgetAll(int $userId): void
    {
        Database::run('DELETE FROM trusted_devices WHERE user_id = ?', [$userId]);
    }

    /** "Chrome on Windows" — enough to recognise, not a fingerprint. */
    public static function describeBrowser(?string $userAgent): string
    {
        $agent = (string) $userAgent;

        $browser = match (true) {
            str_contains($agent, 'Edg/')                              => 'Edge',
            str_contains($agent, 'OPR/')                              => 'Opera',
            str_contains($agent, 'Firefox/')                          => 'Firefox',
            str_contains($agent, 'Chrome/')                           => 'Chrome',
            str_contains($agent, 'Safari/')                           => 'Safari',
            default                                                   => 'Browser',
        };

        $platform = match (true) {
            str_contains($agent, 'Windows')                => 'Windows',
            str_contains($agent, 'iPhone')                 => 'iPhone',
            str_contains($agent, 'iPad')                   => 'iPad',
            str_contains($agent, 'Android')                => 'Android',
            str_contains($agent, 'Mac OS X')               => 'Mac',
            str_contains($agent, 'Linux')                  => 'Linux',
            default                                        => 'an unknown device',
        };

        return $browser . ' on ' . $platform;
    }
}
