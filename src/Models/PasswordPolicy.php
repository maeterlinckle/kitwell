<?php

declare(strict_types=1);

namespace App\Models;

/**
 * How long a password lasts, and how strong it has to be.
 *
 * Two levels. The application sets a policy in Settings; an account may
 * override any part of it. The override is what makes the feature usable at
 * all — a site can require a change every 90 days and still have one shared
 * rig account that never expires, without having to weaken the rule for
 * everybody to accommodate the exception.
 *
 * **NULL is not zero here.** On `users`, NULL means "use the application's
 * policy" and 0 in `password_expiry_days` means "never expires". They are
 * different answers to different questions and the difference is the point: an
 * account explicitly exempted must stay exempt when the site-wide policy
 * changes, and an account that has simply never been thought about must follow
 * whatever the site decides next.
 *
 * Nothing here is hardcoded. The thresholds are settings so they can be tuned
 * without a release, which is why `rule()` builds a validator rule string at
 * runtime rather than the call sites naming numbers.
 */
final class PasswordPolicy
{
    /** The four character classes the complexity rule counts. */
    public const CLASSES = [
        'upper'  => 'an upper-case letter',
        'lower'  => 'a lower-case letter',
        'digit'  => 'a number',
        'symbol' => 'a symbol',
    ];

    /** Bounds, so a mistyped setting cannot lock everybody out or let anything through. */
    private const MIN_LENGTH_FLOOR   = 8;
    private const MIN_LENGTH_CEILING = 64;
    private const EXPIRY_CEILING     = 3650;

    /**
     * The policy in force for one account, or the application's own if no user
     * is given.
     *
     * @param array<string,mixed>|null $user
     * @return array{expiry_days:int,min_length:int,min_classes:int,expiry_overridden:bool,complexity_overridden:bool}
     */
    public static function forUser(?array $user = null): array
    {
        $expiry     = self::appExpiryDays();
        $length     = self::appMinLength();
        $classes    = self::appMinClasses();
        $overExpiry = false;
        $overRules  = false;

        if ($user !== null && array_key_exists('password_expiry_days', $user) && $user['password_expiry_days'] !== null) {
            $expiry     = self::clampExpiry((int) $user['password_expiry_days']);
            $overExpiry = true;
        }

        if ($user !== null && array_key_exists('password_min_length', $user) && $user['password_min_length'] !== null) {
            $length    = self::clampLength((int) $user['password_min_length']);
            $overRules = true;
        }

        if ($user !== null && array_key_exists('password_min_classes', $user) && $user['password_min_classes'] !== null) {
            $classes   = self::clampClasses((int) $user['password_min_classes']);
            $overRules = true;
        }

        return [
            'expiry_days'           => $expiry,
            'min_length'            => $length,
            'min_classes'           => $classes,
            'expiry_overridden'     => $overExpiry,
            'complexity_overridden' => $overRules,
        ];
    }

    public static function appExpiryDays(): int
    {
        return self::clampExpiry(Setting::int('password_expiry_days', 0));
    }

    public static function appMinLength(): int
    {
        return self::clampLength(Setting::int('password_min_length', 12));
    }

    public static function appMinClasses(): int
    {
        return self::clampClasses(Setting::int('password_min_classes', 3));
    }

    /**
     * The validator rule for setting a password under this policy.
     *
     * Built here rather than written out at the call sites, so that changing
     * the setting changes every place a password can be set — the invitation,
     * the reset, the profile change and an administrator's reset — at once.
     *
     * @param array<string,mixed>|null $user
     */
    public static function rule(?array $user = null): string
    {
        $policy = self::forUser($user);

        return 'password:' . $policy['min_length'] . ',' . $policy['min_classes'];
    }

    /** How many of the four character classes this password uses. */
    public static function classesUsed(string $password): int
    {
        $used = 0;

        // Anything that is not a letter, a digit or whitespace counts as a
        // symbol, rather than a list of punctuation somebody has to keep in
        // step with every keyboard layout.
        foreach ([
            '/\p{Lu}/u',
            '/\p{Ll}/u',
            '/\p{Nd}/u',
            '/[^\p{L}\p{Nd}\s]/u',
        ] as $pattern) {
            if (preg_match($pattern, $password) === 1) {
                $used++;
            }
        }

        return $used;
    }

    /**
     * Why this password is not acceptable, or null if it is.
     *
     * @param array<string,mixed>|null $user
     */
    public static function reject(string $password, ?array $user = null): ?string
    {
        $policy = self::forUser($user);

        if (mb_strlen($password) < $policy['min_length']) {
            return 'must be at least ' . $policy['min_length'] . ' characters';
        }

        if (self::classesUsed($password) < $policy['min_classes']) {
            return 'must include at least ' . $policy['min_classes'] . ' of: '
                . implode(', ', array_values(self::CLASSES));
        }

        return null;
    }

    /** The sentence shown under a password box, so every form says the same thing. */
    public static function describe(?array $user = null): string
    {
        $policy = self::forUser($user);

        $sentence = 'At least ' . $policy['min_length'] . ' characters, including at least '
            . $policy['min_classes'] . ' of: ' . implode(', ', array_values(self::CLASSES)) . '.';

        if ($policy['expiry_days'] > 0) {
            $sentence .= ' It will need changing again after ' . $policy['expiry_days'] . ' days.';
        }

        return $sentence;
    }

    /**
     * When this account's password stops being acceptable, or null if never.
     *
     * @param array<string,mixed> $user
     */
    public static function expiresAt(array $user): ?string
    {
        $policy = self::forUser($user);

        if ($policy['expiry_days'] <= 0) {
            return null;
        }

        // An account whose password has no recorded change date is treated as
        // having been set at creation. Falling back to "never expires" would
        // make a missing timestamp into a permanent exemption, which is the
        // wrong way round for a security control.
        $changed = (string) ($user['password_changed_at'] ?? '') ?: (string) ($user['created_at'] ?? '');

        if ($changed === '') {
            return null;
        }

        $at = strtotime($changed);

        if ($at === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $at + $policy['expiry_days'] * 86400);
    }

    /** @param array<string,mixed> $user */
    public static function hasExpired(array $user): bool
    {
        $at = self::expiresAt($user);

        return $at !== null && strtotime($at) < time();
    }

    private static function clampExpiry(int $days): int
    {
        return max(0, min(self::EXPIRY_CEILING, $days));
    }

    private static function clampLength(int $length): int
    {
        return max(self::MIN_LENGTH_FLOOR, min(self::MIN_LENGTH_CEILING, $length));
    }

    private static function clampClasses(int $classes): int
    {
        return max(1, min(count(self::CLASSES), $classes));
    }
}
