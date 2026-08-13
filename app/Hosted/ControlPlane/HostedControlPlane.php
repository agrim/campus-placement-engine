<?php

declare(strict_types=1);

namespace App\Hosted\ControlPlane;

use App\Infrastructure\Persistence\PostgresConnectionProvider;
use App\Infrastructure\Persistence\SqliteConnectionProvider;
use PDO;
use RuntimeException;

final class HostedControlPlane
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $identifier = '',
    ) {
    }

    public static function fromEnvironment(): self
    {
        $url = trim((string) (getenv('CPE_CONTROL_PLANE_DATABASE_URL') ?: ''));
        if ($url !== '') {
            $provider = PostgresConnectionProvider::fromUrl($url, 'CPE_CONTROL_PLANE_DATABASE_URL');
            return new self($provider->connection(), $provider->identifier());
        }
        $path = (string) (getenv('CPE_CONTROL_PLANE_DB_PATH') ?: cpe_data_path('control-plane.sqlite'));
        $provider = new SqliteConnectionProvider($path);
        return new self($provider->connection(), $provider->identifier());
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function migrate(): void
    {
        $this->pdo->exec($this->driver() === 'pgsql'
            ? 'CREATE TABLE IF NOT EXISTS hosted_migrations (id BIGSERIAL PRIMARY KEY, migration TEXT NOT NULL UNIQUE, applied_at TEXT NOT NULL)'
            : 'CREATE TABLE IF NOT EXISTS hosted_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, applied_at TEXT NOT NULL)');
        $directory = cpe_path('database/control-plane/' . ($this->driver() === 'pgsql' ? 'pgsql' : 'sqlite'));
        $files = glob($directory . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM hosted_migrations WHERE migration = ?');
            $stmt->execute([$name]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Unable to read control-plane migration: ' . $name);
            }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $stmt = $this->pdo->prepare('INSERT INTO hosted_migrations (migration, applied_at) VALUES (?, ?)');
                $stmt->execute([$name, cpe_now()]);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }
    }

    public function createTenant(array $input, string $actor = 'operator'): array
    {
        $slug = self::normalizeSlug((string) ($input['slug'] ?? ''));
        $name = self::normalizeText((string) ($input['name'] ?? ''), 120, 'Tenant name');
        $hostname = self::normalizeHostname((string) ($input['domain'] ?? ''));
        $planKey = self::normalizeKey((string) ($input['plan_key'] ?? 'community'), 'plan');
        $reference = self::normalizeDatabaseReference((string) ($input['database_reference'] ?? ''));
        $region = self::normalizeKey((string) ($input['region'] ?? 'local'), 'region');
        $this->requirePlan($planKey);

        $existing = $this->tenantBySlug($slug);
        if ($existing !== null) {
            if ($existing['hostname'] !== $hostname || $existing['database_reference'] !== $reference) {
                throw new RuntimeException('Tenant slug already exists with a different domain or database reference.');
            }
            return $existing;
        }

        $now = cpe_now();
        $publicId = 'tenant_' . bin2hex(random_bytes(16));
        $deploymentPublicId = 'deployment_' . bin2hex(random_bytes(16));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO hosted_tenants (public_id, slug, name, status, plan_key, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$publicId, $slug, $name, 'provisioning', $planKey, $now, $now]);
            $tenantId = $this->lastInsertId();
            $stmt = $this->pdo->prepare(
                'INSERT INTO hosted_domains (tenant_id, hostname, active, verified_at, created_at, updated_at)
                 VALUES (?, ?, 1, ?, ?, ?)'
            );
            $stmt->execute([$tenantId, $hostname, $now, $now, $now]);
            $stmt = $this->pdo->prepare(
                'INSERT INTO hosted_deployments
                 (public_id, tenant_id, environment, region, database_reference, release_version, desired_version, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $deploymentPublicId,
                $tenantId,
                'production',
                $region,
                $reference,
                '',
                (string) cpe_config('app.version', '0.0.0'),
                'provisioning',
                $now,
                $now,
            ]);
            $this->audit($tenantId, $actor, 'tenant.created', 'tenant', $publicId, [
                'domain' => $hostname,
                'plan_key' => $planKey,
                'database_reference' => $reference,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->tenantBySlug($slug) ?? throw new RuntimeException('Created tenant could not be reloaded.');
    }

    public function tenantBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare($this->tenantDeploymentSql() . ' WHERE t.slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function tenant(string $publicIdOrSlug): ?array
    {
        $stmt = $this->pdo->prepare($this->tenantDeploymentSql() . ' WHERE t.public_id = ? OR t.slug = ? LIMIT 1');
        $stmt->execute([$publicIdOrSlug, $publicIdOrSlug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function tenantByDeploymentId(int $deploymentId): ?array
    {
        $stmt = $this->pdo->prepare($this->tenantDeploymentSql() . ' WHERE d.id = ? LIMIT 1');
        $stmt->execute([$deploymentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function resolveHostname(string $hostname): ?array
    {
        $hostname = self::normalizeHostname($hostname);
        $stmt = $this->pdo->prepare(
            $this->tenantDeploymentSql()
            . " WHERE lower(h.hostname) = lower(?) AND h.active = 1 AND h.verified_at IS NOT NULL
                AND t.status = 'active' AND d.status IN ('active', 'upgrading') LIMIT 1"
        );
        $stmt->execute([$hostname]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['entitlements'] = $this->moduleEntitlements((int) $row['tenant_id'], (string) $row['plan_key']);
        return $row;
    }

    public function tenants(): array
    {
        return $this->pdo->query($this->tenantDeploymentSql() . ' ORDER BY t.slug')->fetchAll();
    }

    public function moduleEntitlements(int $tenantId, string $planKey): array
    {
        $entitlements = [];
        $stmt = $this->pdo->prepare('SELECT module_key, enabled FROM hosted_plan_modules WHERE plan_key = ?');
        $stmt->execute([$planKey]);
        foreach ($stmt->fetchAll() as $row) {
            $entitlements[(string) $row['module_key']] = (bool) $row['enabled'];
        }
        $stmt = $this->pdo->prepare('SELECT module_key, enabled FROM hosted_tenant_module_entitlements WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        foreach ($stmt->fetchAll() as $row) {
            $entitlements[(string) $row['module_key']] = (bool) $row['enabled'];
        }
        ksort($entitlements);
        return $entitlements;
    }

    public function setEntitlement(string $tenantKey, string $moduleKey, bool $enabled, string $actor = 'operator'): void
    {
        $tenant = $this->tenant($tenantKey) ?? throw new RuntimeException('Unknown hosted tenant: ' . $tenantKey);
        $moduleKey = self::normalizeKey($moduleKey, 'module');
        $stmt = $this->pdo->prepare(
            'INSERT INTO hosted_tenant_module_entitlements (tenant_id, module_key, enabled, updated_at)
             VALUES (?, ?, ?, ?) ON CONFLICT(tenant_id, module_key)
             DO UPDATE SET enabled = excluded.enabled, updated_at = excluded.updated_at'
        );
        $stmt->execute([(int) $tenant['tenant_id'], $moduleKey, $enabled ? 1 : 0, cpe_now()]);
        $this->audit((int) $tenant['tenant_id'], $actor, 'entitlement.updated', 'module', $moduleKey, ['enabled' => $enabled]);
    }

    public function activateTenant(int $tenantId, string $releaseVersion, string $actor = 'system'): void
    {
        $now = cpe_now();
        $stmt = $this->pdo->prepare("UPDATE hosted_tenants SET status = 'active', updated_at = ? WHERE id = ?");
        $stmt->execute([$now, $tenantId]);
        $stmt = $this->pdo->prepare(
            "UPDATE hosted_deployments SET status = 'active', release_version = ?, desired_version = ?, last_health_at = ?, updated_at = ? WHERE tenant_id = ?"
        );
        $stmt->execute([$releaseVersion, $releaseVersion, $now, $now, $tenantId]);
        $this->audit($tenantId, $actor, 'tenant.activated', 'tenant', '', ['release_version' => $releaseVersion]);
    }

    public function markTenantFailed(int $tenantId, string $error, string $actor = 'system'): void
    {
        $now = cpe_now();
        $stmt = $this->pdo->prepare("UPDATE hosted_tenants SET status = 'failed', updated_at = ? WHERE id = ?");
        $stmt->execute([$now, $tenantId]);
        $stmt = $this->pdo->prepare("UPDATE hosted_deployments SET status = 'failed', updated_at = ? WHERE tenant_id = ?");
        $stmt->execute([$now, $tenantId]);
        $this->audit($tenantId, $actor, 'tenant.provision_failed', 'tenant', '', ['error' => substr($error, 0, 500)]);
    }

    public function beginJob(int $tenantId, int $deploymentId, string $action, string $idempotencyKey, array $payload = []): array
    {
        $now = cpe_now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO hosted_jobs
             (public_id, tenant_id, deployment_id, action, idempotency_key, status, attempts, payload_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?) ON CONFLICT(idempotency_key) DO NOTHING'
        );
        $stmt->execute([
            'job_' . bin2hex(random_bytes(16)),
            $tenantId,
            $deploymentId,
            self::normalizeKey($action, 'job action'),
            $idempotencyKey,
            'pending',
            self::json($payload),
            $now,
            $now,
        ]);
        $stmt = $this->pdo->prepare('SELECT * FROM hosted_jobs WHERE idempotency_key = ?');
        $stmt->execute([$idempotencyKey]);
        $job = $stmt->fetch() ?: throw new RuntimeException('Hosted job could not be loaded.');
        if ($job['status'] === 'failed') {
            $retry = $this->pdo->prepare(
                "UPDATE hosted_jobs SET status = 'pending', last_error = '', started_at = NULL, completed_at = NULL, updated_at = ? WHERE id = ?"
            );
            $retry->execute([$now, (int) $job['id']]);
            $stmt->execute([$idempotencyKey]);
            $job = $stmt->fetch() ?: $job;
        }
        return $job;
    }

    public function claimJob(int $jobId): ?array
    {
        $now = cpe_now();
        $stmt = $this->pdo->prepare(
            "UPDATE hosted_jobs SET status = 'running', attempts = attempts + 1, started_at = ?, updated_at = ?
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$now, $now, $jobId]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM hosted_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        return $stmt->fetch() ?: null;
    }

    public function completeJob(int $jobId): void
    {
        $now = cpe_now();
        $stmt = $this->pdo->prepare(
            "UPDATE hosted_jobs SET status = 'complete', completed_at = ?, last_error = '', updated_at = ? WHERE id = ?"
        );
        $stmt->execute([$now, $now, $jobId]);
    }

    public function failJob(int $jobId, string $error): void
    {
        $stmt = $this->pdo->prepare("UPDATE hosted_jobs SET status = 'failed', last_error = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([substr($error, 0, 1000), cpe_now(), $jobId]);
    }

    public function planUpgradeJobs(string $version): array
    {
        $planned = [];
        foreach ($this->tenants() as $tenant) {
            if ($tenant['tenant_status'] !== 'active' || $tenant['deployment_status'] === 'failed') {
                continue;
            }
            if ((string) $tenant['release_version'] === $version) {
                continue;
            }
            $stmt = $this->pdo->prepare('UPDATE hosted_deployments SET desired_version = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$version, cpe_now(), (int) $tenant['deployment_id']]);
            $planned[] = $this->beginJob(
                (int) $tenant['tenant_id'],
                (int) $tenant['deployment_id'],
                'upgrade',
                'upgrade:' . $tenant['deployment_public_id'] . ':' . $version,
                ['version' => $version]
            );
        }
        return $planned;
    }

    public function pendingJobs(string $action, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM hosted_jobs WHERE action = ? AND status = 'pending' ORDER BY id LIMIT {$limit}"
        );
        $stmt->execute([$action]);
        return $stmt->fetchAll();
    }

    public function recordBackup(array $tenant, int $jobId, array $backup): array
    {
        $publicId = 'backup_' . bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare(
            'INSERT INTO hosted_backup_records
             (public_id, tenant_id, deployment_id, job_id, driver, storage_locator, sha256, status, verified_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $publicId,
            (int) $tenant['tenant_id'],
            (int) $tenant['deployment_id'],
            $jobId,
            (string) $backup['driver'],
            (string) $backup['path'],
            (string) $backup['sha256'],
            'verified',
            cpe_now(),
            cpe_now(),
        ]);
        return ['public_id' => $publicId, ...$backup];
    }

    public function completeUpgrade(array $tenant, string $version, string $actor = 'fleet'): void
    {
        $now = cpe_now();
        $stmt = $this->pdo->prepare(
            "UPDATE hosted_deployments SET release_version = ?, desired_version = ?, status = 'active', last_health_at = ?, updated_at = ? WHERE id = ?"
        );
        $stmt->execute([$version, $version, $now, $now, (int) $tenant['deployment_id']]);
        $this->audit((int) $tenant['tenant_id'], $actor, 'deployment.upgraded', 'deployment', (string) $tenant['deployment_public_id'], ['version' => $version]);
    }

    public function markDeploymentUpgrading(int $deploymentId): void
    {
        $stmt = $this->pdo->prepare("UPDATE hosted_deployments SET status = 'upgrading', updated_at = ? WHERE id = ?");
        $stmt->execute([cpe_now(), $deploymentId]);
    }

    public function markDeploymentDegraded(int $deploymentId): void
    {
        $stmt = $this->pdo->prepare("UPDATE hosted_deployments SET status = 'degraded', updated_at = ? WHERE id = ?");
        $stmt->execute([cpe_now(), $deploymentId]);
    }

    public function grantSupportAccess(string $tenantKey, string $subject, string $reason, string $expiresAt, string $actor = 'operator'): array
    {
        $tenant = $this->tenant($tenantKey) ?? throw new RuntimeException('Unknown hosted tenant: ' . $tenantKey);
        $subject = self::normalizeText($subject, 120, 'Support subject');
        $reason = self::normalizeText($reason, 500, 'Support reason');
        $expiry = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expiresAt, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!$expiry || $expiry <= $now || $expiry > $now->modify('+24 hours')) {
            throw new RuntimeException('Support access expiry must be a UTC timestamp within the next 24 hours.');
        }
        $publicId = 'support_' . bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare(
            'INSERT INTO hosted_support_access_grants
             (public_id, tenant_id, subject, reason, status, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$publicId, (int) $tenant['tenant_id'], $subject, $reason, 'active', $expiresAt, cpe_now()]);
        $this->audit((int) $tenant['tenant_id'], $actor, 'support.granted', 'support_grant', $publicId, [
            'subject' => $subject,
            'reason' => $reason,
            'expires_at' => $expiresAt,
        ]);
        return ['public_id' => $publicId, 'expires_at' => $expiresAt];
    }

    public function revokeSupportAccess(string $publicId, string $actor = 'operator'): void
    {
        $stmt = $this->pdo->prepare('SELECT tenant_id FROM hosted_support_access_grants WHERE public_id = ?');
        $stmt->execute([$publicId]);
        $tenantId = $stmt->fetchColumn();
        if ($tenantId === false) {
            throw new RuntimeException('Unknown support access grant.');
        }
        $stmt = $this->pdo->prepare(
            "UPDATE hosted_support_access_grants SET status = 'revoked', revoked_at = ? WHERE public_id = ? AND status = 'active'"
        );
        $stmt->execute([cpe_now(), $publicId]);
        $this->audit((int) $tenantId, $actor, 'support.revoked', 'support_grant', $publicId, []);
    }

    public function activeSupportGrants(string $tenantKey): array
    {
        $tenant = $this->tenant($tenantKey) ?? throw new RuntimeException('Unknown hosted tenant: ' . $tenantKey);
        $stmt = $this->pdo->prepare(
            "SELECT public_id, subject, reason, expires_at, created_at FROM hosted_support_access_grants
             WHERE tenant_id = ? AND status = 'active' AND expires_at > ? ORDER BY expires_at"
        );
        $stmt->execute([(int) $tenant['tenant_id'], cpe_now()]);
        return $stmt->fetchAll();
    }

    public function audit(
        ?int $tenantId,
        string $actor,
        string $action,
        string $subjectType,
        string $subjectPublicId,
        array $detail,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hosted_audit_logs
             (tenant_id, actor, action, subject_type, subject_public_id, detail_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tenantId,
            self::normalizeText($actor, 120, 'Audit actor'),
            self::normalizeText($action, 120, 'Audit action'),
            self::normalizeText($subjectType, 120, 'Audit subject type'),
            substr($subjectPublicId, 0, 120),
            self::json($detail),
            cpe_now(),
        ]);
    }

    public static function normalizeHostname(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        if (str_contains($hostname, ':')) {
            if (preg_match('/^([^:]+):[0-9]{1,5}$/', $hostname, $matches) !== 1) {
                throw new RuntimeException('Hosted request host is invalid.');
            }
            $hostname = $matches[1];
        }
        if (strlen($hostname) > 253
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $hostname) !== 1) {
            throw new RuntimeException('Hosted domain must be a valid DNS hostname.');
        }
        return $hostname;
    }

    public static function normalizeDatabaseReference(string $reference): string
    {
        $reference = strtoupper(trim($reference));
        if (preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $reference) !== 1) {
            throw new RuntimeException('Database reference must use 2-64 uppercase letters, numbers, or underscores.');
        }
        return $reference;
    }

    private function requirePlan(string $planKey): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM hosted_plans WHERE plan_key = ? AND active = 1');
        $stmt->execute([$planKey]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Unknown or inactive hosted plan: ' . $planKey);
        }
    }

    private function tenantDeploymentSql(): string
    {
        return "SELECT
                    t.id AS tenant_id, t.public_id AS tenant_public_id, t.slug, t.name,
                    t.status AS tenant_status, t.plan_key,
                    h.hostname,
                    d.id AS deployment_id, d.public_id AS deployment_public_id,
                    d.environment, d.region, d.database_reference, d.release_version,
                    d.desired_version, d.status AS deployment_status, d.last_health_at
                FROM hosted_tenants t
                JOIN hosted_domains h ON h.tenant_id = t.id
                JOIN hosted_deployments d ON d.tenant_id = t.id AND d.environment = 'production'";
    }

    private function lastInsertId(): int
    {
        return $this->driver() === 'pgsql'
            ? (int) $this->pdo->query('SELECT LASTVAL()')->fetchColumn()
            : (int) $this->pdo->lastInsertId();
    }

    private static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (preg_match('/^[a-z][a-z0-9-]{1,62}$/', $slug) !== 1) {
            throw new RuntimeException('Tenant slug must use 2-63 lowercase letters, numbers, or hyphens.');
        }
        return $slug;
    }

    private static function normalizeKey(string $key, string $label): string
    {
        $key = strtolower(trim($key));
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key) !== 1) {
            throw new RuntimeException('Invalid ' . $label . ' key: ' . $key);
        }
        return $key;
    }

    private static function normalizeText(string $value, int $maxLength, string $label): string
    {
        $value = preg_replace('/\s+/', ' ', trim(strip_tags($value))) ?? '';
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new RuntimeException($label . ' is required and must be at most ' . $maxLength . ' characters.');
        }
        return $value;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
