<?php

declare(strict_types=1);

namespace App\Hosted\Operations;

use App\Core\Backup\DatabaseBackupService;
use App\Domain\ReadinessService;
use App\Hosted\ControlPlane\HostedControlPlane;
use App\Hosted\HostedBootstrap;
use App\Hosted\HostedContext;
use App\Hosted\Tenant\TenantDatabaseResolver;
use App\Support\Database;
use RuntimeException;

final class FleetUpgradeService
{
    public function __construct(
        private readonly HostedControlPlane $controlPlane,
        private readonly TenantDatabaseResolver $resolver,
    ) {
    }

    public function plan(string $version): array
    {
        $current = (string) cpe_config('app.version', '0.0.0');
        if ($version !== $current) {
            throw new RuntimeException('Fleet jobs can only target the version carried by this release: ' . $current);
        }
        return $this->controlPlane->planUpgradeJobs($version);
    }

    public function run(int $limit = 10): array
    {
        $results = [];
        foreach ($this->controlPlane->pendingJobs('upgrade', $limit) as $pending) {
            $job = $this->controlPlane->claimJob((int) $pending['id']);
            if ($job === null) {
                continue;
            }
            $tenant = null;
            try {
                $tenant = $this->resolver->resolveDeployment((int) $job['deployment_id']);
                $metadata = $tenant->metadata();
                $payload = json_decode((string) $job['payload_json'], true, 32, JSON_THROW_ON_ERROR);
                $version = (string) ($payload['version'] ?? '');
                if ($version !== (string) cpe_config('app.version', '0.0.0')) {
                    throw new RuntimeException('Upgrade job targets a release not carried by this process.');
                }
                Database::useProvider($tenant->provider());
                if (!Database::isInstalled()) {
                    throw new RuntimeException('Tenant database is not installed.');
                }
                HostedBootstrap::assertDataPlaneIdentity($tenant->publicId());
                $this->controlPlane->markDeploymentUpgrading((int) $metadata['deployment_id']);
                $backupDirectory = rtrim((string) (getenv('CPE_HOSTED_BACKUP_DIR') ?: cpe_data_path('hosted-backups')), '/') . '/' . $tenant->slug();
                $backup = (new DatabaseBackupService(Database::connection(), $tenant->postgresUrl()))
                    ->create('pre-upgrade', $backupDirectory);
                $backup = $this->controlPlane->recordBackup($metadata, (int) $job['id'], $backup);

                Database::migrate();
                $snapshot = (new ReadinessService())->snapshot();
                $failures = array_filter($snapshot['checks'], static fn (array $check): bool => $check['status'] === 'fail');
                if ($failures !== []) {
                    throw new RuntimeException('Tenant readiness failed after migration.');
                }
                $this->controlPlane->completeUpgrade($metadata, $version);
                $this->controlPlane->completeJob((int) $job['id']);
                $results[] = [
                    'tenant' => $tenant->slug(),
                    'status' => 'complete',
                    'backup' => $backup['path'],
                    'version' => $version,
                ];
            } catch (\Throwable $e) {
                $this->controlPlane->failJob((int) $job['id'], $e->getMessage());
                if ($tenant !== null) {
                    $this->controlPlane->markDeploymentDegraded((int) $tenant->metadata()['deployment_id']);
                }
                $results[] = [
                    'tenant' => $tenant?->slug() ?? 'unresolved',
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            } finally {
                HostedContext::reset();
                Database::reset();
            }
        }
        return $results;
    }
}
