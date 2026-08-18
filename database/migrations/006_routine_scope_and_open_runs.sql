-- ---------------------------------------------------------------------------
-- 006 Routine scope, out-of-order runs, and per-step attribution
--
-- Three additions to the routines of 005.
--
-- A routine may name a **category**. It is then offered only for assets in
-- that category or any category beneath it, since categories nest and a
-- routine written for "Access equipment" is meant for the ladders and the
-- towers alike. No category means no restriction.
--
-- A version may allow its steps to be answered **out of order**. A run of such
-- a version stays open, is worked through as a checklist rather than a wizard,
-- and is closed by an explicit submission — which is why `routine_completions`
-- gains a status and `completed_at` becomes nullable.
--
-- Each answer records **who gave it and when**, so a routine carried out by
-- five people at five stations says which part each of them did. That is
-- separate from the completion's own `completed_by` / `completed_at`, which
-- are now the person who closed the run out.
-- ---------------------------------------------------------------------------

ALTER TABLE maintenance_routines
    ADD COLUMN category_id INT UNSIGNED NULL
        COMMENT 'Restricts the routine to this category and everything nested beneath it; NULL is unrestricted'
        AFTER description,
    ADD KEY idx_maintenance_routines_category (category_id),
    ADD CONSTRAINT fk_maintenance_routines_category
        FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL;

ALTER TABLE routine_versions
    ADD COLUMN allow_out_of_order TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = a run of this version is a checklist rather than a one-way wizard'
        AFTER is_current;

ALTER TABLE routine_completions
    ADD COLUMN status ENUM('open','submitted') NOT NULL DEFAULT 'submitted'
        COMMENT 'An open run is being worked through; a submitted one is history'
        AFTER schedule_id,
    ADD COLUMN started_by INT UNSIGNED NULL AFTER started_at,
    MODIFY COLUMN completed_at DATETIME NULL
        COMMENT 'When the run was closed out; NULL while it is still open',
    ADD KEY idx_routine_completions_open (asset_id, status),
    ADD CONSTRAINT fk_routine_completions_started_by
        FOREIGN KEY (started_by) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE routine_responses
    ADD COLUMN answered_by INT UNSIGNED NULL AFTER value_boolean,
    ADD COLUMN answered_at DATETIME NULL AFTER answered_by,
    ADD CONSTRAINT fk_routine_responses_answered_by
        FOREIGN KEY (answered_by) REFERENCES users (id) ON DELETE SET NULL;
