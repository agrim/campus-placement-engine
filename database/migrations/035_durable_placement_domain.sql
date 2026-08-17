ALTER TABLE candidates ADD COLUMN public_id TEXT NULL;
ALTER TABLE companies ADD COLUMN public_id TEXT NULL;
ALTER TABLE applications ADD COLUMN public_id TEXT NULL;
ALTER TABLE applications ADD COLUMN participant_id INTEGER NULL REFERENCES placement_cycle_participants(id);
ALTER TABLE applications ADD COLUMN opportunity_id INTEGER NULL REFERENCES placement_opportunities(id);

UPDATE candidates
SET public_id = 'candidate_' || lower(hex(randomblob(16)))
WHERE public_id IS NULL OR public_id = '';

UPDATE companies
SET public_id = 'company_' || lower(hex(randomblob(16)))
WHERE public_id IS NULL OR public_id = '';

UPDATE applications
SET public_id = 'application_' || lower(hex(randomblob(16)))
WHERE public_id IS NULL OR public_id = '';

CREATE UNIQUE INDEX IF NOT EXISTS idx_candidates_public_id
ON candidates(public_id);

CREATE UNIQUE INDEX IF NOT EXISTS idx_companies_public_id
ON companies(public_id);

CREATE UNIQUE INDEX IF NOT EXISTS idx_applications_public_id
ON applications(public_id);

CREATE TABLE IF NOT EXISTS people (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    institution_id INTEGER NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    legacy_candidate_id INTEGER NULL UNIQUE REFERENCES candidates(id) ON DELETE CASCADE,
    display_name TEXT NOT NULL,
    anonymized_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS student_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    institution_id INTEGER NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    person_id INTEGER NOT NULL UNIQUE REFERENCES people(id) ON DELETE CASCADE,
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
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    institution_id INTEGER NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    legacy_company_id INTEGER NULL UNIQUE REFERENCES companies(id) ON DELETE CASCADE,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    tags TEXT NOT NULL DEFAULT '',
    custom_fields_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(institution_id, code)
);

CREATE TABLE IF NOT EXISTS placement_cycle_participants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    cycle_id INTEGER NOT NULL REFERENCES placement_cycles(id) ON DELETE CASCADE,
    student_profile_id INTEGER NOT NULL REFERENCES student_profiles(id) ON DELETE CASCADE,
    legacy_candidate_id INTEGER NULL UNIQUE REFERENCES candidates(id) ON DELETE CASCADE,
    participation_status TEXT NOT NULL DEFAULT 'active',
    opted_out INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(cycle_id, student_profile_id)
);

CREATE TABLE IF NOT EXISTS placement_opportunities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    cycle_id INTEGER NOT NULL REFERENCES placement_cycles(id) ON DELETE CASCADE,
    organization_id INTEGER NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    legacy_company_id INTEGER NULL UNIQUE REFERENCES companies(id) ON DELETE CASCADE,
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

CREATE TABLE IF NOT EXISTS placement_presence (
    participant_id INTEGER PRIMARY KEY REFERENCES placement_cycle_participants(id) ON DELETE CASCADE,
    current_location TEXT NOT NULL DEFAULT 'CP',
    previous_opportunity_id INTEGER NULL REFERENCES placement_opportunities(id),
    next_opportunity_id INTEGER NULL REFERENCES placement_opportunities(id),
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS placement_offers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    participant_id INTEGER NOT NULL REFERENCES placement_cycle_participants(id) ON DELETE CASCADE,
    opportunity_id INTEGER NOT NULL REFERENCES placement_opportunities(id) ON DELETE CASCADE,
    offer_status TEXT NOT NULL DEFAULT 'accepted',
    offer_tier TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT 'placement',
    offered_at TEXT NULL,
    decided_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(participant_id, opportunity_id, source)
);

CREATE INDEX IF NOT EXISTS idx_people_institution
ON people(institution_id);

CREATE INDEX IF NOT EXISTS idx_student_profiles_institution
ON student_profiles(institution_id);

CREATE INDEX IF NOT EXISTS idx_organizations_institution
ON organizations(institution_id);

CREATE INDEX IF NOT EXISTS idx_placement_participants_cycle
ON placement_cycle_participants(cycle_id);

CREATE INDEX IF NOT EXISTS idx_placement_opportunities_cycle
ON placement_opportunities(cycle_id);

CREATE INDEX IF NOT EXISTS idx_applications_participant
ON applications(participant_id);

CREATE INDEX IF NOT EXISTS idx_applications_opportunity
ON applications(opportunity_id);
