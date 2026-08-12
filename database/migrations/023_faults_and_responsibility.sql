-- ---------------------------------------------------------------------------
-- 023 Faults, and who is responsible for an asset
--
-- Two related ideas, in one migration because neither is much use alone:
--
--   1. An asset can name a *responsible party* — one person, or one team.
--   2. An asset can be reported *faulty*, and each report is kept.
--
-- The point of the pair is the notification: reporting a fault tells whoever
-- owns the thing, immediately, and again in a digest until it is dealt with.
-- An asset with nobody named simply tells nobody. That is not a failure state
-- to be papered over with a fallback recipient — mail sent to "whoever is
-- around" is mail everybody learns to ignore.
--
-- Shaped after the maintenance assignment (020) and the maintenance log (004):
-- the same two-nullable-column pattern for "a person OR a team", and the same
-- record-plus-photos pattern for evidence. Both are deliberate copies. A
-- second, subtly different way of saying "assigned to" is how a reminder ends
-- up going to the wrong half of the workshop.
-- ---------------------------------------------------------------------------

-- --- Who is responsible ------------------------------------------------------
-- Two nullable columns rather than a polymorphic (type, id) pair, exactly as
-- maintenance_schedules does it, and for the same reason: both sides are real
-- foreign keys this way, so deleting a team cannot leave an asset pointing at
-- a group that no longer exists.
--
-- The application never writes both — see AssetController::validateAsset(),
-- which stores a single "user:7" / "team:2" form value. SET NULL on both, so
-- removing a user or a team leaves the asset unassigned rather than blocking
-- the delete: an asset must not become impossible to tidy up because of who
-- used to look after it.
ALTER TABLE assets
    ADD COLUMN responsible_user_id INT UNSIGNED NULL
        COMMENT 'Mutually exclusive with responsible_team_id' AFTER location_id,
    ADD COLUMN responsible_team_id INT UNSIGNED NULL
        COMMENT 'Mutually exclusive with responsible_user_id' AFTER responsible_user_id,
    ADD KEY idx_assets_responsible_user (responsible_user_id),
    ADD KEY idx_assets_responsible_team (responsible_team_id),
    ADD CONSTRAINT fk_assets_responsible_user
        FOREIGN KEY (responsible_user_id) REFERENCES users (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_assets_responsible_team
        FOREIGN KEY (responsible_team_id) REFERENCES teams (id) ON DELETE SET NULL;

-- --- The new status ----------------------------------------------------------
-- Appended, not inserted in its natural reading position after 'In Maintenance'.
-- Adding an ENUM member at the end is an in-place, instant change with no
-- re-mapping of existing rows; putting one in the middle forces a table copy
-- and renumbers every member after it. There is no reason to take that risk
-- over presentation order, so the *display* order lives in PHP instead:
-- App\Models\Asset::STATUSES lists it where a human expects it, and the
-- register's "sort by status" uses an explicit FIELD() exactly as the
-- condition sort already does.
ALTER TABLE assets
    MODIFY COLUMN status ENUM('In Stock','On Hire','In Maintenance','Retired','Faulty')
        NOT NULL DEFAULT 'In Stock';

-- --- Fault reports -----------------------------------------------------------
-- A record per report, not a flag on the asset.
--
-- The same argument as PAT and maintenance: an asset that has been faulty three
-- times in a year is telling you something, and a status column that gets
-- overwritten each time cannot say it. `assets.status` answers "is it faulty
-- now?"; this table answers "what has gone wrong with it, and how often?".
--
-- condition_rating is a snapshot taken at the moment of the report. The asset's
-- own condition moves on — maintenance writes to it, and so does the next fault
-- — so copying it here is not duplication; it is the difference between "it was
-- Poor when this was reported" and "it is Poor today".
--
-- reported_by_name is likewise a snapshot, following maintenance_logs and
-- email_log: the report should still say who raised it after the account has
-- gone.
CREATE TABLE IF NOT EXISTS fault_reports (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id         BIGINT UNSIGNED NOT NULL,
    description      TEXT         NOT NULL COMMENT 'What is wrong, in the reporter’s words',
    faulty_on        DATE         NOT NULL COMMENT 'When the fault was noticed, which may be before the report',
    urgency          ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium'
                         COMMENT 'How badly it needs fixing — specific to this report, not to the asset',
    condition_rating ENUM('Excellent','Good','Fair','Poor','Out of Service') NOT NULL
                         COMMENT 'The asset’s condition as judged at the time of the report',
    reported_by      INT UNSIGNED NULL,
    reported_by_name VARCHAR(191) NOT NULL COMMENT 'Snapshot, so the report outlives the account',
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fault_reports_asset (asset_id, faulty_on),
    KEY idx_fault_reports_latest (asset_id, created_at),
    KEY idx_fault_reports_urgency (urgency),
    CONSTRAINT fk_fault_reports_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_fault_reports_reported_by
        FOREIGN KEY (reported_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Evidence. A straight copy of maintenance_log_photos, hanging off a fault
-- report instead of a completion: the file on disk under
-- storage/uploads/faults/{fault_report_id}/, only the relative path in here.
--
-- Not asset_photos, even though these are photographs of an asset. A condition
-- photo is part of the asset's ongoing record and can be made the thumbnail the
-- register shows; a fault photo belongs to the report that explains it, and
-- filing it against the asset would lose which fault it was evidence of.
CREATE TABLE IF NOT EXISTS fault_report_photos (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fault_report_id   BIGINT UNSIGNED NOT NULL,
    file_path         VARCHAR(255) NOT NULL COMMENT 'Relative to the uploads root; never an absolute path',
    original_filename VARCHAR(255) NULL COMMENT 'As supplied by the browser, for display only',
    mime_type         VARCHAR(100) NOT NULL,
    file_size_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
    caption           VARCHAR(255) NULL,
    uploaded_by       INT UNSIGNED NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fault_photos_report (fault_report_id, created_at),
    CONSTRAINT fk_fault_photos_report
        FOREIGN KEY (fault_report_id) REFERENCES fault_reports (id) ON DELETE CASCADE,
    CONSTRAINT fk_fault_photos_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Permission --------------------------------------------------------------
-- Its own permission rather than reusing assets.edit, because the two are not
-- the same act. Reporting a fault is something the person holding the broken
-- thing does; editing an asset is a records job. An installation that wants
-- fitters to be able to say "this is broken" without letting them rewrite
-- purchase costs can now do that.
--
-- It is still a change to the register — it moves the asset's status — so it is
-- not given to the read-only role.
INSERT INTO permissions (slug, name, group_name, description, sort_order) VALUES
    ('faults.report', 'Report faults', 'Assets', 'Mark an asset as faulty and record what is wrong with it.', 35)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    group_name = VALUES(group_name),
    description = VALUES(description),
    sort_order = VALUES(sort_order);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.slug = 'faults.report'
 WHERE r.slug IN ('admin', 'manager');

-- --- Settings ----------------------------------------------------------------
-- The faulty digest joins the other three reminder types and is off until
-- somebody turns it on, like all of them.
--
-- It has no "days before due" window because a fault has no due date: it is
-- open or it is not, and something broken three months ago is more worth
-- mentioning than something broken yesterday, not less. What it has instead is
-- its own repeat interval, following the same 0-means-use-the-site-default
-- convention the day windows use — a Critical fault may deserve chasing daily
-- while the shared reminder_repeat_days stays at a week.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('reminder_faulty_enabled',      '0'),
    ('reminder_faulty_repeat_days',  '0'),

    -- Send the immediate notification the moment a fault is reported. Separate
    -- from the digest: an installation may reasonably want one and not the
    -- other, and this one is not on the cron path at all.
    ('fault_notify_immediately',     '1');
