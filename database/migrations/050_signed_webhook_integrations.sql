CREATE TABLE IF NOT EXISTS webhook_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE
        CHECK (length(public_id) = 38
            AND public_id GLOB 'whsub_[0-9a-f]*'
            AND substr(public_id, 7) NOT GLOB '*[^0-9a-f]*'),
    institution_id INTEGER NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
    endpoint_url TEXT NOT NULL CHECK (length(endpoint_url) BETWEEN 1 AND 2048),
    endpoint_version INTEGER NOT NULL DEFAULT 1 CHECK (endpoint_version > 0),
    lifecycle_state TEXT NOT NULL DEFAULT 'disabled'
        CHECK (lifecycle_state IN ('disabled', 'setup_required', 'validating', 'active', 'degraded')),
    allow_private_network INTEGER NOT NULL DEFAULT 0 CHECK (allow_private_network IN (0, 1)),
    current_secret_ciphertext TEXT NULL CHECK (current_secret_ciphertext IS NULL OR length(current_secret_ciphertext) BETWEEN 40 AND 512),
    current_secret_nonce TEXT NULL CHECK (current_secret_nonce IS NULL OR length(current_secret_nonce) BETWEEN 16 AND 64),
    current_secret_tag TEXT NULL CHECK (current_secret_tag IS NULL OR length(current_secret_tag) BETWEEN 16 AND 64),
    current_secret_key_version TEXT NULL CHECK (current_secret_key_version IS NULL OR length(current_secret_key_version) BETWEEN 1 AND 32),
    previous_secret_ciphertext TEXT NULL CHECK (previous_secret_ciphertext IS NULL OR length(previous_secret_ciphertext) BETWEEN 40 AND 512),
    previous_secret_nonce TEXT NULL CHECK (previous_secret_nonce IS NULL OR length(previous_secret_nonce) BETWEEN 16 AND 64),
    previous_secret_tag TEXT NULL CHECK (previous_secret_tag IS NULL OR length(previous_secret_tag) BETWEEN 16 AND 64),
    previous_secret_key_version TEXT NULL CHECK (previous_secret_key_version IS NULL OR length(previous_secret_key_version) BETWEEN 1 AND 32),
    previous_secret_expires_at TEXT NULL,
    last_validated_at TEXT NULL,
    last_success_at TEXT NULL,
    last_failure_at TEXT NULL,
    last_failure_code TEXT NOT NULL DEFAULT '' CHECK (length(last_failure_code) <= 80),
    last_failure_reference TEXT NOT NULL DEFAULT '' CHECK (length(last_failure_reference) <= 80),
    consecutive_failures INTEGER NOT NULL DEFAULT 0 CHECK (consecutive_failures >= 0),
    circuit_open_until TEXT NULL,
    disabled_at TEXT NULL,
    revoked_at TEXT NULL,
    created_by_user_id INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (
        ((current_secret_ciphertext IS NOT NULL)
       + (current_secret_nonce IS NOT NULL)
       + (current_secret_tag IS NOT NULL)
       + (current_secret_key_version IS NOT NULL)) IN (0, 4)
    ),
    CHECK (
        ((previous_secret_ciphertext IS NOT NULL)
       + (previous_secret_nonce IS NOT NULL)
       + (previous_secret_tag IS NOT NULL)
       + (previous_secret_key_version IS NOT NULL)
       + (previous_secret_expires_at IS NOT NULL)) IN (0, 5)
    )
);

CREATE TABLE IF NOT EXISTS webhook_subscription_events (
    subscription_id INTEGER NOT NULL REFERENCES webhook_subscriptions(id) ON DELETE CASCADE,
    event_type TEXT NOT NULL CHECK (event_type = 'application.status_changed'),
    schema_version INTEGER NOT NULL CHECK (schema_version = 1),
    created_at TEXT NOT NULL,
    PRIMARY KEY (subscription_id, event_type, schema_version)
);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE
        CHECK (length(public_id) = 38
            AND public_id GLOB 'whdel_[0-9a-f]*'
            AND substr(public_id, 7) NOT GLOB '*[^0-9a-f]*'),
    subscription_id INTEGER NOT NULL REFERENCES webhook_subscriptions(id) ON DELETE RESTRICT,
    event_id INTEGER NOT NULL REFERENCES domain_event_outbox(id) ON DELETE RESTRICT,
    endpoint_version INTEGER NOT NULL CHECK (endpoint_version > 0),
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'processing', 'retrying', 'succeeded', 'dead_lettered')),
    attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count BETWEEN 0 AND 100),
    available_at TEXT NOT NULL,
    locked_at TEXT NULL,
    lock_token TEXT NULL CHECK (lock_token IS NULL OR (length(lock_token) = 38 AND lock_token GLOB 'claim_[0-9a-f]*')),
    lease_generation INTEGER NOT NULL DEFAULT 0 CHECK (lease_generation >= 0),
    processed_at TEXT NULL,
    dead_lettered_at TEXT NULL,
    last_http_status INTEGER NULL CHECK (last_http_status IS NULL OR last_http_status BETWEEN 100 AND 599),
    last_error_code TEXT NOT NULL DEFAULT '' CHECK (length(last_error_code) <= 80),
    last_failure_reference TEXT NOT NULL DEFAULT '' CHECK (length(last_failure_reference) <= 80),
    replayed_at TEXT NULL,
    replayed_by_user_id INTEGER NULL REFERENCES users(id),
    retention_until TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (subscription_id, event_id),
    CHECK ((locked_at IS NULL) = (lock_token IS NULL)),
    CHECK ((status = 'processing') = (locked_at IS NOT NULL)),
    CHECK ((status = 'succeeded') = (processed_at IS NOT NULL)),
    CHECK ((status = 'dead_lettered') = (dead_lettered_at IS NOT NULL))
);

CREATE TABLE IF NOT EXISTS webhook_worker_heartbeat (
    singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1),
    worker_public_id TEXT NOT NULL CHECK (length(worker_public_id) <= 64),
    started_at TEXT NOT NULL,
    finished_at TEXT NULL,
    status TEXT NOT NULL CHECK (status IN ('running', 'ok', 'degraded')),
    claimed_count INTEGER NOT NULL DEFAULT 0 CHECK (claimed_count >= 0),
    succeeded_count INTEGER NOT NULL DEFAULT 0 CHECK (succeeded_count >= 0),
    failed_count INTEGER NOT NULL DEFAULT 0 CHECK (failed_count >= 0)
);

CREATE INDEX IF NOT EXISTS idx_webhook_subscriptions_institution_state
ON webhook_subscriptions(institution_id, lifecycle_state);

CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_claim
ON webhook_deliveries(status, available_at, locked_at, subscription_id, event_id);

CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_subscription_health
ON webhook_deliveries(subscription_id, status, available_at);

CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_retention
ON webhook_deliveries(status, retention_until);

CREATE TRIGGER IF NOT EXISTS webhook_subscription_active_guard_insert
BEFORE INSERT ON webhook_subscriptions
WHEN NEW.lifecycle_state IN ('active', 'degraded')
BEGIN
    SELECT RAISE(ABORT, 'webhook subscription must complete setup before activation');
END;

CREATE TRIGGER IF NOT EXISTS webhook_subscription_active_guard_update
BEFORE UPDATE OF lifecycle_state, current_secret_ciphertext, current_secret_nonce,
    current_secret_tag, current_secret_key_version, last_validated_at
ON webhook_subscriptions
WHEN NEW.lifecycle_state IN ('active', 'degraded')
 AND (
       NEW.current_secret_ciphertext IS NULL
    OR NEW.last_validated_at IS NULL
    OR NOT EXISTS (
        SELECT 1 FROM webhook_subscription_events selection
        WHERE selection.subscription_id = NEW.id
          AND selection.event_type = 'application.status_changed'
          AND selection.schema_version = 1
    )
 )
BEGIN
    SELECT RAISE(ABORT, 'active webhook subscription requires a secret, validation, and event selection');
END;

CREATE TRIGGER IF NOT EXISTS webhook_subscription_identity_immutable
BEFORE UPDATE OF public_id, institution_id, endpoint_url, endpoint_version,
    allow_private_network, created_by_user_id, created_at
ON webhook_subscriptions
WHEN OLD.public_id IS NOT NEW.public_id
  OR OLD.institution_id IS NOT NEW.institution_id
  OR OLD.endpoint_url IS NOT NEW.endpoint_url
  OR OLD.endpoint_version IS NOT NEW.endpoint_version
  OR OLD.allow_private_network IS NOT NEW.allow_private_network
  OR OLD.created_by_user_id IS NOT NEW.created_by_user_id
  OR OLD.created_at IS NOT NEW.created_at
BEGIN
    SELECT RAISE(ABORT, 'webhook subscription identity is immutable');
END;

CREATE TRIGGER IF NOT EXISTS webhook_subscription_event_active_delete_guard
BEFORE DELETE ON webhook_subscription_events
WHEN EXISTS (
    SELECT 1 FROM webhook_subscriptions subscription
    WHERE subscription.id = OLD.subscription_id
      AND subscription.lifecycle_state IN ('active', 'degraded')
)
BEGIN
    SELECT RAISE(ABORT, 'disable webhook subscription before changing event selection');
END;

CREATE TRIGGER IF NOT EXISTS webhook_subscription_event_active_update_guard
BEFORE UPDATE ON webhook_subscription_events
WHEN EXISTS (
    SELECT 1 FROM webhook_subscriptions subscription
    WHERE subscription.id = OLD.subscription_id
      AND subscription.lifecycle_state IN ('active', 'degraded')
)
BEGIN
    SELECT RAISE(ABORT, 'disable webhook subscription before changing event selection');
END;

CREATE TRIGGER IF NOT EXISTS webhook_delivery_source_guard
BEFORE INSERT ON webhook_deliveries
WHEN NOT EXISTS (
    SELECT 1
    FROM webhook_subscriptions subscription
    JOIN webhook_subscription_events selection
      ON selection.subscription_id = subscription.id
    JOIN domain_event_outbox event
      ON event.id = NEW.event_id
     AND event.institution_id = subscription.institution_id
     AND event.public_event_type = selection.event_type
     AND event.public_schema_version = selection.schema_version
    WHERE subscription.id = NEW.subscription_id
      AND subscription.lifecycle_state IN ('active', 'degraded')
      AND subscription.endpoint_version = NEW.endpoint_version
)
BEGIN
    SELECT RAISE(ABORT, 'invalid webhook delivery source');
END;

CREATE TRIGGER IF NOT EXISTS webhook_delivery_identity_immutable
BEFORE UPDATE OF public_id, subscription_id, event_id, endpoint_version, created_at, retention_until
ON webhook_deliveries
WHEN OLD.public_id IS NOT NEW.public_id
  OR OLD.subscription_id IS NOT NEW.subscription_id
  OR OLD.event_id IS NOT NEW.event_id
  OR OLD.endpoint_version IS NOT NEW.endpoint_version
  OR OLD.created_at IS NOT NEW.created_at
  OR OLD.retention_until IS NOT NEW.retention_until
BEGIN
    SELECT RAISE(ABORT, 'webhook delivery identity is immutable');
END;
