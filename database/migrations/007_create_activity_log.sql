-- ---------------------------------------------------------------------------
-- 007 Activity / audit log
--
-- entity_type + entity_id form a loose polymorphic reference (asset, user,
-- loan, pat_record, ...). No foreign key on entity_id on purpose: the audit
-- trail must survive deletion of the thing it describes. user_name is a
-- snapshot for the same reason.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS activity_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED    NULL,
    user_name   VARCHAR(191)    NOT NULL DEFAULT 'System',
    action      VARCHAR(100)    NOT NULL COMMENT 'created, updated, deleted, login, checked_out, ...',
    entity_type VARCHAR(64)     NOT NULL COMMENT 'asset, user, loan, pat_record, maintenance_log, ...',
    entity_id   BIGINT UNSIGNED NULL,
    description VARCHAR(500)    NULL,
    -- MariaDB implements JSON as LONGTEXT plus a json_valid() CHECK constraint,
    -- so this column rejects anything that is not valid JSON.
    changes     JSON            NULL COMMENT 'Field-level before/after payload',
    ip_address  VARCHAR(45)     NULL,
    user_agent  VARCHAR(255)    NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_entity (entity_type, entity_id, created_at),
    KEY idx_activity_user (user_id, created_at),
    KEY idx_activity_created (created_at),
    CONSTRAINT fk_activity_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
