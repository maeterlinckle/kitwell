-- ---------------------------------------------------------------------------
-- 004 Maintenance schedules and history
--
-- A schedule describes recurring work. A log row records a completed event and
-- may either belong to a schedule or stand alone (ad-hoc repairs).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS maintenance_schedules (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id            BIGINT UNSIGNED NOT NULL,
    title               VARCHAR(191) NOT NULL COMMENT 'e.g. "Annual service"',
    maintenance_type    ENUM('routine','periodic','ad-hoc') NOT NULL DEFAULT 'periodic',
    frequency_interval  SMALLINT UNSIGNED NULL COMMENT 'Number of frequency_unit between visits',
    frequency_unit      ENUM('days','weeks','months','years') NULL,
    next_due_date       DATE         NULL,
    last_completed_date DATE         NULL,
    assigned_to_user_id INT UNSIGNED NULL,
    instructions        TEXT         NULL,
    estimated_minutes   SMALLINT UNSIGNED NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    created_by          INT UNSIGNED NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_maint_sched_asset (asset_id),
    KEY idx_maint_sched_due (next_due_date, is_active),
    KEY idx_maint_sched_assignee (assigned_to_user_id),
    CONSTRAINT fk_maint_sched_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_maint_sched_assignee
        FOREIGN KEY (assigned_to_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_maint_sched_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_logs (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id              BIGINT UNSIGNED NOT NULL,
    schedule_id           BIGINT UNSIGNED NULL COMMENT 'NULL for ad-hoc work not tied to a schedule',
    maintenance_type      ENUM('routine','periodic','ad-hoc','repair','inspection') NOT NULL DEFAULT 'routine',
    performed_on          DATE         NOT NULL,
    performed_by_user_id  INT UNSIGNED NULL,
    performed_by_name     VARCHAR(191) NULL COMMENT 'Free text for external contractors',
    work_done             TEXT         NULL,
    parts_used            TEXT         NULL,
    cost                  DECIMAL(10,2) NULL,
    downtime_minutes      SMALLINT UNSIGNED NULL,
    result                ENUM('Completed','Partial','Failed','Deferred') NOT NULL DEFAULT 'Completed',
    condition_after       ENUM('Excellent','Good','Fair','Poor','Out of Service') NULL,
    next_due_date         DATE         NULL COMMENT 'Copied onto the schedule when the job is logged',
    notes                 TEXT         NULL,
    created_by            INT UNSIGNED NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_maint_log_asset (asset_id, performed_on),
    KEY idx_maint_log_schedule (schedule_id),
    KEY idx_maint_log_performed_on (performed_on),
    CONSTRAINT fk_maint_log_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_maint_log_schedule
        FOREIGN KEY (schedule_id) REFERENCES maintenance_schedules (id) ON DELETE SET NULL,
    CONSTRAINT fk_maint_log_user
        FOREIGN KEY (performed_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_maint_log_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Photos attached to a maintenance event (before/after shots, faults found).
CREATE TABLE IF NOT EXISTS maintenance_log_photos (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    maintenance_log_id BIGINT UNSIGNED NOT NULL,
    file_path          VARCHAR(255) NOT NULL,
    original_filename  VARCHAR(255) NULL,
    mime_type          VARCHAR(100) NOT NULL,
    file_size_bytes    INT UNSIGNED NOT NULL DEFAULT 0,
    caption            VARCHAR(255) NULL,
    uploaded_by        INT UNSIGNED NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_maint_photos_log (maintenance_log_id),
    CONSTRAINT fk_maint_photos_log
        FOREIGN KEY (maintenance_log_id) REFERENCES maintenance_logs (id) ON DELETE CASCADE,
    CONSTRAINT fk_maint_photos_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
