CREATE TABLE IF NOT EXISTS auth_login_attempts (
    id BIGSERIAL PRIMARY KEY,
    identity_hash TEXT NOT NULL,
    network_hash TEXT NOT NULL DEFAULT '',
    succeeded SMALLINT NOT NULL DEFAULT 0,
    attempted_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_auth_login_identity ON auth_login_attempts(identity_hash, attempted_at);
CREATE INDEX IF NOT EXISTS idx_auth_login_network ON auth_login_attempts(network_hash, attempted_at);

CREATE TABLE IF NOT EXISTS external_identities (
    id BIGSERIAL PRIMARY KEY,
    provider_key TEXT NOT NULL,
    subject TEXT NOT NULL,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    linked_email TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(provider_key, subject)
);

CREATE INDEX IF NOT EXISTS idx_external_identities_user ON external_identities(user_id);

CREATE TABLE IF NOT EXISTS auth_sso_nonces (
    nonce_hash TEXT PRIMARY KEY,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS web_sessions (
    session_key TEXT PRIMARY KEY,
    payload TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_web_sessions_expiry ON web_sessions(expires_at);
