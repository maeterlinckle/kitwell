-- ---------------------------------------------------------------------------
-- 001 Kitwell schema
--
-- Every table the application uses. Tables are created in dependency order so
-- the foreign keys resolve on a first run, and each statement is written to be
-- safe to re-run.
--
-- Conventions:
--   * InnoDB, utf8mb4 / utf8mb4_unicode_ci throughout.
--   * Surrogate INT/BIGINT UNSIGNED primary keys named `id`.
--   * Index names are prefixed uq_ (unique), idx_ (plain) or fk_ (constraint).
--   * Rows that must survive the deletion of a person use ON DELETE SET NULL
--     and keep a name snapshot alongside the id.
-- ---------------------------------------------------------------------------

-- --- Authentication, roles and permissions ---------------------------------

CREATE TABLE IF NOT EXISTS roles (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug          VARCHAR(50)  NOT NULL COMMENT 'Stable machine name, e.g. admin',
    name          VARCHAR(100) NOT NULL,
    description   VARCHAR(255) NULL,
    is_superuser  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = implicitly holds every permission',
    is_system     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = built in, cannot be deleted',
    sort_order    SMALLINT     NOT NULL DEFAULT 100,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(100) NOT NULL COMMENT 'Dot-notation, e.g. assets.edit',
    name        VARCHAR(150) NOT NULL,
    group_name  VARCHAR(60)  NOT NULL DEFAULT 'General' COMMENT 'Grouping for the admin UI',
    description VARCHAR(255) NULL,
    sort_order  SMALLINT     NOT NULL DEFAULT 100,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_slug (slug),
    KEY idx_permissions_group (group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_role_permissions_permission (permission_id),
    CONSTRAINT fk_role_permissions_role
        FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission
        FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                     VARCHAR(150) NOT NULL,
    email                    VARCHAR(190) NOT NULL,
    password_hash            VARCHAR(255) NOT NULL COMMENT 'password_hash(), PASSWORD_DEFAULT',
    role_id                  INT UNSIGNED NOT NULL,
    is_active                TINYINT(1)   NOT NULL DEFAULT 1,
    phone                    VARCHAR(50)  NULL,
    job_title                VARCHAR(150) NULL,
    last_login_at            DATETIME     NULL,
    last_login_ip            VARCHAR(45)  NULL COMMENT 'IPv4 or IPv6',
    password_changed_at      DATETIME     NULL,
    two_factor_enabled       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = this account is challenged for a second factor at sign-in',
    totp_secret              VARCHAR(255) NULL COMMENT 'Base32 TOTP secret, encrypted at rest with APP_KEY (App\\Core\\Crypto)',
    totp_confirmed_at        DATETIME     NULL COMMENT 'Set when a code from the app was first verified; NULL means enrolment never finished',
    calendar_token           CHAR(64)     NULL COMMENT 'Secret in the personal .ics feed URL',
    calendar_token_created_at DATETIME    NULL,
    created_by               INT UNSIGNED NULL,
    created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_calendar_token (calendar_token),
    KEY idx_users_role (role_id),
    KEY idx_users_active (is_active),
    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles (id),
    CONSTRAINT fk_users_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email        VARCHAR(190) NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,
    successful   TINYINT(1)   NOT NULL DEFAULT 0,
    user_agent   VARCHAR(255) NULL,
    attempted_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_attempts_email_time (email, attempted_at),
    KEY idx_login_attempts_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    purpose     ENUM('invite','password_reset') NOT NULL,
    token_hash  CHAR(64)     NOT NULL COMMENT 'SHA-256 of the token; the token itself is only ever in the email',
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME     NULL COMMENT 'Set on use; a link is good for one use only',
    created_by  INT UNSIGNED NULL COMMENT 'The administrator who issued it; NULL for a self-service reset',
    created_ip  VARCHAR(45)  NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_tokens_hash (token_hash),
    KEY idx_user_tokens_user (user_id, purpose, expires_at),
    KEY idx_user_tokens_expiry (expires_at),
    CONSTRAINT fk_user_tokens_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_tokens_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS trusted_devices (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    token_hash      CHAR(64)     NOT NULL COMMENT 'SHA-256 of the cookie value',
    label           VARCHAR(191) NULL COMMENT 'Something the owner will recognise in the list',
    ip_address      VARCHAR(45)  NULL,
    user_agent_hash CHAR(64)     NULL,
    last_seen_at    DATETIME     NOT NULL,
    expires_at      DATETIME     NOT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trusted_devices_token (token_hash),
    KEY idx_trusted_devices_user (user_id, expires_at),
    CONSTRAINT fk_trusted_devices_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teams (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = archived',
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teams_name (name),
    KEY idx_teams_active (is_active),
    CONSTRAINT fk_teams_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_members (
    team_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    added_by   INT UNSIGNED NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (team_id, user_id),
    KEY idx_team_members_user (user_id),
    CONSTRAINT fk_team_members_team
        FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
    CONSTRAINT fk_team_members_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_team_members_added_by
        FOREIGN KEY (added_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Reference data ---------------------------------------------------------

CREATE TABLE IF NOT EXISTS categories (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    slug        VARCHAR(120) NOT NULL,
    parent_id   INT UNSIGNED NULL,
    description VARCHAR(255) NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug),
    KEY idx_categories_parent (parent_id),
    CONSTRAINT fk_categories_parent
        FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS locations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    code        VARCHAR(40)  NULL COMMENT 'Short code used on labels',
    parent_id   INT UNSIGNED NULL,
    description VARCHAR(255) NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_locations_name_parent (name, parent_id),
    KEY idx_locations_parent (parent_id),
    CONSTRAINT fk_locations_parent
        FOREIGN KEY (parent_id) REFERENCES locations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Assets -----------------------------------------------------------------

CREATE TABLE IF NOT EXISTS assets (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_tag            VARCHAR(64)  NOT NULL COMMENT 'Printed/scanned tag - the primary barcode value',
    barcode              VARCHAR(64)  NULL COMMENT 'Optional secondary/manufacturer barcode',
    name                 VARCHAR(191) NOT NULL,
    description          TEXT         NULL,
    category_id          INT UNSIGNED NULL,
    location_id          INT UNSIGNED NULL,
    responsible_user_id  INT UNSIGNED NULL COMMENT 'Mutually exclusive with responsible_team_id',
    responsible_team_id  INT UNSIGNED NULL COMMENT 'Mutually exclusive with responsible_user_id',
    condition_rating     ENUM('Excellent','Good','Fair','Poor','Out of Service') NOT NULL DEFAULT 'Good',
    status               ENUM('In Stock','On Hire','In Maintenance','Retired','Faulty') NOT NULL DEFAULT 'In Stock',
    purchase_date        DATE         NULL,
    purchase_cost        DECIMAL(12,2) NULL COMMENT 'Purchase price in the configured currency',
    current_value        DECIMAL(12,2) NULL COMMENT 'Current/replacement value',
    supplier             VARCHAR(191) NULL,
    warranty_expires_on  DATE         NULL,
    serial_number        VARCHAR(191) NULL,
    manufacturer         VARCHAR(191) NULL,
    model                VARCHAR(191) NULL,
    manufacturer_url     VARCHAR(500) NULL COMMENT 'Product/support page',
    plug_fuse_rating_amps DECIMAL(5,2) NULL COMMENT 'Plug fuse rating in Amps (A), e.g. 3.00, 5.00, 13.00',
    cable_csa_mm2        DECIMAL(5,2) NULL COMMENT 'Cable cross-sectional area in square millimetres (mm2)',
    requires_pat         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = portable appliance testing applies',
    appliance_class      ENUM('Class I','Class II','Class III','Not Applicable') NULL COMMENT 'Fixed property of the appliance; NULL = not yet established',
    load_rating_va       DECIMAL(9,2) NULL COMMENT 'Rated load in volt-amps',
    has_fuse             TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = the plug carries a fuse, so the fuse check applies',
    pat_interval_months  SMALLINT UNSIGNED NULL COMMENT 'Retest interval in months; NULL = use site default',
    parent_asset_id      BIGINT UNSIGNED NULL COMMENT 'Set on sub-assets/accessories/related items',
    relationship_type    ENUM('sub-asset','accessory','related') NULL COMMENT 'Only meaningful when parent_asset_id is set',
    is_hireable          TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Can this item be hired out',
    notes                TEXT         NULL,
    retired_on           DATE         NULL,
    created_by           INT UNSIGNED NULL,
    updated_by           INT UNSIGNED NULL,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assets_asset_tag (asset_tag),
    UNIQUE KEY uq_assets_barcode (barcode),
    KEY idx_assets_name (name),
    KEY idx_assets_status (status),
    KEY idx_assets_condition (condition_rating),
    KEY idx_assets_category (category_id),
    KEY idx_assets_location (location_id),
    KEY idx_assets_parent (parent_asset_id),
    KEY idx_assets_requires_pat (requires_pat),
    KEY idx_assets_serial (serial_number),
    KEY idx_assets_status_category (status, category_id),
    KEY idx_assets_appliance_class (appliance_class),
    KEY idx_assets_responsible_user (responsible_user_id),
    KEY idx_assets_responsible_team (responsible_team_id),
    CONSTRAINT fk_assets_category
        FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_location
        FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_responsible_user
        FOREIGN KEY (responsible_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_responsible_team
        FOREIGN KEY (responsible_team_id) REFERENCES teams (id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_parent
        FOREIGN KEY (parent_asset_id) REFERENCES assets (id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_photos (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id          BIGINT UNSIGNED NOT NULL,
    file_path         VARCHAR(255) NOT NULL COMMENT 'Path relative to the uploads root',
    thumbnail_path    VARCHAR(255) NULL COMMENT 'Path relative to the uploads root; NULL when no thumbnail could be made',
    original_filename VARCHAR(255) NULL,
    mime_type         VARCHAR(100) NOT NULL,
    file_size_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
    width_px          SMALLINT UNSIGNED NULL,
    height_px         SMALLINT UNSIGNED NULL,
    caption           VARCHAR(255) NULL,
    is_primary        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = thumbnail shown in listings',
    taken_at          DATETIME     NULL COMMENT 'When the condition was recorded, if not the upload time',
    uploaded_by       INT UNSIGNED NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_asset_photos_asset (asset_id, created_at),
    KEY idx_asset_photos_primary (asset_id, is_primary),
    KEY idx_asset_photos_taken (asset_id, taken_at),
    CONSTRAINT fk_asset_photos_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_photos_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_manuals (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id          BIGINT UNSIGNED NOT NULL,
    title             VARCHAR(191) NOT NULL COMMENT 'e.g. "User Manual", "Wiring Diagram"',
    file_path         VARCHAR(255) NOT NULL COMMENT 'Path relative to the uploads root',
    original_filename VARCHAR(255) NULL,
    mime_type         VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    file_size_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
    page_count        SMALLINT UNSIGNED NULL,
    notes             VARCHAR(255) NULL,
    uploaded_by       INT UNSIGNED NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_asset_manuals_asset (asset_id, created_at),
    CONSTRAINT fk_asset_manuals_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_manuals_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Maintenance ------------------------------------------------------------

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
    assigned_to_team_id INT UNSIGNED NULL COMMENT 'Mutually exclusive with assigned_to_user_id',
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
    KEY idx_maintenance_schedules_team (assigned_to_team_id),
    CONSTRAINT fk_maint_sched_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_maint_sched_assignee
        FOREIGN KEY (assigned_to_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_maintenance_schedules_team
        FOREIGN KEY (assigned_to_team_id) REFERENCES teams (id) ON DELETE SET NULL,
    CONSTRAINT fk_maint_sched_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_logs (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id             BIGINT UNSIGNED NOT NULL,
    schedule_id          BIGINT UNSIGNED NULL COMMENT 'NULL for ad-hoc work not tied to a schedule',
    maintenance_type     ENUM('routine','periodic','ad-hoc','repair','inspection') NOT NULL DEFAULT 'routine',
    performed_on         DATE         NOT NULL,
    performed_by_user_id INT UNSIGNED NULL,
    performed_by_name    VARCHAR(191) NULL COMMENT 'Free text for external contractors',
    work_done            TEXT         NULL,
    parts_used           TEXT         NULL,
    cost                 DECIMAL(10,2) NULL,
    downtime_minutes     SMALLINT UNSIGNED NULL,
    result               ENUM('Completed','Partial','Failed','Deferred') NOT NULL DEFAULT 'Completed',
    condition_after      ENUM('Excellent','Good','Fair','Poor','Out of Service') NULL,
    next_due_date        DATE         NULL COMMENT 'Copied onto the schedule when the job is logged',
    notes                TEXT         NULL,
    created_by           INT UNSIGNED NULL,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_maint_log_asset (asset_id, performed_on),
    KEY idx_maint_log_schedule (schedule_id),
    KEY idx_maint_log_performed_on (performed_on),
    KEY idx_maint_log_created (created_at),
    CONSTRAINT fk_maint_log_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_maint_log_schedule
        FOREIGN KEY (schedule_id) REFERENCES maintenance_schedules (id) ON DELETE SET NULL,
    CONSTRAINT fk_maint_log_user
        FOREIGN KEY (performed_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_maint_log_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS maintenance_log_documents (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    maintenance_log_id BIGINT UNSIGNED NOT NULL,
    title              VARCHAR(191) NOT NULL COMMENT 'Falls back to the file name when nothing is typed',
    file_path          VARCHAR(255) NOT NULL COMMENT 'Relative to the uploads root; never an absolute path',
    original_filename  VARCHAR(255) NULL COMMENT 'As supplied by the browser, for display only',
    mime_type          VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    file_size_bytes    INT UNSIGNED NOT NULL DEFAULT 0,
    notes              VARCHAR(255) NULL,
    uploaded_by        INT UNSIGNED NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_maintenance_log_documents_log (maintenance_log_id, created_at),
    CONSTRAINT fk_maintenance_log_documents_log
        FOREIGN KEY (maintenance_log_id) REFERENCES maintenance_logs (id) ON DELETE CASCADE,
    CONSTRAINT fk_maintenance_log_documents_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- PAT testing ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pat_records (
    id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id                    BIGINT UNSIGNED NOT NULL,
    test_date                   DATE         NOT NULL,
    retest_due_date             DATE         NULL COMMENT 'Next test due',
    tester_user_id              INT UNSIGNED NULL COMMENT 'Application user who performed the test, if internal',
    tester_name                 VARCHAR(191) NULL COMMENT 'Name of the tester (internal or contractor)',
    tester_reference            VARCHAR(100) NULL COMMENT 'Tester ID / competency reference',
    test_equipment              VARCHAR(191) NULL COMMENT 'PAT tester make/model and serial',
    appliance_class             ENUM('Class I','Class II','Class III','Not Applicable') NOT NULL DEFAULT 'Class I',
    visual_inspection_pass      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = pass, 0 = fail',
    visual_plug_pass            TINYINT(1)   NULL COMMENT 'Plug: condition, correct wiring, no damage',
    visual_cable_pass           TINYINT(1)   NULL COMMENT 'Cable: no cuts, fraying or unsafe repairs',
    visual_case_pass            TINYINT(1)   NULL COMMENT 'Case/enclosure: no cracks or damage',
    visual_fuse_pass            TINYINT(1)   NULL COMMENT 'Fuse fitted matches the rating recorded on the asset; NULL when unfused',
    earth_continuity_ohms       DECIMAL(7,3) NULL COMMENT 'Earth continuity resistance in Ohms; Class I only',
    earth_continuity_pass       TINYINT(1)   NULL COMMENT 'Tester''s verdict on the earth continuity reading; NULL unless Class I',
    extension_lead_metres       DECIMAL(6,2) NULL COMMENT 'Extra lead length under test; raises the earth continuity guideline',
    insulation_resistance_mohms DECIMAL(8,2) NULL COMMENT 'Insulation resistance in Megohms',
    insulation_resistance_pass  TINYINT(1)   NULL COMMENT 'Tester''s verdict on the insulation reading',
    leakage_current_ma          DECIMAL(7,3) NULL COMMENT 'Leakage current in milliamps',
    leakage_current_pass        TINYINT(1)   NULL COMMENT 'Tester''s verdict on the leakage reading',
    load_test_va                DECIMAL(9,2) NULL COMMENT 'Load/power measurement in volt-amps',
    polarity_pass               TINYINT(1)   NULL COMMENT 'Lead polarity check, where applicable',
    functional_check_pass       TINYINT(1)   NULL COMMENT '1 = pass, 0 = fail, NULL = not performed',
    overall_result              ENUM('Pass','Fail') NOT NULL,
    pat_label_serial            VARCHAR(100) NULL COMMENT 'Serial printed on the PAT label applied to the item',
    fuse_fitted_amps            DECIMAL(5,2) NULL COMMENT 'Fuse found/fitted at test time, in Amps',
    remedial_action             TEXT         NULL COMMENT 'What was done when the item failed',
    notes                       TEXT         NULL,
    created_by                  INT UNSIGNED NULL,
    created_at                  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pat_asset_date (asset_id, test_date),
    KEY idx_pat_retest_due (retest_due_date),
    KEY idx_pat_result (overall_result),
    KEY idx_pat_label (pat_label_serial),
    KEY idx_pat_asset_latest (asset_id, test_date, id),
    CONSTRAINT fk_pat_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_pat_tester
        FOREIGN KEY (tester_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_pat_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Hires ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS hirers (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    hirer_type   ENUM('Person','Company') NOT NULL DEFAULT 'Person',
    name         VARCHAR(191) NOT NULL COMMENT 'Person name, or trading name for a company',
    company_name VARCHAR(191) NULL COMMENT 'Employer or parent company when the hirer is a person',
    reference    VARCHAR(64)  NULL COMMENT 'Staff number, account number, membership id',
    email        VARCHAR(190) NULL,
    phone        VARCHAR(50)  NULL,
    address      TEXT         NULL,
    user_id      INT UNSIGNED NULL COMMENT 'Optional link to a sign-in account with the Hirer role',
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    notes        TEXT         NULL,
    created_by   INT UNSIGNED NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hirers_user (user_id),
    KEY idx_hirers_name (name),
    KEY idx_hirers_type (hirer_type),
    KEY idx_hirers_reference (reference),
    CONSTRAINT fk_hirers_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_hirers_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hires (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference                VARCHAR(40)  NULL COMMENT 'Human-friendly hire reference',
    asset_id                 BIGINT UNSIGNED NOT NULL,
    hirer_id                 INT UNSIGNED NOT NULL,
    checked_out_at           DATETIME     NOT NULL,
    due_back_date            DATE         NOT NULL,
    checked_out_by_user_id   INT UNSIGNED NULL COMMENT 'Staff member who issued the item',
    condition_out            ENUM('Excellent','Good','Fair','Poor','Out of Service') NULL,
    returned_at              DATETIME     NULL,
    returned_to_user_id      INT UNSIGNED NULL COMMENT 'Staff member who accepted the return',
    condition_in             ENUM('Excellent','Good','Fair','Poor','Out of Service') NULL,
    returned_condition_notes TEXT         NULL,
    status                   ENUM('Out','Overdue','Returned') NOT NULL DEFAULT 'Out',
    purpose                  VARCHAR(255) NULL,
    hire_charge              DECIMAL(10,2) NULL,
    notes                    TEXT         NULL,
    created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hires_reference (reference),
    KEY idx_hires_asset (asset_id, status),
    KEY idx_hires_status_due (status, due_back_date),
    KEY idx_hires_due (due_back_date),
    KEY idx_hires_open (asset_id, returned_at),
    KEY idx_hires_hirer (hirer_id, status),
    CONSTRAINT fk_hires_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id),
    CONSTRAINT fk_hires_hirer
        FOREIGN KEY (hirer_id) REFERENCES hirers (id),
    CONSTRAINT fk_hires_checked_out_by
        FOREIGN KEY (checked_out_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_hires_returned_to
        FOREIGN KEY (returned_to_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hire_photos (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    hire_id           BIGINT UNSIGNED NOT NULL,
    stage             ENUM('out','in') NOT NULL DEFAULT 'in' COMMENT 'Taken at checkout or at return',
    file_path         VARCHAR(255) NOT NULL COMMENT 'Path relative to the uploads root',
    original_filename VARCHAR(255) NULL,
    mime_type         VARCHAR(100) NOT NULL,
    file_size_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
    caption           VARCHAR(255) NULL,
    uploaded_by       INT UNSIGNED NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hire_photos_hire (hire_id, stage),
    CONSTRAINT fk_hire_photos_hire
        FOREIGN KEY (hire_id) REFERENCES hires (id) ON DELETE CASCADE,
    CONSTRAINT fk_hire_photos_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Faults -----------------------------------------------------------------

CREATE TABLE IF NOT EXISTS fault_reports (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id         BIGINT UNSIGNED NOT NULL,
    description      TEXT         NOT NULL COMMENT 'What is wrong, in the reporter''s words',
    faulty_on        DATE         NOT NULL COMMENT 'When the fault was noticed, which may be before the report',
    urgency          ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium' COMMENT 'How badly this fault needs fixing',
    condition_rating ENUM('Excellent','Good','Fair','Poor','Out of Service') NOT NULL COMMENT 'The asset''s condition as judged at the time of the report',
    reported_by      INT UNSIGNED NULL,
    reported_by_name VARCHAR(191) NOT NULL COMMENT 'Snapshot, so the report outlives the account',
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fault_reports_asset (asset_id, faulty_on),
    KEY idx_fault_reports_latest (asset_id, created_at),
    KEY idx_fault_reports_urgency (urgency),
    CONSTRAINT fk_fault_reports_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_fault_reports_reported_by
        FOREIGN KEY (reported_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fault_report_photos (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fault_report_id   BIGINT UNSIGNED NOT NULL,
    file_path         VARCHAR(255) NOT NULL COMMENT 'Relative to the uploads root; never an absolute path',
    original_filename VARCHAR(255) NULL COMMENT 'As supplied by the browser, for display only',
    mime_type         VARCHAR(100) NOT NULL,
    file_size_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
    caption           VARCHAR(255) NULL,
    uploaded_by       INT UNSIGNED NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fault_photos_report (fault_report_id, created_at),
    CONSTRAINT fk_fault_photos_report
        FOREIGN KEY (fault_report_id) REFERENCES fault_reports (id) ON DELETE CASCADE,
    CONSTRAINT fk_fault_photos_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Audit trail and settings -----------------------------------------------

CREATE TABLE IF NOT EXISTS activity_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL,
    user_name   VARCHAR(191) NOT NULL DEFAULT 'System',
    action      VARCHAR(100) NOT NULL COMMENT 'created, updated, deleted, login, checked_out, ...',
    entity_type VARCHAR(64)  NOT NULL COMMENT 'asset, user, hire, pat_record, maintenance_log, ...',
    entity_id   BIGINT UNSIGNED NULL,
    description VARCHAR(500) NULL,
    changes     JSON         NULL COMMENT 'Field-level before/after payload',
    ip_address  VARCHAR(45)  NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_entity (entity_type, entity_id, created_at),
    KEY idx_activity_user (user_id, created_at),
    KEY idx_activity_created (created_at),
    CONSTRAINT fk_activity_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(100) NOT NULL,
    setting_value TEXT         NULL,
    updated_by    INT UNSIGNED NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_settings_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Email ------------------------------------------------------------------

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

CREATE TABLE IF NOT EXISTS email_log (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient      VARCHAR(190) NOT NULL,
    recipient_name VARCHAR(191) NULL,
    subject        VARCHAR(255) NOT NULL,
    template_key   VARCHAR(60)  NULL COMMENT 'NULL for a one-off message such as the SMTP test',
    entity_type    VARCHAR(64)  NULL COMMENT 'asset | hire | hirer | maintenance_schedule ...',
    entity_id      BIGINT UNSIGNED NULL,
    status         ENUM('sent','failed') NOT NULL,
    error          VARCHAR(500) NULL COMMENT 'SMTP or configuration failure, for diagnosis',
    trigger_source ENUM('system','user') NOT NULL DEFAULT 'system',
    user_id        INT UNSIGNED NULL,
    user_name      VARCHAR(191) NOT NULL DEFAULT 'System',
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_email_log_created (created_at),
    KEY idx_email_log_status (status, created_at),
    KEY idx_email_log_template (template_key, created_at),
    KEY idx_email_log_entity (entity_type, entity_id),
    CONSTRAINT fk_email_log_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_reminders (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reminder_key VARCHAR(40)  NOT NULL COMMENT 'pat_due | pat_overdue | maintenance_due | ...',
    entity_type  VARCHAR(64)  NOT NULL,
    entity_id    BIGINT UNSIGNED NOT NULL,
    recipient    VARCHAR(190) NOT NULL,
    last_sent_at DATETIME     NOT NULL,
    send_count   INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_reminders (reminder_key, entity_type, entity_id, recipient),
    KEY idx_email_reminders_sent (last_sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Custom reports and API keys --------------------------------------------

CREATE TABLE IF NOT EXISTS custom_reports (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_key     VARCHAR(80)  NOT NULL,
    name           VARCHAR(120) NOT NULL,
    description    VARCHAR(500) NULL,
    data_source    VARCHAR(40)  NOT NULL,
    filters        JSON         NOT NULL,
    columns        JSON         NOT NULL,
    sort_column    VARCHAR(64)  NULL COMMENT 'One of the chosen columns; NULL = the source default',
    sort_direction ENUM('asc','desc') NOT NULL DEFAULT 'asc',
    is_active      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = hidden from the index without being deleted',
    created_by     INT UNSIGNED NULL,
    updated_by     INT UNSIGNED NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_custom_reports_key (report_key),
    KEY idx_custom_reports_active (is_active, name),
    CONSTRAINT fk_custom_reports_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_custom_reports_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                   VARCHAR(120) NOT NULL COMMENT 'What it is for, e.g. “Stores dashboard”',
    user_id                INT UNSIGNED NOT NULL,
    token_prefix           CHAR(12)     NOT NULL COMMENT 'Clear, for display: identifies a key without revealing it',
    token_hash             CHAR(64)     NOT NULL COMMENT 'SHA-256 of the whole key',
    scope                  ENUM('read','full') NOT NULL DEFAULT 'read' COMMENT 'read = GET only, whatever the owner may otherwise do',
    expires_at             DATETIME     NULL COMMENT 'NULL = no expiry',
    revoked_at             DATETIME     NULL COMMENT 'NULL = still usable',
    last_used_at           DATETIME     NULL,
    last_used_ip           VARCHAR(45)  NULL,
    request_count          BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rate_window_started_at DATETIME     NULL,
    rate_count             INT UNSIGNED NOT NULL DEFAULT 0,
    created_by             INT UNSIGNED NULL,
    created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_keys_hash (token_hash),
    KEY idx_api_keys_user (user_id),
    KEY idx_api_keys_active (revoked_at, expires_at),
    CONSTRAINT fk_api_keys_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_api_keys_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
