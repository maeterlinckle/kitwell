-- ---------------------------------------------------------------------------
-- 010 Per-account permission overrides
--
-- A role has always been the whole answer to "what may this person do", which
-- means the only way to give one person one extra ability has been to invent a
-- role for them. Sites end up with "Manager plus reports" and "Manager plus
-- reports but not deletes", and after a year nobody can say which of the nine
-- roles anybody should be on.
--
-- So a role becomes the *baseline* and an account may differ from it, in either
-- direction:
--
--     effective = (role's permissions + this account's grants) - this account's denies
--
-- One row per (user, permission), carrying which way it goes. The primary key
-- is the pair, so an account cannot be granted and denied the same permission —
-- the contradiction is impossible to store rather than resolved by a rule
-- somebody has to remember.
--
-- A superuser role is unaffected by either direction; see PROJECT_STATE §4.19
-- for why, and `Auth::can()` for where that is enforced.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    effect        ENUM('grant', 'deny') NOT NULL
                  COMMENT 'grant = in addition to the role; deny = withheld despite the role',
    granted_by    INT UNSIGNED NULL COMMENT 'Who set it, for the audit trail',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission_id),
    KEY idx_user_permissions_permission (permission_id),
    KEY idx_user_permissions_effect (user_id, effect),
    CONSTRAINT fk_user_permissions_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permissions_permission
        FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permissions_granted_by
        FOREIGN KEY (granted_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
