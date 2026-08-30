CREATE TABLE IF NOT EXISTS webhook_subscriptions (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE CHECK (public_id ~ '^whsub_[a-f0-9]{32}$'),
    institution_id BIGINT NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    name TEXT NOT NULL CHECK (char_length(name) BETWEEN 1 AND 120),
    endpoint_url TEXT NOT NULL CHECK (char_length(endpoint_url) BETWEEN 1 AND 2048),
    endpoint_version INTEGER NOT NULL DEFAULT 1 CHECK (endpoint_version > 0),
    lifecycle_state TEXT NOT NULL DEFAULT 'disabled'
        CHECK (lifecycle_state IN ('disabled', 'setup_required', 'validating', 'active', 'degraded')),
    allow_private_network SMALLINT NOT NULL DEFAULT 0 CHECK (allow_private_network IN (0, 1)),
    current_secret_ciphertext TEXT NULL CHECK (current_secret_ciphertext IS NULL OR char_length(current_secret_ciphertext) BETWEEN 40 AND 512),
    current_secret_nonce TEXT NULL CHECK (current_secret_nonce IS NULL OR char_length(current_secret_nonce) BETWEEN 16 AND 64),
    current_secret_tag TEXT NULL CHECK (current_secret_tag IS NULL OR char_length(current_secret_tag) BETWEEN 16 AND 64),
    current_secret_key_version TEXT NULL CHECK (current_secret_key_version IS NULL OR char_length(current_secret_key_version) BETWEEN 1 AND 32),
    previous_secret_ciphertext TEXT NULL CHECK (previous_secret_ciphertext IS NULL OR char_length(previous_secret_ciphertext) BETWEEN 40 AND 512),
    previous_secret_nonce TEXT NULL CHECK (previous_secret_nonce IS NULL OR char_length(previous_secret_nonce) BETWEEN 16 AND 64),
    previous_secret_tag TEXT NULL CHECK (previous_secret_tag IS NULL OR char_length(previous_secret_tag) BETWEEN 16 AND 64),
    previous_secret_key_version TEXT NULL CHECK (previous_secret_key_version IS NULL OR char_length(previous_secret_key_version) BETWEEN 1 AND 32),
    previous_secret_expires_at TEXT NULL,
    last_validated_at TEXT NULL,
    last_success_at TEXT NULL,
    last_failure_at TEXT NULL,
    last_failure_code TEXT NOT NULL DEFAULT '' CHECK (char_length(last_failure_code) <= 80),
    last_failure_reference TEXT NOT NULL DEFAULT '' CHECK (char_length(last_failure_reference) <= 80),
    consecutive_failures INTEGER NOT NULL DEFAULT 0 CHECK (consecutive_failures >= 0),
    circuit_open_until TEXT NULL,
    disabled_at TEXT NULL,
    revoked_at TEXT NULL,
    created_by_user_id BIGINT NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (num_nonnulls(current_secret_ciphertext, current_secret_nonce, current_secret_tag, current_secret_key_version) IN (0, 4)),
    CHECK (num_nonnulls(previous_secret_ciphertext, previous_secret_nonce, previous_secret_tag, previous_secret_key_version, previous_secret_expires_at) IN (0, 5))
);

CREATE TABLE IF NOT EXISTS webhook_subscription_events (
    subscription_id BIGINT NOT NULL REFERENCES webhook_subscriptions(id) ON DELETE CASCADE,
    event_type TEXT NOT NULL CHECK (event_type = 'application.status_changed'),
    schema_version INTEGER NOT NULL CHECK (schema_version = 1),
    created_at TEXT NOT NULL,
    PRIMARY KEY (subscription_id, event_type, schema_version)
);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE CHECK (public_id ~ '^whdel_[a-f0-9]{32}$'),
    subscription_id BIGINT NOT NULL REFERENCES webhook_subscriptions(id) ON DELETE RESTRICT,
    event_id BIGINT NOT NULL REFERENCES domain_event_outbox(id) ON DELETE RESTRICT,
    endpoint_version INTEGER NOT NULL CHECK (endpoint_version > 0),
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'processing', 'retrying', 'succeeded', 'dead_lettered')),
    attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count BETWEEN 0 AND 100),
    available_at TEXT NOT NULL,
    locked_at TEXT NULL,
    lock_token TEXT NULL CHECK (lock_token IS NULL OR lock_token ~ '^claim_[a-f0-9]{32}$'),
    lease_generation INTEGER NOT NULL DEFAULT 0 CHECK (lease_generation >= 0),
    processed_at TEXT NULL,
    dead_lettered_at TEXT NULL,
    last_http_status INTEGER NULL CHECK (last_http_status IS NULL OR last_http_status BETWEEN 100 AND 599),
    last_error_code TEXT NOT NULL DEFAULT '' CHECK (char_length(last_error_code) <= 80),
    last_failure_reference TEXT NOT NULL DEFAULT '' CHECK (char_length(last_failure_reference) <= 80),
    replayed_at TEXT NULL,
    replayed_by_user_id BIGINT NULL REFERENCES users(id),
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
    singleton_id SMALLINT PRIMARY KEY CHECK (singleton_id = 1),
    worker_public_id TEXT NOT NULL CHECK (char_length(worker_public_id) <= 64),
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

CREATE OR REPLACE FUNCTION cpe_guard_webhook_subscription()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND ROW(
        OLD.public_id, OLD.institution_id, OLD.endpoint_url, OLD.endpoint_version,
        OLD.allow_private_network, OLD.created_by_user_id, OLD.created_at
    ) IS DISTINCT FROM ROW(
        NEW.public_id, NEW.institution_id, NEW.endpoint_url, NEW.endpoint_version,
        NEW.allow_private_network, NEW.created_by_user_id, NEW.created_at
    ) THEN
        RAISE EXCEPTION 'webhook subscription identity is immutable';
    END IF;
    IF TG_OP = 'INSERT' AND NEW.lifecycle_state IN ('active', 'degraded') THEN
        RAISE EXCEPTION 'webhook subscription must complete setup before activation';
    END IF;
    IF NEW.lifecycle_state IN ('active', 'degraded') THEN
        IF NEW.current_secret_ciphertext IS NULL OR NEW.last_validated_at IS NULL THEN
            RAISE EXCEPTION 'active webhook subscription requires a secret and validation';
        END IF;
        IF TG_OP = 'UPDATE' AND NOT EXISTS (
            SELECT 1 FROM webhook_subscription_events selection
            WHERE selection.subscription_id = NEW.id
              AND selection.event_type = 'application.status_changed'
              AND selection.schema_version = 1
        ) THEN
            RAISE EXCEPTION 'active webhook subscription requires event selection';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER webhook_subscription_guard
BEFORE INSERT OR UPDATE ON webhook_subscriptions
FOR EACH ROW EXECUTE FUNCTION cpe_guard_webhook_subscription();

CREATE OR REPLACE FUNCTION cpe_guard_webhook_subscription_event()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') AND EXISTS (
        SELECT 1 FROM webhook_subscriptions subscription
        WHERE subscription.id = OLD.subscription_id
          AND subscription.lifecycle_state IN ('active', 'degraded')
    ) THEN
        RAISE EXCEPTION 'disable webhook subscription before changing event selection';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER webhook_subscription_event_guard
BEFORE UPDATE OR DELETE ON webhook_subscription_events
FOR EACH ROW EXECUTE FUNCTION cpe_guard_webhook_subscription_event();

CREATE OR REPLACE FUNCTION cpe_guard_webhook_delivery()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'INSERT' AND NOT EXISTS (
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
    ) THEN
        RAISE EXCEPTION 'invalid webhook delivery source';
    END IF;
    IF TG_OP = 'UPDATE' AND ROW(
        OLD.public_id, OLD.subscription_id, OLD.event_id, OLD.endpoint_version,
        OLD.created_at, OLD.retention_until
    ) IS DISTINCT FROM ROW(
        NEW.public_id, NEW.subscription_id, NEW.event_id, NEW.endpoint_version,
        NEW.created_at, NEW.retention_until
    ) THEN
        RAISE EXCEPTION 'webhook delivery identity is immutable';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER webhook_delivery_guard
BEFORE INSERT OR UPDATE ON webhook_deliveries
FOR EACH ROW EXECUTE FUNCTION cpe_guard_webhook_delivery();
