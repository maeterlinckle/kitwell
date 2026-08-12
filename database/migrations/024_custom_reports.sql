-- ---------------------------------------------------------------------------
-- 024 Custom reports
--
-- A report somebody defined, rather than one that shipped. It is the same kind
-- of thing as the built-ins: it appears in the same registry, on the same
-- index, and opens through the same controller, table, print view and CSV
-- export. The only difference is where its definition comes from — a row here
-- instead of a PHP class.
--
-- What a definition is *not* is a query. There is no SQL in this table and no
-- column names from user input anywhere near one. A definition names an
-- existing data source (assets, maintenance, PAT, hires, faults), a set of
-- values for that source's *already existing* filters — the same ones the list
-- page offers, handled by the same model code — and a choice of which of that
-- source's declared columns to show. Anything not on those lists is discarded
-- when the definition is read, so a hand-edited row cannot widen what a report
-- can reach.
--
-- Three JSON columns rather than child tables. They are read as one blob,
-- written as one blob, never joined and never filtered on; a `custom_report_
-- filters` table would be three more foreign keys and a two-table write to
-- store what is, in practice, a single value. MariaDB validates the JSON.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS custom_reports (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- The URL slug, always prefixed `custom-` by the application. The prefix is
    -- what keeps this namespace and the built-in report keys from ever
    -- colliding: no shipped report may start with it, so a custom report can
    -- never shadow `all-assets` or be shadowed by a future built-in.
    report_key    VARCHAR(80)  NOT NULL,

    name          VARCHAR(120) NOT NULL,
    description   VARCHAR(500) NULL,

    -- Matches a key in App\Reports\DataSourceRegistry. Not a foreign key
    -- because the sources are code, not data.
    data_source   VARCHAR(40)  NOT NULL,

    -- {filterKey: value} against the source's declared filters.
    filters       JSON         NOT NULL,

    -- ["asset_tag","name",…] — an ordered subset of the source's columns. The
    -- order is the column order on screen and in the CSV.
    columns       JSON         NOT NULL,

    sort_column   VARCHAR(64)  NULL COMMENT 'One of the chosen columns; NULL = the source default',
    sort_direction ENUM('asc','desc') NOT NULL DEFAULT 'asc',

    is_active     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = hidden from the index without being deleted',
    created_by    INT UNSIGNED NULL,
    updated_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_custom_reports_key (report_key),
    KEY idx_custom_reports_active (is_active, name),
    CONSTRAINT fk_custom_reports_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_custom_reports_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Permission --------------------------------------------------------------
-- Defining a report is not the same act as reading one. `reports.view` stays
-- what it was — open the reports section — and this is the right to add to it.
--
-- Manager / Staff as well as Administrator, because the person who knows which
-- report the workshop actually needs is the person doing the work, not the one
-- who administers accounts. It grants nothing new to see: a custom report is
-- refused at open time unless the reader also holds its data source's own
-- permission, exactly as the built-ins are.
INSERT INTO permissions (slug, name, group_name, description, sort_order) VALUES
    ('reports.manage', 'Manage custom reports', 'Reports', 'Create, edit and delete saved report definitions.', 20)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    group_name = VALUES(group_name),
    description = VALUES(description),
    sort_order = VALUES(sort_order);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.slug = 'reports.manage'
 WHERE r.slug IN ('admin', 'manager');
