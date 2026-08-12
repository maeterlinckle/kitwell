-- ---------------------------------------------------------------------------
-- 003 Default application settings
--
-- Every key the application reads, with the value a fresh installation starts
-- with. All of them are editable from Settings in the admin area.
--
-- INSERT IGNORE, so a key that already has a value keeps it.
-- ---------------------------------------------------------------------------

-- Asset tags and printed labels. Tags are generated as <prefix><zero-padded
-- number>, e.g. AST-0001. Changing the prefix starts a new sequence and leaves
-- existing tags alone.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('asset_tag_prefix',    'AST-'),
    ('asset_tag_pad',       '4'),
    ('organisation_name',   ''),
    ('label_show_location', '1'),
    ('label_show_name',     '1');

-- How far ahead work counts as "due".
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('maintenance_due_days',        '30'),
    ('pat_default_interval_months', '12'),
    ('pat_due_days',                '30');

-- Hires: the default hire period, when an item counts as due back soon, and the
-- prefix for generated hire references.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('hire_default_days',     '7'),
    ('hire_due_soon_days',    '2'),
    ('hire_reference_prefix', 'HR-');

-- PAT pass/fail guidelines, shown beside each reading as the tester enters it.
-- The earth continuity guideline is the base figure plus an allowance for extra
-- lead length.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('pat_guide_earth_base_ohm',    '0.1'),
    ('pat_guide_earth_lead_metres', '7.5'),
    ('pat_guide_earth_lead_ohm',    '0.1'),
    ('pat_guide_insulation_mohm',   '1'),
    ('pat_guide_leakage_class1_ma', '3.5'),
    ('pat_guide_leakage_class2_ma', '0.25');

-- Outgoing mail. Email is off until a host is configured and the switch is on.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('mail_enabled',      '0'),
    ('mail_host',         ''),
    ('mail_port',         '587'),
    ('mail_encryption',   'tls'),
    ('mail_username',     ''),
    ('mail_password',     ''),
    ('mail_from_address', ''),
    ('mail_from_name',    ''),
    ('mail_reply_to',     ''),
    ('mail_timeout',      '15');

-- Scheduled reminders. Each digest is off until switched on, and the schedule
-- is driven by the cron entry described in the documentation.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('reminder_pat_enabled',          '0'),
    ('reminder_pat_days',             '0'),
    ('reminder_maintenance_enabled',  '0'),
    ('reminder_maintenance_days',     '0'),
    ('reminder_maintenance_assignee', '1'),
    ('reminder_hire_enabled',         '0'),
    ('reminder_hire_days',            '0'),
    ('reminder_hire_notify_hirer',    '0'),
    ('reminder_faulty_enabled',       '0'),
    ('reminder_faulty_repeat_days',   '0'),
    ('reminder_repeat_days',          '7'),
    ('reminder_recipient_user_ids',   '');

-- Faults: email the responsible party as soon as an asset is marked faulty.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('fault_notify_immediately', '1');

-- How long an invitation and a password-reset link stay valid, in hours.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('invite_expiry_hours',         '72'),
    ('password_reset_expiry_hours', '2');

-- Two-factor authentication.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('two_factor_required',      '0'),
    ('two_factor_max_attempts',  '5'),
    ('email_otp_minutes',        '10'),
    ('trusted_device_days',      '30'),
    ('trusted_device_idle_days', '14');

-- The REST API. Off until switched on; the limits apply per key.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('api_enabled',          '0'),
    ('api_default_per_page', '25'),
    ('api_max_per_page',     '100'),
    ('api_rate_limit',       '120');
