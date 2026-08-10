-- ---------------------------------------------------------------------------
-- 014 Move the fixed electrical values from the PAT record onto the asset
--
-- Appliance class, load rating and the fuse arrangement are properties of the
-- appliance, not of any one test. Re-entering them at every test invited drift
-- (the same item tested as Class I one year and Class II the next) and made the
-- tester answer questions the register already knew the answer to.
--
-- They live on the asset from here. pat_records keeps its own appliance_class
-- column as a SNAPSHOT of what the asset was at the time of the test, so old
-- records stay meaningful and the audit trail is not rewritten.
--
--   appliance_class    Class I / Class II / Class III / Not Applicable
--   load_rating_va     rated load in volt-amps (VA)
--   has_fuse           does the plug carry a fuse at all
--   plug_fuse_rating_amps  already exists; now constrained to 3/5/10/13 A in
--                          the application rather than free numeric entry.
--                          The column type is deliberately left alone so no
--                          existing value is destroyed by this migration.
-- ---------------------------------------------------------------------------

ALTER TABLE assets
    ADD COLUMN appliance_class ENUM('Class I','Class II','Class III','Not Applicable') NULL
        COMMENT 'Fixed property of the appliance; NULL = not yet established'
        AFTER requires_pat,
    ADD COLUMN load_rating_va DECIMAL(9,2) NULL
        COMMENT 'Rated load in volt-amps'
        AFTER appliance_class,
    ADD COLUMN has_fuse TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = the plug carries a fuse, so the fuse check applies'
        AFTER load_rating_va;

CREATE INDEX idx_assets_appliance_class ON assets (appliance_class);

-- --- Best-effort backfill from the most recent test ------------------------
-- Anything that cannot be inferred is left NULL rather than guessed. List the
-- gaps afterwards with:  php bin/console.php pat:missing-details

UPDATE assets a
   SET a.appliance_class = (
        SELECT p.appliance_class
          FROM pat_records p
         WHERE p.asset_id = a.id
         ORDER BY p.test_date DESC, p.id DESC
         LIMIT 1
   )
 WHERE a.appliance_class IS NULL
   AND EXISTS (SELECT 1 FROM pat_records p2 WHERE p2.asset_id = a.id);

UPDATE assets a
   SET a.load_rating_va = (
        SELECT p.load_test_va
          FROM pat_records p
         WHERE p.asset_id = a.id
           AND p.load_test_va IS NOT NULL
         ORDER BY p.test_date DESC, p.id DESC
         LIMIT 1
   )
 WHERE a.load_rating_va IS NULL
   AND EXISTS (SELECT 1 FROM pat_records p2 WHERE p2.asset_id = a.id AND p2.load_test_va IS NOT NULL);

-- A recorded plug fuse rating, or a fuse seen at any past test, means fused.
UPDATE assets a
   SET a.has_fuse = 1
 WHERE a.has_fuse = 0
   AND (
        a.plug_fuse_rating_amps IS NOT NULL
     OR EXISTS (SELECT 1 FROM pat_records p WHERE p.asset_id = a.id AND p.fuse_fitted_amps IS NOT NULL)
   );

-- Class II appliances are double-insulated; a fuse rating recorded against one
-- is usually a data-entry slip, but it is left in place rather than deleted.
