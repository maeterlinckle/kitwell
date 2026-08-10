-- ---------------------------------------------------------------------------
-- 016 Guideline pass ranges for the electrical tests
--
-- These are shown to the tester as helper text next to each reading. They are
-- GUIDANCE, not a rule: acceptable values vary by appliance, and the tester's
-- own pass/fail toggle is what decides the result. Nothing in the application
-- compares a reading against these to set a result automatically.
--
-- They live in settings rather than in the templates so a workshop can tune
-- them to its own policy without a code change.
--
--   pat_guide_insulation_mohm      typical minimum insulation resistance (MΩ)
--   pat_guide_earth_base_ohm       typical maximum for the appliance alone (Ω)
--   pat_guide_earth_lead_ohm       allowance added per length of extra lead (Ω)
--   pat_guide_earth_lead_metres    the length that allowance covers (m)
--   pat_guide_leakage_class1_ma    typical maximum leakage, Class I (mA)
--   pat_guide_leakage_class2_ma    typical maximum leakage, Class II (mA)
--
-- The earth continuity guideline shown is therefore:
--   base + (extension lead length / lead_metres) * lead_ohm
-- which for the defaults is 0.1 Ω plus 0.1 Ω for every 7.5 m of extra lead.
-- ---------------------------------------------------------------------------

INSERT INTO settings (setting_key, setting_value) VALUES
    ('pat_guide_insulation_mohm',   '1'),
    ('pat_guide_earth_base_ohm',    '0.1'),
    ('pat_guide_earth_lead_ohm',    '0.1'),
    ('pat_guide_earth_lead_metres', '7.5'),
    ('pat_guide_leakage_class1_ma', '3.5'),
    ('pat_guide_leakage_class2_ma', '0.25')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
