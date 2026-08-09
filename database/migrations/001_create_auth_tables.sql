-- ---------------------------------------------------------------------------
-- 001 Authentication, roles and permissions
--
-- Permissions are stored as data (roles -> role_permissions -> permissions) so
-- new roles and finer-grained permissions can be added later by inserting rows,
-- never by altering the schema.
-- ---------------------------------------------------------------------------

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
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                VARCHAR(150) NOT NULL,
    email               VARCHAR(190) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL COMMENT 'password_hash(), PASSWORD_DEFAULT',
    role_id             INT UNSIGNED NOT NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    phone               VARCHAR(50)  NULL,
    job_title           VARCHAR(150) NULL,
    last_login_at       DATETIME     NULL,
    last_login_ip       VARCHAR(45)  NULL COMMENT 'IPv4 or IPv6',
    password_changed_at DATETIME     NULL,
    created_by          INT UNSIGNED NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role_id),
    KEY idx_users_active (is_active),
    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT,
    CONSTRAINT fk_users_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed/successful sign-in attempts, used for throttling and lockout.
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

-- Simple key/value application settings (label formats, defaults, etc.).
CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(100) NOT NULL,
    setting_value TEXT         NULL,
    updated_by    INT UNSIGNED NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_settings_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
