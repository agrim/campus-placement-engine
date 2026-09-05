-- Invalidate browser sessions after credential or activation changes.
-- Existing sessions without a generation must authenticate again after upgrade.
ALTER TABLE users ADD COLUMN session_generation INTEGER NOT NULL DEFAULT 1 CHECK (session_generation >= 1);
