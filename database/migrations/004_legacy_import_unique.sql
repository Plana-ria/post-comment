CREATE UNIQUE INDEX IF NOT EXISTS comments_legacy_import_unique
ON comments(room_id, legacy_source_id)
WHERE legacy_source_id IS NOT NULL;
