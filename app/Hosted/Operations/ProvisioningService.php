<?php

declare(strict_types=1);

namespace App\Hosted\Operations;

use App\Core\Modules\ModuleLifecycleService;
use App\Domain\ReadinessService;
use App\Hosted\ControlPlane\HostedControlPlane;
use App\Hosted\HostedBootstrap;
use App\Hosted\HostedContext;
use App\Hosted\Tenant\TenantDatabaseResolver;
use App\Install\Installer;
use App\Support\Database;
use RuntimeException;

final class ProvisioningService
{
    public function __construct(
        private readonly HostedControlPlane $controlPlane,
        private readonly TenantDatabaseResolver $resolver,
    ) {
    }

    public function provision(string $tenantKey, array $input, string $actor = 'operator'): array
    {
        $resolved = $this->resolver->resolveTenant($tenantKey, false);
        $metadata = $resolved->metadata();
        $job = $this->controlPlane->beginJob(
            $resolved->tenantId(),
            (int) $metadata['deployment_id'],
            'provision',
            'provision:' . $resolved->publicId(),
            ['release_version' => (string) cpe_config('app.version', '0.0.0')]
        );
        if ($job['status'] === 'complete') {
            return [
                'status' => 'complete',
                'tenant' => $metadata,
                'job' => $job,
                'release_version' => (string) $metadata['release_version'],
                'resumed' => true,
            ];
        }
        $job = $this->controlPlane->claimJob((int) $job['id']);
        if ($job === null) {
            throw new RuntimeException('Provisioning job is already running.');
        }

        try {
            Database::useProvider($resolved->provider());
            if (Database::isInstalled()) {
                HostedBootstrap::assertDataPlaneIdentity($resolved->publicId());
            } else {
                (new Installer())->install([
                    'college_name' => (string) $metadata['name'],
                    'site_name' => trim((string) ($input['site_name'] ?? '')) ?: 'Career Services Portal',
                    'timezone' => (string) ($input['timezone'] ?? 'UTC'),
                    'cycle_name' => trim((string) ($input['cycle_name'] ?? '')) ?: $metadata['name'] . ' Placement Cycle',
                    'workflow' => (string) ($input['workflow'] ?? 'default'),
                    'admin_name' => (string) ($input['admin_name'] ?? ''),
                    'admin_email' => (string) ($input['admin_email'] ?? ''),
                    'admin_password' => (string) ($input['admin_password'] ?? ''),
                    'seed_demo' => !empty($input['seed_demo']) ? '1' : '',
                ]);
                HostedBootstrap::bindInstalledDataPlane($resolved->publicId());
            }

            $this->synchronizeEntitledModules($metadata);
            $snapshot = (new ReadinessService())->snapshot();
            $failures = array_filter($snapshot['checks'], static fn (array $check): bool => $check['status'] === 'fail');
            if ($failures !== []) {
                throw new RuntimeException('Provisioned tenant failed readiness checks.');
            }
            $version = (string) cpe_config('app.version', '0.0.0');
            $this->controlPlane->activateTenant($resolved->tenantId(), $version, $actor);
            $this->controlPlane->completeJob((int) $job['id']);
            return [
                'status' => 'complete',
                'tenant' => $metadata,
                'job' => $job,
                'release_version' => $version,
            ];
        } catch (\Throwable $e) {
            $this->controlPlane->failJob((int) $job['id'], $e->getMessage());
            $this->controlPlane->markTenantFailed($resolved->tenantId(), $e->getMessage(), $actor);
            throw $e;
        } finally {
            HostedContext::reset();
            Database::reset();
        }
    }

    private function synchronizeEntitledModules(array $metadata): void
    {
        $entitlements = $this->controlPlane->moduleEntitlements((int) $metadata['tenant_id'], (string) $metadata['plan_key']);
        $service = new ModuleLifecycleService(Database::connection());
        foreach (array_keys(cpe_config('modules', [])) as $moduleKey) {
            $included = (bool) ($entitlements[$moduleKey] ?? false);
            $current = null;
            foreach ($service->modules() as $module) {
                if ($module['key'] === $moduleKey) {
                    $current = $module;
                    break;
                }
            }
            if ($included && !($current['configured_enabled'] ?? false)) {
                $service->enable($moduleKey);
            } elseif (!$included && ($current['configured_enabled'] ?? false)) {
                $service->disable($moduleKey);
            }
        }
    }
}
