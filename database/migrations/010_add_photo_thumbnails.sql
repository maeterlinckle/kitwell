-- ---------------------------------------------------------------------------
-- 010 Thumbnail support for asset photos
--
-- Thumbnails are generated with GD where it is available. The column is
-- nullable on purpose: if GD is missing the photo still uploads and displays,
-- it just serves the full image instead. Nothing breaks on a host without it.
-- ---------------------------------------------------------------------------

ALTER TABLE asset_photos
    ADD COLUMN thumbnail_path VARCHAR(255) NULL COMMENT 'Path relative to the uploads root; NULL when no thumbnail could be made' AFTER file_path;

-- Photos are listed newest-first by the date the condition was recorded,
-- falling back to the upload time.
ALTER TABLE asset_photos
    ADD KEY idx_asset_photos_taken (asset_id, taken_at);
