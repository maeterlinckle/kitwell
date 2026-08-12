-- ---------------------------------------------------------------------------
-- 022 Two-factor authentication
--
-- Three columns on `users`, two new tables, and five settings.
--
-- What is deliberately NOT here: the email one-time code. That lives in the
-- session for the few minutes a sign-in is in progress, hashed, with its own
-- attempt counter — see App\Services\TwoFactor. A code that exists only for the
-- duration of one login attempt does not need a table, a cleanup job, or a row
-- left behind on every abandoned sign-in.
-- ---------------------------------------------------------------------------

ALTER TABLE users
    ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = this account is challenged for a second factor at sign-in'
        AFTER password_changed_at,
    ADD COLUMN totp_secret VARCHAR(255) NULL
        COMMENT 'Base32 TOTP secret, encrypted at rest with APP_KEY (App\\Core\\Crypto)'
        AFTER two_factor_enabled,
    ADD COLUMN totp_confirmed_at DATETIME NULL
        COMMENT 'Set when a code from the app was first verified; NULL means enrolment never finished'
        AFTER totp_secret;

-- --- Backup codes -----------------------------------------------------------
-- Issued at enrolment, for the day the phone is lost. Stored with
-- password_hash() rather than SHA-256: these are short enough to be worth
-- brute-forcing from a stolen dump, and slow hashing is the whole defence.
--
-- `used_at` rather than a delete, so "you have already used that one" is a
-- thing the screen can say, and so the count of codes left is honest.
CREATE TABLE IF NOT EXISTS user_backup_codes (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    code_hash  VARCHAR(255) NOT NULL,
    used_at    DATETIME     NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_backup_codes_user (user_id, used_at),
    CONSTRAINT fk_user_backup_codes_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Trusted devices --------------------------------------------------------
-- "Do not ask again on this computer", with an end to it.
--
-- The cookie holds 32 random bytes; only their SHA-256 is stored, so a stolen
-- database is not a set of working bypasses. The row also pins the browser
-- (a hash of the user agent) and the network it was trusted from, because a
-- token that travels to a different machine is the case this feature has to
-- fail closed on.
--
-- Three separate ways it stops working, all of them time-based or
-- circumstantial rather than "forever until revoked":
--   expires_at    the outer limit, set from trusted_device_days
--   last_seen_at  an idle window; a device unused for trusted_device_idle_days
--                 is challenged again
--   ip_address    kept so a significant change of network re-challenges
CREATE TABLE IF NOT EXISTS trusted_devices (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED    NOT NULL,
    token_hash      CHAR(64)        NOT NULL COMMENT 'SHA-256 of the cookie value',
    label           VARCHAR(191)    NULL COMMENT 'Something the owner will recognise in the list',
    ip_address      VARCHAR(45)     NULL,
    user_agent_hash CHAR(64)        NULL,
    last_seen_at    DATETIME        NOT NULL,
    expires_at      DATETIME        NOT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trusted_devices_token (token_hash),
    KEY idx_trusted_devices_user (user_id, expires_at),
    CONSTRAINT fk_trusted_devices_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Settings ---------------------------------------------------------------
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    -- Off by default. Turning it on for everybody is a decision with a support
    -- cost, and it is not one an upgrade should make on an administrator's
    -- behalf.
    ('two_factor_required',        '0'),

    -- How long "trust this device" lasts at the outside.
    ('trusted_device_days',        '30'),

    -- ...and how long it survives without being used. A laptop last signed in
    -- from six weeks ago is not the laptop that was trusted.
    ('trusted_device_idle_days',   '14'),

    -- How long an emailed code is good for.
    ('email_otp_minutes',          '10'),

    -- Wrong codes allowed against one challenge before it is torn up and the
    -- sign-in has to start again.
    ('two_factor_max_attempts',    '5');
