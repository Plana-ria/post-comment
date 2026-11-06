CREATE TABLE users (
    id TEXT PRIMARY KEY,
    login_id TEXT NOT NULL UNIQUE,
    code_hash TEXT NOT NULL,
    code_version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    disabled_at TEXT
);
CREATE TABLE user_sessions (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id),
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    revoked_at TEXT
);
CREATE TABLE administrators (
    id TEXT PRIMARY KEY,
    login_id TEXT NOT NULL UNIQUE,
    secret_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK(role IN ('system', 'room')),
    disabled_at TEXT
);
CREATE TABLE rooms (
    id TEXT PRIMARY KEY,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    auth_mode TEXT NOT NULL CHECK(auth_mode IN ('required', 'none')),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active', 'closed')),
    active_form_version_id TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE admin_room_roles (
    admin_id TEXT NOT NULL REFERENCES administrators(id),
    room_id TEXT NOT NULL REFERENCES rooms(id),
    role TEXT NOT NULL DEFAULT 'manager',
    PRIMARY KEY(admin_id, room_id)
);
CREATE TABLE room_participants (
    room_id TEXT NOT NULL REFERENCES rooms(id),
    user_id TEXT NOT NULL REFERENCES users(id),
    first_joined_at TEXT NOT NULL,
    PRIMARY KEY(room_id, user_id)
);
CREATE TABLE form_versions (
    id TEXT PRIMARY KEY,
    room_id TEXT NOT NULL REFERENCES rooms(id),
    version_no INTEGER NOT NULL,
    definition_json TEXT NOT NULL,
    published_at TEXT NOT NULL,
    UNIQUE(room_id, version_no)
);
CREATE TABLE anonymous_sessions (
    id TEXT PRIMARY KEY,
    room_id TEXT NOT NULL REFERENCES rooms(id),
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    revoked_at TEXT
);
CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id TEXT NOT NULL REFERENCES rooms(id),
    user_id TEXT REFERENCES users(id),
    anonymous_session_id TEXT REFERENCES anonymous_sessions(id),
    form_version_id TEXT NOT NULL REFERENCES form_versions(id),
    comment_text TEXT NOT NULL,
    submitted_values_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    legacy_source_id TEXT,
    CHECK (NOT (user_id IS NOT NULL AND anonymous_session_id IS NOT NULL))
);
CREATE INDEX comments_room_cursor ON comments(room_id, id);
CREATE INDEX comments_room_user ON comments(room_id, user_id, id);
CREATE INDEX comments_room_anonymous ON comments(room_id, anonymous_session_id, id);
CREATE TABLE site_settings (
    key TEXT PRIMARY KEY,
    value_json TEXT NOT NULL
);
CREATE TABLE audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id TEXT,
    action TEXT NOT NULL,
    target_type TEXT NOT NULL,
    target_id TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE update_jobs (
    id TEXT PRIMARY KEY,
    from_version TEXT,
    to_version TEXT NOT NULL,
    state TEXT NOT NULL,
    error_code TEXT,
    started_at TEXT NOT NULL,
    finished_at TEXT
);
