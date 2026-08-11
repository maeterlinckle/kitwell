-- ---------------------------------------------------------------------------
-- 020 Teams
--
-- Work is assigned to a person or to a group of people. Until now a maintenance
-- schedule could only name one user, so "the bench fitters do this one" had to
-- be recorded as one fitter, and the reminder went to them alone — which is
-- exactly the case where a job falls through the gap because that person is on
-- holiday.
--
-- Two tables and one column:
--
--   teams          the group itself
--   team_members   the many-to-many; a user may belong to several teams
--   maintenance_schedules.assigned_to_team_id
--
-- The assignment stays *one* thing, not two. A schedule is assigned to a user
-- OR to a team OR to nobody, and the application never writes both — see
-- MaintenanceController::validateSchedule(). Two nullable columns rather than a
-- polymorphic (type, id) pair because both sides are real foreign keys this
-- way: deleting a team cannot leave a schedule pointing at a group that no
-- longer exists.
--
-- Nothing else in the schema carries an assignment. PAT is not scheduled per
-- job — its due dates are derived from the asset's own retest interval, and
-- `pat_records.tester_user_id` records who *did* a test rather than who owes
-- one — so there is no PAT assignment for a team to take over.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS teams (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = archived; kept so historic assignments still read',
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teams_name (name),
    KEY idx_teams_active (is_active),
    CONSTRAINT fk_teams_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Membership. CASCADE on both sides: a row here is meaningless without both
-- ends of it, and there is nothing to keep for history — the audit trail
-- records who was added and removed, and when.
CREATE TABLE IF NOT EXISTS team_members (
    team_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    added_by   INT UNSIGNED NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (team_id, user_id),
    KEY idx_team_members_user (user_id),
    CONSTRAINT fk_team_members_team
        FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
    CONSTRAINT fk_team_members_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_team_members_added_by
        FOREIGN KEY (added_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SET NULL rather than RESTRICT: archiving is the ordinary way to retire a
-- team, so a delete is the deliberate act of someone who wants it gone, and it
-- should not be blocked by a schedule that can simply become unassigned.
ALTER TABLE maintenance_schedules
    ADD COLUMN assigned_to_team_id INT UNSIGNED NULL
        COMMENT 'Mutually exclusive with assigned_to_user_id' AFTER assigned_to_user_id,
    ADD KEY idx_maintenance_schedules_team (assigned_to_team_id),
    ADD CONSTRAINT fk_maintenance_schedules_team
        FOREIGN KEY (assigned_to_team_id) REFERENCES teams (id) ON DELETE SET NULL;

-- --- Permission -------------------------------------------------------------
-- Administrators only. Membership decides who receives a reminder and who a job
-- is expected of, which makes it an administrative control rather than part of
-- doing the work.
INSERT INTO permissions (slug, name, group_name, description, sort_order) VALUES
    ('teams.manage', 'Manage teams', 'Administration', 'Create teams, archive them, and add or remove their members.', 45)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    group_name = VALUES(group_name),
    description = VALUES(description),
    sort_order = VALUES(sort_order);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.slug = 'teams.manage'
 WHERE r.slug = 'admin';
