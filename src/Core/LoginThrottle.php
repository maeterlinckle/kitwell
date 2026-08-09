<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Failed-login throttling, keyed on both the email address and the client IP so
 * neither a single account nor a single source can be hammered.
 */
final class LoginThrottle
{
    public static function record(string $email, string $ip, bool $successful): void
    {
        Database::insert('login_attempts', [
            'email'        => mb_substr($email, 0, 190),
            'ip_address'   => $ip,
            'successful'   => $successful ? 1 : 0,
            'user_agent'   => Request::userAgent(),
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);

        // Opportunistic cleanup so the table cannot grow forever.
        if (random_int(1, 50) === 1) {
            Database::run('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 30 DAY)');
        }
    }

    public static function isLocked(string $email, string $ip): bool
    {
        $max     = (int) Config::get('security.login.max_attempts', 5);
        $decay   = (int) Config::get('security.login.decay_minutes', 15);
        $since   = date('Y-m-d H:i:s', time() - ($decay * 60));

        $byEmail = (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts
              WHERE email = ? AND successful = 0 AND attempted_at >= ?',
            [mb_substr(mb_strtolower($email), 0, 190), $since]
        );

        if ($byEmail >= $max) {
            return true;
        }

        // A wider net for the IP: several accounts probed from one address.
        $byIp = (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts
              WHERE ip_address = ? AND successful = 0 AND attempted_at >= ?',
            [$ip, $since]
        );

        return $byIp >= ($max * 3);
    }

    public static function clear(string $email, string $ip): void
    {
        Database::run(
            'DELETE FROM login_attempts WHERE successful = 0 AND (email = ? OR ip_address = ?)',
            [mb_substr(mb_strtolower($email), 0, 190), $ip]
        );
    }

    /** Remaining attempts before lockout, for a friendlier warning message. */
    public static function remaining(string $email, string $ip): int
    {
        $max   = (int) Config::get('security.login.max_attempts', 5);
        $decay = (int) Config::get('security.login.decay_minutes', 15);
        $since = date('Y-m-d H:i:s', time() - ($decay * 60));

        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts
              WHERE email = ? AND successful = 0 AND attempted_at >= ?',
            [mb_substr(mb_strtolower($email), 0, 190), $since]
        );

        return max(0, $max - $count);
    }
}
