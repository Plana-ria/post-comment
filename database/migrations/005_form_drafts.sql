CREATE TABLE form_drafts (
    room_id TEXT PRIMARY KEY REFERENCES rooms(id) ON DELETE CASCADE,
    base_form_version_id TEXT NOT NULL REFERENCES form_versions(id),
    definition_json TEXT NOT NULL,
    updated_by TEXT NOT NULL REFERENCES administrators(id),
    updated_at TEXT NOT NULL
);
