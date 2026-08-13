-- ---------------------------------------------------------------------------
-- 004 Shared media library and asset templates
--
-- A photo or document that is genuinely the same for every unit of a model —
-- a manufacturer's stock photo, a manual — is held once in `media_library` and
-- attached to as many assets as need it through `asset_media`. Attaching one to
-- a second asset writes a join row and nothing else.
--
-- This covers generic media only. Condition photos (`asset_photos`), PAT,
-- fault and maintenance evidence stay where they are: each of those records the
-- state of one physical item at one moment, and belongs to that record alone.
--
-- The manuals that were held per-asset become library items attached to the
-- asset that had them, keeping their files exactly where they are.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS media_library (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    media_type        ENUM('photo','document') NOT NULL,
    title             VARCHAR(191) NOT NULL,
    description       VARCHAR(500) NULL,
    file_path         VARCHAR(255) NOT NULL COMMENT 'Relative to the uploads root; never an absolute path',
    original_filename VARCHAR(255) NULL COMMENT 'As supplied by the browser, for display and downloads',
    mime_type         VARCHAR(100) NOT NULL,
    file_size_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
    file_hash         CHAR(64) NULL COMMENT 'SHA-256 of the contents; an upload matching one is attached rather than stored again',
    thumbnail_path    VARCHAR(255) NULL COMMENT 'Photos only; NULL when no thumbnail could be made',
    width_px          SMALLINT UNSIGNED NULL,
    height_px         SMALLINT UNSIGNED NULL,
    uploaded_by       INT UNSIGNED NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_media_library_hash (file_hash),
    KEY idx_media_library_type (media_type, title),
    KEY idx_media_library_created (created_at),
    CONSTRAINT fk_media_library_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_media (
    asset_id    BIGINT UNSIGNED NOT NULL,
    media_id    BIGINT UNSIGNED NOT NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    attached_by INT UNSIGNED NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (asset_id, media_id),
    KEY idx_asset_media_media (media_id),
    KEY idx_asset_media_order (asset_id, sort_order),
    CONSTRAINT fk_asset_media_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_media_media
        FOREIGN KEY (media_id) REFERENCES media_library (id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_media_attached_by
        FOREIGN KEY (attached_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Asset templates --------------------------------------------------------
--
-- Every column here is a default offered on the Add asset form and editable
-- before the asset is created. They are all nullable: NULL means the template
-- has nothing to say about that field, which is why the three flags are
-- TINYINT rather than a NOT NULL boolean.
--
-- `asset_tag` and `barcode` are deliberately absent. They identify one physical
-- item, so they are generated or typed per asset however it was started.

CREATE TABLE IF NOT EXISTS asset_templates (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                  VARCHAR(120) NOT NULL COMMENT 'What the template is called in the picker',
    description           VARCHAR(500) NULL,
    asset_name            VARCHAR(191) NULL COMMENT 'Default for the asset''s own name',
    asset_description     TEXT NULL,
    category_id           INT UNSIGNED NULL,
    location_id           INT UNSIGNED NULL,
    manufacturer          VARCHAR(191) NULL,
    model                 VARCHAR(191) NULL,
    manufacturer_url      VARCHAR(500) NULL,
    supplier              VARCHAR(191) NULL,
    condition_rating      ENUM('Excellent','Good','Fair','Poor','Out of Service') NULL,
    appliance_class       ENUM('Class I','Class II','Class III','Not Applicable') NULL,
    load_rating_va        DECIMAL(9,2) NULL,
    has_fuse              TINYINT(1) NULL,
    plug_fuse_rating_amps DECIMAL(5,2) NULL,
    cable_csa_mm2         DECIMAL(5,2) NULL,
    requires_pat          TINYINT(1) NULL,
    pat_interval_months   SMALLINT UNSIGNED NULL,
    is_hireable           TINYINT(1) NULL,
    notes                 TEXT NULL,
    is_active             TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = kept but not offered on the Add asset form',
    created_by            INT UNSIGNED NULL,
    updated_by            INT UNSIGNED NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_asset_templates_name (name),
    KEY idx_asset_templates_active (is_active, name),
    CONSTRAINT fk_asset_templates_category
        FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_asset_templates_location
        FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE SET NULL,
    CONSTRAINT fk_asset_templates_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_asset_templates_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS template_media (
    template_id INT UNSIGNED NOT NULL,
    media_id    BIGINT UNSIGNED NOT NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (template_id, media_id),
    KEY idx_template_media_media (media_id),
    CONSTRAINT fk_template_media_template
        FOREIGN KEY (template_id) REFERENCES asset_templates (id) ON DELETE CASCADE,
    CONSTRAINT fk_template_media_media
        FOREIGN KEY (media_id) REFERENCES media_library (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Manuals become library items -------------------------------------------
--
-- One library row per manual, then one join row attaching it to the asset it
-- was on. The file itself is not touched. Runs as a no-op on an installation
-- that has no manuals, and on one where the table has already gone.

INSERT INTO media_library
        (id, media_type, title, description, file_path, original_filename,
         mime_type, file_size_bytes, uploaded_by, created_at)
SELECT   m.id, 'document', m.title, m.notes, m.file_path, m.original_filename,
         m.mime_type, m.file_size_bytes, m.uploaded_by, m.created_at
FROM     asset_manuals m
WHERE    NOT EXISTS (SELECT 1 FROM media_library l WHERE l.id = m.id);

INSERT IGNORE INTO asset_media (asset_id, media_id, attached_by, created_at)
SELECT m.asset_id, m.id, m.uploaded_by, m.created_at FROM asset_manuals m;

DROP TABLE IF EXISTS asset_manuals;

-- --- Permission -------------------------------------------------------------
--
-- Templates sit with the other reference data an operation maintains for
-- itself, so this is granted like `categories.manage`: Administrator and
-- Manager / Staff.

INSERT INTO permissions (slug, name, group_name, description, sort_order) VALUES
    ('templates.manage', 'Manage asset templates', 'Administration', 'Create and edit the templates the Add asset form can start from.', 42)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    group_name = VALUES(group_name),
    description = VALUES(description),
    sort_order = VALUES(sort_order);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug IN ('admin', 'manager') AND p.slug = 'templates.manage';
