-- ---------------------------------------------------------------------------
-- 003 Assets (including sub-assets, accessories and related items)
--
-- Sub-assets carry the same fields as a top-level asset, so they live in this
-- same table and point at their parent via parent_asset_id. relationship_type
-- says how the child relates to the parent. A row with a NULL parent_asset_id
-- is a top-level asset.
--
-- Units are explicit in the column names: _amps, _mm2.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS assets (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_tag             VARCHAR(64)  NOT NULL COMMENT 'Printed/scanned tag - the primary barcode value',
    barcode               VARCHAR(64)  NULL COMMENT 'Optional secondary/manufacturer barcode',
    name                  VARCHAR(191) NOT NULL,
    description           TEXT         NULL,
    category_id           INT UNSIGNED NULL,
    location_id           INT UNSIGNED NULL,
    condition_rating      ENUM('Excellent','Good','Fair','Poor','Out of Service') NOT NULL DEFAULT 'Good',
    status                ENUM('In Stock','On Loan','In Maintenance','Retired')   NOT NULL DEFAULT 'In Stock',
    purchase_date         DATE           NULL,
    purchase_cost         DECIMAL(12,2)  NULL COMMENT 'Purchase price in the configured currency',
    current_value         DECIMAL(12,2)  NULL COMMENT 'Current/replacement value',
    supplier              VARCHAR(191)   NULL,
    warranty_expires_on   DATE           NULL,
    serial_number         VARCHAR(191)   NULL,
    manufacturer          VARCHAR(191)   NULL,
    model                 VARCHAR(191)   NULL,
    manufacturer_url      VARCHAR(500)   NULL COMMENT 'Product/support page',
    plug_fuse_rating_amps DECIMAL(5,2)   NULL COMMENT 'Plug fuse rating in Amps (A), e.g. 3.00, 5.00, 13.00',
    cable_csa_mm2         DECIMAL(5,2)   NULL COMMENT 'Cable cross-sectional area in square millimetres (mm2)',
    requires_pat          TINYINT(1)     NOT NULL DEFAULT 0 COMMENT '1 = portable appliance testing applies',
    pat_interval_months   SMALLINT UNSIGNED NULL COMMENT 'Retest interval in months; NULL = use site default',
    parent_asset_id       BIGINT UNSIGNED NULL COMMENT 'Set on sub-assets/accessories/related items',
    relationship_type     ENUM('sub-asset','accessory','related') NULL COMMENT 'Only meaningful when parent_asset_id is set',
    is_loanable           TINYINT(1)     NOT NULL DEFAULT 1 COMMENT '0 = never goes out on loan',
    notes                 TEXT           NULL,
    retired_on            DATE           NULL,
    created_by            INT UNSIGNED   NULL,
    updated_by            INT UNSIGNED   NULL,
    created_at            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
    CONSTRAINT fk_assets_category
        FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_location
        FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_parent
        FOREIGN KEY (parent_asset_id) REFERENCES assets (id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Photos of an asset over time. Files live on disk under
-- storage/uploads/assets/{asset_id}/photos/ ; only the path is stored here.
CREATE TABLE IF NOT EXISTS asset_photos (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id          BIGINT UNSIGNED NOT NULL,
    file_path         VARCHAR(255) NOT NULL COMMENT 'Path relative to the uploads root',
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
    CONSTRAINT fk_asset_photos_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_photos_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PDF manuals/datasheets, stored on disk the same way as photos.
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
