CREATE TABLE IF NOT EXISTS candidate_unavailability_windows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    candidate_id INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    label TEXT NOT NULL DEFAULT '',
    schedule_day TEXT NOT NULL DEFAULT '',
    starts_at TEXT NOT NULL DEFAULT '',
    ends_at TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(candidate_id, schedule_day, starts_at, ends_at, label)
);

CREATE INDEX IF NOT EXISTS idx_candidate_unavailability_candidate ON candidate_unavailability_windows(candidate_id);
