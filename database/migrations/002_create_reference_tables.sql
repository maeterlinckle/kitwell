-- ---------------------------------------------------------------------------
-- 002 Reference data: categories and locations
--
-- Both are self-referencing so they can be nested (e.g. Workshop > Bench 3)
-- without needing another table later.
-- ---------------------------------------------------------------------------

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
