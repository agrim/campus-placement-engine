ALTER TABLE webhook_worker_heartbeat
ADD COLUMN claim_cursor_subscription_id INTEGER NOT NULL DEFAULT 0
    CHECK (claim_cursor_subscription_id >= 0);
