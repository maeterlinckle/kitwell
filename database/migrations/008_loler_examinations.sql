-- ---------------------------------------------------------------------------
-- 008 In-house LOLER reports of thorough examination
--
-- LOLER 1998 regulation 9 requires lifting equipment exposed to conditions
-- causing deterioration to be thoroughly examined: every 6 months for
-- equipment that lifts people and for accessories for lifting, every 12 months
-- for other lifting equipment, or on the interval set by a written examination
-- scheme — and again after exceptional circumstances. The interval therefore
-- lives on the asset, not in a site-wide setting.
--
-- Regulation 10 requires a written report containing the information in
-- Schedule 1. `loler_examinations` is shaped by that schedule, paragraph for
-- paragraph, and the column comments say which. A report missing a Schedule 1
-- item is not a report of thorough examination, so the columns that the
-- schedule makes unconditional are NOT NULL.
--
-- Schedule 1(8) makes the defect model three-part rather than two, so defects
-- are their own table: a defect that IS a danger, one that is not yet but
-- could become one (with the date by which it could), and — separately from
-- both — the regulation 10(1)(c) case of an existing or imminent risk of
-- serious personal injury, which obliges the examiner to send a copy of the
-- report to the enforcing authority. The application records and prompts that
-- duty; discharging it stays with the examiner.
-- ---------------------------------------------------------------------------

-- --- The equipment's own fixed characteristics ------------------------------
--
-- Alongside `requires_pat` and for the same reason (see PROJECT_STATE §5.1
-- item 10): these describe the equipment rather than any one examination, so
-- the examiner is shown them to confirm rather than asked to type them again.
-- `serial_number` is deliberately absent — assets already have one, and a
-- second would be a second answer to the same question.

ALTER TABLE assets
    ADD COLUMN requires_loler TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = a periodic LOLER report of thorough examination applies'
        AFTER pat_interval_months,
    ADD COLUMN loler_type VARCHAR(60) NULL
        COMMENT 'Category of lifting equipment or accessory; keys of App\\Models\\LolerExamination::TYPES'
        AFTER requires_loler,
    ADD COLUMN loler_interval_months SMALLINT UNSIGNED NULL
        COMMENT 'Examination interval for this item. 6 for equipment lifting persons and for accessories, 12 for other lifting equipment, or whatever an examination scheme sets'
        AFTER loler_type,
    ADD COLUMN loler_swl DECIMAL(12,3) NULL
        COMMENT 'Safe working load / working load limit',
    ADD COLUMN loler_swl_unit VARCHAR(12) NULL
        COMMENT 'Unit the SWL is expressed in — kg, t, kN, lb or persons',
    ADD COLUMN loler_date_of_manufacture DATE NULL,
    ADD COLUMN loler_manufacture_unknown TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Schedule 1(3) asks for the date of manufacture "where known"; older and unbranded equipment often carries none',
    ADD KEY idx_assets_requires_loler (requires_loler);

-- --- The report of thorough examination -------------------------------------

CREATE TABLE IF NOT EXISTS loler_examinations (
    id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id                  BIGINT UNSIGNED NOT NULL,

    -- Schedule 1(3): particulars sufficient to identify the equipment,
    -- including where known its date of manufacture. Snapshotted rather than
    -- read back from the asset, so a report says what it said on the day.
    loler_type                VARCHAR(60) NOT NULL,
    serial_number             VARCHAR(191) NULL,
    date_of_manufacture       DATE NULL,
    manufacture_unknown       TINYINT(1) NOT NULL DEFAULT 0,

    -- Schedule 1(5): the safe working load, or where it depends on the
    -- configuration, the SWL for the last configuration examined.
    swl                       DECIMAL(12,3) NULL,
    swl_unit                  VARCHAR(12) NULL,
    swl_configuration         VARCHAR(255) NULL
        COMMENT 'Schedule 1(5): named configuration for equipment whose SWL depends on one',

    -- Schedule 1(1) and (2).
    employer_name             VARCHAR(191) NOT NULL,
    employer_address          VARCHAR(500) NOT NULL,
    examination_address       VARCHAR(500) NOT NULL,

    -- Not a Schedule 1 item. Regulation 10(1)(b)(ii) requires the report to go
    -- to anyone the equipment was hired or leased from, which is who this
    -- names when it is not the employer.
    owner_name                VARCHAR(191) NULL,
    owner_address             VARCHAR(500) NULL,

    -- Schedule 1(4).
    previous_examination_date DATE NULL,

    -- Schedule 1(6): the first thorough examination after installation, or
    -- after assembly at a new site or in a new location.
    is_first_examination      TINYINT(1) NOT NULL DEFAULT 0,
    installed_correctly       TINYINT(1) NULL
        COMMENT 'Schedule 1(6)(b); only meaningful on a first examination',

    -- Schedule 1(7)(a): which of regulation 9(3)(a)(i)-(iv) this examination
    -- is being carried out under.
    examination_basis         ENUM('6-month','12-month','scheme','exceptional') NOT NULL,
    interval_months           SMALLINT UNSIGNED NULL
        COMMENT 'The interval in force for this item at the time of examination',

    -- Schedule 1(6)(b) and (7)(b).
    safe_to_operate           TINYINT(1) NOT NULL DEFAULT 0,

    -- Schedule 1(8)(e).
    testing_carried_out       TINYINT(1) NOT NULL DEFAULT 0,
    test_particulars          TEXT NULL,

    -- Schedule 1(8)(d) and (f), and (11).
    examined_on               DATE NOT NULL,
    next_examination_date     DATE NOT NULL,
    reported_on               DATE NOT NULL,

    -- Schedule 1(9): the person making the report.
    examiner_user_id          INT UNSIGNED NULL,
    examiner_name             VARCHAR(191) NOT NULL,
    examiner_qualifications   VARCHAR(500) NULL,
    examiner_self_employed    TINYINT(1) NOT NULL DEFAULT 0,
    examiner_employer_name    VARCHAR(191) NULL,
    examiner_employer_address VARCHAR(500) NULL,

    -- Schedule 1(10), and regulation 10(1)(b)'s "authenticated by signature or
    -- equally secure means". The signed-in account that submitted the report is
    -- the secure means; the name is snapshotted so a renamed or deleted user
    -- cannot rewrite an authenticated report.
    authenticated_by          INT UNSIGNED NULL,
    authenticated_name        VARCHAR(191) NOT NULL,
    authenticated_at          DATETIME NOT NULL,

    outcome                   ENUM('none','defects') NOT NULL DEFAULT 'none'
        COMMENT 'none = no defect found at this examination',
    notes                     TEXT NULL,

    created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_loler_exam_asset (asset_id, examined_on),
    KEY idx_loler_exam_next_due (next_examination_date),
    KEY idx_loler_exam_examiner (examiner_user_id),
    KEY idx_loler_exam_outcome (outcome),
    CONSTRAINT fk_loler_exam_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_loler_exam_examiner
        FOREIGN KEY (examiner_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_loler_exam_authenticated_by
        FOREIGN KEY (authenticated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Schedule 1(8): defects --------------------------------------------------
--
-- `danger` is Schedule 1(8)(a) and (b): a defect which IS a danger to persons.
-- Regulation 10(3)(a) then forbids use of the equipment before it is
-- rectified.
--
-- `becoming_danger` is Schedule 1(8)(c): not yet a danger but could become
-- one. It carries the date by which it could — regulation 10(3)(b) forbids use
-- after that date and before rectification — and the remedy required.
--
-- `serious_injury_risk` is regulation 10(1)(c) and sits across the two: where
-- a defect involves an existing or imminent risk of serious personal injury,
-- the examiner must send a copy of the report to the relevant enforcing
-- authority. Recorded and prompted here; never sent by this application.

CREATE TABLE IF NOT EXISTS loler_defects (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    examination_id      BIGINT UNSIGNED NOT NULL,
    position            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    category            ENUM('danger','becoming_danger') NOT NULL,
    part_identified     VARCHAR(255) NOT NULL
        COMMENT 'Schedule 1(8)(a): identification of the part found to have the defect',
    description         TEXT NOT NULL,
    remedy              TEXT NULL
        COMMENT 'Schedule 1(8)(b) and (8)(c)(ii): the repair, renewal or alteration required',
    becomes_danger_by   DATE NULL
        COMMENT 'Schedule 1(8)(c)(i): the time by which it could become a danger',
    serious_injury_risk TINYINT(1) NOT NULL DEFAULT 0,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_loler_defects_exam (examination_id, position),
    CONSTRAINT fk_loler_defects_exam
        FOREIGN KEY (examination_id) REFERENCES loler_examinations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Settings ----------------------------------------------------------------
--
-- The organisation's own address, beside the name the print masthead already
-- uses. It is what the "Use our details" buttons on the examination form fill
-- in: in-house examinations name the same organisation as employer, premises
-- and owner most of the time.

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('organisation_address', '');

-- --- Permission ---------------------------------------------------------------
--
-- Submitting a report of thorough examination is a competent person's act, not
-- an ordinary maintenance one: LOLER regulation 9 requires appropriate
-- practical and theoretical knowledge of the equipment, and L113 paragraph 297
-- requires an in-house examiner to have genuine authority and independence.
-- Granted to nobody by default, precisely so that a site has to decide who
-- holds it rather than inheriting it from a role they already had.

INSERT INTO permissions (slug, name, group_name, description, sort_order) VALUES
    ('loler.inspect', 'Carry out LOLER examinations', 'Maintenance', 'Submit an in-house LOLER report of thorough examination. Grant only to those competent to make one.', 50)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    group_name = VALUES(group_name),
    description = VALUES(description),
    sort_order = VALUES(sort_order);
