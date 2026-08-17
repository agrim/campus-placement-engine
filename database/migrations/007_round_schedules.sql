CREATE TABLE IF NOT EXISTS round_schedules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    round_id INTEGER NOT NULL REFERENCES company_rounds(id) ON DELETE CASCADE,
    sequence INTEGER NOT NULL DEFAULT 1,
    room TEXT NOT NULL DEFAULT '',
    starts_at TEXT NOT NULL DEFAULT '',
    ends_at TEXT NOT NULL DEFAULT '',
    capacity INTEGER NOT NULL DEFAULT 0,
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(round_id, sequence, room, starts_at)
);

CREATE INDEX IF NOT EXISTS idx_round_schedules_round ON round_schedules(round_id);
