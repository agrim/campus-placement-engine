<?php

declare(strict_types=1);

namespace App\Operations;

use App\Infrastructure\Persistence\PostgresConnectionProvider;
use App\Integrations\IntegrationState;
use App\Integrations\Webhooks\WebhookHealthService;
use App\Support\Database;
use JsonException;
use PDO;
use RuntimeException;

/**
 * Bounded, allowlisted diagnostics for institution-approved support sharing.
 *
 * No placement records, logs, paths, endpoints, credentials, or free-form
 * failure text are selected by this service.
 */
final class SupportReportService
{
    private const SCHEMA_VERSION = 1;
    private const MAX_INTEGRATIONS = 200;
    private const MAX_INCIDENT_IDS = 20;
    private const MAX_INCIDENT_REFERENCE_ROWS = 50;

    public function __construct(private readonly ?PDO $connection = null)
    {
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $pdo = $this->connection ?? Database::connection();
        $health = (new WebhookHealthService($pdo))->snapshot();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'engine' => [
                'version' => (string) cpe_config('app.version', '0.0.0'),
                'artifact_sha256' => $this->artifactDigest(),
            ],
            'runtime' => [
                'php_version' => PHP_VERSION,
                'database_driver' => (string) $health['database_driver'],
                'database_driver_ready' => (bool) $health['database_driver_ready'],
            ],
            'migrations' => $this->migrations($pdo, (string) $health['database_driver']),
            'enabled_module_ids' => $this->enabledModuleIds($pdo),
            'capability_catalog_revision' => $this->capabilityCatalogRevision(),
            'integrations' => $this->integrations($pdo),
            'delivery' => [
                'pending_count' => (int) $health['pending'],
                'dead_letter_count' => (int) $health['dead_lettered'],
                'oldest_pending_age_seconds' => $health['oldest_pending_age_seconds'] === null
                    ? null
                    : (int) $health['oldest_pending_age_seconds'],
            ],
            'incident_ids' => $this->incidentIds($pdo),
            'scheduler' => [
                'worker_required' => (bool) $health['worker_required'],
                'worker_configured' => (bool) $health['worker_configured'],
                'status' => (string) $health['worker_status'],
                'freshness' => (string) $health['scheduler_freshness'],
                'last_heartbeat_age_seconds' => $health['worker_heartbeat_age_seconds'] === null
                    ? null
                    : (int) $health['worker_heartbeat_age_seconds'],
            ],
            'transport_policy' => $this->transportPolicy($health),
        ];
    }

    /** @throws JsonException */
    public function json(): string
    {
        return json_encode(
            $this->snapshot(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    private function artifactDigest(): ?string
    {
        $configured = strtolower(trim((string) (getenv('CPE_ENGINE_ARTIFACT_SHA256') ?: '')));
        if (str_starts_with($configured, 'sha256:')) {
            $configured = substr($configured, 7);
        }
        return preg_match('/\A[a-f0-9]{64}\z/D', $configured) === 1 ? $configured : null;
    }

    /** @return array{applied: list<string>, pending: list<string>, unrecognized_applied_count: int} */
    private function migrations(PDO $pdo, string $driver): array
    {
        $knownDirectory = $driver === 'pgsql'
            ? cpe_path('database/migrations/pgsql')
            : cpe_path('database/migrations');
        $known = array_map('basename', glob($knownDirectory . '/*.sql') ?: []);
        $known = array_values(array_filter($known, $this->safeMigrationId(...)));
        sort($known, SORT_STRING);
        $knownSet = array_fill_keys($known, true);

        $applied = [];
        $unrecognized = 0;
        foreach ($pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN) as $migration) {
            $migration = (string) $migration;
            if (!$this->safeMigrationId($migration) || !isset($knownSet[$migration])) {
                $unrecognized++;
                continue;
            }
            $applied[$migration] = true;
        }
        $appliedIds = array_keys($applied);
        sort($appliedIds, SORT_STRING);

        return [
            'applied' => $appliedIds,
            'pending' => array_values(array_diff($known, $appliedIds)),
            'unrecognized_applied_count' => $unrecognized,
        ];
    }

    private function safeMigrationId(string $value): bool
    {
        return preg_match('/\A[0-9]{3}_[a-z0-9_]+\.sql\z/D', $value) === 1;
    }

    /** @return list<string> */
    private function enabledModuleIds(PDO $pdo): array
    {
        $modules = [];
        foreach ($pdo->query(
            'SELECT module_key FROM module_installations WHERE enabled = 1 ORDER BY module_key',
        )->fetchAll(PDO::FETCH_COLUMN) as $moduleId) {
            $moduleId = (string) $moduleId;
            if (preg_match('/\A[a-z][a-z0-9_]{1,63}\z/D', $moduleId) !== 1) {
                throw new RuntimeException('Support report encountered an invalid module identifier.');
            }
            $modules[] = $moduleId;
        }
        return $modules;
    }

    private function capabilityCatalogRevision(): string
    {
        $catalog = [];
        foreach ((array) cpe_config('capabilities.roles', []) as $capabilities) {
            foreach ((array) $capabilities as $capability) {
                $this->addCapability($catalog, (string) $capability);
            }
        }
        foreach ((array) cpe_config('modules', []) as $module) {
            foreach ((array) ($module['capabilities'] ?? []) as $capability) {
                $this->addCapability($catalog, (string) $capability);
            }
        }
        $capabilities = array_keys($catalog);
        sort($capabilities, SORT_STRING);
        return 'sha256:' . hash('sha256', implode("\n", $capabilities));
    }

    /** @param array<string, true> $catalog */
    private function addCapability(array &$catalog, string $capability): void
    {
        if ($capability === '*') {
            return;
        }
        if (preg_match('/\A[a-z][a-z0-9_.]{2,127}\z/D', $capability) !== 1) {
            throw new RuntimeException('Support report encountered an invalid capability identifier.');
        }
        $catalog[$capability] = true;
    }

    /**
     * @return array{
     *   total_count: int,
     *   items: list<array{id: string, state: string, state_label: string}>,
     *   truncated: bool
     * }
     */
    private function integrations(PDO $pdo): array
    {
        $total = (int) $pdo->query('SELECT COUNT(*) FROM webhook_subscriptions')->fetchColumn();
        $integrations = [];
        foreach ($pdo->query(
            'SELECT public_id, lifecycle_state FROM webhook_subscriptions ORDER BY public_id LIMIT '
            . self::MAX_INTEGRATIONS,
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (string) ($row['public_id'] ?? '');
            $state = (string) ($row['lifecycle_state'] ?? '');
            if (preg_match('/\Awhsub_[a-f0-9]{32}\z/D', $id) !== 1) {
                throw new RuntimeException('Support report encountered an invalid Integration identifier.');
            }
            $integrations[] = [
                'id' => $id,
                'state' => $state,
                'state_label' => IntegrationState::label($state),
            ];
        }
        return [
            'total_count' => $total,
            'items' => $integrations,
            'truncated' => $total > self::MAX_INTEGRATIONS,
        ];
    }

    /** @return list<string> */
    private function incidentIds(PDO $pdo): array
    {
        $ids = [];
        foreach (['webhook_subscriptions', 'webhook_deliveries'] as $table) {
            $rows = $pdo->query(
                'SELECT last_failure_reference FROM ' . $table
                . " WHERE last_failure_reference <> '' ORDER BY updated_at DESC LIMIT "
                . self::MAX_INCIDENT_REFERENCE_ROWS,
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $reference) {
                if (preg_match_all('/\binc_[a-f0-9]{32}\b/', (string) $reference, $matches) < 1) {
                    continue;
                }
                foreach ($matches[0] as $incidentId) {
                    $ids[$incidentId] = true;
                    if (count($ids) >= self::MAX_INCIDENT_IDS) {
                        break 3;
                    }
                }
            }
        }
        $result = array_keys($ids);
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param array<string, mixed> $health
     * @return array<string, mixed>
     */
    private function transportPolicy(array $health): array
    {
        return [
            'inbound_proxy_headers_trusted' => $this->truthy(getenv('CPE_TRUST_PROXY_HEADERS')),
            'outbound_webhook_proxy_environment' => 'ignored',
            'webhook_tls_policy' => (string) $health['tls_policy'],
            'hosted_mode' => $this->truthy(getenv('CPE_HOSTED_MODE')),
            'database_tls' => $this->databaseTls((string) $health['database_driver']),
        ];
    }

    /** @return array<string, bool|string|null> */
    private function databaseTls(string $driver): array
    {
        if ($driver !== 'pgsql') {
            return ['mode' => 'not_applicable'];
        }

        $provider = Database::provider();
        if (!$provider instanceof PostgresConnectionProvider) {
            return ['mode' => 'unavailable'];
        }
        $diagnostics = $provider->diagnostics();
        return [
            'mode' => (string) ($diagnostics['ssl_mode'] ?? 'unavailable'),
            'strict_policy' => (bool) ($diagnostics['strict_policy'] ?? false),
            'pool_mode' => (string) ($diagnostics['pool_mode'] ?? 'unavailable'),
            'trusted_root_configured' => (bool) ($diagnostics['trusted_root_configured'] ?? false),
            'negotiated_tls_verified' => isset($diagnostics['negotiated_tls_verified'])
                ? (bool) $diagnostics['negotiated_tls_verified']
                : null,
        ];
    }

    private function truthy(string|false $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
