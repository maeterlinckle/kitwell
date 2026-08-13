<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Setting;

/**
 * Resolves `{{setting:key}}` tokens in the documentation to the value this site
 * actually has configured, so Help never shows a default an administrator has
 * since changed.
 *
 * Only the keys in RESOLVABLE are substituted. That is an allow-list, not a
 * convenience: without it a token in a documentation file would be able to
 * print any settings row, including the SMTP password. A key that is not listed
 * is left as written.
 *
 * Each entry names where the value lives — `setting` for a settings row,
 * `config` for a configuration value from .env — and how to render it.
 */
final class HelpSettings
{
    /** @var array<string,array{source:string,format:string,suffix?:string}> */
    private const RESOLVABLE = [
        // Asset tags and labels
        'asset_tag_prefix'            => ['source' => 'setting', 'format' => 'code'],
        'asset_tag_pad'               => ['source' => 'setting', 'format' => 'number'],

        // Due windows
        'maintenance_due_days'        => ['source' => 'setting', 'format' => 'number'],
        'pat_due_days'                => ['source' => 'setting', 'format' => 'number'],
        'pat_default_interval_months' => ['source' => 'setting', 'format' => 'number'],

        // Hires
        'hire_default_days'           => ['source' => 'setting', 'format' => 'number'],
        'hire_due_soon_days'          => ['source' => 'setting', 'format' => 'number'],
        'hire_reference_prefix'       => ['source' => 'setting', 'format' => 'code'],

        // PAT guidelines
        'pat_guide_earth_base_ohm'    => ['source' => 'setting', 'format' => 'decimal'],
        'pat_guide_earth_lead_metres' => ['source' => 'setting', 'format' => 'decimal'],
        'pat_guide_earth_lead_ohm'    => ['source' => 'setting', 'format' => 'decimal'],
        'pat_guide_insulation_mohm'   => ['source' => 'setting', 'format' => 'decimal'],
        'pat_guide_leakage_class1_ma' => ['source' => 'setting', 'format' => 'decimal'],
        'pat_guide_leakage_class2_ma' => ['source' => 'setting', 'format' => 'decimal'],

        // Reminders. A window of 0 means "use the register's own window", so it
        // is rendered as that phrase rather than as the number nought.
        'reminder_pat_days'           => ['source' => 'setting', 'format' => 'window'],
        'reminder_maintenance_days'   => ['source' => 'setting', 'format' => 'window'],
        'reminder_hire_days'          => ['source' => 'setting', 'format' => 'window'],
        'reminder_repeat_days'        => ['source' => 'setting', 'format' => 'days'],
        'reminder_faulty_repeat_days' => ['source' => 'setting', 'format' => 'repeat'],
        'fault_notify_immediately'    => ['source' => 'setting', 'format' => 'onoff'],

        // Accounts and two-factor
        'invite_expiry_hours'         => ['source' => 'setting', 'format' => 'hours'],
        'password_reset_expiry_hours' => ['source' => 'setting', 'format' => 'hours'],
        'two_factor_required'         => ['source' => 'setting', 'format' => 'onoff'],
        'two_factor_max_attempts'     => ['source' => 'setting', 'format' => 'number'],
        'email_otp_minutes'           => ['source' => 'setting', 'format' => 'minutes'],
        'trusted_device_days'         => ['source' => 'setting', 'format' => 'days'],
        'trusted_device_idle_days'    => ['source' => 'setting', 'format' => 'days'],

        // API
        'api_rate_limit'              => ['source' => 'setting', 'format' => 'number'],
        'api_default_per_page'        => ['source' => 'setting', 'format' => 'number'],
        'api_max_per_page'            => ['source' => 'setting', 'format' => 'number'],

        // From .env rather than the settings table
        'upload_max_photo_mb'         => ['source' => 'config', 'format' => 'megabytes', 'key' => 'uploads.max_photo_bytes'],
        'upload_max_pdf_mb'           => ['source' => 'config', 'format' => 'megabytes', 'key' => 'uploads.max_pdf_bytes'],
    ];

    /** Replace every resolvable token in a documentation page. */
    public static function resolve(string $source): string
    {
        return preg_replace_callback(
            '/\{\{setting:([a-z0-9_.]+)\}\}/i',
            static function (array $match): string {
                $rendered = self::value(strtolower($match[1]));

                return $rendered ?? $match[0];
            },
            $source
        ) ?? $source;
    }

    /** The rendered value for one key, or null when it is not resolvable. */
    public static function value(string $name): ?string
    {
        $spec = self::RESOLVABLE[$name] ?? null;

        if ($spec === null) {
            return null;
        }

        $raw = $spec['source'] === 'config'
            ? Config::get((string) ($spec['key'] ?? $name))
            : Setting::get($name);

        if ($raw === null || $raw === '') {
            return null;
        }

        return self::format((string) $raw, $spec['format']);
    }

    /** @return array<int,string> Every key a documentation page may reference. */
    public static function keys(): array
    {
        return array_keys(self::RESOLVABLE);
    }

    private static function format(string $raw, string $format): string
    {
        $number = static fn (): string => rtrim(rtrim(number_format((float) $raw, 2, '.', ','), '0'), '.');
        $plural = static fn (string $unit): string
            => ((float) $raw === 1.0 ? '1 ' . $unit : $number() . ' ' . $unit . 's');

        return match ($format) {
            'code'      => '`' . $raw . '`',
            'decimal'   => $number(),
            'megabytes' => (string) (int) round(((float) $raw) / 1048576),
            'days'    => $plural('day'),
            'hours'   => $plural('hour'),
            'minutes' => $plural('minute'),
            'onoff'   => (string) $raw === '1' ? 'on' : 'off',
            'window'  => (int) $raw === 0
                ? "the register's own window"
                : $plural('day') . ' before due',
            'repeat'  => (int) $raw === 0 ? 'once only, with no repeat' : 'every ' . $plural('day'),
            default   => $number(),
        };
    }
}
