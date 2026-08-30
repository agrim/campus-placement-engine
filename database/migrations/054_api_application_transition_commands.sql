DROP TRIGGER IF EXISTS api_service_account_scope_update_guard;

CREATE TABLE api_service_account_scopes_phase4b (
    service_account_id INTEGER NOT NULL REFERENCES api_service_accounts(id) ON DELETE CASCADE,
    scope TEXT NOT NULL CHECK (scope IN ('opportunities.read', 'applications.read', 'applications.transition')),
    created_by_user_id INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    PRIMARY KEY (service_account_id, scope)
);

INSERT INTO api_service_account_scopes_phase4b
    (service_account_id, scope, created_by_user_id, created_at)
SELECT service_account_id, scope, created_by_user_id, created_at
FROM api_service_account_scopes;

DROP TABLE api_service_account_scopes;
ALTER TABLE api_service_account_scopes_phase4b RENAME TO api_service_account_scopes;

CREATE INDEX idx_api_service_account_scopes_scope
ON api_service_account_scopes(scope, service_account_id);

CREATE TRIGGER api_service_account_scope_update_guard
BEFORE UPDATE ON api_service_account_scopes
BEGIN
    SELECT RAISE(ABORT, 'API scope grants are exact immutable rows');
END;

DROP TRIGGER IF EXISTS api_request_audit_institution_guard_insert;
DROP TRIGGER IF EXISTS api_request_audit_immutable;

CREATE TABLE api_request_audit_events_phase4b (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE
        CHECK (length(public_id) = 39
            AND public_id GLOB 'apiaud_[0-9a-f]*'
            AND substr(public_id, 8) NOT GLOB '*[^0-9a-f]*'),
    institution_id INTEGER NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    service_account_id INTEGER NULL REFERENCES api_service_accounts(id) ON DELETE SET NULL,
    token_id INTEGER NULL REFERENCES api_access_tokens(id) ON DELETE SET NULL,
    request_id TEXT NOT NULL
        CHECK (length(request_id) = 36
            AND request_id GLOB 'req_[0-9a-f]*'
            AND substr(request_id, 5) NOT GLOB '*[^0-9a-f]*'),
    route_class TEXT NOT NULL
        CHECK (length(route_class) BETWEEN 1 AND 80
            AND route_class NOT GLOB '*[^a-z0-9_.-]*'),
    required_scope TEXT NOT NULL DEFAULT ''
        CHECK (required_scope IN ('', 'opportunities.read', 'applications.read', 'applications.transition')),
    outcome TEXT NOT NULL
        CHECK (outcome IN ('authenticated', 'authorized', 'denied', 'rate_limited', 'succeeded', 'failed')),
    status_code INTEGER NOT NULL CHECK (status_code BETWEEN 100 AND 599),
    detail_code TEXT NOT NULL DEFAULT ''
        CHECK (length(detail_code) <= 80
            AND detail_code NOT GLOB '*[^A-Z0-9_]*'),
    source_fingerprint TEXT NOT NULL DEFAULT ''
        CHECK (source_fingerprint = '' OR (
            length(source_fingerprint) = 64
            AND source_fingerprint GLOB '[0-9a-f]*'
            AND source_fingerprint NOT GLOB '*[^0-9a-f]*'
        )),
    retention_until TEXT NOT NULL,
    created_at TEXT NOT NULL,
    CHECK (retention_until > created_at)
);

INSERT INTO api_request_audit_events_phase4b
    (id, public_id, institution_id, service_account_id, token_id, request_id,
     route_class, required_scope, outcome, status_code, detail_code,
     source_fingerprint, retention_until, created_at)
SELECT id, public_id, institution_id, service_account_id, token_id, request_id,
       route_class, required_scope, outcome, status_code, detail_code,
       source_fingerprint, retention_until, created_at
FROM api_request_audit_events;

DROP TABLE api_request_audit_events;
ALTER TABLE api_request_audit_events_phase4b RENAME TO api_request_audit_events;

CREATE INDEX idx_api_request_audit_retention
ON api_request_audit_events(retention_until, id);

CREATE INDEX idx_api_request_audit_aggregate
ON api_request_audit_events(institution_id, outcome, created_at);

CREATE TRIGGER api_request_audit_institution_guard_insert
BEFORE INSERT ON api_request_audit_events
WHEN (NEW.service_account_id IS NOT NULL AND NOT EXISTS (
          SELECT 1 FROM api_service_accounts account
          WHERE account.id = NEW.service_account_id
            AND account.institution_id = NEW.institution_id
      ))
   OR (NEW.token_id IS NOT NULL AND NOT EXISTS (
          SELECT 1
          FROM api_access_tokens token
          JOIN api_service_accounts account ON account.id = token.service_account_id
          WHERE token.id = NEW.token_id
            AND account.institution_id = NEW.institution_id
            AND (NEW.service_account_id IS NULL OR token.service_account_id = NEW.service_account_id)
      ))
BEGIN
    SELECT RAISE(ABORT, 'API request audit references must belong to one institution and account');
END;

CREATE TRIGGER api_request_audit_immutable
BEFORE UPDATE ON api_request_audit_events
BEGIN
    SELECT RAISE(ABORT, 'API request audit events are immutable');
END;

ALTER TABLE events
ADD COLUMN actor_service_account_id INTEGER NULL REFERENCES api_service_accounts(id);

ALTER TABLE workflow_transition_events
ADD COLUMN actor_service_account_id INTEGER NULL REFERENCES api_service_accounts(id);

ALTER TABLE audit_logs
ADD COLUMN actor_service_account_id INTEGER NULL REFERENCES api_service_accounts(id);

CREATE INDEX idx_events_actor_service_account
ON events(actor_service_account_id)
WHERE actor_service_account_id IS NOT NULL;

CREATE INDEX idx_workflow_transition_events_actor_service_account
ON workflow_transition_events(actor_service_account_id)
WHERE actor_service_account_id IS NOT NULL;

CREATE INDEX idx_audit_logs_actor_service_account
ON audit_logs(actor_service_account_id)
WHERE actor_service_account_id IS NOT NULL;

CREATE TRIGGER api_transition_event_actor_guard_insert
BEFORE INSERT ON events
WHEN NEW.actor_service_account_id IS NOT NULL AND (
    NEW.actor_user_id IS NOT NULL OR NOT EXISTS (
        SELECT 1
        FROM api_service_accounts account
        JOIN placement_cycles cycle ON cycle.institution_id = account.institution_id
        JOIN placement_cycle_participants participant ON participant.cycle_id = cycle.id
        JOIN applications application ON application.participant_id = participant.id
        WHERE account.id = NEW.actor_service_account_id
          AND application.id = NEW.application_id
    )
)
BEGIN
    SELECT RAISE(ABORT, 'API transition event actor must exclusively belong to the application institution');
END;

CREATE TRIGGER api_transition_event_actor_guard_update
BEFORE UPDATE ON events
WHEN OLD.actor_user_id IS NOT NEW.actor_user_id
  OR OLD.actor_service_account_id IS NOT NEW.actor_service_account_id
  OR (OLD.actor_service_account_id IS NOT NULL
      AND OLD.application_id IS NOT NEW.application_id)
  OR (NEW.actor_service_account_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM api_service_accounts account
        JOIN placement_cycles cycle ON cycle.institution_id = account.institution_id
        JOIN placement_cycle_participants participant ON participant.cycle_id = cycle.id
        JOIN applications application ON application.participant_id = participant.id
        WHERE account.id = NEW.actor_service_account_id
          AND application.id = NEW.application_id
    ))
BEGIN
    SELECT RAISE(ABORT, 'API transition event actor is immutable and institution-bound');
END;

CREATE TRIGGER api_workflow_transition_actor_guard_insert
BEFORE INSERT ON workflow_transition_events
WHEN NEW.actor_service_account_id IS NOT NULL AND (
    NEW.actor_user_id IS NOT NULL OR NOT EXISTS (
        SELECT 1
        FROM api_service_accounts account
        JOIN placement_cycles cycle ON cycle.institution_id = account.institution_id
        JOIN placement_cycle_participants participant ON participant.cycle_id = cycle.id
        JOIN applications application ON application.participant_id = participant.id
        WHERE account.id = NEW.actor_service_account_id
          AND application.id = NEW.application_id
    )
)
BEGIN
    SELECT RAISE(ABORT, 'API workflow transition actor must exclusively belong to the application institution');
END;

CREATE TRIGGER api_workflow_transition_actor_guard_update
BEFORE UPDATE ON workflow_transition_events
WHEN OLD.actor_user_id IS NOT NEW.actor_user_id
  OR OLD.actor_service_account_id IS NOT NEW.actor_service_account_id
  OR (OLD.actor_service_account_id IS NOT NULL
      AND OLD.application_id IS NOT NEW.application_id)
  OR (NEW.actor_service_account_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM api_service_accounts account
        JOIN placement_cycles cycle ON cycle.institution_id = account.institution_id
        JOIN placement_cycle_participants participant ON participant.cycle_id = cycle.id
        JOIN applications application ON application.participant_id = participant.id
        WHERE account.id = NEW.actor_service_account_id
          AND application.id = NEW.application_id
    ))
BEGIN
    SELECT RAISE(ABORT, 'API workflow transition actor is immutable and institution-bound');
END;

CREATE TRIGGER api_transition_audit_actor_guard_insert
BEFORE INSERT ON audit_logs
WHEN NEW.actor_service_account_id IS NOT NULL AND (
    NEW.actor_user_id IS NOT NULL
    OR NEW.action <> 'transition'
    OR NEW.subject_type <> 'application'
    OR NEW.subject_id IS NULL
    OR NOT EXISTS (
        SELECT 1
        FROM api_service_accounts account
        JOIN placement_cycles cycle ON cycle.institution_id = account.institution_id
        JOIN placement_cycle_participants participant ON participant.cycle_id = cycle.id
        JOIN applications application ON application.participant_id = participant.id
        WHERE account.id = NEW.actor_service_account_id
          AND application.id = NEW.subject_id
    )
)
BEGIN
    SELECT RAISE(ABORT, 'API transition audit actor must exclusively belong to the application institution');
END;

CREATE TRIGGER api_transition_audit_actor_guard_update
BEFORE UPDATE ON audit_logs
WHEN OLD.actor_user_id IS NOT NEW.actor_user_id
  OR OLD.actor_service_account_id IS NOT NEW.actor_service_account_id
  OR (OLD.actor_service_account_id IS NOT NULL
      AND OLD.subject_id IS NOT NEW.subject_id)
  OR (NEW.actor_service_account_id IS NOT NULL AND (
        NEW.action <> 'transition'
        OR NEW.subject_type <> 'application'
        OR NEW.subject_id IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM api_service_accounts account
            JOIN placement_cycles cycle ON cycle.institution_id = account.institution_id
            JOIN placement_cycle_participants participant ON participant.cycle_id = cycle.id
            JOIN applications application ON application.participant_id = participant.id
            WHERE account.id = NEW.actor_service_account_id
              AND application.id = NEW.subject_id
        )
    ))
BEGIN
    SELECT RAISE(ABORT, 'API transition audit actor is immutable and institution-bound');
END;

CREATE TABLE api_command_idempotency_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    institution_id INTEGER NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    service_account_id INTEGER NOT NULL REFERENCES api_service_accounts(id),
    operation TEXT NOT NULL CHECK (operation = 'application.transition'),
    key_version TEXT NOT NULL
        CHECK (length(key_version) BETWEEN 1 AND 32
            AND key_version NOT GLOB '*[^A-Za-z0-9_.-]*'),
    key_hash TEXT NOT NULL
        CHECK (length(key_hash) = 64
            AND key_hash GLOB '[0-9a-f]*'
            AND key_hash NOT GLOB '*[^0-9a-f]*'),
    request_hash TEXT NOT NULL
        CHECK (length(request_hash) = 64
            AND request_hash GLOB '[0-9a-f]*'
            AND request_hash NOT GLOB '*[^0-9a-f]*'),
    aggregate_public_id TEXT NOT NULL REFERENCES applications(public_id),
    lifecycle_state TEXT NOT NULL DEFAULT 'pending'
        CHECK (lifecycle_state IN ('pending', 'completed')),
    response_json TEXT NULL CHECK (response_json IS NULL OR length(response_json) BETWEEN 2 AND 16384),
    response_status INTEGER NULL,
    response_etag TEXT NULL,
    created_at TEXT NOT NULL,
    completed_at TEXT NULL,
    expires_at TEXT NOT NULL,
    CHECK (
        (lifecycle_state = 'pending'
            AND response_json IS NULL AND response_status IS NULL
            AND response_etag IS NULL AND completed_at IS NULL)
        OR
        (lifecycle_state = 'completed'
            AND response_json IS NOT NULL AND response_status = 200
            AND length(response_etag) = 66
            AND substr(response_etag, 1, 1) = '"'
            AND substr(response_etag, 66, 1) = '"'
            AND substr(response_etag, 2, 64) NOT GLOB '*[^0-9a-f]*'
            AND completed_at IS NOT NULL
            AND completed_at >= created_at AND completed_at <= expires_at)
    ),
    UNIQUE (institution_id, operation, key_hash)
);

CREATE INDEX idx_api_command_idempotency_expiry
ON api_command_idempotency_keys(institution_id, expires_at, id);

CREATE INDEX idx_api_command_idempotency_aggregate
ON api_command_idempotency_keys(institution_id, aggregate_public_id, created_at, id);

CREATE TRIGGER api_command_idempotency_insert_guard
BEFORE INSERT ON api_command_idempotency_keys
WHEN strftime('%Y-%m-%d %H:%M:%S', NEW.created_at) IS NOT NEW.created_at
  OR NEW.expires_at IS NOT datetime(NEW.created_at, '+48 hours')
  OR (NEW.completed_at IS NOT NULL
      AND strftime('%Y-%m-%d %H:%M:%S', NEW.completed_at) IS NOT NEW.completed_at)
  OR NOT EXISTS (
      SELECT 1 FROM api_service_accounts account
      WHERE account.id = NEW.service_account_id
        AND account.institution_id = NEW.institution_id
  )
  OR NOT EXISTS (
      SELECT 1
      FROM applications application
      JOIN placement_cycle_participants participant ON participant.id = application.participant_id
      JOIN placement_cycles cycle ON cycle.id = participant.cycle_id
      WHERE application.public_id = NEW.aggregate_public_id
        AND cycle.institution_id = NEW.institution_id
  )
BEGIN
    SELECT RAISE(ABORT, 'API command idempotency identity, institution, or retry horizon is invalid');
END;

CREATE TRIGGER api_command_idempotency_update_guard
BEFORE UPDATE ON api_command_idempotency_keys
WHEN OLD.institution_id IS NOT NEW.institution_id
  OR OLD.service_account_id IS NOT NEW.service_account_id
  OR OLD.operation IS NOT NEW.operation
  OR OLD.key_version IS NOT NEW.key_version
  OR OLD.key_hash IS NOT NEW.key_hash
  OR OLD.request_hash IS NOT NEW.request_hash
  OR OLD.aggregate_public_id IS NOT NEW.aggregate_public_id
  OR OLD.created_at IS NOT NEW.created_at
  OR OLD.expires_at IS NOT NEW.expires_at
  OR OLD.lifecycle_state <> 'pending'
  OR NEW.lifecycle_state <> 'completed'
  OR strftime('%Y-%m-%d %H:%M:%S', NEW.completed_at) IS NOT NEW.completed_at
BEGIN
    SELECT RAISE(ABORT, 'API command idempotency identity and completed results are immutable');
END;
