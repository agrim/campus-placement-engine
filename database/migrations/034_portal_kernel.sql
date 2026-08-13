CREATE TABLE IF NOT EXISTS institutions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    timezone TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

INSERT INTO institutions (public_id, slug, name, timezone, created_at, updated_at)
SELECT
    'inst_' || lower(hex(randomblob(16))),
    'default',
    COALESCE(NULLIF((SELECT value FROM settings WHERE key = 'college_name'), ''), 'Demo College'),
    COALESCE(NULLIF((SELECT value FROM settings WHERE key = 'timezone'), ''), 'Asia/Kolkata'),
    datetime('now'),
    datetime('now')
WHERE NOT EXISTS (SELECT 1 FROM institutions WHERE slug = 'default');

CREATE TABLE IF NOT EXISTS placement_cycles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    institution_id INTEGER NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    cycle_key TEXT NOT NULL,
    name TEXT NOT NULL,
    cycle_type TEXT NOT NULL DEFAULT 'final',
    starts_on TEXT NOT NULL DEFAULT '',
    ends_on TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(institution_id, cycle_key)
);

INSERT INTO placement_cycles (
    public_id, institution_id, cycle_key, name, cycle_type,
    starts_on, ends_on, status, created_at, updated_at
)
SELECT
    'cycle_' || lower(hex(randomblob(16))),
    id,
    'default',
    COALESCE(NULLIF((SELECT value FROM settings WHERE key = 'cycle_name'), ''), 'Placement Cycle'),
    COALESCE(NULLIF((SELECT value FROM settings WHERE key = 'cycle_type'), ''), 'final'),
    COALESCE((SELECT value FROM settings WHERE key = 'cycle_start_date'), ''),
    COALESCE((SELECT value FROM settings WHERE key = 'cycle_end_date'), ''),
    'active',
    datetime('now'),
    datetime('now')
FROM institutions
WHERE slug = 'default'
  AND NOT EXISTS (SELECT 1 FROM placement_cycles WHERE cycle_key = 'default');

CREATE TABLE IF NOT EXISTS module_installations (
    module_key TEXT PRIMARY KEY,
    version TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    installed_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

INSERT INTO module_installations (module_key, version, enabled, installed_at, updated_at)
SELECT 'placement', '0.1.0', 1, datetime('now'), datetime('now')
WHERE NOT EXISTS (SELECT 1 FROM module_installations WHERE module_key = 'placement');

CREATE TABLE IF NOT EXISTS roles (
    role_key TEXT PRIMARY KEY,
    label TEXT NOT NULL,
    system_role INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS role_capabilities (
    role_key TEXT NOT NULL REFERENCES roles(role_key) ON DELETE CASCADE,
    capability TEXT NOT NULL,
    PRIMARY KEY (role_key, capability)
);

CREATE TABLE IF NOT EXISTS user_role_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_key TEXT NOT NULL REFERENCES roles(role_key) ON DELETE CASCADE,
    scope_type TEXT NOT NULL DEFAULT '',
    scope_value TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    UNIQUE(user_id, role_key, scope_type, scope_value)
);

CREATE INDEX IF NOT EXISTS idx_user_role_assignments_user
ON user_role_assignments(user_id);

CREATE TABLE IF NOT EXISTS scoped_settings (
    scope_type TEXT NOT NULL,
    scope_id TEXT NOT NULL,
    setting_key TEXT NOT NULL,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (scope_type, scope_id, setting_key)
);
