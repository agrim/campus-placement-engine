CREATE TABLE IF NOT EXISTS domain_event_module_fanout (
    id BIGSERIAL PRIMARY KEY,
    event_id BIGINT NOT NULL REFERENCES domain_event_outbox(id) ON DELETE CASCADE,
    module_key TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'retrying', 'expanded', 'dead_lettered')),
    attempt_count INTEGER NOT NULL DEFAULT 0,
    available_at TEXT NOT NULL,
    locked_at TEXT NULL,
    lock_token TEXT NULL,
    expanded_at TEXT NULL,
    failed_at TEXT NULL,
    replayed_at TEXT NULL,
    replayed_by_user_id BIGINT NULL REFERENCES users(id),
    last_error TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(event_id, module_key)
);

CREATE INDEX IF NOT EXISTS idx_domain_event_module_fanout_available
ON domain_event_module_fanout(status, available_at, locked_at);

CREATE INDEX IF NOT EXISTS idx_domain_event_module_fanout_lease
ON domain_event_module_fanout(lock_token, locked_at);

CREATE TABLE IF NOT EXISTS domain_event_deliveries (
    id BIGSERIAL PRIMARY KEY,
    event_id BIGINT NOT NULL REFERENCES domain_event_outbox(id) ON DELETE CASCADE,
    subscription_id TEXT NOT NULL,
    module_key TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'retrying', 'delivered', 'skipped', 'dead_lettered')),
    attempt_count INTEGER NOT NULL DEFAULT 0,
    available_at TEXT NOT NULL,
    locked_at TEXT NULL,
    lock_token TEXT NULL,
    delivered_at TEXT NULL,
    skipped_at TEXT NULL,
    failed_at TEXT NULL,
    replayed_at TEXT NULL,
    replayed_by_user_id BIGINT NULL REFERENCES users(id),
    last_error TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(event_id, subscription_id)
);

CREATE INDEX IF NOT EXISTS idx_domain_event_deliveries_available
ON domain_event_deliveries(status, available_at, locked_at);

CREATE INDEX IF NOT EXISTS idx_domain_event_deliveries_lease
ON domain_event_deliveries(lock_token, locked_at);
