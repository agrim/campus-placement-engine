ALTER TABLE module_installations ADD COLUMN installed_by INTEGER NULL REFERENCES users(id);
ALTER TABLE module_installations ADD COLUMN enabled_at TEXT NULL;
ALTER TABLE module_installations ADD COLUMN disabled_at TEXT NULL;
ALTER TABLE module_installations ADD COLUMN configuration_json TEXT NOT NULL DEFAULT '{}';

UPDATE module_installations
SET enabled_at = COALESCE(enabled_at, installed_at)
WHERE enabled = 1;

CREATE TABLE IF NOT EXISTS module_lifecycle_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_key TEXT NOT NULL,
    event_type TEXT NOT NULL,
    from_version TEXT NULL,
    to_version TEXT NULL,
    actor_user_id INTEGER NULL REFERENCES users(id),
    detail TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS domain_event_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    event_name TEXT NOT NULL,
    aggregate_type TEXT NOT NULL,
    aggregate_public_id TEXT NOT NULL,
    institution_id INTEGER NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    module_key TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    occurred_at TEXT NOT NULL,
    available_at TEXT NOT NULL,
    processed_at TEXT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_module_lifecycle_events_module
ON module_lifecycle_events(module_key, created_at);

CREATE INDEX IF NOT EXISTS idx_domain_event_outbox_pending
ON domain_event_outbox(processed_at, available_at);
