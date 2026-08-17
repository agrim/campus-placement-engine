CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient_role TEXT NOT NULL DEFAULT '',
    recipient_scope_type TEXT NOT NULL DEFAULT '',
    recipient_scope_value TEXT NOT NULL DEFAULT '',
    channel TEXT NOT NULL DEFAULT 'in_app',
    template_key TEXT NOT NULL DEFAULT '',
    subject TEXT NOT NULL,
    body TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'open',
    source_type TEXT NOT NULL DEFAULT '',
    source_id INTEGER NULL,
    created_by INTEGER NULL REFERENCES users(id),
    acknowledged_by INTEGER NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    acknowledged_at TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_notifications_status ON notifications(status);
CREATE INDEX IF NOT EXISTS idx_notifications_recipient ON notifications(recipient_role, recipient_scope_type, recipient_scope_value);
CREATE INDEX IF NOT EXISTS idx_notifications_source ON notifications(source_type, source_id);
