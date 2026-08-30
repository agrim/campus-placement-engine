INSERT INTO settings (key, value) VALUES ('api_enabled', '0')
ON CONFLICT(key) DO NOTHING;

CREATE TABLE IF NOT EXISTS api_service_accounts (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE CHECK (public_id ~ '^apisa_[a-f0-9]{32}$'),
    institution_id BIGINT NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    name TEXT NOT NULL CHECK (char_length(name) BETWEEN 1 AND 120),
    status TEXT NOT NULL DEFAULT 'enabled'
        CHECK (status IN ('enabled', 'disabled', 'revoked')),
    disabled_at TEXT NULL,
    revoked_at TEXT NULL,
    created_by_user_id BIGINT NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (
        (status = 'enabled' AND disabled_at IS NULL AND revoked_at IS NULL)
        OR (status = 'disabled' AND disabled_at IS NOT NULL AND revoked_at IS NULL)
        OR (status = 'revoked' AND revoked_at IS NOT NULL)
    )
);

CREATE TABLE IF NOT EXISTS api_service_account_scopes (
    service_account_id BIGINT NOT NULL REFERENCES api_service_accounts(id) ON DELETE CASCADE,
    scope TEXT NOT NULL CHECK (scope IN ('opportunities.read', 'applications.read')),
    created_by_user_id BIGINT NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    PRIMARY KEY (service_account_id, scope)
);

CREATE TABLE IF NOT EXISTS api_access_tokens (
    id BIGSERIAL PRIMARY KEY,
    service_account_id BIGINT NOT NULL REFERENCES api_service_accounts(id) ON DELETE CASCADE,
    lookup_id TEXT NOT NULL UNIQUE CHECK (lookup_id ~ '^[a-f0-9]{32}$'),
    secret_verifier BYTEA NOT NULL CHECK (octet_length(secret_verifier) = 32),
    key_version TEXT NOT NULL CHECK (key_version ~ '^[A-Za-z0-9_.-]{1,32}$'),
    expires_at TEXT NOT NULL,
    rotation_grace_expires_at TEXT NULL,
    revoked_at TEXT NULL,
    last_used_at TEXT NULL,
    created_by_user_id BIGINT NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (expires_at > created_at),
    CHECK (rotation_grace_expires_at IS NULL
        OR (rotation_grace_expires_at > created_at AND rotation_grace_expires_at <= expires_at)),
    CHECK (revoked_at IS NULL OR revoked_at >= created_at),
    CHECK (last_used_at IS NULL OR last_used_at >= created_at),
    CHECK (updated_at >= created_at)
);

CREATE TABLE IF NOT EXISTS api_rate_limit_buckets (
    id BIGSERIAL PRIMARY KEY,
    institution_id BIGINT NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    token_id BIGINT NULL REFERENCES api_access_tokens(id) ON DELETE CASCADE,
    dimension TEXT NOT NULL CHECK (dimension IN ('institution', 'token', 'source')),
    bucket_key TEXT NOT NULL CHECK (bucket_key ~ '^[a-f0-9]{64}$'),
    route_class TEXT NOT NULL CHECK (route_class ~ '^[a-z0-9_.-]{1,80}$'),
    window_started_at TEXT NOT NULL,
    window_seconds INTEGER NOT NULL CHECK (window_seconds BETWEEN 1 AND 86400),
    request_count INTEGER NOT NULL DEFAULT 1 CHECK (request_count >= 1),
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK ((dimension = 'token') = (token_id IS NOT NULL)),
    CHECK (expires_at > window_started_at),
    CHECK (updated_at >= created_at),
    UNIQUE (institution_id, dimension, bucket_key, route_class, window_started_at, window_seconds)
);

CREATE TABLE IF NOT EXISTS api_request_audit_events (
    id BIGSERIAL PRIMARY KEY,
    public_id TEXT NOT NULL UNIQUE CHECK (public_id ~ '^apiaud_[a-f0-9]{32}$'),
    institution_id BIGINT NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    service_account_id BIGINT NULL REFERENCES api_service_accounts(id) ON DELETE SET NULL,
    token_id BIGINT NULL REFERENCES api_access_tokens(id) ON DELETE SET NULL,
    request_id TEXT NOT NULL CHECK (request_id ~ '^req_[a-f0-9]{32}$'),
    route_class TEXT NOT NULL CHECK (route_class ~ '^[a-z0-9_.-]{1,80}$'),
    required_scope TEXT NOT NULL DEFAULT ''
        CHECK (required_scope IN ('', 'opportunities.read', 'applications.read')),
    outcome TEXT NOT NULL
        CHECK (outcome IN ('authenticated', 'authorized', 'denied', 'rate_limited', 'succeeded', 'failed')),
    status_code INTEGER NOT NULL CHECK (status_code BETWEEN 100 AND 599),
    detail_code TEXT NOT NULL DEFAULT '' CHECK (detail_code ~ '^[A-Z0-9_]{0,80}$'),
    source_fingerprint TEXT NOT NULL DEFAULT ''
        CHECK (source_fingerprint = '' OR source_fingerprint ~ '^[a-f0-9]{64}$'),
    retention_until TEXT NOT NULL,
    created_at TEXT NOT NULL,
    CHECK (retention_until > created_at)
);

CREATE INDEX IF NOT EXISTS idx_api_service_accounts_institution_status
ON api_service_accounts(institution_id, status);

CREATE INDEX IF NOT EXISTS idx_api_service_account_scopes_scope
ON api_service_account_scopes(scope, service_account_id);

CREATE INDEX IF NOT EXISTS idx_api_access_tokens_account_lifecycle
ON api_access_tokens(service_account_id, revoked_at, expires_at, rotation_grace_expires_at);

CREATE INDEX IF NOT EXISTS idx_api_access_tokens_key_version
ON api_access_tokens(key_version, revoked_at, expires_at);

CREATE UNIQUE INDEX IF NOT EXISTS idx_api_access_tokens_one_current
ON api_access_tokens(service_account_id)
WHERE revoked_at IS NULL AND rotation_grace_expires_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_api_rate_limit_buckets_expiry
ON api_rate_limit_buckets(expires_at, id);

CREATE INDEX IF NOT EXISTS idx_api_request_audit_retention
ON api_request_audit_events(retention_until, id);

CREATE INDEX IF NOT EXISTS idx_api_request_audit_aggregate
ON api_request_audit_events(institution_id, outcome, created_at);

CREATE OR REPLACE FUNCTION cpe_guard_api_enabled_setting()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.key = 'api_enabled' THEN
            RAISE EXCEPTION 'api_enabled is required local security state';
        END IF;
        RETURN OLD;
    END IF;
    IF NEW.key = 'api_enabled' AND NEW.value NOT IN ('0', '1') THEN
        RAISE EXCEPTION 'api_enabled must be 0 or 1';
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.key = 'api_enabled' AND NEW.key <> 'api_enabled' THEN
        RAISE EXCEPTION 'api_enabled must remain a boolean local setting';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER api_enabled_setting_guard
BEFORE INSERT OR UPDATE OR DELETE ON settings
FOR EACH ROW EXECUTE FUNCTION cpe_guard_api_enabled_setting();

CREATE OR REPLACE FUNCTION cpe_guard_api_service_account()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF ROW(OLD.public_id, OLD.institution_id, OLD.created_by_user_id, OLD.created_at)
        IS DISTINCT FROM ROW(NEW.public_id, NEW.institution_id, NEW.created_by_user_id, NEW.created_at) THEN
        RAISE EXCEPTION 'API service-account identity is immutable';
    END IF;
    IF OLD.status = 'revoked' AND NEW.status <> 'revoked' THEN
        RAISE EXCEPTION 'API service-account revocation is terminal';
    END IF;
    IF OLD.revoked_at IS NOT NULL AND OLD.revoked_at IS DISTINCT FROM NEW.revoked_at THEN
        RAISE EXCEPTION 'API service-account revocation is immutable';
    END IF;
    IF NEW.updated_at < OLD.updated_at THEN
        RAISE EXCEPTION 'API service-account update timestamp cannot move backward';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER api_service_account_guard
BEFORE UPDATE ON api_service_accounts
FOR EACH ROW EXECUTE FUNCTION cpe_guard_api_service_account();

CREATE OR REPLACE FUNCTION cpe_guard_api_scope_update()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'API scope grants are exact immutable rows';
END;
$$;

CREATE TRIGGER api_service_account_scope_update_guard
BEFORE UPDATE ON api_service_account_scopes
FOR EACH ROW EXECUTE FUNCTION cpe_guard_api_scope_update();

CREATE OR REPLACE FUNCTION cpe_guard_api_access_token()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        PERFORM id FROM api_service_accounts
        WHERE id = NEW.service_account_id
        FOR UPDATE;
        IF NEW.revoked_at IS NULL AND (
            SELECT COUNT(*) FROM api_access_tokens
            WHERE service_account_id = NEW.service_account_id AND revoked_at IS NULL
        ) >= 2 THEN
            RAISE EXCEPTION 'API service account cannot retain more than two unrevoked tokens';
        END IF;
        RETURN NEW;
    END IF;
    IF ROW(
        OLD.service_account_id, OLD.lookup_id, OLD.secret_verifier, OLD.key_version,
        OLD.expires_at, OLD.created_by_user_id, OLD.created_at
    ) IS DISTINCT FROM ROW(
        NEW.service_account_id, NEW.lookup_id, NEW.secret_verifier, NEW.key_version,
        NEW.expires_at, NEW.created_by_user_id, NEW.created_at
    ) THEN
        RAISE EXCEPTION 'API token identity and expiry are immutable';
    END IF;
    IF OLD.rotation_grace_expires_at IS NOT NULL
        AND OLD.rotation_grace_expires_at IS DISTINCT FROM NEW.rotation_grace_expires_at THEN
        RAISE EXCEPTION 'API token rotation grace is immutable once assigned';
    END IF;
    IF OLD.revoked_at IS NOT NULL AND OLD.revoked_at IS DISTINCT FROM NEW.revoked_at THEN
        RAISE EXCEPTION 'API token revocation is immutable';
    END IF;
    IF OLD.last_used_at IS NOT NULL
        AND (NEW.last_used_at IS NULL OR NEW.last_used_at < OLD.last_used_at) THEN
        RAISE EXCEPTION 'API token last-used timestamp cannot move backward';
    END IF;
    IF NEW.updated_at < OLD.updated_at THEN
        RAISE EXCEPTION 'API token update timestamp cannot move backward';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER api_access_token_guard
BEFORE INSERT OR UPDATE ON api_access_tokens
FOR EACH ROW EXECUTE FUNCTION cpe_guard_api_access_token();

CREATE OR REPLACE FUNCTION cpe_guard_api_rate_limit_bucket()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF ROW(
        OLD.institution_id, OLD.token_id, OLD.dimension, OLD.bucket_key,
        OLD.route_class, OLD.window_started_at, OLD.window_seconds, OLD.expires_at, OLD.created_at
    ) IS DISTINCT FROM ROW(
        NEW.institution_id, NEW.token_id, NEW.dimension, NEW.bucket_key,
        NEW.route_class, NEW.window_started_at, NEW.window_seconds, NEW.expires_at, NEW.created_at
    ) OR NEW.request_count < OLD.request_count OR NEW.updated_at < OLD.updated_at THEN
        RAISE EXCEPTION 'API rate-limit bucket identity is immutable';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER api_rate_limit_bucket_identity_guard
BEFORE UPDATE ON api_rate_limit_buckets
FOR EACH ROW EXECUTE FUNCTION cpe_guard_api_rate_limit_bucket();

CREATE OR REPLACE FUNCTION cpe_guard_api_rate_limit_institution()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.token_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM api_access_tokens token
        JOIN api_service_accounts account ON account.id = token.service_account_id
        WHERE token.id = NEW.token_id AND account.institution_id = NEW.institution_id
    ) THEN
        RAISE EXCEPTION 'API rate-limit token must belong to its institution';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER api_rate_limit_bucket_institution_guard
BEFORE INSERT OR UPDATE ON api_rate_limit_buckets
FOR EACH ROW EXECUTE FUNCTION cpe_guard_api_rate_limit_institution();

CREATE OR REPLACE FUNCTION cpe_guard_api_request_audit_institution()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.service_account_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM api_service_accounts account
        WHERE account.id = NEW.service_account_id
          AND account.institution_id = NEW.institution_id
    ) THEN
        RAISE EXCEPTION 'API request audit service account must belong to its institution';
    END IF;
    IF NEW.token_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM api_access_tokens token
        JOIN api_service_accounts account ON account.id = token.service_account_id
        WHERE token.id = NEW.token_id
          AND account.institution_id = NEW.institution_id
          AND (NEW.service_account_id IS NULL OR token.service_account_id = NEW.service_account_id)
    ) THEN
        RAISE EXCEPTION 'API request audit token must belong to its institution and account';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER api_request_audit_institution_guard
BEFORE INSERT ON api_request_audit_events
FOR EACH ROW EXECUTE FUNCTION cpe_guard_api_request_audit_institution();

CREATE OR REPLACE FUNCTION cpe_guard_api_request_audit()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'API request audit events are immutable';
END;
$$;

CREATE TRIGGER api_request_audit_immutable
BEFORE UPDATE ON api_request_audit_events
FOR EACH ROW EXECUTE FUNCTION cpe_guard_api_request_audit();
