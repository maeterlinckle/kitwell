<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Auth;
use App\Core\Database;

/**
 * Editable email templates.
 *
 * The defaults below are the single source of truth for what the application
 * sends out of the box. The `email_templates` table stores *overrides* only: a
 * row exists for a key precisely when an administrator has edited it. That
 * gives three things worth having —
 *
 *   - a fresh install sends properly worded mail with an empty table;
 *   - "reset to default" is a DELETE, not a re-seed, so it cannot go stale;
 *   - the default text exists in exactly one place, so a migration and a class
 *     have nothing to drift apart about.
 *
 * Each definition also carries its own list of merge fields. That list is what
 * the edit screen documents, so the placeholders an administrator is offered
 * are always the ones the sending code actually supplies — the two cannot get
 * out of step, because they are the same array.
 */
final class EmailTemplate
{
    /**
     * Merge fields every template can use, whatever it is about.
     *
     * @var array<string,string>
     */
    public const COMMON_FIELDS = [
        'app_name'         => 'The application name, from .env',
        'organisation_name'=> 'Organisation name from Settings, if one is set',
        'app_url'          => 'The base web address of this application',
        'recipient_name'   => 'Name of the person the message is addressed to',
        'today'            => 'Today’s date',
    ];

    /**
     * The shipped templates.
     *
     * `fields` documents the merge fields specific to this message. `group`
     * decides the heading it is listed under. `sample` supplies the values the
     * preview uses, so an administrator can see their wording rendered without
     * having to find a genuinely overdue item first.
     *
     * @var array<string,array{name:string,description:string,group:string,subject:string,body:string,fields:array<string,string>,sample:array<string,string>}>
     */
    public const DEFAULTS = [
        // -- PAT ------------------------------------------------------------
        'pat_due' => [
            'name'        => 'PAT test due soon',
            'description' => 'Sent to the notify list when an asset’s PAT retest is coming up.',
            'group'       => 'Reminders',
            'subject'     => 'PAT due soon: {{count}} item(s) need testing',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>The following equipment is due for PAT testing within the next {{days}} days.</p>

<div class="items">{{items}}</div>

<p><a href="{{app_url}}/pat">Record a test</a></p>
HTML,
            'fields' => [
                'count' => 'How many items are listed',
                'days'  => 'The “due soon” window, in days',
                'items' => 'The list itself: asset tag, name, location and due date, one per line',
            ],
            'sample' => [
                'count' => '2',
                'days'  => '30',
                'items' => "AST-0007  Bench grinder — Workshop bay 2 — due 3 Sep 2026\nAST-0012  Extension lead 10 m — Store — due 11 Sep 2026",
            ],
        ],

        'pat_overdue' => [
            'name'        => 'PAT needs attention',
            'description' => 'Sent to the notify list for equipment whose retest date has passed, that failed its last test, or that has never been tested.',
            'group'       => 'Reminders',
            'subject'     => 'PAT needs attention: {{count}} item(s)',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>The following equipment needs a PAT test. <strong>Until it is tested and passes, it
should be taken out of service.</strong></p>

<div class="items">{{items}}</div>

<p><a href="{{app_url}}/pat">Record a test</a></p>
HTML,
            'fields' => [
                'count' => 'How many items are listed',
                'items' => 'The list itself: asset tag, name, location, and why it needs attention — overdue, failed, or never tested',
            ],
            'sample' => [
                'count' => '2',
                'items' => "AST-0003  Pillar drill — Workshop bay 1 — 14 days overdue\nAST-0008  Angle grinder — Store — FAILED its last test on 29 Jul 2026",
            ],
        ],

        // -- Maintenance ----------------------------------------------------
        'maintenance_due' => [
            'name'        => 'Maintenance due soon',
            'description' => 'Sent when a maintenance schedule is approaching its next due date.',
            'group'       => 'Reminders',
            'subject'     => 'Maintenance due soon: {{count}} job(s)',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>The following maintenance is due within the next {{days}} days.</p>

<div class="items">{{items}}</div>

<p><a href="{{app_url}}/maintenance">See the full list</a></p>
HTML,
            'fields' => [
                'count' => 'How many jobs are listed',
                'days'  => 'The “due soon” window, in days',
                'items' => 'The list itself: job title, asset, and due date',
            ],
            'sample' => [
                'count' => '2',
                'days'  => '30',
                'items' => "Annual service — AST-0004 Compressor — due 2 Sep 2026\nBelt inspection — AST-0009 Bandsaw — due 9 Sep 2026",
            ],
        ],

        'maintenance_overdue' => [
            'name'        => 'Maintenance overdue',
            'description' => 'Sent when a maintenance schedule’s due date has passed.',
            'group'       => 'Reminders',
            'subject'     => 'Maintenance OVERDUE: {{count}} job(s)',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>The following maintenance is <strong>now overdue</strong>.</p>

<div class="items">{{items}}</div>

<p><a href="{{app_url}}/maintenance">See the full list</a></p>
HTML,
            'fields' => [
                'count' => 'How many jobs are listed',
                'items' => 'The list itself: job title, asset, and how overdue it is',
            ],
            'sample' => [
                'count' => '1',
                'items' => 'Annual service — AST-0004 Compressor — 6 days overdue',
            ],
        ],

        // -- Hires ----------------------------------------------------------
        'hire_due' => [
            'name'        => 'Hire due back soon',
            'description' => 'Sent when hired equipment is approaching its due-back date.',
            'group'       => 'Reminders',
            'subject'     => 'Due back soon: {{count}} item(s) on hire',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>The following equipment is due back within the next {{days}} days.</p>

<div class="items">{{items}}</div>

<p><a href="{{app_url}}/hires">See all current hires</a></p>
HTML,
            'fields' => [
                'count' => 'How many hires are listed',
                'days'  => 'The “due soon” window, in days',
                'items' => 'The list itself: reference, asset, hirer and due-back date',
            ],
            'sample' => [
                'count' => '1',
                'days'  => '2',
                'items' => 'LN-2026-0004  AST-0011 Site transformer — Northfield Electrical — due back 12 Aug 2026',
            ],
        ],

        'hire_overdue' => [
            'name'        => 'Hire overdue',
            'description' => 'Sent when hired equipment has passed its due-back date.',
            'group'       => 'Reminders',
            'subject'     => 'OVERDUE: {{count}} item(s) not returned',
            'body'        => <<<'HTML'
<p>Hello {{recipient_name}},</p>

<p>The following equipment is <strong>past its due-back date</strong> and has not been booked in.</p>

<div class="items">{{items}}</div>

<p><a href="{{app_url}}/hires">See all current hires</a></p>
HTML,
            'fields' => [
                'count' => 'How many hires are listed',
                'items' => 'The list itself: reference, asset, hirer and how overdue it is',
            ],
            'sample' => [
                'count' => '1',
                'items' => 'LN-2026-0002  AST-0006 Cement mixer — J Bloggs — 3 days overdue',
            ],
        ],

        // -- Sent to the hirer ----------------------------------------------
        'hirer_hire_list' => [
            'name'        => 'Hire list for a hirer',
            'description' => 'The one-click “Email hire list” button on a hirer’s page. Everything they currently hold.',
            'group'       => 'Sent to hirers',
            'subject'     => 'Equipment currently on hire to you',
            'body'        => <<<'HTML'
<p>Hello {{hirer_name}},</p>

<p>Our records show the following equipment is currently on hire to you.</p>

<div class="items">{{items}}</div>

<p>If anything here is wrong, or you have already returned an item, please let us
know so we can correct our records.</p>

<p>— {{organisation_name}}</p>
HTML,
            'fields' => [
                'hirer_name'    => 'Name of the hirer',
                'hirer_company' => 'Company name, if the hirer has one',
                'count'         => 'How many items they hold',
                'items'         => 'The list itself: asset tag, name, checkout date and due-back date',
                'overdue_count' => 'How many of those are overdue',
            ],
            'sample' => [
                'hirer_name'    => 'Jo Bloggs',
                'hirer_company' => 'Northfield Electrical',
                'count'         => '2',
                'items'         => "AST-0011  Site transformer — out 5 Aug 2026, due back 12 Aug 2026\nAST-0006  Cement mixer — out 1 Aug 2026, due back 8 Aug 2026 (OVERDUE)",
                'overdue_count' => '1',
            ],
        ],

        'hirer_overdue_notice' => [
            'name'        => 'Overdue notice to a hirer',
            'description' => 'The “Email reminder” button on an individual hire, sent to the hirer.',
            'group'       => 'Sent to hirers',
            'subject'     => 'Please return: {{asset_name}} ({{asset_tag}})',
            'body'        => <<<'HTML'
<p>Hello {{hirer_name}},</p>

<p>Our records show that the following item is still with you:</p>

<div class="items">
  <strong>{{asset_tag}} — {{asset_name}}</strong><br>
  Hire reference: {{reference}}<br>
  Taken out: {{checked_out_date}}<br>
  Due back: {{due_date}}<br>
  <strong>{{status_line}}</strong>
</div>

<p>Please arrange to return it, or contact us if you need it for longer.</p>

<p>— {{organisation_name}}</p>
HTML,
            'fields' => [
                'hirer_name'       => 'Name of the hirer',
                'asset_tag'        => 'The asset tag',
                'asset_name'       => 'The asset name',
                'reference'        => 'The hire reference, e.g. LN-2026-0004',
                'checked_out_date' => 'When the item went out',
                'due_date'         => 'When it is due back',
                'days_overdue'     => 'Days past the due date (0 if not yet due)',
                'status_line'      => 'A ready-made sentence: “This is 3 days overdue.” or “This is due back in 2 days.”',
            ],
            'sample' => [
                'hirer_name'       => 'Jo Bloggs',
                'asset_tag'        => 'AST-0006',
                'asset_name'       => 'Cement mixer',
                'reference'        => 'LN-2026-0002',
                'checked_out_date' => '1 Aug 2026',
                'due_date'         => '8 Aug 2026',
                'days_overdue'     => '3',
                'status_line'      => 'This is 3 days overdue.',
            ],
        ],

        // -- Diagnostics ----------------------------------------------------
        'smtp_test' => [
            'name'        => 'SMTP test message',
            'description' => 'Sent by the “Send test email” button so the configuration can be proved before anything relies on it.',
            'group'       => 'Diagnostics',
            'subject'     => '{{app_name}}: test email',
            'body'        => <<<'HTML'
<p>This is a test message from {{app_name}}.</p>

<p>If you are reading it, outbound email is working: the message was accepted by
<strong>{{mail_host}}</strong> and delivered to <strong>{{recipient}}</strong>.</p>

<p>If the logo appears at the top of this message, branding is reaching email too.</p>

<p>Sent {{sent_at}} by {{sent_by}}.</p>
HTML,
            'fields' => [
                'mail_host' => 'The SMTP host the message went through',
                'recipient' => 'The address it was sent to',
                'sent_at'   => 'Date and time of the send',
                'sent_by'   => 'Who pressed the button',
            ],
            'sample' => [
                'mail_host' => 'smtp.example.com',
                'recipient' => 'someone@example.com',
                'sent_at'   => '10 Aug 2026, 14:32',
                'sent_by'   => 'Alex Admin',
            ],
        ],
    ];

    /** Every template key the application knows about. */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    public static function exists(string $key): bool
    {
        return isset(self::DEFAULTS[$key]);
    }

    /**
     * One template, defaults merged with any override.
     *
     * @return array<string,mixed>|null
     */
    public static function find(string $key): ?array
    {
        if (!self::exists($key)) {
            return null;
        }

        $default  = self::DEFAULTS[$key];
        $override = Database::selectOne(
            'SELECT t.*, u.name AS updated_by_name
               FROM email_templates t
               LEFT JOIN users u ON u.id = t.updated_by
              WHERE t.template_key = ?',
            [$key]
        );

        return [
            'key'             => $key,
            'name'            => $default['name'],
            'description'     => $default['description'],
            'group'           => $default['group'],
            'fields'          => array_merge($default['fields'], self::COMMON_FIELDS),
            'sample'          => $default['sample'],
            'subject'         => $override === null ? $default['subject'] : (string) $override['subject'],
            'body'            => $override === null ? $default['body'] : (string) $override['body'],
            // The shipped bodies are HTML. An override says for itself, because
            // an administrator who rewrites one in plain text should get plain
            // text sent.
            'is_html'         => $override === null
                ? ($default['is_html'] ?? true)
                : (int) $override['is_html'] === 1,
            'is_active'       => $override === null || (int) $override['is_active'] === 1,
            'is_customised'   => $override !== null,
            'updated_at'      => $override['updated_at'] ?? null,
            'updated_by_name' => $override['updated_by_name'] ?? null,
            'default_subject' => $default['subject'],
            'default_body'    => $default['body'],
        ];
    }

    /**
     * Every template, in listing order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        $templates = [];

        foreach (self::keys() as $key) {
            $template = self::find($key);

            if ($template !== null) {
                $templates[] = $template;
            }
        }

        return $templates;
    }

    /** Save an override. */
    public static function save(string $key, string $subject, string $body, bool $isHtml, bool $isActive): void
    {
        Database::run(
            'INSERT INTO email_templates (template_key, subject, body, is_html, is_active, updated_by)
                  VALUES (:k, :s, :b, :h, :a, :u)
             ON DUPLICATE KEY UPDATE
                  subject = VALUES(subject),
                  body = VALUES(body),
                  is_html = VALUES(is_html),
                  is_active = VALUES(is_active),
                  updated_by = VALUES(updated_by)',
            [
                'k' => $key,
                's' => $subject,
                'b' => $body,
                'h' => $isHtml ? 1 : 0,
                'a' => $isActive ? 1 : 0,
                'u' => Auth::id(),
            ]
        );
    }

    /** Drop the override, so the shipped default applies again. */
    public static function reset(string $key): void
    {
        Database::run('DELETE FROM email_templates WHERE template_key = ?', [$key]);
    }

    /** How many templates have been edited — shown on the templates list. */
    public static function customisedCount(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM email_templates');
    }
}
