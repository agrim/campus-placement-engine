ALTER TABLE users ADD COLUMN scope_type TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN scope_value TEXT NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS preference_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    candidate_id INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'open',
    note TEXT NOT NULL DEFAULT '',
    requested_by INTEGER NULL REFERENCES users(id),
    decision_company_id INTEGER NULL REFERENCES companies(id),
    created_at TEXT NOT NULL,
    resolved_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS preference_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL REFERENCES preference_requests(id) ON DELETE CASCADE,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE(request_id, company_id)
);

CREATE TABLE IF NOT EXISTS wanted_alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    candidate_id INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    reason TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    created_by INTEGER NULL REFERENCES users(id),
    resolved_by INTEGER NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    resolved_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS workflow_status_overrides (
    status_key TEXT PRIMARY KEY,
    label TEXT NOT NULL,
    color TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS workflow_transition_overrides (
    from_status TEXT NOT NULL,
    to_status TEXT NOT NULL,
    roles_csv TEXT NOT NULL,
    PRIMARY KEY (from_status, to_status)
);

CREATE INDEX IF NOT EXISTS idx_preference_requests_candidate ON preference_requests(candidate_id);
CREATE INDEX IF NOT EXISTS idx_wanted_alerts_candidate ON wanted_alerts(candidate_id);
CREATE INDEX IF NOT EXISTS idx_wanted_alerts_status ON wanted_alerts(status);
