-- ---------------------------------------------------------------------------
-- 009 Default application settings
--
-- Asset tags are generated as <prefix><zero-padded number>, e.g. AST-0001.
-- Both parts are configurable from Settings in the admin area; changing the
-- prefix starts a new sequence rather than renumbering anything existing.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('asset_tag_prefix',    'AST-'),
    ('asset_tag_pad',       '4'),
    ('organisation_name',   ''),
    ('label_show_location', '1'),
    ('label_show_name',     '1');
