CREATE TABLE IF NOT EXISTS advising_appointments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    institution_id INTEGER NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    student_profile_id INTEGER NOT NULL REFERENCES student_profiles(id) ON DELETE CASCADE,
    adviser_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    appointment_status TEXT NOT NULL DEFAULT 'requested',
    starts_at TEXT NOT NULL,
    ends_at TEXT NOT NULL,
    appointment_mode TEXT NOT NULL DEFAULT 'in_person',
    location TEXT NOT NULL DEFAULT '',
    topic TEXT NOT NULL DEFAULT '',
    student_notes TEXT NOT NULL DEFAULT '',
    created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS advising_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    institution_id INTEGER NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    student_profile_id INTEGER NOT NULL REFERENCES student_profiles(id) ON DELETE CASCADE,
    appointment_id INTEGER NULL REFERENCES advising_appointments(id) ON DELETE SET NULL,
    author_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    visibility TEXT NOT NULL DEFAULT 'staff',
    body TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS advising_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    institution_id INTEGER NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    student_profile_id INTEGER NULL REFERENCES student_profiles(id) ON DELETE SET NULL,
    task_type TEXT NOT NULL,
    task_status TEXT NOT NULL DEFAULT 'open',
    title TEXT NOT NULL,
    due_on TEXT NOT NULL DEFAULT '',
    detail TEXT NOT NULL DEFAULT '',
    source_event_name TEXT NOT NULL DEFAULT '',
    source_aggregate_public_id TEXT NOT NULL DEFAULT '',
    subject_reference TEXT NOT NULL DEFAULT '',
    completed_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    completed_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(source_event_name, source_aggregate_public_id, task_type)
);

CREATE INDEX IF NOT EXISTS idx_advising_appointments_student ON advising_appointments(student_profile_id, starts_at);
CREATE INDEX IF NOT EXISTS idx_advising_appointments_adviser ON advising_appointments(adviser_user_id, starts_at);
CREATE INDEX IF NOT EXISTS idx_advising_appointments_status ON advising_appointments(appointment_status, starts_at);
CREATE INDEX IF NOT EXISTS idx_advising_notes_student ON advising_notes(student_profile_id, created_at);
CREATE INDEX IF NOT EXISTS idx_advising_tasks_status ON advising_tasks(task_status, due_on);
