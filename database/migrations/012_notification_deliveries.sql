CREATE TABLE IF NOT EXISTS notification_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    notification_id INTEGER NOT NULL REFERENCES notifications(id) ON DELETE CASCADE,
    channel TEXT NOT NULL,
    target TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'queued',
    attempt_count INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '',
    payload_json TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    delivered_at TEXT NULL,
    UNIQUE(notification_id, channel, target)
);

CREATE INDEX IF NOT EXISTS idx_notification_deliveries_status
    ON notification_deliveries(status);
CREATE INDEX IF NOT EXISTS idx_notification_deliveries_notification
    ON notification_deliveries(notification_id);
