-- ---------------------------------------------------------------------------
-- 015 Store the full step-by-step breakdown of a PAT test
--
-- The old record kept one `visual_inspection_pass` flag and the measured
-- values. That answers "did it pass?" but not "what was actually checked?" —
-- so a later dispute, or an insurer, could not see whether the cable was
-- inspected or the fuse verified.
--
-- The guided flow asks each check separately, so each is stored separately.
-- Every column is nullable: a check that does not apply to an appliance (the
-- fuse check on an unfused item, earth continuity on Class II) is NULL, which
-- is meaningfully different from a recorded fail.
--
--   1 = pass, 0 = fail, NULL = not applicable / not performed
--
-- `visual_inspection_pass` is kept and is now derived: it is 0 if any of the
-- individual visual checks failed. Existing screens, reports and the CSV
-- importer keep working unchanged.
-- ---------------------------------------------------------------------------

ALTER TABLE pat_records
    ADD COLUMN visual_plug_pass TINYINT(1) NULL
        COMMENT 'Plug: condition, correct wiring, no damage'
        AFTER visual_inspection_pass,
    ADD COLUMN visual_cable_pass TINYINT(1) NULL
        COMMENT 'Cable: no cuts, fraying or unsafe repairs'
        AFTER visual_plug_pass,
    ADD COLUMN visual_case_pass TINYINT(1) NULL
        COMMENT 'Case/enclosure: no cracks or damage'
        AFTER visual_cable_pass,
    ADD COLUMN visual_fuse_pass TINYINT(1) NULL
        COMMENT 'Fuse fitted matches the rating recorded on the asset; NULL when unfused'
        AFTER visual_case_pass,
    ADD COLUMN earth_continuity_pass TINYINT(1) NULL
        COMMENT "Tester's verdict on the earth continuity reading; NULL unless Class I"
        AFTER earth_continuity_ohms,
    ADD COLUMN insulation_resistance_pass TINYINT(1) NULL
        COMMENT "Tester's verdict on the insulation reading"
        AFTER insulation_resistance_mohms,
    ADD COLUMN leakage_current_pass TINYINT(1) NULL
        COMMENT "Tester's verdict on the leakage reading"
        AFTER leakage_current_ma,
    ADD COLUMN extension_lead_metres DECIMAL(6,2) NULL
        COMMENT 'Extra lead length under test; raises the earth continuity guideline'
        AFTER earth_continuity_pass;

-- Historic records predate the per-step breakdown. Rather than leave every old
-- row entirely blank, carry the one flag that did exist onto the visual checks
-- it stood for. Electrical verdicts are NOT invented: a reading was stored but
-- no per-test verdict ever was, so those stay NULL and the screens say
-- "not recorded" rather than claiming a pass nobody gave.
UPDATE pat_records
   SET visual_plug_pass  = visual_inspection_pass,
       visual_cable_pass = visual_inspection_pass,
       visual_case_pass  = visual_inspection_pass
 WHERE visual_plug_pass IS NULL;
