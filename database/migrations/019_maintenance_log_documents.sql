-- ---------------------------------------------------------------------------
-- 019 Documents attached to a maintenance record
--
-- A contractor's service report, a calibration certificate, an invoice: the
-- paperwork that proves the work happened. Photos already had a home
-- (`maintenance_log_photos`); this is the same idea for documents.
--
-- Shaped after `asset_manuals` rather than invented fresh, because it is the
-- same job — a PDF held outside the document root, streamed through PHP, with
-- the original filename kept for display only. The difference is what it hangs
-- off: a maintenance log, not an asset. A service report belongs to the visit
-- it describes, and filing it against the machine instead would lose which
-- visit produced it.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS maintenance_log_documents (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    maintenance_log_id BIGINT UNSIGNED NOT NULL,
    title              VARCHAR(191)    NOT NULL COMMENT 'Falls back to the file name when nothing is typed',
    file_path          VARCHAR(255)    NOT NULL COMMENT 'Relative to the uploads root; never an absolute path',
    original_filename  VARCHAR(255)    NULL COMMENT 'As supplied by the browser, for display only',
    mime_type          VARCHAR(100)    NOT NULL DEFAULT 'application/pdf',
    file_size_bytes    INT UNSIGNED    NOT NULL DEFAULT 0,
    notes              VARCHAR(255)    NULL,
    uploaded_by        INT UNSIGNED    NULL,
    created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_maintenance_log_documents_log (maintenance_log_id, created_at),
    CONSTRAINT fk_maintenance_log_documents_log
        FOREIGN KEY (maintenance_log_id) REFERENCES maintenance_logs (id) ON DELETE CASCADE,
    CONSTRAINT fk_maintenance_log_documents_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
