CREATE TABLE IF NOT EXISTS user_board_preferences (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    q TEXT NOT NULL DEFAULT '',
    company TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT '',
    flag TEXT NOT NULL DEFAULT '',
    actionable INTEGER NOT NULL DEFAULT 0,
    compact INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
