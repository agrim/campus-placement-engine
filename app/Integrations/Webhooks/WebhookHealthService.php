<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Support\Database;
use PDO;
use RuntimeException;

final class WebhookHealthService
{
    private const REQUIRED_RELATIONS = [
        'webhook_subscriptions',
        'webhook_subscription_events',
        'webhook_deliveries',
        'webhook_worker_heartbeat',
    ];

    public function __construct(private readonly ?PDO $connection = null)
    {
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $pdo = $this->connection ?? Database::connection();
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $driverReady = $this->databaseDriverReady($pdo, $driver);
        $workerConfigured = $this->truthy(getenv('CPE_INTEGRATION_WORKER_CONFIGURED'));
        $tlsPolicy = $this->tlsPolicy();
        $presentRelations = 0;
        foreach (self::REQUIRED_RELATIONS as $relation) {
            if ($this->tableExists($pdo, $relation)) {
                $presentRelations++;
            }
        }
        if ($presentRelations === 0) {
            return $this->emptySnapshot(
                'not_installed',
                $driver,
                $driverReady,
                $workerConfigured,
                $tlsPolicy,
            );
        }
        if ($presentRelations !== count(self::REQUIRED_RELATIONS)) {
            throw new RuntimeException('Webhook health storage is only partially installed.');
        }
        foreach (self::REQUIRED_RELATIONS as $relation) {
            $pdo->query('SELECT 1 FROM ' . $relation . ' WHERE 1 = 0')->fetchColumn();
        }
        $states = ['disabled' => 0, 'setup_required' => 0, 'validating' => 0, 'active' => 0, 'degraded' => 0];
        foreach ($pdo->query(
            'SELECT lifecycle_state, COUNT(*) AS state_count
             FROM webhook_subscriptions GROUP BY lifecycle_state',
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $states[(string) $row['lifecycle_state']] = (int) $row['state_count'];
        }
        $deliveries = $pdo->query(
            "SELECT
                SUM(CASE WHEN status IN ('pending', 'processing', 'retrying') THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'dead_lettered' THEN 1 ELSE 0 END) AS dead_letter_count,
                MIN(CASE WHEN status IN ('pending', 'processing', 'retrying') THEN created_at ELSE NULL END) AS oldest_pending_at
             FROM webhook_deliveries",
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $heartbeat = $pdo->query(
            'SELECT started_at, finished_at, status, claimed_count, succeeded_count, failed_count,
                    claim_cursor_subscription_id
             FROM webhook_worker_heartbeat WHERE singleton_id = 1',
        )->fetch(PDO::FETCH_ASSOC);
        $heartbeatAge = null;
        if (is_array($heartbeat) && (string) ($heartbeat['finished_at'] ?? '') !== '') {
            $timestamp = strtotime((string) $heartbeat['finished_at'] . ' UTC');
            $heartbeatAge = $timestamp === false ? null : max(0, time() - $timestamp);
        }
        $workerStatus = is_array($heartbeat) ? (string) $heartbeat['status'] : 'never_run';
        $activeCount = $states['active'] + $states['degraded'];
        $deadLetters = (int) ($deliveries['dead_letter_count'] ?? 0);
        $keyring = WebhookSecretCipher::environmentStatus();
        $referencedVersions = [];
        $now = cpe_now();
        foreach ($pdo->query(
            'SELECT current_secret_key_version, previous_secret_key_version, previous_secret_expires_at
             FROM webhook_subscriptions',
        )->fetchAll(PDO::FETCH_ASSOC) as $subscription) {
            $current = (string) ($subscription['current_secret_key_version'] ?? '');
            if ($current !== '') {
                $referencedVersions[$current] = true;
            }
            $previous = (string) ($subscription['previous_secret_key_version'] ?? '');
            if ($previous !== '' && (string) ($subscription['previous_secret_expires_at'] ?? '') >= $now) {
                $referencedVersions[$previous] = true;
            }
        }
        $missingVersions = array_diff(array_keys($referencedVersions), $keyring['versions']);
        $keyReferencesReady = $missingVersions === [];
        $status = 'ok';
        if ($activeCount > 0 && (!$keyring['present'] || !$keyReferencesReady)) {
            $status = 'fail';
        } elseif ($referencedVersions !== [] && (!$keyring['present'] || !$keyReferencesReady)) {
            $status = 'warn';
        } elseif ($activeCount > 0 && !$workerConfigured) {
            $status = 'warn';
        } elseif ($activeCount > 0 && ($heartbeatAge === null || $heartbeatAge > 900)) {
            $status = 'warn';
        } elseif ($activeCount > 0 && $workerStatus !== 'ok') {
            $status = 'warn';
        } elseif ($states['degraded'] > 0 || $deadLetters > 0) {
            $status = 'warn';
        }
        $oldestPendingAge = null;
        if ((string) ($deliveries['oldest_pending_at'] ?? '') !== '') {
            $timestamp = strtotime((string) $deliveries['oldest_pending_at'] . ' UTC');
            $oldestPendingAge = $timestamp === false ? null : max(0, time() - $timestamp);
        }
        $privateCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM webhook_subscriptions WHERE allow_private_network = 1',
        )->fetchColumn();
        $workerFreshness = $this->workerFreshness($activeCount, $heartbeatAge);
        return [
            'status' => $status,
            'configured' => array_sum($states),
            'states' => $states,
            'pending' => (int) ($deliveries['pending_count'] ?? 0),
            'dead_lettered' => $deadLetters,
            'oldest_pending_age_seconds' => $oldestPendingAge,
            'worker_heartbeat_age_seconds' => $heartbeatAge,
            'worker_status' => $workerStatus,
            'worker_required' => $activeCount > 0,
            'worker_configured' => $workerConfigured,
            'scheduler_freshness' => $workerFreshness,
            'encryption_key_present' => (bool) $keyring['present'],
            'encryption_key_active_version' => (string) $keyring['active_version'],
            'encryption_key_references_ready' => $keyReferencesReady,
            'missing_encryption_key_versions' => count($missingVersions),
            'network_policy' => $this->hostedMode() ? 'managed_public_egress' : 'self_hosted_explicit_private_opt_in',
            'tls_policy' => $tlsPolicy['key'],
            'tls_policy_message' => $tlsPolicy['message'],
            'database_driver' => $driver,
            'database_driver_ready' => $driverReady,
            'private_policy_subscriptions' => $privateCount,
            'message' => $this->message(
                $status,
                $activeCount,
                $states['degraded'],
                $deadLetters,
                $heartbeatAge,
                $workerStatus,
                $workerConfigured,
                (bool) $keyring['present'],
                count($missingVersions),
            ),
        ];
    }

    /** @param array{key: string, message: string} $tlsPolicy */
    private function emptySnapshot(
        string $workerStatus,
        string $driver,
        bool $driverReady,
        bool $workerConfigured,
        array $tlsPolicy,
    ): array
    {
        $keyring = WebhookSecretCipher::environmentStatus();
        return [
            'status' => 'ok',
            'configured' => 0,
            'states' => ['disabled' => 0, 'setup_required' => 0, 'validating' => 0, 'active' => 0, 'degraded' => 0],
            'pending' => 0,
            'dead_lettered' => 0,
            'oldest_pending_age_seconds' => null,
            'worker_heartbeat_age_seconds' => null,
            'worker_status' => $workerStatus,
            'worker_required' => false,
            'worker_configured' => $workerConfigured,
            'scheduler_freshness' => 'not_required',
            'encryption_key_present' => (bool) $keyring['present'],
            'encryption_key_active_version' => (string) $keyring['active_version'],
            'encryption_key_references_ready' => true,
            'missing_encryption_key_versions' => 0,
            'network_policy' => $this->hostedMode() ? 'managed_public_egress' : 'self_hosted_explicit_private_opt_in',
            'tls_policy' => $tlsPolicy['key'],
            'tls_policy_message' => $tlsPolicy['message'],
            'database_driver' => $driver,
            'database_driver_ready' => $driverReady,
            'private_policy_subscriptions' => 0,
            'message' => 'No signed webhook integration is configured.',
        ];
    }

    private function message(
        string $status,
        int $active,
        int $degraded,
        int $deadLetters,
        ?int $heartbeatAge,
        string $workerStatus,
        bool $workerConfigured,
        bool $keyPresent,
        int $missingKeyVersions,
    ): string {
        if (!$keyPresent && ($active > 0 || $missingKeyVersions > 0)) {
            return $active > 0
                ? 'Active webhook integrations cannot decrypt signing secrets; restore the external keyring.'
                : 'Stored webhook signing secrets require the external keyring before activation.';
        }
        if ($missingKeyVersions > 0) {
            return $active > 0
                ? 'Active webhook integrations reference an unavailable external encryption key version.'
                : 'Stored webhook signing secrets reference an unavailable external encryption key version.';
        }
        if ($active === 0) {
            return 'No active signed webhook integration requires a worker.';
        }
        if (!$workerConfigured) {
            return 'Active integrations exist, but scheduler configuration has not been attested with CPE_INTEGRATION_WORKER_CONFIGURED.';
        }
        if ($heartbeatAge === null || $heartbeatAge > 900) {
            return 'The signed webhook worker has no recent heartbeat.';
        }
        if ($workerStatus !== 'ok') {
            return 'The latest signed webhook worker run needs review.';
        }
        if ($degraded > 0 || $deadLetters > 0) {
            return $degraded . ' integration(s) are degraded and ' . $deadLetters . ' delivery or deliveries need review.';
        }
        return $status === 'ok'
            ? $active . ' active signed webhook integration(s) have a recent worker heartbeat.'
            : 'Signed webhook integrations need review.';
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $table) !== 1) {
            throw new RuntimeException('Webhook health table identifier is invalid.');
        }
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $query = $pdo->prepare(
                'SELECT type FROM sqlite_master WHERE name = ? ORDER BY type',
            );
            $query->execute([$table]);
            $objects = $query->fetchAll(PDO::FETCH_COLUMN);
            if ($objects === []) {
                return false;
            }
            if ($objects !== ['table']) {
                throw new RuntimeException('Webhook health storage has an unexpected SQLite object type.');
            }
            return true;
        }
        if ($driver === 'pgsql') {
            $schema = $pdo->query('SELECT current_schema()')->fetchColumn();
            if (!is_string($schema) || $schema === '') {
                throw new RuntimeException('Webhook health PostgreSQL schema is unavailable.');
            }
            $query = $pdo->prepare(
                'SELECT class.relkind
                 FROM pg_catalog.pg_class class
                 JOIN pg_catalog.pg_namespace namespace ON namespace.oid = class.relnamespace
                 WHERE namespace.nspname = ? AND class.relname = ?',
            );
            $query->execute([$schema, $table]);
            $objects = $query->fetchAll(PDO::FETCH_COLUMN);
            if ($objects === []) {
                return false;
            }
            if (count($objects) !== 1 || !in_array((string) $objects[0], ['r', 'p'], true)) {
                throw new RuntimeException('Webhook health storage has an unexpected PostgreSQL object type.');
            }
            return true;
        }
        throw new RuntimeException('Webhook health storage driver is unsupported.');
    }

    private function hostedMode(): bool
    {
        return $this->truthy(getenv('CPE_HOSTED_MODE'));
    }

    /** @return array{key: string, message: string} */
    private function tlsPolicy(): array
    {
        if ($this->hostedMode()) {
            return [
                'key' => 'https_public_egress_only',
                'message' => 'HTTPS on public egress only; TLS verification is required and redirects and inherited proxies are disabled.',
            ];
        }
        if ($this->truthy(getenv('CPE_WEBHOOK_ALLOW_HTTP'))) {
            return [
                'key' => 'https_default_private_http_opt_in',
                'message' => 'HTTPS is the default. HTTP is limited to an explicitly approved private-network integration and port; redirects and inherited proxies are disabled.',
            ];
        }
        return [
            'key' => 'https_required',
            'message' => 'HTTPS and peer/hostname verification are required; redirects and inherited proxies are disabled.',
        ];
    }

    private function workerFreshness(int $activeCount, ?int $heartbeatAge): string
    {
        if ($activeCount === 0) {
            return 'not_required';
        }
        if ($heartbeatAge === null) {
            return 'never_observed';
        }
        return $heartbeatAge <= 900 ? 'fresh' : 'stale';
    }

    private function databaseDriverReady(PDO $pdo, string $driver): bool
    {
        $extensionReady = match ($driver) {
            'sqlite' => extension_loaded('pdo_sqlite'),
            'pgsql' => extension_loaded('pdo_pgsql'),
            default => false,
        };
        if (!$extensionReady) {
            return false;
        }
        try {
            return (int) $pdo->query('SELECT 1')->fetchColumn() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function truthy(string|false $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
