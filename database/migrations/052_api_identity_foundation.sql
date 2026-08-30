INSERT INTO settings (key, value) VALUES ('api_enabled', '0')
ON CONFLICT(key) DO NOTHING;

CREATE TABLE IF NOT EXISTS api_service_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE
        CHECK (length(public_id) = 38
            AND public_id GLOB 'apisa_[0-9a-f]*'
            AND substr(public_id, 7) NOT GLOB '*[^0-9a-f]*'),
    institution_id INTEGER NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
    status TEXT NOT NULL DEFAULT 'enabled'
        CHECK (status IN ('enabled', 'disabled', 'revoked')),
    disabled_at TEXT NULL,
    revoked_at TEXT NULL,
    created_by_user_id INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (
        (status = 'enabled' AND disabled_at IS NULL AND revoked_at IS NULL)
        OR (status = 'disabled' AND disabled_at IS NOT NULL AND revoked_at IS NULL)
        OR (status = 'revoked' AND revoked_at IS NOT NULL)
    )
);

CREATE TABLE IF NOT EXISTS api_service_account_scopes (
    service_account_id INTEGER NOT NULL REFERENCES api_service_accounts(id) ON DELETE CASCADE,
    scope TEXT NOT NULL CHECK (scope IN ('opportunities.read', 'applications.read')),
    created_by_user_id INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    PRIMARY KEY (service_account_id, scope)
);

CREATE TABLE IF NOT EXISTS api_access_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_account_id INTEGER NOT NULL REFERENCES api_service_accounts(id) ON DELETE CASCADE,
    lookup_id TEXT NOT NULL UNIQUE
        CHECK (length(lookup_id) = 32
            AND lookup_id GLOB '[0-9a-f]*'
            AND lookup_id NOT GLOB '*[^0-9a-f]*'),
    secret_verifier BLOB NOT NULL
        CHECK (typeof(secret_verifier) = 'blob' AND length(secret_verifier) = 32),
    key_version TEXT NOT NULL
        CHECK (length(key_version) BETWEEN 1 AND 32
            AND key_version NOT GLOB '*[^A-Za-z0-9_.-]*'),
    expires_at TEXT NOT NULL,
    rotation_grace_expires_at TEXT NULL,
    revoked_at TEXT NULL,
    last_used_at TEXT NULL,
    created_by_user_id INTEGER NOT NULL REFERENCES users(id),
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
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    institution_id INTEGER NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
    token_id INTEGER NULL REFERENCES api_access_tokens(id) ON DELETE CASCADE,
    dimension TEXT NOT NULL CHECK (dimension IN ('institution', 'token', 'source')),
    bucket_key TEXT NOT NULL
        CHECK (length(bucket_key) = 64
            AND bucket_key GLOB '[0-9a-f]*'
            AND bucket_key NOT GLOB '*[^0-9a-f]*'),
    route_class TEXT NOT NULL
        CHECK (length(route_class) BETWEEN 1 AND 80
            AND route_class NOT GLOB '*[^a-z0-9_.-]*'),
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
        CHECK (required_scope IN ('', 'opportunities.read', 'applications.read')),
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

CREATE TRIGGER IF NOT EXISTS api_enabled_setting_guard_insert
BEFORE INSERT ON settings
WHEN NEW.key = 'api_enabled' AND NEW.value NOT IN ('0', '1')
BEGIN
    SELECT RAISE(ABORT, 'api_enabled must be 0 or 1');
END;

CREATE TRIGGER IF NOT EXISTS api_enabled_setting_guard_update
BEFORE UPDATE OF key, value ON settings
WHEN (NEW.key = 'api_enabled' AND NEW.value NOT IN ('0', '1'))
  OR (OLD.key = 'api_enabled' AND NEW.key <> 'api_enabled')
BEGIN
    SELECT RAISE(ABORT, 'api_enabled must remain a boolean local setting');
END;

CREATE TRIGGER IF NOT EXISTS api_enabled_setting_guard_delete
BEFORE DELETE ON settings
WHEN OLD.key = 'api_enabled'
BEGIN
    SELECT RAISE(ABORT, 'api_enabled is required local security state');
END;

CREATE TRIGGER IF NOT EXISTS api_service_account_guard
BEFORE UPDATE ON api_service_accounts
WHEN OLD.public_id IS NOT NEW.public_id
  OR OLD.institution_id IS NOT NEW.institution_id
  OR OLD.created_by_user_id IS NOT NEW.created_by_user_id
  OR OLD.created_at IS NOT NEW.created_at
  OR (OLD.status = 'revoked' AND NEW.status <> 'revoked')
  OR (OLD.revoked_at IS NOT NULL AND OLD.revoked_at IS NOT NEW.revoked_at)
  OR NEW.updated_at < OLD.updated_at
BEGIN
    SELECT RAISE(ABORT, 'API service-account identity and revocation are immutable');
END;

CREATE TRIGGER IF NOT EXISTS api_service_account_scope_update_guard
BEFORE UPDATE ON api_service_account_scopes
BEGIN
    SELECT RAISE(ABORT, 'API scope grants are exact immutable rows');
END;

CREATE TRIGGER IF NOT EXISTS api_access_token_insert_guard
BEFORE INSERT ON api_access_tokens
WHEN NEW.revoked_at IS NULL
 AND (SELECT COUNT(*) FROM api_access_tokens
      WHERE service_account_id = NEW.service_account_id AND revoked_at IS NULL) >= 2
BEGIN
    SELECT RAISE(ABORT, 'API service account cannot retain more than two unrevoked tokens');
END;

CREATE TRIGGER IF NOT EXISTS api_access_token_update_guard
BEFORE UPDATE ON api_access_tokens
WHEN OLD.service_account_id IS NOT NEW.service_account_id
  OR OLD.lookup_id IS NOT NEW.lookup_id
  OR OLD.secret_verifier IS NOT NEW.secret_verifier
  OR OLD.key_version IS NOT NEW.key_version
  OR OLD.expires_at IS NOT NEW.expires_at
  OR OLD.created_by_user_id IS NOT NEW.created_by_user_id
  OR OLD.created_at IS NOT NEW.created_at
  OR (OLD.rotation_grace_expires_at IS NOT NULL
      AND OLD.rotation_grace_expires_at IS NOT NEW.rotation_grace_expires_at)
  OR (OLD.revoked_at IS NOT NULL AND OLD.revoked_at IS NOT NEW.revoked_at)
  OR (OLD.last_used_at IS NOT NULL
      AND (NEW.last_used_at IS NULL OR NEW.last_used_at < OLD.last_used_at))
  OR NEW.updated_at < OLD.updated_at
BEGIN
    SELECT RAISE(ABORT, 'API token identity, expiry, and lifecycle transitions are immutable');
END;

CREATE TRIGGER IF NOT EXISTS api_rate_limit_bucket_identity_guard
BEFORE UPDATE ON api_rate_limit_buckets
WHEN OLD.institution_id IS NOT NEW.institution_id
  OR OLD.token_id IS NOT NEW.token_id
  OR OLD.dimension IS NOT NEW.dimension
  OR OLD.bucket_key IS NOT NEW.bucket_key
  OR OLD.route_class IS NOT NEW.route_class
  OR OLD.window_started_at IS NOT NEW.window_started_at
  OR OLD.window_seconds IS NOT NEW.window_seconds
  OR OLD.expires_at IS NOT NEW.expires_at
  OR OLD.created_at IS NOT NEW.created_at
  OR NEW.request_count < OLD.request_count
  OR NEW.updated_at < OLD.updated_at
BEGIN
    SELECT RAISE(ABORT, 'API rate-limit bucket identity is immutable');
END;

CREATE TRIGGER IF NOT EXISTS api_rate_limit_bucket_institution_guard_insert
BEFORE INSERT ON api_rate_limit_buckets
WHEN NEW.token_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
    FROM api_access_tokens token
    JOIN api_service_accounts account ON account.id = token.service_account_id
    WHERE token.id = NEW.token_id AND account.institution_id = NEW.institution_id
)
BEGIN
    SELECT RAISE(ABORT, 'API rate-limit token must belong to its institution');
END;

CREATE TRIGGER IF NOT EXISTS api_rate_limit_bucket_institution_guard_update
BEFORE UPDATE ON api_rate_limit_buckets
WHEN NEW.token_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
    FROM api_access_tokens token
    JOIN api_service_accounts account ON account.id = token.service_account_id
    WHERE token.id = NEW.token_id AND account.institution_id = NEW.institution_id
)
BEGIN
    SELECT RAISE(ABORT, 'API rate-limit token must belong to its institution');
END;

CREATE TRIGGER IF NOT EXISTS api_request_audit_institution_guard_insert
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

CREATE TRIGGER IF NOT EXISTS api_request_audit_immutable
BEFORE UPDATE ON api_request_audit_events
BEGIN
    SELECT RAISE(ABORT, 'API request audit events are immutable');
END;
