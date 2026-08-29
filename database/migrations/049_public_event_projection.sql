ALTER TABLE applications
ADD COLUMN aggregate_version INTEGER NOT NULL DEFAULT 1 CHECK (aggregate_version > 0);

ALTER TABLE domain_event_outbox ADD COLUMN public_event_type TEXT NULL;
ALTER TABLE domain_event_outbox ADD COLUMN public_schema_version INTEGER NULL;
ALTER TABLE domain_event_outbox ADD COLUMN public_instance_id TEXT NULL;
ALTER TABLE domain_event_outbox ADD COLUMN public_aggregate_type TEXT NULL;
ALTER TABLE domain_event_outbox ADD COLUMN public_aggregate_id TEXT NULL;
ALTER TABLE domain_event_outbox ADD COLUMN public_aggregate_version INTEGER NULL;
ALTER TABLE domain_event_outbox ADD COLUMN public_payload_json TEXT NULL;
ALTER TABLE domain_event_outbox ADD COLUMN public_correlation_id TEXT NULL;

CREATE TRIGGER IF NOT EXISTS applications_aggregate_version_insert
BEFORE INSERT ON applications
WHEN typeof(NEW.aggregate_version) <> 'integer' OR NEW.aggregate_version < 1
BEGIN
    SELECT RAISE(ABORT, 'applications.aggregate_version must be a positive integer');
END;

CREATE TRIGGER IF NOT EXISTS applications_status_version_update
BEFORE UPDATE OF current_status, aggregate_version ON applications
WHEN typeof(NEW.aggregate_version) <> 'integer'
  OR NEW.aggregate_version < 1
  OR (NEW.current_status <> OLD.current_status AND NEW.aggregate_version <> OLD.aggregate_version + 1)
  OR (NEW.current_status = OLD.current_status AND NEW.aggregate_version <> OLD.aggregate_version)
BEGIN
    SELECT RAISE(ABORT, 'application status and aggregate version must advance together');
END;

CREATE TRIGGER IF NOT EXISTS domain_event_outbox_public_projection_insert
BEFORE INSERT ON domain_event_outbox
WHEN
    (
        (NEW.public_event_type IS NOT NULL)
      + (NEW.public_schema_version IS NOT NULL)
      + (NEW.public_instance_id IS NOT NULL)
      + (NEW.public_aggregate_type IS NOT NULL)
      + (NEW.public_aggregate_id IS NOT NULL)
      + (NEW.public_aggregate_version IS NOT NULL)
      + (NEW.public_payload_json IS NOT NULL)
      + (NEW.public_correlation_id IS NOT NULL)
    ) NOT IN (0, 8)
 OR (
        NEW.public_event_type IS NOT NULL
    AND (
           NEW.public_event_type <> 'application.status_changed'
        OR typeof(NEW.public_schema_version) <> 'integer'
        OR NEW.public_schema_version <> 1
        OR NEW.public_aggregate_type <> 'application'
        OR typeof(NEW.public_aggregate_version) <> 'integer'
        OR NEW.public_aggregate_version < 2
        OR length(NEW.public_id) <> 38
        OR NEW.public_id NOT GLOB 'event_[0-9a-f]*'
        OR substr(NEW.public_id, 7) GLOB '*[^0-9a-f]*'
        OR NOT (
               (length(NEW.public_instance_id) = 37
                AND NEW.public_instance_id GLOB 'inst_[0-9a-f]*'
                AND substr(NEW.public_instance_id, 6) NOT GLOB '*[^0-9a-f]*')
            OR (length(NEW.public_instance_id) = 39
                AND NEW.public_instance_id GLOB 'tenant_[0-9a-f]*'
                AND substr(NEW.public_instance_id, 8) NOT GLOB '*[^0-9a-f]*')
        )
        OR length(NEW.public_aggregate_id) <> 44
        OR NEW.public_aggregate_id NOT GLOB 'application_[0-9a-f]*'
        OR substr(NEW.public_aggregate_id, 13) GLOB '*[^0-9a-f]*'
        OR length(NEW.public_correlation_id) <> 28
        OR NEW.public_correlation_id NOT GLOB 'req_[0-9a-f]*'
        OR substr(NEW.public_correlation_id, 5) GLOB '*[^0-9a-f]*'
        OR json_valid(NEW.public_payload_json) <> 1
        OR json_type(NEW.public_payload_json) <> 'object'
        OR (SELECT COUNT(*) FROM json_each(NEW.public_payload_json)) <> 2
        OR json_type(NEW.public_payload_json, '$.from_status') <> 'text'
        OR json_type(NEW.public_payload_json, '$.to_status') <> 'text'
        OR json_extract(NEW.public_payload_json, '$.from_status') = json_extract(NEW.public_payload_json, '$.to_status')
        OR length(json_extract(NEW.public_payload_json, '$.from_status')) NOT BETWEEN 1 AND 80
        OR length(json_extract(NEW.public_payload_json, '$.to_status')) NOT BETWEEN 1 AND 80
        OR json_extract(NEW.public_payload_json, '$.from_status') GLOB '*[^a-z0-9_]*'
        OR json_extract(NEW.public_payload_json, '$.to_status') GLOB '*[^a-z0-9_]*'
        OR substr(json_extract(NEW.public_payload_json, '$.from_status'), 1, 1) NOT GLOB '[a-z]'
        OR substr(json_extract(NEW.public_payload_json, '$.to_status'), 1, 1) NOT GLOB '[a-z]'
       )
    )
BEGIN
    SELECT RAISE(ABORT, 'invalid public event projection');
END;

CREATE TRIGGER IF NOT EXISTS domain_event_outbox_public_projection_immutable
BEFORE UPDATE OF public_event_type, public_schema_version, public_instance_id,
                 public_aggregate_type, public_aggregate_id, public_aggregate_version,
                 public_payload_json, public_correlation_id
ON domain_event_outbox
WHEN OLD.public_event_type IS NOT NEW.public_event_type
  OR OLD.public_schema_version IS NOT NEW.public_schema_version
  OR OLD.public_instance_id IS NOT NEW.public_instance_id
  OR OLD.public_aggregate_type IS NOT NEW.public_aggregate_type
  OR OLD.public_aggregate_id IS NOT NEW.public_aggregate_id
  OR OLD.public_aggregate_version IS NOT NEW.public_aggregate_version
  OR OLD.public_payload_json IS NOT NEW.public_payload_json
  OR OLD.public_correlation_id IS NOT NEW.public_correlation_id
BEGIN
    SELECT RAISE(ABORT, 'public event projection is immutable');
END;

CREATE TRIGGER IF NOT EXISTS domain_event_outbox_public_identity_immutable
BEFORE UPDATE OF public_id, event_name, aggregate_type, aggregate_public_id,
                 institution_id, module_key, payload_json, occurred_at
ON domain_event_outbox
WHEN OLD.public_event_type IS NOT NULL
 AND (
       OLD.public_id IS NOT NEW.public_id
    OR OLD.event_name IS NOT NEW.event_name
    OR OLD.aggregate_type IS NOT NEW.aggregate_type
    OR OLD.aggregate_public_id IS NOT NEW.aggregate_public_id
    OR OLD.institution_id IS NOT NEW.institution_id
    OR OLD.module_key IS NOT NEW.module_key
    OR OLD.payload_json IS NOT NEW.payload_json
    OR OLD.occurred_at IS NOT NEW.occurred_at
 )
BEGIN
    SELECT RAISE(ABORT, 'public event identity and content are immutable');
END;

CREATE UNIQUE INDEX IF NOT EXISTS idx_domain_event_outbox_public_aggregate_version
ON domain_event_outbox(
    public_event_type,
    public_aggregate_type,
    public_aggregate_id,
    public_aggregate_version
)
WHERE public_event_type IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_domain_event_outbox_public_pending
ON domain_event_outbox(
    processed_at,
    failed_at,
    available_at,
    public_aggregate_type,
    public_aggregate_id,
    public_aggregate_version
)
WHERE public_event_type IS NOT NULL;
