CREATE TABLE IF NOT EXISTS idempotency_keys (
    key TEXT PRIMARY KEY,
    actor_user_id INTEGER NULL REFERENCES users(id),
    action TEXT NOT NULL,
    application_id INTEGER NULL REFERENCES applications(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_idempotency_created_at ON idempotency_keys(created_at);
