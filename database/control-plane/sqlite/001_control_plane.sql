CREATE TABLE IF NOT EXISTS hosted_plans (
    plan_key TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS hosted_plan_modules (
    plan_key TEXT NOT NULL REFERENCES hosted_plans(plan_key) ON DELETE CASCADE,
    module_key TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (plan_key, module_key)
);

CREATE TABLE IF NOT EXISTS hosted_tenants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'provisioning',
    plan_key TEXT NOT NULL REFERENCES hosted_plans(plan_key),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS hosted_domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES hosted_tenants(id) ON DELETE CASCADE,
    hostname TEXT NOT NULL UNIQUE,
    active INTEGER NOT NULL DEFAULT 1,
    verified_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS hosted_deployments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    tenant_id INTEGER NOT NULL REFERENCES hosted_tenants(id) ON DELETE CASCADE,
    environment TEXT NOT NULL DEFAULT 'production',
    region TEXT NOT NULL DEFAULT 'local',
    database_reference TEXT NOT NULL,
    release_version TEXT NOT NULL DEFAULT '',
    desired_version TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'provisioning',
    last_health_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (tenant_id, environment),
    UNIQUE (database_reference)
);

CREATE TABLE IF NOT EXISTS hosted_tenant_module_entitlements (
    tenant_id INTEGER NOT NULL REFERENCES hosted_tenants(id) ON DELETE CASCADE,
    module_key TEXT NOT NULL,
    enabled INTEGER NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (tenant_id, module_key)
);

CREATE TABLE IF NOT EXISTS hosted_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    tenant_id INTEGER NULL REFERENCES hosted_tenants(id) ON DELETE CASCADE,
    deployment_id INTEGER NULL REFERENCES hosted_deployments(id) ON DELETE CASCADE,
    action TEXT NOT NULL,
    idempotency_key TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    payload_json TEXT NOT NULL DEFAULT '{}',
    last_error TEXT NOT NULL DEFAULT '',
    started_at TEXT NULL,
    completed_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS hosted_backup_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    tenant_id INTEGER NOT NULL REFERENCES hosted_tenants(id) ON DELETE CASCADE,
    deployment_id INTEGER NOT NULL REFERENCES hosted_deployments(id) ON DELETE CASCADE,
    job_id INTEGER NULL REFERENCES hosted_jobs(id) ON DELETE SET NULL,
    driver TEXT NOT NULL,
    storage_locator TEXT NOT NULL,
    sha256 TEXT NOT NULL,
    status TEXT NOT NULL,
    verified_at TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS hosted_support_access_grants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    tenant_id INTEGER NOT NULL REFERENCES hosted_tenants(id) ON DELETE CASCADE,
    subject TEXT NOT NULL,
    reason TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    expires_at TEXT NOT NULL,
    revoked_at TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS hosted_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NULL REFERENCES hosted_tenants(id) ON DELETE SET NULL,
    actor TEXT NOT NULL,
    action TEXT NOT NULL,
    subject_type TEXT NOT NULL,
    subject_public_id TEXT NOT NULL DEFAULT '',
    detail_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_hosted_domains_tenant ON hosted_domains(tenant_id);
CREATE INDEX IF NOT EXISTS idx_hosted_deployments_tenant ON hosted_deployments(tenant_id);
CREATE INDEX IF NOT EXISTS idx_hosted_jobs_status ON hosted_jobs(status, action, created_at);
CREATE INDEX IF NOT EXISTS idx_hosted_backups_tenant ON hosted_backup_records(tenant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_hosted_support_tenant ON hosted_support_access_grants(tenant_id, status, expires_at);

INSERT INTO hosted_plans (plan_key, name, active, created_at, updated_at)
VALUES ('community', 'Community', 1, '1970-01-01 00:00:00', '1970-01-01 00:00:00')
ON CONFLICT(plan_key) DO NOTHING;

INSERT INTO hosted_plans (plan_key, name, active, created_at, updated_at)
VALUES ('career_services', 'Career Services', 1, '1970-01-01 00:00:00', '1970-01-01 00:00:00')
ON CONFLICT(plan_key) DO NOTHING;

INSERT INTO hosted_plan_modules (plan_key, module_key, enabled)
VALUES ('community', 'placement', 1), ('career_services', 'placement', 1), ('career_services', 'advising', 1)
ON CONFLICT(plan_key, module_key) DO NOTHING;
