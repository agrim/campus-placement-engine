ALTER TABLE domain_event_outbox ADD COLUMN locked_at TEXT NULL;
ALTER TABLE domain_event_outbox ADD COLUMN lock_token TEXT NULL;
ALTER TABLE domain_event_outbox ADD COLUMN delivered_to TEXT NOT NULL DEFAULT '';
ALTER TABLE domain_event_outbox ADD COLUMN failed_at TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_domain_event_outbox_delivery
ON domain_event_outbox(processed_at, failed_at, available_at, locked_at);
