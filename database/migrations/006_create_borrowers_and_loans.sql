-- ---------------------------------------------------------------------------
-- 006 Borrowers and loans/hires
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS borrowers (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    borrower_type ENUM('Person','Company') NOT NULL DEFAULT 'Person',
    name          VARCHAR(191) NOT NULL COMMENT 'Person name, or trading name for a company',
    company_name  VARCHAR(191) NULL COMMENT 'Employer/parent company when the borrower is a person',
    reference     VARCHAR(64)  NULL COMMENT 'Staff number, account number, membership id',
    email         VARCHAR(190) NULL,
    phone         VARCHAR(50)  NULL,
    address       TEXT         NULL,
    user_id       INT UNSIGNED NULL COMMENT 'Optional link to a login for the Borrower role',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    notes         TEXT         NULL,
    created_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_borrowers_name (name),
    KEY idx_borrowers_type (borrower_type),
    KEY idx_borrowers_reference (reference),
    UNIQUE KEY uq_borrowers_user (user_id),
    CONSTRAINT fk_borrowers_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_borrowers_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loans (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference                VARCHAR(40)  NULL COMMENT 'Human-friendly loan reference',
    asset_id                 BIGINT UNSIGNED NOT NULL,
    borrower_id              INT UNSIGNED NOT NULL,
    checked_out_at           DATETIME     NOT NULL,
    due_back_date            DATE         NOT NULL,
    checked_out_by_user_id   INT UNSIGNED NULL COMMENT 'Staff member who issued the item',
    condition_out            ENUM('Excellent','Good','Fair','Poor','Out of Service') NULL,
    returned_at              DATETIME     NULL,
    returned_to_user_id      INT UNSIGNED NULL COMMENT 'Staff member who accepted the return',
    condition_in             ENUM('Excellent','Good','Fair','Poor','Out of Service') NULL,
    returned_condition_notes TEXT         NULL,
    status                   ENUM('Out','Overdue','Returned') NOT NULL DEFAULT 'Out',
    purpose                  VARCHAR(255) NULL,
    hire_charge              DECIMAL(10,2) NULL,
    notes                    TEXT         NULL,
    created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_loans_reference (reference),
    KEY idx_loans_asset (asset_id, status),
    KEY idx_loans_borrower (borrower_id, status),
    KEY idx_loans_status_due (status, due_back_date),
    KEY idx_loans_due (due_back_date),
    CONSTRAINT fk_loans_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE RESTRICT,
    CONSTRAINT fk_loans_borrower
        FOREIGN KEY (borrower_id) REFERENCES borrowers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_loans_checked_out_by
        FOREIGN KEY (checked_out_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_loans_returned_to
        FOREIGN KEY (returned_to_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
