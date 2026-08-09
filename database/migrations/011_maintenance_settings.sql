-- ---------------------------------------------------------------------------
-- 011 Maintenance settings
--
-- maintenance_due_days is the "due soon" horizon used by the dashboard, the
-- maintenance list and (from stage 7) the reports module. One setting, one
-- meaning, so every screen agrees on what "due soon" is.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('maintenance_due_days', '30');

-- Completions are queried by date for history and reporting.
ALTER TABLE maintenance_logs
    ADD KEY idx_maint_log_created (created_at);
