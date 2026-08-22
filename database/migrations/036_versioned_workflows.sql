CREATE TABLE IF NOT EXISTS workflow_definitions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    institution_id INTEGER NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    workflow_key TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    source_template_key TEXT NOT NULL DEFAULT '',
    active_version_id INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(institution_id, workflow_key)
);

CREATE TABLE IF NOT EXISTS workflow_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    workflow_definition_id INTEGER NOT NULL REFERENCES workflow_definitions(id) ON DELETE CASCADE,
    version_number INTEGER NOT NULL,
    lifecycle_status TEXT NOT NULL DEFAULT 'draft',
    source_type TEXT NOT NULL DEFAULT 'template',
    initial_state_key TEXT NOT NULL,
    definition_json TEXT NOT NULL,
    checksum TEXT NOT NULL,
    created_by INTEGER NULL REFERENCES users(id),
    published_by INTEGER NULL REFERENCES users(id),
    published_at TEXT NULL,
    retired_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(workflow_definition_id, version_number),
    UNIQUE(workflow_definition_id, checksum)
);

CREATE TABLE IF NOT EXISTS workflow_states (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_version_id INTEGER NOT NULL REFERENCES workflow_versions(id) ON DELETE CASCADE,
    state_key TEXT NOT NULL,
    label TEXT NOT NULL,
    semantic_category TEXT NOT NULL,
    display_order INTEGER NOT NULL,
    color TEXT NOT NULL,
    is_terminal INTEGER NOT NULL DEFAULT 0,
    UNIQUE(workflow_version_id, state_key)
);

CREATE TABLE IF NOT EXISTS workflow_transitions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_version_id INTEGER NOT NULL REFERENCES workflow_versions(id) ON DELETE CASCADE,
    transition_key TEXT NOT NULL,
    label TEXT NOT NULL,
    from_state_key TEXT NOT NULL,
    to_state_key TEXT NOT NULL,
    required_capability TEXT NOT NULL DEFAULT 'placement.application.transition',
    roles_csv TEXT NOT NULL,
    guards_json TEXT NOT NULL DEFAULT '[]',
    effects_json TEXT NOT NULL DEFAULT '[]',
    display_order INTEGER NOT NULL DEFAULT 0,
    is_correction INTEGER NOT NULL DEFAULT 0,
    UNIQUE(workflow_version_id, transition_key)
);

CREATE TABLE IF NOT EXISTS workflow_instances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    application_id INTEGER NOT NULL UNIQUE REFERENCES applications(id) ON DELETE CASCADE,
    workflow_version_id INTEGER NOT NULL REFERENCES workflow_versions(id),
    current_state_key TEXT NOT NULL,
    started_at TEXT NOT NULL,
    completed_at TEXT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS workflow_transition_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    workflow_instance_id INTEGER NOT NULL REFERENCES workflow_instances(id) ON DELETE CASCADE,
    application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    workflow_version_id INTEGER NOT NULL REFERENCES workflow_versions(id),
    workflow_transition_id INTEGER NULL REFERENCES workflow_transitions(id),
    transition_key TEXT NOT NULL,
    from_state_key TEXT NOT NULL,
    to_state_key TEXT NOT NULL,
    actor_user_id INTEGER NULL REFERENCES users(id),
    actor_role TEXT NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    note TEXT NOT NULL DEFAULT '',
    context_json TEXT NOT NULL DEFAULT '{}',
    occurred_at TEXT NOT NULL
);

ALTER TABLE placement_cycles ADD COLUMN active_workflow_version_id INTEGER NULL REFERENCES workflow_versions(id);
ALTER TABLE applications ADD COLUMN workflow_version_id INTEGER NULL REFERENCES workflow_versions(id);

CREATE INDEX IF NOT EXISTS idx_workflow_definitions_institution
ON workflow_definitions(institution_id);

CREATE INDEX IF NOT EXISTS idx_workflow_versions_definition
ON workflow_versions(workflow_definition_id, lifecycle_status);

CREATE INDEX IF NOT EXISTS idx_workflow_states_version
ON workflow_states(workflow_version_id, display_order);

CREATE INDEX IF NOT EXISTS idx_workflow_transitions_version_from
ON workflow_transitions(workflow_version_id, from_state_key, display_order);

CREATE INDEX IF NOT EXISTS idx_workflow_instances_version
ON workflow_instances(workflow_version_id);

CREATE INDEX IF NOT EXISTS idx_workflow_transition_events_instance
ON workflow_transition_events(workflow_instance_id, occurred_at);

CREATE INDEX IF NOT EXISTS idx_applications_workflow_version
ON applications(workflow_version_id);
