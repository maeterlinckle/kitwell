-- ---------------------------------------------------------------------------
-- 005 PAT (Portable Appliance Testing) records
--
-- One row per test, so every asset keeps a full testing history rather than
-- just the latest result. Relevant where assets.requires_pat = 1.
--
-- Measurement units are explicit in the column names:
--   earth_continuity_ohms        Ohms         (Ohm)
--   insulation_resistance_mohms  Megohms      (MOhm)
--   leakage_current_ma           milliamps    (mA)
--   load_test_va                 volt-amps    (VA)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pat_records (
    id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id                    BIGINT UNSIGNED NOT NULL,
    test_date                   DATE          NOT NULL,
    retest_due_date             DATE          NULL COMMENT 'Next test due',
    tester_user_id              INT UNSIGNED  NULL COMMENT 'Application user who performed the test, if internal',
    tester_name                 VARCHAR(191)  NULL COMMENT 'Name of the tester (internal or contractor)',
    tester_reference            VARCHAR(100)  NULL COMMENT 'Tester ID / competency reference',
    test_equipment              VARCHAR(191)  NULL COMMENT 'PAT tester make/model and serial',
    appliance_class             ENUM('Class I','Class II','Class III','Not Applicable') NOT NULL DEFAULT 'Class I',
    visual_inspection_pass      TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '1 = pass, 0 = fail',
    earth_continuity_ohms       DECIMAL(7,3)  NULL COMMENT 'Earth continuity resistance in Ohms; Class I only',
    insulation_resistance_mohms DECIMAL(8,2)  NULL COMMENT 'Insulation resistance in Megohms',
    leakage_current_ma          DECIMAL(7,3)  NULL COMMENT 'Leakage current in milliamps',
    load_test_va                DECIMAL(9,2)  NULL COMMENT 'Load/power measurement in volt-amps',
    polarity_pass               TINYINT(1)    NULL COMMENT 'Lead polarity check, where applicable',
    functional_check_pass       TINYINT(1)    NULL COMMENT '1 = pass, 0 = fail, NULL = not performed',
    overall_result              ENUM('Pass','Fail') NOT NULL,
    pat_label_serial            VARCHAR(100)  NULL COMMENT 'Serial printed on the PAT label applied to the item',
    fuse_fitted_amps            DECIMAL(5,2)  NULL COMMENT 'Fuse found/fitted at test time, in Amps',
    remedial_action             TEXT          NULL COMMENT 'What was done when the item failed',
    notes                       TEXT          NULL,
    created_by                  INT UNSIGNED  NULL,
    created_at                  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pat_asset_date (asset_id, test_date),
    KEY idx_pat_retest_due (retest_due_date),
    KEY idx_pat_result (overall_result),
    KEY idx_pat_label (pat_label_serial),
    CONSTRAINT fk_pat_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_pat_tester
        FOREIGN KEY (tester_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_pat_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
