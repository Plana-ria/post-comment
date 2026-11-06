CREATE TABLE auth_attempts (
    scope TEXT NOT NULL,
    identity_hash TEXT NOT NULL,
    window_start INTEGER NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    blocked_until INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (scope, identity_hash)
);
CREATE INDEX auth_attempts_blocked ON auth_attempts(blocked_until);
