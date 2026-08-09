-- ---------------------------------------------------------------------------
-- 012 PAT settings
--
-- pat_due_days           how far ahead a retest counts as "due soon"
-- pat_default_interval_months  fallback retest interval when an asset does not
--                        set its own (assets.pat_interval_months)
--
-- Twelve months is the common default for portable equipment, but the correct
-- interval depends on the equipment and its environment — it is a site
-- decision, which is why it is a setting rather than a hard-coded rule.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('pat_due_days',                '30'),
    ('pat_default_interval_months', '12');

-- The PAT list is driven by "latest test per asset", so this covers the lookup.
ALTER TABLE pat_records
    ADD KEY idx_pat_asset_latest (asset_id, test_date, id);
