CREATE TABLE IF NOT EXISTS application_slot_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    round_schedule_id INTEGER NOT NULL REFERENCES round_schedules(id) ON DELETE CASCADE,
    sequence INTEGER NOT NULL DEFAULT 1,
    assignment_status TEXT NOT NULL DEFAULT 'assigned',
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(application_id, round_schedule_id)
);

CREATE INDEX IF NOT EXISTS idx_application_slot_assignments_application
    ON application_slot_assignments(application_id);
CREATE INDEX IF NOT EXISTS idx_application_slot_assignments_schedule
    ON application_slot_assignments(round_schedule_id);
