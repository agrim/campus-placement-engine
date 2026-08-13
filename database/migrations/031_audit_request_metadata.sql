ALTER TABLE audit_logs ADD COLUMN ip_address TEXT NOT NULL DEFAULT '';
ALTER TABLE audit_logs ADD COLUMN user_agent TEXT NOT NULL DEFAULT '';

INSERT INTO settings (key, value)
VALUES ('audit_request_metadata', 'none')
ON CONFLICT(key) DO NOTHING;
