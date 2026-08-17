-- ---------------------------------------------------------------------------
-- 005 Maintenance routines
--
-- A routine is a procedure a technician steps through and fills in: pages of
-- questions with a type each, rather than one free-text box.
--
-- The definition is versioned because the answers outlive it. A completion
-- points at the exact version that was in force when the work was done, so a
-- record from two years ago still shows the questions that were actually
-- asked. `routine_completions` therefore holds the version down with a
-- RESTRICT: a version that has been used cannot be deleted, only superseded.
--
-- Answers live in `routine_responses`, one row per step, with the value in
-- whichever column suits the step's type. Photos and documents are exclusive
-- to the completion that produced them — evidence of one job on one day — so
-- they are stored in `routine_response_files` and never in the shared media
-- library.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS maintenance_routines (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(191) NOT NULL,
    description VARCHAR(1000) NULL,
    status      ENUM('active','archived') NOT NULL DEFAULT 'active'
                COMMENT 'Archived routines keep their history but are not offered for new work',
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_maintenance_routines_name (name),
    KEY idx_maintenance_routines_status (status, name),
    CONSTRAINT fk_maintenance_routines_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One edition of a routine. `published_at IS NULL` is a draft: it can be
-- edited freely and cannot be run. At most one version of a routine is the
-- current one, and only a published version ever is.
CREATE TABLE IF NOT EXISTS routine_versions (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    routine_id     INT UNSIGNED NOT NULL,
    version_number SMALLINT UNSIGNED NOT NULL,
    is_current     TINYINT(1) NOT NULL DEFAULT 0,
    published_at   DATETIME NULL COMMENT 'NULL while this version is still a draft',
    published_by   INT UNSIGNED NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_routine_versions_number (routine_id, version_number),
    KEY idx_routine_versions_current (routine_id, is_current),
    CONSTRAINT fk_routine_versions_routine
        FOREIGN KEY (routine_id) REFERENCES maintenance_routines (id) ON DELETE CASCADE,
    CONSTRAINT fk_routine_versions_published_by
        FOREIGN KEY (published_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routine_pages (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_id  INT UNSIGNED NOT NULL,
    position    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    title       VARCHAR(191) NOT NULL,
    description VARCHAR(1000) NULL COMMENT 'Shown under the page heading while the routine is being carried out',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_routine_pages_version (version_id, position),
    CONSTRAINT fk_routine_pages_version
        FOREIGN KEY (version_id) REFERENCES routine_versions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One question. `options` is a JSON array of choice labels and is only read by
-- the two choice types; every other type ignores it.
CREATE TABLE IF NOT EXISTS routine_steps (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    page_id     INT UNSIGNED NOT NULL,
    position    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    label       VARCHAR(255) NOT NULL,
    help_text   VARCHAR(1000) NULL,
    field_type  ENUM('short_text','long_text','number','date','boolean',
                     'single_choice','multi_choice','photo','document')
                NOT NULL DEFAULT 'short_text',
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    unit        VARCHAR(30) NULL COMMENT 'Number fields only: the unit shown beside the box, e.g. "bar"',
    options     JSON NULL COMMENT 'Choice fields only: a JSON array of the labels offered',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_routine_steps_page (page_id, position),
    CONSTRAINT fk_routine_steps_page
        FOREIGN KEY (page_id) REFERENCES routine_pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A routine actually carried out. The maintenance log is what puts it in the
-- asset's history; this row is the detail behind that entry.
CREATE TABLE IF NOT EXISTS routine_completions (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    routine_id         INT UNSIGNED NOT NULL,
    version_id         INT UNSIGNED NOT NULL,
    asset_id           BIGINT UNSIGNED NOT NULL,
    schedule_id        BIGINT UNSIGNED NULL COMMENT 'Set when the routine was reached from a scheduled job',
    maintenance_log_id BIGINT UNSIGNED NULL,
    completed_by       INT UNSIGNED NULL,
    started_at         DATETIME NULL,
    completed_at       DATETIME NOT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_routine_completions_routine (routine_id, completed_at),
    KEY idx_routine_completions_version (version_id),
    KEY idx_routine_completions_asset (asset_id, completed_at),
    KEY idx_routine_completions_schedule (schedule_id),
    KEY idx_routine_completions_log (maintenance_log_id),
    CONSTRAINT fk_routine_completions_routine
        FOREIGN KEY (routine_id) REFERENCES maintenance_routines (id),
    CONSTRAINT fk_routine_completions_version
        FOREIGN KEY (version_id) REFERENCES routine_versions (id),
    CONSTRAINT fk_routine_completions_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_routine_completions_schedule
        FOREIGN KEY (schedule_id) REFERENCES maintenance_schedules (id) ON DELETE SET NULL,
    CONSTRAINT fk_routine_completions_log
        FOREIGN KEY (maintenance_log_id) REFERENCES maintenance_logs (id) ON DELETE CASCADE,
    CONSTRAINT fk_routine_completions_completed_by
        FOREIGN KEY (completed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One answer. The value lands in the column its step's type calls for; a
-- multi-choice answer is the chosen labels one per line in value_text, which
-- is why a choice label may not contain a line break.
CREATE TABLE IF NOT EXISTS routine_responses (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    completion_id BIGINT UNSIGNED NOT NULL,
    step_id       INT UNSIGNED NOT NULL,
    value_text    TEXT NULL,
    value_number  DECIMAL(14,4) NULL,
    value_date    DATE NULL,
    value_boolean TINYINT(1) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_routine_responses_step (completion_id, step_id),
    KEY idx_routine_responses_step (step_id),
    CONSTRAINT fk_routine_responses_completion
        FOREIGN KEY (completion_id) REFERENCES routine_completions (id) ON DELETE CASCADE,
    CONSTRAINT fk_routine_responses_step
        FOREIGN KEY (step_id) REFERENCES routine_steps (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Photographs and paperwork captured while the routine was carried out. These
-- belong to the completion alone and are never shared, for the same reason a
-- condition photo is not a library item: they are a claim about one item on
-- one day.
CREATE TABLE IF NOT EXISTS routine_response_files (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    completion_id     BIGINT UNSIGNED NOT NULL,
    step_id           INT UNSIGNED NOT NULL,
    file_kind         ENUM('photo','document') NOT NULL,
    file_path         VARCHAR(255) NOT NULL COMMENT 'Relative to the uploads root; never an absolute path',
    original_filename VARCHAR(255) NULL,
    mime_type         VARCHAR(100) NOT NULL,
    file_size_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by       INT UNSIGNED NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_routine_response_files_step (completion_id, step_id),
    CONSTRAINT fk_routine_response_files_completion
        FOREIGN KEY (completion_id) REFERENCES routine_completions (id) ON DELETE CASCADE,
    CONSTRAINT fk_routine_response_files_step
        FOREIGN KEY (step_id) REFERENCES routine_steps (id),
    CONSTRAINT fk_routine_response_files_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- A schedule may call for a routine --------------------------------------
--
-- Optional, and beside the instructions rather than instead of them: a job can
-- have both a procedure to follow and a line of context about this machine.

ALTER TABLE maintenance_schedules
    ADD COLUMN routine_id INT UNSIGNED NULL
        COMMENT 'Completing this job runs the routine instead of the free-text form'
        AFTER instructions,
    ADD KEY idx_maint_sched_routine (routine_id),
    ADD CONSTRAINT fk_maint_sched_routine
        FOREIGN KEY (routine_id) REFERENCES maintenance_routines (id) ON DELETE SET NULL;

-- --- Permission -------------------------------------------------------------
--
-- Designing a procedure and carrying one out are different jobs. Running a
-- routine needs `maintenance.complete`, which Manager / Staff already hold;
-- rewriting what it asks needs this, which by default only an Administrator
-- has. Add it to any role from Settings → Roles where that is not the right
-- split for a site.

INSERT INTO permissions (slug, name, group_name, description, sort_order) VALUES
    ('routines.manage', 'Manage maintenance routines', 'Maintenance', 'Create and edit the routines technicians fill in. Carrying one out needs only “Record maintenance”.', 40)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    group_name = VALUES(group_name),
    description = VALUES(description),
    sort_order = VALUES(sort_order);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug = 'admin' AND p.slug = 'routines.manage';
