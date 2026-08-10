-- ---------------------------------------------------------------------------
-- 017 Loans become Hires, Borrowers become Hirers
--
-- The workshop hires equipment out; it does not lend it. The interface now says
-- so, and the schema is renamed to match rather than leaving the code calling
-- something "loans" while every screen says "hires" — a mismatch that costs
-- someone an afternoon a year from now.
--
-- This renames in place. There are no compatibility views or aliases: the whole
-- application is renamed in the same commit, so nothing is left referring to
-- the old names, and a permanent shim would just be the mismatch again in
-- another form.
--
--   loans          -> hires
--   loan_photos    -> hire_photos      (loan_id -> hire_id)
--   borrowers      -> hirers           (borrower_type -> hirer_type)
--   loans.borrower_id -> hires.hirer_id
--   assets.is_loanable -> assets.is_hireable
--   assets.status 'On Loan' -> 'On Hire'
--   permissions loans.* / borrowers.* -> hires.* / hirers.*
--   role 'borrower' -> 'hirer'
--   settings loan_* -> hire_*
--
-- Uploaded hire photos keep their existing paths under storage/uploads/loans/.
-- Each row stores its own relative path, so old files resolve exactly as
-- before; only newly uploaded ones go to storage/uploads/hires/. Nothing needs
-- moving on disk.
-- ---------------------------------------------------------------------------

-- --- Tables -----------------------------------------------------------------
RENAME TABLE loans TO hires;
RENAME TABLE borrowers TO hirers;
RENAME TABLE loan_photos TO hire_photos;

-- --- Columns inside a foreign key -------------------------------------------
-- A column that takes part in a foreign key cannot simply be renamed:
-- ALGORITHM=COPY refuses it ("Columns participating in a foreign key are
-- renamed"), and ALGORITHM=INPLACE refuses as soon as anything else about the
-- definition differs. Dropping the constraint, renaming, and putting it back is
-- the version-proof way round, and it lets the index and constraint names stop
-- referring to a column that no longer exists.
--
-- The composite indexes and delete rules are recreated exactly as they were:
-- (hirer_id, status) RESTRICT and (hire_id, stage) CASCADE.
--
-- hires.hirer_id is INT UNSIGNED, not BIGINT — it points at hirers.id.
ALTER TABLE hires DROP FOREIGN KEY fk_loans_borrower;
ALTER TABLE hires DROP INDEX idx_loans_borrower;
ALTER TABLE hires CHANGE COLUMN borrower_id hirer_id INT UNSIGNED NOT NULL;
ALTER TABLE hires ADD INDEX idx_hires_hirer (hirer_id, status);
ALTER TABLE hires ADD CONSTRAINT fk_hires_hirer
    FOREIGN KEY (hirer_id) REFERENCES hirers (id) ON DELETE RESTRICT;

ALTER TABLE hire_photos DROP FOREIGN KEY fk_loan_photos_loan;
ALTER TABLE hire_photos DROP INDEX idx_loan_photos_loan;
ALTER TABLE hire_photos CHANGE COLUMN loan_id hire_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE hire_photos ADD INDEX idx_hire_photos_hire (hire_id, stage);
ALTER TABLE hire_photos ADD CONSTRAINT fk_hire_photos_hire
    FOREIGN KEY (hire_id) REFERENCES hires (id) ON DELETE CASCADE;

-- --- Columns not inside a foreign key ---------------------------------------
-- The remaining constraints on these tables still carry their original
-- fk_loans_* / fk_loan_photos_* names. They are internal and harmless, and
-- renaming a constraint means dropping and re-adding it, so they are left
-- alone rather than churned for cosmetics.
ALTER TABLE hirers
    CHANGE COLUMN borrower_type hirer_type ENUM('Person','Company') NOT NULL DEFAULT 'Person';

ALTER TABLE assets
    CHANGE COLUMN is_loanable is_hireable TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Can this item be hired out';

-- --- The asset status enum --------------------------------------------------
-- Widen first so both spellings are briefly legal, move the rows, then narrow.
-- Doing it in one step would fail every row already sitting at 'On Loan'.
ALTER TABLE assets
    MODIFY COLUMN status ENUM('In Stock','On Loan','On Hire','In Maintenance','Retired')
        NOT NULL DEFAULT 'In Stock';

UPDATE assets SET status = 'On Hire' WHERE status = 'On Loan';

ALTER TABLE assets
    MODIFY COLUMN status ENUM('In Stock','On Hire','In Maintenance','Retired')
        NOT NULL DEFAULT 'In Stock';

-- --- Permissions ------------------------------------------------------------
-- Permissions are data, so this is an UPDATE and not a schema change. The
-- role_permissions rows point at permission ids, which do not move, so every
-- existing grant survives untouched.
UPDATE permissions SET slug = 'hires.view'     WHERE slug = 'loans.view';
UPDATE permissions SET slug = 'hires.view_own' WHERE slug = 'loans.view_own';
UPDATE permissions SET slug = 'hires.create'   WHERE slug = 'loans.create';
UPDATE permissions SET slug = 'hires.return'   WHERE slug = 'loans.return';
UPDATE permissions SET slug = 'hires.manage'   WHERE slug = 'loans.manage';
UPDATE permissions SET slug = 'hirers.view'    WHERE slug = 'borrowers.view';
UPDATE permissions SET slug = 'hirers.manage'  WHERE slug = 'borrowers.manage';

UPDATE permissions SET group_name = 'Hires' WHERE group_name = 'Loans & hire';
UPDATE permissions SET group_name = 'Hirers' WHERE group_name = 'Borrowers';

UPDATE permissions SET name = REPLACE(name, 'loan', 'hire')         WHERE name LIKE '%loan%';
UPDATE permissions SET name = REPLACE(name, 'Loan', 'Hire')         WHERE name LIKE '%Loan%';
UPDATE permissions SET name = REPLACE(name, 'borrower', 'hirer')    WHERE name LIKE '%borrower%';
UPDATE permissions SET name = REPLACE(name, 'Borrower', 'Hirer')    WHERE name LIKE '%Borrower%';
UPDATE permissions SET description = REPLACE(description, 'loan', 'hire')      WHERE description LIKE '%loan%';
UPDATE permissions SET description = REPLACE(description, 'borrower', 'hirer') WHERE description LIKE '%borrower%';

-- --- The role ---------------------------------------------------------------
UPDATE roles
   SET slug = 'hirer',
       name = 'Hirer',
       description = 'Signs in to see only the equipment they currently hold'
 WHERE slug = 'borrower';

-- --- Settings ---------------------------------------------------------------
UPDATE settings SET setting_key = 'hire_default_days'     WHERE setting_key = 'loan_default_days';
UPDATE settings SET setting_key = 'hire_due_soon_days'    WHERE setting_key = 'loan_due_soon_days';
UPDATE settings SET setting_key = 'hire_reference_prefix' WHERE setting_key = 'loan_reference_prefix';

-- --- The audit trail --------------------------------------------------------
-- activity_log.entity_type is a plain string with no foreign key, so the
-- history of what was done to a "loan" would otherwise become unsearchable
-- under the new name.
UPDATE activity_log SET entity_type = 'hire'  WHERE entity_type = 'loan';
UPDATE activity_log SET entity_type = 'hirer' WHERE entity_type = 'borrower';
