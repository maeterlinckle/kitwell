-- ---------------------------------------------------------------------------
-- 013 Loans support and a properly restricted Borrower role
--
-- The Borrower role previously held `assets.view`, which grants the whole
-- asset register — far too much for a self-service borrower. Borrowers get
-- their own read-only portal instead (/my-loans), which never touches the
-- asset controllers, so there is no route by which they can reach financials,
-- internal notes, maintenance or the full PAT history.
-- ---------------------------------------------------------------------------

DELETE rp
  FROM role_permissions rp
  INNER JOIN roles r ON r.id = rp.role_id
  INNER JOIN permissions p ON p.id = rp.permission_id
 WHERE r.slug = 'borrower'
   AND p.slug <> 'loans.view_own';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.slug = 'loans.view_own'
 WHERE r.slug = 'borrower';

UPDATE roles
   SET description = 'Self-service access to their own loans only. No access to the register, maintenance, PAT history or any editing.'
 WHERE slug = 'borrower';

-- Loan settings.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('loan_default_days',   '7'),
    ('loan_due_soon_days',  '2'),
    ('loan_reference_prefix', 'LN-');

-- Photos taken when an item comes back, so the condition on return is
-- evidenced rather than just described. Mirrors asset_photos/maintenance
-- photos: file on disk, path in the database.
CREATE TABLE IF NOT EXISTS loan_photos (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_id           BIGINT UNSIGNED NOT NULL,
    stage             ENUM('out','in') NOT NULL DEFAULT 'in' COMMENT 'Taken at checkout or at return',
    file_path         VARCHAR(255) NOT NULL COMMENT 'Path relative to the uploads root',
    original_filename VARCHAR(255) NULL,
    mime_type         VARCHAR(100) NOT NULL,
    file_size_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
    caption           VARCHAR(255) NULL,
    uploaded_by       INT UNSIGNED NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_loan_photos_loan (loan_id, stage),
    CONSTRAINT fk_loan_photos_loan
        FOREIGN KEY (loan_id) REFERENCES loans (id) ON DELETE CASCADE,
    CONSTRAINT fk_loan_photos_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Finding the open loan for an asset is the hot path on every checkout and
-- return, and on the asset page.
ALTER TABLE loans
    ADD KEY idx_loans_open (asset_id, returned_at);
