ALTER TABLE api_service_account_scopes
DROP CONSTRAINT api_service_account_scopes_scope_check;

ALTER TABLE api_service_account_scopes
ADD CONSTRAINT api_service_account_scopes_scope_check
CHECK (scope IN ('opportunities.read', 'applications.read', 'applications.transition'));

ALTER TABLE api_request_audit_events
DROP CONSTRAINT api_request_audit_events_required_scope_check;

ALTER TABLE api_request_audit_events
ADD CONSTRAINT api_request_audit_events_required_scope_check
CHECK (required_scope IN ('', 'opportunities.read', 'applications.read', 'applications.transition'));

ALTER TABLE events
ADD COLUMN actor_service_account_id BIGINT NULL REFERENCES api_service_accounts(id);

ALTER TABLE workflow_transition_events
ADD COLUMN actor_service_account_id BIGINT NULL REFERENCES api_service_accounts(id);

ALTER TABLE audit_logs
ADD COLUMN actor_service_account_id BIGINT NULL REFERENCES api_service_accounts(id);

ALTER TABLE events
ADD CONSTRAINT events_one_actor_check
CHECK (actor_user_id IS NULL OR actor_service_account_id IS NULL);

ALTER TABLE workflow_transition_events
ADD CONSTRAINT workflow_transition_events_one_actor_check
CHECK (actor_user_id IS NULL OR actor_service_account_id IS NULL);

ALTER TABLE audit_logs
ADD CONSTRAINT audit_logs_one_actor_check
CHECK (actor_user_id IS NULL OR actor_service_account_id IS NULL);

CREATE INDEX idx_events_actor_service_account
ON events(actor_service_account_id)
WHERE actor_service_account_id IS NOT NULL;

CREATE INDEX idx_workflow_transition_events_actor_service_account
ON workflow_transition_events(actor_service_account_id)
WHERE actor_service_account_id IS NOT NULL;

CREATE INDEX idx_audit_logs_actor_service_account
ON audit_logs(actor_service_account_id)
WHERE actor_service_account_id IS NOT NULL;

CREATE OR REPLACE FUNCTION cpe_api_application_actor_matches(
    service_account BIGINT,
    application BIGINT
)
RETURNS BOOLEAN
LANGUAGE sql
STABLE
AS $$
    SELECT EXISTS (
        SELECT 1
        FROM api_service_accounts account
        JOIN placement_cycles cycle ON cycle.institution_id = account.institution_id
        JOIN placement_cycle_participants participant ON participant.cycle_id = cycle.id
        JOIN applications placed_application ON placed_application.participant_id = participant.id
        WHERE account.id = service_account
          AND placed_application.id = application
    )
$$;

CREATE OR REPLACE FUNCTION cpe_guard_api_transition_event_actor()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF ROW(OLD.actor_user_id, OLD.actor_service_account_id)
            IS DISTINCT FROM ROW(NEW.actor_user_id, NEW.actor_service_account_id) THEN
            RAISE EXCEPTION 'API transition event actor is immutable';
        END IF;
        IF OLD.actor_service_account_id IS NOT NULL
            AND OLD.application_id IS DISTINCT FROM NEW.application_id THEN
            RAISE EXCEPTION 'API transition event aggregate is immutable';
        END IF;
    END IF;
    IF NEW.actor_service_account_id IS NOT NULL AND (
        NEW.actor_user_id IS NOT NULL
        OR NOT cpe_api_application_actor_matches(NEW.actor_service_account_id, NEW.application_id)
    ) THEN
        RAISE EXCEPTION 'API transition event actor must exclusively belong to the application institution';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER api_transition_event_actor_guard
BEFORE INSERT OR UPDATE ON events
FOR EACH ROW EXECUTE FUNCTION cpe_guard_api_transition_event_actor();

CREATE OR REPLACE FUNCTION cpe_guard_api_workflow_transition_actor()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF ROW(OLD.actor_user_id, OLD.actor_service_account_id)
            IS DISTINCT FROM ROW(NEW.actor_user_id, NEW.actor_service_account_id) THEN
            RAISE EXCEPTION 'API workflow transition actor is immutable';
        END IF;
        IF OLD.actor_service_account_id IS NOT NULL
            AND OLD.application_id IS DISTINCT FROM NEW.application_id THEN
            RAISE EXCEPTION 'API workflow transition aggregate is immutable';
        END IF;
    END IF;
    IF NEW.actor_service_account_id IS NOT NULL AND (
        NEW.actor_user_id IS NOT NULL
        OR NOT cpe_api_application_actor_matches(NEW.actor_service_account_id, NEW.application_id)
    ) THEN
        RAISE EXCEPTION 'API workflow transition actor must exclusively belong to the application institution';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER api_workflow_transition_actor_guard
BEFORE INSERT OR UPDATE ON workflow_transition_events
FOR EACH ROW EXECUTE FUNCTION cpe_guard_api_workflow_transition_actor();

CREATE OR REPLACE FUNCTION cpe_guard_api_transition_audit_actor()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF ROW(OLD.actor_user_id, OLD.actor_service_account_id)
            IS DISTINCT FROM ROW(NEW.actor_user_id, NEW.actor_service_account_id) THEN
            RAISE EXCEPTION 'API transition audit actor is immutable';
        END IF;
        IF OLD.actor_service_account_id IS NOT NULL
            AND OLD.subject_id IS DISTINCT FROM NEW.subject_id THEN
            RAISE EXCEPTION 'API transition audit aggregate is immutable';
        END IF;
    END IF;
    IF NEW.actor_service_account_id IS NOT NULL AND (
        NEW.actor_user_id IS NOT NULL
        OR NEW.action <> 'transition'
        OR NEW.subject_type <> 'application'
        OR NEW.subject_id IS NULL
        OR NOT cpe_api_application_actor_matches(NEW.actor_service_account_id, NEW.subject_id)
    ) THEN
        RAISE EXCEPTION 'API transition audit actor must exclusively belong to the application institution';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER api_transition_audit_actor_guard
BEFORE INSERT OR UPDATE ON audit_logs
FOR EACH ROW EXECUTE FUNCTION cpe_guard_api_transition_audit_actor();

CREATE TABLE api_command_idempotency_keys (
    id BIGSERIAL PRIMARY KEY,
    institution_id BIGINT NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    service_account_id BIGINT NOT NULL REFERENCES api_service_accounts(id),
    operation TEXT NOT NULL CHECK (operation = 'application.transition'),
    key_version TEXT NOT NULL CHECK (key_version ~ '^[A-Za-z0-9_.-]{1,32}$'),
    key_hash TEXT NOT NULL CHECK (key_hash ~ '^[a-f0-9]{64}$'),
    request_hash TEXT NOT NULL CHECK (request_hash ~ '^[a-f0-9]{64}$'),
    aggregate_public_id TEXT NOT NULL REFERENCES applications(public_id),
    lifecycle_state TEXT NOT NULL DEFAULT 'pending'
        CHECK (lifecycle_state IN ('pending', 'completed')),
    response_json TEXT NULL CHECK (response_json IS NULL OR char_length(response_json) BETWEEN 2 AND 16384),
    response_status INTEGER NULL,
    response_etag TEXT NULL,
    created_at TEXT NOT NULL CHECK (created_at ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'),
    completed_at TEXT NULL CHECK (
        completed_at IS NULL
        OR completed_at ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'
    ),
    expires_at TEXT NOT NULL CHECK (expires_at ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'),
    CHECK (
        (lifecycle_state = 'pending'
            AND response_json IS NULL AND response_status IS NULL
            AND response_etag IS NULL AND completed_at IS NULL)
        OR
        (lifecycle_state = 'completed'
            AND response_json IS NOT NULL AND response_status = 200
            AND response_etag ~ '^"[a-f0-9]{64}"$'
            AND completed_at IS NOT NULL
            AND completed_at >= created_at AND completed_at <= expires_at)
    ),
    UNIQUE (institution_id, operation, key_hash)
);

CREATE INDEX idx_api_command_idempotency_expiry
ON api_command_idempotency_keys(institution_id, expires_at, id);

CREATE INDEX idx_api_command_idempotency_aggregate
ON api_command_idempotency_keys(institution_id, aggregate_public_id, created_at, id);

CREATE OR REPLACE FUNCTION cpe_guard_api_command_idempotency()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.created_at <> to_char(to_timestamp(NEW.created_at, 'YYYY-MM-DD HH24:MI:SS'), 'YYYY-MM-DD HH24:MI:SS')
            OR NEW.expires_at <> to_char(
            to_timestamp(NEW.created_at, 'YYYY-MM-DD HH24:MI:SS') + interval '48 hours',
            'YYYY-MM-DD HH24:MI:SS'
        ) THEN
            RAISE EXCEPTION 'API command idempotency retry horizon must be exactly 48 hours';
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM api_service_accounts account
            WHERE account.id = NEW.service_account_id
              AND account.institution_id = NEW.institution_id
        ) THEN
            RAISE EXCEPTION 'API command service account must belong to its institution';
        END IF;
        IF NOT EXISTS (
            SELECT 1
            FROM applications application
            JOIN placement_cycle_participants participant ON participant.id = application.participant_id
            JOIN placement_cycles cycle ON cycle.id = participant.cycle_id
            WHERE application.public_id = NEW.aggregate_public_id
              AND cycle.institution_id = NEW.institution_id
        ) THEN
            RAISE EXCEPTION 'API command aggregate must belong to its institution';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.completed_at <> to_char(
        to_timestamp(NEW.completed_at, 'YYYY-MM-DD HH24:MI:SS'),
        'YYYY-MM-DD HH24:MI:SS'
    ) THEN
        RAISE EXCEPTION 'API command completion timestamp is invalid';
    END IF;
    IF ROW(
        OLD.institution_id, OLD.service_account_id, OLD.operation, OLD.key_version,
        OLD.key_hash, OLD.request_hash, OLD.aggregate_public_id, OLD.created_at, OLD.expires_at
    ) IS DISTINCT FROM ROW(
        NEW.institution_id, NEW.service_account_id, NEW.operation, NEW.key_version,
        NEW.key_hash, NEW.request_hash, NEW.aggregate_public_id, NEW.created_at, NEW.expires_at
    ) OR OLD.lifecycle_state <> 'pending' OR NEW.lifecycle_state <> 'completed' THEN
        RAISE EXCEPTION 'API command idempotency identity and completed results are immutable';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER api_command_idempotency_guard
BEFORE INSERT OR UPDATE ON api_command_idempotency_keys
FOR EACH ROW EXECUTE FUNCTION cpe_guard_api_command_idempotency();
