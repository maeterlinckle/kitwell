-- ---------------------------------------------------------------------------
-- 007 Page-batched routine runs
--
-- A second way of working through an out-of-order routine. With
-- `page_batched` set, the pages of a version stay independent of each other —
-- any page, in any order — but a page is answered and submitted as one unit
-- rather than a step at a time. That suits a station where one person has the
-- item in front of them for the whole of one page's worth of work.
--
-- The unit of completion moves with it. `routine_page_completions` records who
-- finished a page and when, which is what a batched run reports instead of the
-- per-step names it would otherwise carry.
--
-- A page may also be marked as required before the run can be signed off. Not
-- every page is: one may be for a fault that was not found. What is flagged is
-- checked at sign-off, and what is not is left alone.
-- ---------------------------------------------------------------------------

ALTER TABLE routine_versions
    ADD COLUMN page_batched TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = a page of this version is answered and submitted as one unit; only read when allow_out_of_order is 1'
        AFTER allow_out_of_order;

ALTER TABLE routine_pages
    ADD COLUMN required_for_signoff TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = this page must be completed before the run can be signed off'
        AFTER description;

CREATE TABLE IF NOT EXISTS routine_page_completions (
    completion_id BIGINT UNSIGNED NOT NULL,
    page_id       INT UNSIGNED NOT NULL,
    completed_by  INT UNSIGNED NULL,
    completed_at  DATETIME NOT NULL,
    PRIMARY KEY (completion_id, page_id),
    KEY idx_routine_page_completions_page (page_id),
    CONSTRAINT fk_routine_page_completions_completion
        FOREIGN KEY (completion_id) REFERENCES routine_completions (id) ON DELETE CASCADE,
    CONSTRAINT fk_routine_page_completions_page
        FOREIGN KEY (page_id) REFERENCES routine_pages (id),
    CONSTRAINT fk_routine_page_completions_by
        FOREIGN KEY (completed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
