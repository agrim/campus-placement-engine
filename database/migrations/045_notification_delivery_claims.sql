ALTER TABLE notification_deliveries ADD COLUMN locked_at TEXT NULL;
ALTER TABLE notification_deliveries ADD COLUMN lock_token TEXT NULL;
ALTER TABLE notification_deliveries ADD COLUMN available_at TEXT NOT NULL DEFAULT '1970-01-01 00:00:00';
ALTER TABLE notification_deliveries ADD COLUMN delivered_to TEXT NOT NULL DEFAULT '';
ALTER TABLE notification_deliveries ADD COLUMN idempotency_key TEXT NOT NULL DEFAULT '';

UPDATE notification_deliveries
SET target = CASE channel
        WHEN 'file' THEN '[config:notification_file]'
        WHEN 'webhook' THEN '[config:notification_webhook]'
        WHEN 'email' THEN '[config:notification_email]'
        WHEN 'sms' THEN '[config:notification_sms]'
        WHEN 'whatsapp' THEN '[config:notification_whatsapp]'
        ELSE '[config:notification_unknown]'
    END,
    delivered_to = CASE
        WHEN status = 'delivered' AND channel IN ('file', 'webhook', 'email', 'sms', 'whatsapp') THEN channel
        ELSE ''
    END,
    available_at = CASE
        WHEN updated_at <> '' THEN updated_at
        ELSE created_at
    END,
    idempotency_key = 'ndk_' || lower(hex(randomblob(16)));

CREATE UNIQUE INDEX IF NOT EXISTS idx_notification_deliveries_idempotency
    ON notification_deliveries(idempotency_key);
CREATE INDEX IF NOT EXISTS idx_notification_deliveries_claim
    ON notification_deliveries(status, available_at, locked_at);
