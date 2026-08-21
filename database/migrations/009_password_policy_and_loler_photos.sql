-- ---------------------------------------------------------------------------
-- 009 Password policy, and photographic evidence on a LOLER examination
--
-- Two unrelated features that happen to land in the same release.
--
-- Every statement here is IF NOT EXISTS or INSERT IGNORE, so the file is safe
-- to apply twice. `ADD COLUMN IF NOT EXISTS` is MariaDB syntax; the project
-- targets MariaDB (see PROJECT_STATE §1) and not MySQL, where it does not
-- exist.
-- ---------------------------------------------------------------------------

-- --- Password policy, application level ---------------------------------------
--
-- Three keys rather than a hardcoded rule, so the thresholds can be tuned from
-- Settings without a release. `password_expiry_days` of 0 means "never" — a
-- policy that expires nothing is a legitimate policy, and the alternative (a
-- separate on/off key beside a number) is two settings that can disagree.
--
-- The complexity default is 12 characters and 3 of the 4 character classes.
-- Length is the part that actually costs an attacker time; the class count is
-- there because a 12-character password drawn from one class is usually a word.

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('password_expiry_days', '0'),
    ('password_min_length', '12'),
    ('password_min_classes', '3');

-- --- Password policy, account level -------------------------------------------
--
-- All three are NULL by default, and NULL means "whatever the application
-- says". That is the distinction the whole feature turns on: a shared or
-- service account set never to expire has to be *set* to it, and must not
-- quietly start expiring again the day somebody edits the site-wide policy.
--
-- 0 in `password_expiry_days` means never, exactly as it does in settings. NULL
-- and 0 are therefore different values with different meanings. Do not "tidy"
-- one into the other, and do not give these columns defaults.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS password_expiry_days SMALLINT UNSIGNED NULL
        COMMENT 'NULL = use the application policy; 0 = never expires'
        AFTER password_changed_at,
    ADD COLUMN IF NOT EXISTS password_min_length TINYINT UNSIGNED NULL
        COMMENT 'NULL = use the application policy'
        AFTER password_expiry_days,
    ADD COLUMN IF NOT EXISTS password_min_classes TINYINT UNSIGNED NULL
        COMMENT 'NULL = use the application policy'
        AFTER password_min_length;

-- --- LOLER examination photographs --------------------------------------------
--
-- Evidence of the physical examination, belonging to the examination that
-- produced it and to nothing else — the same treatment PAT, fault and
-- maintenance photographs already get, and deliberately not the shared media
-- library, which describes an asset rather than one day's inspection of it.
--
-- CASCADE on the examination for the same reason: the photograph is part of
-- the report, not a fact about the equipment that should outlive it.

CREATE TABLE IF NOT EXISTS loler_photos (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    examination_id    BIGINT UNSIGNED NOT NULL,
    position          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    file_path         VARCHAR(255) NOT NULL,
    thumbnail_path    VARCHAR(255) NULL COMMENT 'NULL when no thumbnail could be made — gd is optional',
    original_filename VARCHAR(255) NULL,
    mime_type         VARCHAR(100) NOT NULL,
    file_size_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
    width_px          SMALLINT UNSIGNED NULL,
    height_px         SMALLINT UNSIGNED NULL,
    description       VARCHAR(500) NULL COMMENT 'Optional: what the photograph shows, where that is not self-evident',
    uploaded_by       INT UNSIGNED NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_loler_photos_exam (examination_id, position),
    CONSTRAINT fk_loler_photos_exam FOREIGN KEY (examination_id) REFERENCES loler_examinations (id) ON DELETE CASCADE,
    CONSTRAINT fk_loler_photos_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
