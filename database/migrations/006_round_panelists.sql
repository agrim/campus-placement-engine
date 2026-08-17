CREATE TABLE IF NOT EXISTS round_panelists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    round_id INTEGER NOT NULL REFERENCES company_rounds(id) ON DELETE CASCADE,
    sequence INTEGER NOT NULL DEFAULT 1,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT '',
    affiliation TEXT NOT NULL DEFAULT '',
    contact TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(round_id, sequence, name)
);

CREATE INDEX IF NOT EXISTS idx_round_panelists_round ON round_panelists(round_id);
