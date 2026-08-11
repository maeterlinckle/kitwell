-- ---------------------------------------------------------------------------
-- 021 Invite and password-reset links
--
-- One table for both, because they are the same object: a single-use link with
-- an expiry that proves control of a mailbox. Two tables would mean two copies
-- of the issue/expire/consume logic and two chances to get one of them wrong.
--
-- What is stored is a SHA-256 of the token, never the token itself. The raw
-- value exists in exactly one place — the email that was sent — so a stolen
-- database backup cannot be used to take over an account. It also means a lost
-- link cannot be looked up and re-sent; a fresh one has to be issued, which is
-- the right answer anyway.
--
-- `used_at` rather than a delete, so "this link has already been used" is a
-- state the page can explain instead of an indistinguishable "not found".
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS user_tokens (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED    NOT NULL,
    purpose    ENUM('invite','password_reset') NOT NULL,
    token_hash CHAR(64)        NOT NULL COMMENT 'SHA-256 of the token; the token itself is only ever in the email',
    expires_at DATETIME        NOT NULL,
    used_at    DATETIME        NULL COMMENT 'Set on use; a used link is refused with an explanation, not a 404',
    created_by INT UNSIGNED    NULL COMMENT 'The administrator who issued it; NULL for a self-service reset',
    created_ip VARCHAR(45)     NULL,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_tokens_hash (token_hash),
    KEY idx_user_tokens_user (user_id, purpose, expires_at),
    KEY idx_user_tokens_expiry (expires_at),
    CONSTRAINT fk_user_tokens_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_tokens_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Settings ---------------------------------------------------------------
-- Two windows, not one, because they are answers to different questions.
--
-- An invite is expected: somebody has been told an account is coming, and may
-- be away for a day or two — three days is generous without being indefinite.
-- A reset is unexpected: it lands in a mailbox because somebody asked for it a
-- moment ago, and the shorter it lives the smaller the window in which a
-- forwarded or intercepted message is worth anything. Two hours is the usual
-- shape of that trade-off.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('invite_expiry_hours',         '72'),
    ('password_reset_expiry_hours', '2');
