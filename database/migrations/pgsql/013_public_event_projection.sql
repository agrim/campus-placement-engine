ALTER TABLE applications
ADD COLUMN aggregate_version INTEGER NOT NULL DEFAULT 1;

ALTER TABLE applications
ADD CONSTRAINT applications_aggregate_version_positive CHECK (aggregate_version > 0);

ALTER TABLE domain_event_outbox ADD COLUMN public_event_type TEXT NULL;
ALTER TABLE domain_event_outbox ADD COLUMN public_schema_version INTEGER NULL;
ALTER TABLE domain_event_outbox ADD COLUMN public_instance_id TEXT NULL;
ALTER TABLE domain_event_outbox ADD COLUMN public_aggregate_type TEXT NULL;
ALTER TABLE domain_event_outbox ADD COLUMN public_aggregate_id TEXT NULL;
ALTER TABLE domain_event_outbox ADD COLUMN public_aggregate_version INTEGER NULL;
ALTER TABLE domain_event_outbox ADD COLUMN public_payload_json TEXT NULL;
ALTER TABLE domain_event_outbox ADD COLUMN public_correlation_id TEXT NULL;

CREATE OR REPLACE FUNCTION cpe_guard_application_aggregate_version()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.aggregate_version < 1
       OR (NEW.current_status IS DISTINCT FROM OLD.current_status
           AND NEW.aggregate_version <> OLD.aggregate_version + 1)
       OR (NEW.current_status IS NOT DISTINCT FROM OLD.current_status
           AND NEW.aggregate_version <> OLD.aggregate_version) THEN
        RAISE EXCEPTION 'application status and aggregate version must advance together';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER applications_status_version_update
BEFORE UPDATE OF current_status, aggregate_version ON applications
FOR EACH ROW EXECUTE FUNCTION cpe_guard_application_aggregate_version();

CREATE OR REPLACE FUNCTION cpe_guard_public_event_projection()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    projection_count INTEGER;
    public_payload JSON;
BEGIN
    projection_count := num_nonnulls(
        NEW.public_event_type,
        NEW.public_schema_version,
        NEW.public_instance_id,
        NEW.public_aggregate_type,
        NEW.public_aggregate_id,
        NEW.public_aggregate_version,
        NEW.public_payload_json,
        NEW.public_correlation_id
    );
    IF projection_count NOT IN (0, 8) THEN
        RAISE EXCEPTION 'public event projection must be entirely absent or entirely present';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF ROW(
            OLD.public_event_type,
            OLD.public_schema_version,
            OLD.public_instance_id,
            OLD.public_aggregate_type,
            OLD.public_aggregate_id,
            OLD.public_aggregate_version,
            OLD.public_payload_json,
            OLD.public_correlation_id
        ) IS DISTINCT FROM ROW(
            NEW.public_event_type,
            NEW.public_schema_version,
            NEW.public_instance_id,
            NEW.public_aggregate_type,
            NEW.public_aggregate_id,
            NEW.public_aggregate_version,
            NEW.public_payload_json,
            NEW.public_correlation_id
        ) THEN
            RAISE EXCEPTION 'public event projection is immutable';
        END IF;
        IF OLD.public_event_type IS NOT NULL
           AND ROW(
               OLD.public_id,
               OLD.event_name,
               OLD.aggregate_type,
               OLD.aggregate_public_id,
               OLD.institution_id,
               OLD.module_key,
               OLD.payload_json,
               OLD.occurred_at
           ) IS DISTINCT FROM ROW(
               NEW.public_id,
               NEW.event_name,
               NEW.aggregate_type,
               NEW.aggregate_public_id,
               NEW.institution_id,
               NEW.module_key,
               NEW.payload_json,
               NEW.occurred_at
           ) THEN
            RAISE EXCEPTION 'public event identity and content are immutable';
        END IF;
    END IF;

    IF NEW.public_event_type IS NOT NULL THEN
        IF NEW.public_event_type <> 'application.status_changed'
           OR NEW.public_schema_version <> 1
           OR NEW.public_aggregate_type <> 'application'
           OR NEW.public_aggregate_version < 2
           OR NEW.public_id !~ '^event_[a-f0-9]{32}$'
           OR NEW.public_instance_id !~ '^(inst|tenant)_[a-f0-9]{32}$'
           OR NEW.public_aggregate_id !~ '^application_[a-f0-9]{32}$'
           OR NEW.public_correlation_id !~ '^req_[a-f0-9]{24}$' THEN
            RAISE EXCEPTION 'invalid public event projection';
        END IF;
        BEGIN
            public_payload := NEW.public_payload_json::json;
        EXCEPTION WHEN OTHERS THEN
            RAISE EXCEPTION 'invalid public event projection';
        END;
        IF json_typeof(public_payload) <> 'object'
           OR (SELECT COUNT(*) FROM json_each(public_payload)) <> 2
           OR (SELECT COUNT(*) FROM json_each(public_payload) WHERE key = 'from_status') <> 1
           OR (SELECT COUNT(*) FROM json_each(public_payload) WHERE key = 'to_status') <> 1
           OR json_typeof(public_payload->'from_status') <> 'string'
           OR json_typeof(public_payload->'to_status') <> 'string'
           OR public_payload->>'from_status' !~ '^[a-z][a-z0-9_]{0,79}$'
           OR public_payload->>'to_status' !~ '^[a-z][a-z0-9_]{0,79}$'
           OR public_payload->>'from_status' = public_payload->>'to_status' THEN
            RAISE EXCEPTION 'invalid public event projection';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER domain_event_outbox_public_projection_guard
BEFORE INSERT OR UPDATE ON domain_event_outbox
FOR EACH ROW EXECUTE FUNCTION cpe_guard_public_event_projection();

CREATE UNIQUE INDEX idx_domain_event_outbox_public_aggregate_version
ON domain_event_outbox(
    public_event_type,
    public_aggregate_type,
    public_aggregate_id,
    public_aggregate_version
)
WHERE public_event_type IS NOT NULL;

CREATE INDEX idx_domain_event_outbox_public_pending
ON domain_event_outbox(
    processed_at,
    failed_at,
    available_at,
    public_aggregate_type,
    public_aggregate_id,
    public_aggregate_version
)
WHERE public_event_type IS NOT NULL;
