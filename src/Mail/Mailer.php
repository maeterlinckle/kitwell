<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Config;
use App\Core\Crypto;
use App\Models\Setting;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * Outbound email.
 *
 * SMTP is spoken by PHPMailer rather than by anything written here. Talking to
 * a mail server correctly means STARTTLS negotiation, AUTH mechanisms, line
 * ending and dot-stuffing rules, MIME assembly and header encoding — all of it
 * long solved, none of it worth re-deriving in an asset register.
 *
 * PHPMailer is the one runtime package this application has, and it arrives
 * through `composer install`. Everything else still runs from a plain file
 * copy, so this class checks the class actually exists and reports a clear,
 * actionable problem when it does not. A missing package must look like
 * "run composer install", not like a white screen.
 *
 * Nothing here throws at the caller. A send either happens or is logged as a
 * failure and returns false: a reminder that cannot go out must never take down
 * the page or the cron run that triggered it.
 */
final class Mailer
{
    public const ENCRYPTIONS = [
        'tls'  => 'STARTTLS (usually port 587)',
        'ssl'  => 'SSL/TLS (usually port 465)',
        'none' => 'None — unencrypted (only on a trusted local relay)',
    ];

    /**
     * The effective configuration.
     *
     * The password is returned decrypted, and MAIL_PASSWORD in .env wins over
     * whatever is in the database.
     *
     * @return array<string,mixed>
     */
    public static function settings(): array
    {
        return [
            'enabled'      => Setting::bool('mail_enabled', false),
            'host'         => trim((string) Setting::get('mail_host', '')),
            'port'         => max(1, min(65535, Setting::int('mail_port', 587))),
            'encryption'   => self::encryption(),
            'username'     => trim((string) Setting::get('mail_username', '')),
            'password'     => self::password(),
            'from_address' => trim((string) Setting::get('mail_from_address', '')),
            'from_name'    => trim((string) Setting::get('mail_from_name', (string) Config::get('app.name', 'Asset Register'))),
            'reply_to'     => trim((string) Setting::get('mail_reply_to', '')),
            'timeout'      => max(5, min(120, Setting::int('mail_timeout', 15))),
        ];
    }

    private static function encryption(): string
    {
        $value = (string) Setting::get('mail_encryption', 'tls');

        return isset(self::ENCRYPTIONS[$value]) ? $value : 'tls';
    }

    /** Where is the password coming from — 'env', 'database' or 'unset'? */
    public static function passwordSource(): string
    {
        if ((string) Config::get('mail.password', '') !== '') {
            return 'env';
        }

        return (string) Setting::get('mail_password', '') !== '' ? 'database' : 'unset';
    }

    private static function password(): string
    {
        $fromEnv = (string) Config::get('mail.password', '');

        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $stored = (string) Setting::get('mail_password', '');

        if ($stored === '') {
            return '';
        }

        // Anything in this setting should be ciphertext. If it will not
        // decrypt — wrong APP_KEY, or a value pasted in by hand — treat it as
        // unset rather than sending the raw string as a password.
        return Crypto::decrypt($stored) ?? '';
    }

    /**
     * Store the SMTP password, encrypted.
     *
     * Returns false when it could not be encrypted, so the caller can refuse to
     * save rather than fall back to storing it in the clear.
     */
    public static function storePassword(string $plaintext): bool
    {
        if ($plaintext === '') {
            Setting::put('mail_password', '');

            return true;
        }

        $ciphertext = Crypto::encrypt($plaintext);

        if ($ciphertext === null) {
            return false;
        }

        Setting::put('mail_password', $ciphertext);

        return true;
    }

    /** Is the PHPMailer package installed? */
    public static function libraryInstalled(): bool
    {
        return class_exists(PHPMailer::class);
    }

    /**
     * Has an administrator switched this one message off?
     *
     * sendTemplate() returns false in that case *without* writing a log row —
     * a deliberately silenced message is not a failure, and logging one on
     * every cron run would be noise. Callers that report a result to a person
     * need to be able to tell the two apart, or they end up saying "see the
     * log" about a log entry that was never written.
     */
    public static function isTemplateActive(string $templateKey): bool
    {
        $template = EmailTemplate::find($templateKey);

        return $template !== null && $template['is_active'] === true;
    }

    /**
     * Everything standing between this installation and a working send.
     *
     * Used by the Settings page, by `console.php doctor`, and by send() itself,
     * so the same list explains a greyed-out button and a failed send.
     *
     * @return array<int,string>
     */
    public static function problems(): array
    {
        $problems = [];
        $settings = self::settings();

        if (!self::libraryInstalled()) {
            // Names manage.sh rather than composer itself: the machine may not
            // have Composer at all, in which case "run composer install" is
            // advice that fails with "command not found". manage.sh fetches
            // Composer first when it needs to.
            $problems[] = 'The PHPMailer package is not installed. Run “sudo '
                . self::appRootPath() . '/manage.sh composer-install” on the server.';
        }

        if ($settings['host'] === '') {
            $problems[] = 'No SMTP host is set.';
        }

        if ($settings['from_address'] === '') {
            $problems[] = 'No “from” address is set.';
        } elseif (filter_var($settings['from_address'], FILTER_VALIDATE_EMAIL) === false) {
            $problems[] = 'The “from” address is not a valid email address.';
        }

        if ($settings['username'] !== '' && $settings['password'] === '') {
            $problems[] = self::passwordSource() === 'database'
                ? 'The stored SMTP password could not be decrypted. APP_KEY in .env has probably changed — re-enter the password.'
                : 'An SMTP username is set but no password.';
        }

        if (!Crypto::isAvailable()) {
            $problems[] = 'The PHP openssl extension is not loaded, so the SMTP password cannot be stored securely.';
        } elseif (!Crypto::hasKey() && self::passwordSource() !== 'env') {
            $problems[] = 'APP_KEY is not set in .env, so the SMTP password cannot be stored securely. Generate one with “php bin/console.php key:generate”.';
        }

        return $problems;
    }

    /**
     * The application root with forward slashes and no trailing separator.
     *
     * These paths are only ever shown inside a shell command for a Linux
     * server, so a Windows development box producing `C:\foo/manage.sh` is
     * cosmetic — but a command someone might copy should not look broken.
     */
    private static function appRootPath(): string
    {
        return rtrim(str_replace('\\', '/', (string) Config::get('app.root', '.')), '/');
    }

    /** Configured, installed and switched on. */
    public static function isReady(): bool
    {
        return self::settings()['enabled'] === true && self::problems() === [];
    }

    /**
     * Send one of the application's templates.
     *
     * @param array<string,string> $fields  Merge-field values
     * @param array<string,mixed>  $context entity_type, entity_id, trigger
     */
    public static function sendTemplate(
        string $templateKey,
        string $toAddress,
        ?string $toName,
        array $fields,
        array $context = []
    ): bool {
        $template = EmailTemplate::find($templateKey);

        if ($template === null) {
            return false;
        }

        $context['template_key'] = $templateKey;

        if ($template['is_active'] !== true) {
            // Switched off deliberately by an administrator. Not an error, and
            // not worth a log row on every cron run.
            return false;
        }

        $merged  = array_merge(self::commonFields($toName), $fields);
        $subject = Merge::render((string) $template['subject'], $merged);
        $body    = Merge::render((string) $template['body'], $merged, (bool) $template['is_html']);

        return self::send($toAddress, $toName, $subject, $body, (bool) $template['is_html'], $context);
    }

    /**
     * The merge fields available to every template.
     *
     * @return array<string,string>
     */
    public static function commonFields(?string $recipientName = null): array
    {
        return [
            'app_name'          => (string) Config::get('app.name', 'Asset Register'),
            'organisation_name' => (string) (Setting::get('organisation_name', '') ?: Config::get('app.name', 'Asset Register')),
            'app_url'           => rtrim((string) Config::get('app.url', ''), '/'),
            'recipient_name'    => $recipientName ?? 'there',
            'today'             => date('j M Y'),
        ];
    }

    /**
     * Send a message. Always logs; never throws.
     *
     * @param array<string,mixed> $context
     */
    public static function send(
        string $toAddress,
        ?string $toName,
        string $subject,
        string $body,
        bool $isHtml = false,
        array $context = []
    ): bool {
        $toAddress = trim($toAddress);

        if ($toAddress === '' || filter_var($toAddress, FILTER_VALIDATE_EMAIL) === false) {
            EmailLog::record($toAddress === '' ? '(none)' : $toAddress, $toName, $subject, 'failed', 'Not a valid email address.', $context);

            return false;
        }

        $settings = self::settings();

        if ($settings['enabled'] !== true) {
            EmailLog::record($toAddress, $toName, $subject, 'failed', 'Email sending is switched off in Settings → Email.', $context);

            return false;
        }

        $problems = self::problems();

        if ($problems !== []) {
            EmailLog::record($toAddress, $toName, $subject, 'failed', implode(' ', $problems), $context);

            return false;
        }

        try {
            $mail = self::transport($settings);

            $mail->addAddress($toAddress, (string) ($toName ?? ''));
            $mail->Subject = $subject;

            if ($isHtml) {
                $mail->isHTML(true);
                $mail->Body    = $body;
                $mail->AltBody = Merge::htmlToText($body);
            } else {
                $mail->isHTML(false);
                $mail->Body = $body;
            }

            $mail->send();

            EmailLog::record($toAddress, $toName, $subject, 'sent', null, $context);

            return true;
        } catch (Throwable $e) {
            // PHPMailer's own exception message is the useful one ("SMTP
            // connect() failed", "Could not authenticate"), so it goes into the
            // log verbatim for whoever has to diagnose it.
            EmailLog::record($toAddress, $toName, $subject, 'failed', $e->getMessage(), $context);

            error_log('[' . date('Y-m-d H:i:s') . '] Email to ' . $toAddress . ' failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * A configured PHPMailer instance.
     *
     * @param array<string,mixed> $settings
     */
    private static function transport(array $settings): PHPMailer
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host    = (string) $settings['host'];
        $mail->Port    = (int) $settings['port'];
        $mail->Timeout = (int) $settings['timeout'];
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        if ($settings['encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($settings['encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            // Explicitly off, including PHPMailer's opportunistic upgrade —
            // someone who chose "none" has a reason, usually a local relay with
            // a self-signed certificate that would fail verification.
            $mail->SMTPSecure  = '';
            $mail->SMTPAutoTLS = false;
        }

        if ((string) $settings['username'] !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = (string) $settings['username'];
            $mail->Password = (string) $settings['password'];
        }

        $mail->setFrom((string) $settings['from_address'], (string) $settings['from_name']);

        if ((string) $settings['reply_to'] !== '') {
            $mail->addReplyTo((string) $settings['reply_to']);
        }

        return $mail;
    }
}
