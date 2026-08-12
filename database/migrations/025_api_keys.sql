-- ---------------------------------------------------------------------------
-- 025 API keys
--
-- A key is a way to sign in as a user without a browser. It is *not* a second
-- permission system, and this table is deliberately shaped to make that
-- impossible: there is no permission column here, only `user_id`. Every API
-- request adopts that user for its lifetime and then runs the same
-- `Auth::can()` the web interface runs, against the same roles and grants. A
-- key therefore cannot outgrow its owner — change their role and every key they
-- hold changes with it, in the same instant, with nothing to keep in step.
--
-- The one thing a key may do is *less*. `scope = 'read'` refuses every method
-- except GET, whatever its owner could otherwise do, so a key handed to a
-- dashboard or a spreadsheet cannot delete an asset even by accident.
--
-- The secret is never stored. `token_hash` is a SHA-256 of the whole key and is
-- what a request is looked up by; `token_prefix` is the first few characters,
-- kept in clear so the list can say *which* key was used without being able to
-- reconstruct it. SHA-256 rather than password_hash() because this is a
-- 48-character random value, not a human-chosen password: there is nothing to
-- brute-force, and a lookup has to be a single indexed query rather than a
-- verify against every row.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS api_keys (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(120) NOT NULL COMMENT 'What it is for, e.g. “Stores dashboard”',

    -- CASCADE: a key is a credential belonging to a person. When the account
    -- goes, the credential goes with it — leaving it behind pointing at nobody
    -- would be a key that authenticates as a user who no longer exists.
    user_id       INT UNSIGNED NOT NULL,

    token_prefix  CHAR(12)     NOT NULL COMMENT 'Clear, for display: identifies a key without revealing it',
    token_hash    CHAR(64)     NOT NULL COMMENT 'SHA-256 of the whole key',

    scope         ENUM('read','full') NOT NULL DEFAULT 'read'
                      COMMENT 'read = GET only, whatever the owner may otherwise do',

    expires_at    DATETIME     NULL COMMENT 'NULL = no expiry',
    revoked_at    DATETIME     NULL COMMENT 'Set instead of deleting, so the log still reads',
    last_used_at  DATETIME     NULL,
    last_used_ip  VARCHAR(45)  NULL,
    request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,

    -- Rate limiting, kept on the row rather than in a log table: one counter,
    -- one UPDATE per request, and nothing that grows. A fixed window rather
    -- than a sliding one — it can allow up to twice the limit across a window
    -- boundary, which is the honest trade for not writing a row per request on
    -- the sort of hosting this application is built for.
    rate_window_started_at DATETIME NULL,
    rate_count    INT UNSIGNED NOT NULL DEFAULT 0,

    created_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_api_keys_hash (token_hash),
    KEY idx_api_keys_user (user_id),
    KEY idx_api_keys_active (revoked_at, expires_at),
    CONSTRAINT fk_api_keys_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_api_keys_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Permission --------------------------------------------------------------
-- Issuing a credential that acts as somebody is the same kind of act as
-- creating their account, so it sits with the administrator alone — the same
-- place `users.manage` and `roles.manage` sit.
INSERT INTO permissions (slug, name, group_name, description, sort_order) VALUES
    ('api.manage', 'Manage API keys', 'Administration', 'Issue and revoke API keys, and see when each was last used.', 65)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    group_name = VALUES(group_name),
    description = VALUES(description),
    sort_order = VALUES(sort_order);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.slug = 'api.manage'
 WHERE r.slug = 'admin';

-- --- Settings ----------------------------------------------------------------
-- Off until an administrator turns it on. An installation that does not want a
-- programmable interface should not have one listening, and the endpoints
-- answer 503 with a reason rather than 404 so the person testing knows the
-- difference between "not enabled" and "not there".
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('api_enabled',           '0'),
    ('api_rate_limit',        '120'),
    ('api_default_per_page',  '25'),
    ('api_max_per_page',      '100');
