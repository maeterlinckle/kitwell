-- ---------------------------------------------------------------------------
-- 018 Outbound email, reminder tracking and personal calendar feeds
--
-- Three new tables, two new columns on `users`, the mail and reminder settings,
-- and two new permissions.
--
-- Note what is NOT here: the text of the default email templates. Defaults live
-- in PHP (App\Mail\EmailTemplate::DEFAULTS) and `email_templates` stores only
-- the rows an administrator has actually edited. That way there is exactly one
-- copy of each default — a migration and a class cannot drift apart — and
-- "reset to default" is a DELETE rather than a re-seed. An untouched
-- installation has an empty `email_templates` table and still sends properly
-- worded mail.
-- ---------------------------------------------------------------------------

-- --- Template overrides -----------------------------------------------------
-- A row here overrides the shipped default for one template key. The template's
-- human name, purpose and list of merge fields are documentation, not content,
-- so they stay in code and are not duplicated into the database.
CREATE TABLE IF NOT EXISTS email_templates (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_key VARCHAR(60)  NOT NULL COMMENT 'Matches a key in EmailTemplate::DEFAULTS',
    subject      VARCHAR(255) NOT NULL,
    body         TEXT         NOT NULL,
    is_html      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = body is HTML, a plain-text part is generated on send',
    is_active    TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = suppress this message entirely',
    updated_by   INT UNSIGNED NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_templates_key (template_key),
    CONSTRAINT fk_email_templates_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- The send log -----------------------------------------------------------
-- Every send, automated or manual, successful or not. Like activity_log this
-- carries no foreign key on entity_id and snapshots the triggering user's name,
-- so the log outlives whatever it describes.
CREATE TABLE IF NOT EXISTS email_log (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient      VARCHAR(190)  NOT NULL,
    recipient_name VARCHAR(191)  NULL,
    subject        VARCHAR(255)  NOT NULL,
    template_key   VARCHAR(60)   NULL COMMENT 'NULL for a one-off message such as the SMTP test',
    entity_type    VARCHAR(64)   NULL COMMENT 'asset | hire | hirer | maintenance_schedule …',
    entity_id      BIGINT UNSIGNED NULL,
    status         ENUM('sent','failed') NOT NULL,
    error          VARCHAR(500)  NULL COMMENT 'SMTP or configuration failure, for diagnosis',
    trigger_source ENUM('system','user') NOT NULL DEFAULT 'system',
    user_id        INT UNSIGNED  NULL,
    user_name      VARCHAR(191)  NOT NULL DEFAULT 'System',
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_email_log_created (created_at),
    KEY idx_email_log_status (status, created_at),
    KEY idx_email_log_template (template_key, created_at),
    KEY idx_email_log_entity (entity_type, entity_id),
    CONSTRAINT fk_email_log_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Reminder de-duplication ------------------------------------------------
-- One row per (reminder, record, recipient). The cron run consults this before
-- sending so an item that has been overdue for three weeks does not generate
-- twenty-one identical emails; `reminder_repeat_days` decides how long a row
-- suppresses the next send for.
--
-- The unique key is (40 + 64 + 190) utf8mb4 characters plus an 8-byte BIGINT,
-- about 1.2 KB — well inside InnoDB's 3072-byte limit.
CREATE TABLE IF NOT EXISTS email_reminders (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reminder_key VARCHAR(40)  NOT NULL COMMENT 'pat_due | pat_overdue | maintenance_due | …',
    entity_type  VARCHAR(64)  NOT NULL,
    entity_id    BIGINT UNSIGNED NOT NULL,
    recipient    VARCHAR(190) NOT NULL,
    last_sent_at DATETIME     NOT NULL,
    send_count   INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_reminders (reminder_key, entity_type, entity_id, recipient),
    KEY idx_email_reminders_sent (last_sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Personal calendar feeds ------------------------------------------------
-- The token is the credential for a read-only .ics subscription, so it is
-- unique, long, and regenerable by the user from their own profile page.
-- NULL means the user has never opened the calendar page; no token, no feed.
ALTER TABLE users
    ADD COLUMN calendar_token CHAR(64) NULL COMMENT 'Secret in the personal .ics feed URL' AFTER password_changed_at,
    ADD COLUMN calendar_token_created_at DATETIME NULL AFTER calendar_token,
    ADD UNIQUE KEY uq_users_calendar_token (calendar_token);

-- --- Settings ---------------------------------------------------------------
-- The SMTP password is not here: it is written to `mail_password` encrypted
-- (see App\Core\Crypto), and MAIL_PASSWORD in .env overrides it entirely.
--
-- Mail is off until an administrator configures and tests it. That is
-- deliberate — an install that starts trying to send on day one, to a host that
-- does not exist, just fills the log with failures.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('mail_enabled',        '0'),
    ('mail_host',           ''),
    ('mail_port',           '587'),
    ('mail_encryption',     'tls'),
    ('mail_username',       ''),
    ('mail_password',       ''),
    ('mail_from_address',   ''),
    ('mail_from_name',      ''),
    ('mail_reply_to',       ''),
    ('mail_timeout',        '15'),

    -- Reminders. A days value of 0 means "use the same window the report and
    -- dashboard already use" (pat_due_days, maintenance_due_days,
    -- hire_due_soon_days), so the defaults agree with the rest of the app
    -- without hardcoding the number in two places.
    ('reminder_pat_enabled',          '0'),
    ('reminder_pat_days',             '0'),
    ('reminder_maintenance_enabled',  '0'),
    ('reminder_maintenance_days',     '0'),
    ('reminder_hire_enabled',         '0'),
    ('reminder_hire_days',            '0'),

    -- While an item stays due or overdue, remind again after this many days.
    -- 1 = every day the cron runs.
    ('reminder_repeat_days',          '7'),

    -- Comma-separated user ids: the staff notify list.
    ('reminder_recipient_user_ids',   ''),

    -- Also send a maintenance reminder to the user the schedule is assigned to.
    ('reminder_maintenance_assignee', '1'),

    -- Also send a hire return reminder to the hirer themselves.
    ('reminder_hire_notify_hirer',    '0');

-- --- Permissions ------------------------------------------------------------
INSERT INTO permissions (slug, name, group_name, description, sort_order) VALUES
    ('email.manage', 'Manage email',  'Email', 'Configure SMTP, edit templates and reminders, and read the send log.', 10),
    ('email.send',   'Send email',    'Email', 'Use the “Email this” actions, such as sending a hirer their hire list.', 20)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    group_name = VALUES(group_name),
    description = VALUES(description),
    sort_order = VALUES(sort_order);

-- Administrator holds everything.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.slug IN ('email.manage', 'email.send')
 WHERE r.slug = 'admin';

-- Manager / Staff can send a hirer their list, but not reconfigure the server.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.slug = 'email.send'
 WHERE r.slug = 'manager';
