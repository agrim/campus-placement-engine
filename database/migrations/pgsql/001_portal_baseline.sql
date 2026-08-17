CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL,
    active SMALLINT NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    scope_type TEXT NOT NULL DEFAULT '',
    scope_value TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGSERIAL PRIMARY KEY,
    actor_user_id BIGINT NULL REFERENCES users(id),
    action TEXT NOT NULL,
    subject_type TEXT NOT NULL,
    subject_id BIGINT NULL,
    detail TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    ip_address TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS institutions (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    timezone TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS roles (
    role_key TEXT PRIMARY KEY,
    label TEXT NOT NULL,
    system_role SMALLINT NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS role_capabilities (
    role_key TEXT NOT NULL REFERENCES roles(role_key) ON DELETE CASCADE,
    capability TEXT NOT NULL,
    PRIMARY KEY (role_key, capability)
);

CREATE TABLE IF NOT EXISTS user_role_assignments (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_key TEXT NOT NULL REFERENCES roles(role_key) ON DELETE CASCADE,
    scope_type TEXT NOT NULL DEFAULT '',
    scope_value TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    UNIQUE(user_id, role_key, scope_type, scope_value)
);

CREATE TABLE IF NOT EXISTS scoped_settings (
    scope_type TEXT NOT NULL,
    scope_id TEXT NOT NULL,
    setting_key TEXT NOT NULL,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (scope_type, scope_id, setting_key)
);

CREATE TABLE IF NOT EXISTS module_installations (
    module_key TEXT PRIMARY KEY,
    version TEXT NOT NULL,
    enabled SMALLINT NOT NULL DEFAULT 1,
    installed_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    installed_by BIGINT NULL REFERENCES users(id),
    enabled_at TEXT NULL,
    disabled_at TEXT NULL,
    configuration_json TEXT NOT NULL DEFAULT '{}'
);

CREATE TABLE IF NOT EXISTS placement_cycles (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE,
    institution_id BIGINT NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    cycle_key TEXT NOT NULL,
    name TEXT NOT NULL,
    cycle_type TEXT NOT NULL DEFAULT 'final',
    starts_on TEXT NOT NULL DEFAULT '',
    ends_on TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    active_workflow_version_id BIGINT NULL,
    UNIQUE(institution_id, cycle_key)
);

CREATE TABLE IF NOT EXISTS workflow_definitions (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE,
    institution_id BIGINT NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    workflow_key TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    source_template_key TEXT NOT NULL DEFAULT '',
    active_version_id BIGINT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(institution_id, workflow_key)
);

CREATE TABLE IF NOT EXISTS workflow_versions (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE,
    workflow_definition_id BIGINT NOT NULL REFERENCES workflow_definitions(id) ON DELETE CASCADE,
    version_number INTEGER NOT NULL,
    lifecycle_status TEXT NOT NULL DEFAULT 'draft',
    source_type TEXT NOT NULL DEFAULT 'template',
    initial_state_key TEXT NOT NULL,
    definition_json TEXT NOT NULL,
    checksum TEXT NOT NULL,
    created_by BIGINT NULL REFERENCES users(id),
    published_by BIGINT NULL REFERENCES users(id),
    published_at TEXT NULL,
    retired_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(workflow_definition_id, version_number),
    UNIQUE(workflow_definition_id, checksum)
);

ALTER TABLE placement_cycles
    ADD CONSTRAINT placement_cycles_active_workflow_fk
    FOREIGN KEY (active_workflow_version_id) REFERENCES workflow_versions(id);

ALTER TABLE workflow_definitions
    ADD CONSTRAINT workflow_definitions_active_version_fk
    FOREIGN KEY (active_version_id) REFERENCES workflow_versions(id);

CREATE TABLE IF NOT EXISTS workflow_states (
    id BIGSERIAL PRIMARY KEY,
    workflow_version_id BIGINT NOT NULL REFERENCES workflow_versions(id) ON DELETE CASCADE,
    state_key TEXT NOT NULL,
    label TEXT NOT NULL,
    semantic_category TEXT NOT NULL,
    display_order INTEGER NOT NULL,
    color TEXT NOT NULL,
    is_terminal SMALLINT NOT NULL DEFAULT 0,
    UNIQUE(workflow_version_id, state_key)
);

CREATE TABLE IF NOT EXISTS workflow_transitions (
    id BIGSERIAL PRIMARY KEY,
    workflow_version_id BIGINT NOT NULL REFERENCES workflow_versions(id) ON DELETE CASCADE,
    transition_key TEXT NOT NULL,
    label TEXT NOT NULL,
    from_state_key TEXT NOT NULL,
    to_state_key TEXT NOT NULL,
    required_capability TEXT NOT NULL DEFAULT 'placement.application.transition',
    roles_csv TEXT NOT NULL,
    guards_json TEXT NOT NULL DEFAULT '[]',
    effects_json TEXT NOT NULL DEFAULT '[]',
    display_order INTEGER NOT NULL DEFAULT 0,
    is_correction SMALLINT NOT NULL DEFAULT 0,
    UNIQUE(workflow_version_id, transition_key)
);

CREATE TABLE IF NOT EXISTS companies (
    id BIGSERIAL PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    slot TEXT NOT NULL DEFAULT '',
    offer_tier TEXT NOT NULL DEFAULT '',
    process_type TEXT NOT NULL DEFAULT '',
    room TEXT NOT NULL DEFAULT '',
    tracker_name TEXT NOT NULL DEFAULT '',
    max_active INTEGER NOT NULL DEFAULT 0,
    process_notes TEXT NOT NULL DEFAULT '',
    deadline_day TEXT NOT NULL DEFAULT '',
    deadline_at TEXT NOT NULL DEFAULT '',
    tags TEXT NOT NULL DEFAULT '',
    custom_fields_json TEXT NOT NULL DEFAULT '{}',
    public_id TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS candidates (
    id BIGSERIAL PRIMARY KEY,
    external_id TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    program TEXT NOT NULL DEFAULT '',
    current_location TEXT NOT NULL DEFAULT 'CP',
    placed_company_id BIGINT NULL REFERENCES companies(id),
    opted_out SMALLINT NOT NULL DEFAULT 0,
    accommodation_notes TEXT NOT NULL DEFAULT '',
    anonymized_at TEXT NULL,
    tags TEXT NOT NULL DEFAULT '',
    custom_fields_json TEXT NOT NULL DEFAULT '{}',
    public_id TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS people (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE,
    institution_id BIGINT NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    legacy_candidate_id BIGINT NULL UNIQUE REFERENCES candidates(id) ON DELETE CASCADE,
    display_name TEXT NOT NULL,
    anonymized_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS student_profiles (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE,
    institution_id BIGINT NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    person_id BIGINT NOT NULL UNIQUE REFERENCES people(id) ON DELETE CASCADE,
    external_id TEXT NOT NULL,
    program TEXT NOT NULL DEFAULT '',
    tags TEXT NOT NULL DEFAULT '',
    accommodation_notes TEXT NOT NULL DEFAULT '',
    custom_fields_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(institution_id, external_id)
);

CREATE TABLE IF NOT EXISTS organizations (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE,
    institution_id BIGINT NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    legacy_company_id BIGINT NULL UNIQUE REFERENCES companies(id) ON DELETE CASCADE,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    tags TEXT NOT NULL DEFAULT '',
    custom_fields_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(institution_id, code)
);

CREATE TABLE IF NOT EXISTS placement_cycle_participants (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE,
    cycle_id BIGINT NOT NULL REFERENCES placement_cycles(id) ON DELETE CASCADE,
    student_profile_id BIGINT NOT NULL REFERENCES student_profiles(id) ON DELETE CASCADE,
    legacy_candidate_id BIGINT NULL UNIQUE REFERENCES candidates(id) ON DELETE CASCADE,
    participation_status TEXT NOT NULL DEFAULT 'active',
    opted_out SMALLINT NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(cycle_id, student_profile_id)
);

CREATE TABLE IF NOT EXISTS placement_opportunities (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE,
    cycle_id BIGINT NOT NULL REFERENCES placement_cycles(id) ON DELETE CASCADE,
    organization_id BIGINT NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    legacy_company_id BIGINT NULL UNIQUE REFERENCES companies(id) ON DELETE CASCADE,
    opportunity_key TEXT NOT NULL,
    title TEXT NOT NULL,
    slot TEXT NOT NULL DEFAULT '',
    offer_tier TEXT NOT NULL DEFAULT '',
    process_type TEXT NOT NULL DEFAULT '',
    room TEXT NOT NULL DEFAULT '',
    tracker_name TEXT NOT NULL DEFAULT '',
    max_active INTEGER NOT NULL DEFAULT 0,
    deadline_day TEXT NOT NULL DEFAULT '',
    deadline_at TEXT NOT NULL DEFAULT '',
    process_notes TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'open',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(cycle_id, opportunity_key)
);

CREATE TABLE IF NOT EXISTS applications (
    id BIGSERIAL PRIMARY KEY,
    candidate_id BIGINT NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    current_status TEXT NOT NULL DEFAULT 'idle',
    previous_company_id BIGINT NULL REFERENCES companies(id),
    next_company_id BIGINT NULL REFERENCES companies(id),
    waitlist_rank INTEGER NULL,
    public_id TEXT NULL,
    participant_id BIGINT NULL REFERENCES placement_cycle_participants(id),
    opportunity_id BIGINT NULL REFERENCES placement_opportunities(id),
    workflow_version_id BIGINT NULL REFERENCES workflow_versions(id),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(candidate_id, company_id)
);

CREATE TABLE IF NOT EXISTS workflow_instances (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE,
    application_id BIGINT NOT NULL UNIQUE REFERENCES applications(id) ON DELETE CASCADE,
    workflow_version_id BIGINT NOT NULL REFERENCES workflow_versions(id),
    current_state_key TEXT NOT NULL,
    started_at TEXT NOT NULL,
    completed_at TEXT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS workflow_transition_events (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE,
    workflow_instance_id BIGINT NOT NULL REFERENCES workflow_instances(id) ON DELETE CASCADE,
    application_id BIGINT NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    workflow_version_id BIGINT NOT NULL REFERENCES workflow_versions(id),
    workflow_transition_id BIGINT NULL REFERENCES workflow_transitions(id),
    transition_key TEXT NOT NULL,
    from_state_key TEXT NOT NULL,
    to_state_key TEXT NOT NULL,
    actor_user_id BIGINT NULL REFERENCES users(id),
    actor_role TEXT NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    note TEXT NOT NULL DEFAULT '',
    context_json TEXT NOT NULL DEFAULT '{}',
    occurred_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS placement_presence (
    participant_id BIGINT PRIMARY KEY REFERENCES placement_cycle_participants(id) ON DELETE CASCADE,
    current_location TEXT NOT NULL DEFAULT 'CP',
    previous_opportunity_id BIGINT NULL REFERENCES placement_opportunities(id),
    next_opportunity_id BIGINT NULL REFERENCES placement_opportunities(id),
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS placement_offers (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE,
    participant_id BIGINT NOT NULL REFERENCES placement_cycle_participants(id) ON DELETE CASCADE,
    opportunity_id BIGINT NOT NULL REFERENCES placement_opportunities(id) ON DELETE CASCADE,
    offer_status TEXT NOT NULL DEFAULT 'accepted',
    offer_tier TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT 'placement',
    offered_at TEXT NULL,
    decided_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(participant_id, opportunity_id, source)
);

CREATE TABLE IF NOT EXISTS events (
    id BIGSERIAL PRIMARY KEY,
    application_id BIGINT NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    candidate_id BIGINT NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    from_status TEXT NOT NULL,
    to_status TEXT NOT NULL,
    actor_user_id BIGINT NULL REFERENCES users(id),
    actor_role TEXT NOT NULL,
    note TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS preference_requests (
    id BIGSERIAL PRIMARY KEY,
    candidate_id BIGINT NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'open',
    note TEXT NOT NULL DEFAULT '',
    requested_by BIGINT NULL REFERENCES users(id),
    decision_company_id BIGINT NULL REFERENCES companies(id),
    created_at TEXT NOT NULL,
    resolved_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS preference_options (
    id BIGSERIAL PRIMARY KEY,
    request_id BIGINT NOT NULL REFERENCES preference_requests(id) ON DELETE CASCADE,
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE(request_id, company_id)
);

CREATE TABLE IF NOT EXISTS wanted_alerts (
    id BIGSERIAL PRIMARY KEY,
    candidate_id BIGINT NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    reason TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    created_by BIGINT NULL REFERENCES users(id),
    resolved_by BIGINT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    resolved_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS workflow_status_overrides (
    status_key TEXT PRIMARY KEY,
    label TEXT NOT NULL,
    color TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS workflow_transition_overrides (
    from_status TEXT NOT NULL,
    to_status TEXT NOT NULL,
    roles_csv TEXT NOT NULL,
    PRIMARY KEY (from_status, to_status)
);

CREATE TABLE IF NOT EXISTS company_rounds (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
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

CREATE TABLE IF NOT EXISTS round_panelists (
    id BIGSERIAL PRIMARY KEY,
    round_id BIGINT NOT NULL REFERENCES company_rounds(id) ON DELETE CASCADE,
    sequence INTEGER NOT NULL DEFAULT 1,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT '',
    affiliation TEXT NOT NULL DEFAULT '',
    contact TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    availability_status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(round_id, sequence, name)
);

CREATE TABLE IF NOT EXISTS round_schedules (
    id BIGSERIAL PRIMARY KEY,
    round_id BIGINT NOT NULL REFERENCES company_rounds(id) ON DELETE CASCADE,
    sequence INTEGER NOT NULL DEFAULT 1,
    room TEXT NOT NULL DEFAULT '',
    starts_at TEXT NOT NULL DEFAULT '',
    ends_at TEXT NOT NULL DEFAULT '',
    capacity INTEGER NOT NULL DEFAULT 0,
    notes TEXT NOT NULL DEFAULT '',
    schedule_status TEXT NOT NULL DEFAULT 'active',
    schedule_day TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(round_id, sequence, room, starts_at)
);

CREATE TABLE IF NOT EXISTS application_slot_assignments (
    id BIGSERIAL PRIMARY KEY,
    application_id BIGINT NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    round_schedule_id BIGINT NOT NULL REFERENCES round_schedules(id) ON DELETE CASCADE,
    sequence INTEGER NOT NULL DEFAULT 1,
    assignment_status TEXT NOT NULL DEFAULT 'assigned',
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(application_id, round_schedule_id)
);

CREATE TABLE IF NOT EXISTS candidate_unavailability_windows (
    id BIGSERIAL PRIMARY KEY,
    candidate_id BIGINT NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    label TEXT NOT NULL DEFAULT '',
    schedule_day TEXT NOT NULL DEFAULT '',
    starts_at TEXT NOT NULL DEFAULT '',
    ends_at TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(candidate_id, schedule_day, starts_at, ends_at, label)
);

CREATE TABLE IF NOT EXISTS user_board_preferences (
    user_id BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    q TEXT NOT NULL DEFAULT '',
    company TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT '',
    flag TEXT NOT NULL DEFAULT '',
    actionable SMALLINT NOT NULL DEFAULT 0,
    compact SMALLINT NOT NULL DEFAULT 0,
    stale_minutes INTEGER NOT NULL DEFAULT 90,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS notifications (
    id BIGSERIAL PRIMARY KEY,
    recipient_role TEXT NOT NULL DEFAULT '',
    recipient_scope_type TEXT NOT NULL DEFAULT '',
    recipient_scope_value TEXT NOT NULL DEFAULT '',
    channel TEXT NOT NULL DEFAULT 'in_app',
    template_key TEXT NOT NULL DEFAULT '',
    subject TEXT NOT NULL,
    body TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'open',
    source_type TEXT NOT NULL DEFAULT '',
    source_id BIGINT NULL,
    created_by BIGINT NULL REFERENCES users(id),
    acknowledged_by BIGINT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    acknowledged_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS notification_deliveries (
    id BIGSERIAL PRIMARY KEY,
    notification_id BIGINT NOT NULL REFERENCES notifications(id) ON DELETE CASCADE,
    channel TEXT NOT NULL,
    target TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'queued',
    attempt_count INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '',
    payload_json TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    delivered_at TEXT NULL,
    UNIQUE(notification_id, channel, target)
);

CREATE TABLE IF NOT EXISTS idempotency_keys (
    key TEXT PRIMARY KEY,
    actor_user_id BIGINT NULL REFERENCES users(id),
    action TEXT NOT NULL,
    application_id BIGINT NULL REFERENCES applications(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS module_lifecycle_events (
    id BIGSERIAL PRIMARY KEY,
    module_key TEXT NOT NULL,
    event_type TEXT NOT NULL,
    from_version TEXT NULL,
    to_version TEXT NULL,
    actor_user_id BIGINT NULL REFERENCES users(id),
    detail TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS domain_event_outbox (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE,
    event_name TEXT NOT NULL,
    aggregate_type TEXT NOT NULL,
    aggregate_public_id TEXT NOT NULL,
    institution_id BIGINT NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    module_key TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    occurred_at TEXT NOT NULL,
    available_at TEXT NOT NULL,
    processed_at TEXT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT ''
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_candidates_public_id ON candidates(public_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_companies_public_id ON companies(public_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_applications_public_id ON applications(public_id);
CREATE INDEX IF NOT EXISTS idx_applications_status ON applications(current_status);
CREATE INDEX IF NOT EXISTS idx_applications_candidate ON applications(candidate_id);
CREATE INDEX IF NOT EXISTS idx_applications_company ON applications(company_id);
CREATE INDEX IF NOT EXISTS idx_applications_participant ON applications(participant_id);
CREATE INDEX IF NOT EXISTS idx_applications_opportunity ON applications(opportunity_id);
CREATE INDEX IF NOT EXISTS idx_applications_workflow_version ON applications(workflow_version_id);
CREATE INDEX IF NOT EXISTS idx_events_application ON events(application_id);
CREATE INDEX IF NOT EXISTS idx_events_candidate ON events(candidate_id);
CREATE INDEX IF NOT EXISTS idx_preference_requests_candidate ON preference_requests(candidate_id);
CREATE INDEX IF NOT EXISTS idx_wanted_alerts_candidate ON wanted_alerts(candidate_id);
CREATE INDEX IF NOT EXISTS idx_wanted_alerts_status ON wanted_alerts(status);
CREATE INDEX IF NOT EXISTS idx_companies_process_type ON companies(process_type);
CREATE INDEX IF NOT EXISTS idx_company_rounds_company ON company_rounds(company_id);
CREATE INDEX IF NOT EXISTS idx_round_panelists_round ON round_panelists(round_id);
CREATE INDEX IF NOT EXISTS idx_round_schedules_round ON round_schedules(round_id);
CREATE INDEX IF NOT EXISTS idx_application_slot_assignments_application ON application_slot_assignments(application_id);
CREATE INDEX IF NOT EXISTS idx_application_slot_assignments_schedule ON application_slot_assignments(round_schedule_id);
CREATE INDEX IF NOT EXISTS idx_notifications_status ON notifications(status);
CREATE INDEX IF NOT EXISTS idx_notifications_recipient ON notifications(recipient_role, recipient_scope_type, recipient_scope_value);
CREATE INDEX IF NOT EXISTS idx_notifications_source ON notifications(source_type, source_id);
CREATE INDEX IF NOT EXISTS idx_notification_deliveries_status ON notification_deliveries(status);
CREATE INDEX IF NOT EXISTS idx_notification_deliveries_notification ON notification_deliveries(notification_id);
CREATE INDEX IF NOT EXISTS idx_candidate_unavailability_candidate ON candidate_unavailability_windows(candidate_id);
CREATE INDEX IF NOT EXISTS idx_idempotency_created_at ON idempotency_keys(created_at);
CREATE INDEX IF NOT EXISTS idx_user_role_assignments_user ON user_role_assignments(user_id);
CREATE INDEX IF NOT EXISTS idx_people_institution ON people(institution_id);
CREATE INDEX IF NOT EXISTS idx_student_profiles_institution ON student_profiles(institution_id);
CREATE INDEX IF NOT EXISTS idx_organizations_institution ON organizations(institution_id);
CREATE INDEX IF NOT EXISTS idx_placement_participants_cycle ON placement_cycle_participants(cycle_id);
CREATE INDEX IF NOT EXISTS idx_placement_opportunities_cycle ON placement_opportunities(cycle_id);
CREATE INDEX IF NOT EXISTS idx_workflow_definitions_institution ON workflow_definitions(institution_id);
CREATE INDEX IF NOT EXISTS idx_workflow_versions_definition ON workflow_versions(workflow_definition_id, lifecycle_status);
CREATE INDEX IF NOT EXISTS idx_workflow_states_version ON workflow_states(workflow_version_id, display_order);
CREATE INDEX IF NOT EXISTS idx_workflow_transitions_version_from ON workflow_transitions(workflow_version_id, from_state_key, display_order);
CREATE INDEX IF NOT EXISTS idx_workflow_instances_version ON workflow_instances(workflow_version_id);
CREATE INDEX IF NOT EXISTS idx_workflow_transition_events_instance ON workflow_transition_events(workflow_instance_id, occurred_at);
CREATE INDEX IF NOT EXISTS idx_module_lifecycle_events_module ON module_lifecycle_events(module_key, created_at);
CREATE INDEX IF NOT EXISTS idx_domain_event_outbox_pending ON domain_event_outbox(processed_at, available_at);

INSERT INTO settings (key, value) VALUES
    ('board_card_fields', 'candidate_id,program,tags,company,process,tracker,active_cap,rounds,schedule,slot,panel,route,location,accommodation,waitlist'),
    ('board_refresh_seconds', '45'),
    ('configuration_freeze', '0'),
    ('export_profile_custom_datasets', 'placement_totals,application_status_counts,placements_by_company'),
    ('import_header_aliases_json', ''),
    ('terminology_candidate_label', 'Candidate'),
    ('terminology_candidates_label', 'Candidates'),
    ('terminology_company_label', 'Company'),
    ('terminology_companies_label', 'Companies'),
    ('site_name', 'Campus Placement Engine'),
    ('site_tagline', ''),
    ('public_placements_title', 'Public Placements'),
    ('candidate_status_title', ''),
    ('calendar_non_operating_weekdays', ''),
    ('calendar_non_operating_dates', ''),
    ('audit_request_metadata', 'none')
ON CONFLICT(key) DO NOTHING;
