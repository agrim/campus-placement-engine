ALTER TABLE web_sessions ADD COLUMN lock_token TEXT NOT NULL DEFAULT '';
ALTER TABLE web_sessions ADD COLUMN locked_at TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_web_sessions_lock
ON web_sessions(lock_token, locked_at);
