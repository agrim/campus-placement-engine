CREATE TABLE IF NOT EXISTS company_rounds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    sequence INTEGER NOT NULL DEFAULT 1,
    label TEXT NOT NULL,
    round_type TEXT NOT NULL DEFAULT '',
    room TEXT NOT NULL DEFAULT '',
    duration_minutes INTEGER NOT NULL DEFAULT 0,
    instructions TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(company_id, sequence, label)
);

CREATE INDEX IF NOT EXISTS idx_company_rounds_company ON company_rounds(company_id);
