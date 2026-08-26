<?php

declare(strict_types=1);

$databasePath = sys_get_temp_dir() . '/cpe-managed-hosting-' . bin2hex(random_bytes(4)) . '.sqlite';

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Hosted\HostedBootstrap;
use App\Hosted\HostedContext;
use App\Hosted\Tenant\HostedResolutionException;
use App\Hosted\Tenant\ResolvedTenant;
use App\Hosted\Tenant\TenantResolver;
use App\Infrastructure\Persistence\SqliteConnectionProvider;
use App\Install\Installer;
use App\Support\Database;

final class ContractTenantResolver implements TenantResolver
{
    public function __construct(private readonly ResolvedTenant $tenant)
    {
    }

    public function resolveHost(string $host): ResolvedTenant
    {
        if ($host !== 'alpha.example.test') {
            throw new HostedResolutionException('Unknown contract host.', 404);
        }
        return $this->tenant;
    }
}

function contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    contract_assert(HostedBootstrap::CONTRACT_VERSION === 1, 'Unexpected managed-hosting contract version.');

    putenv('CPE_PLATFORM_BOOTSTRAP=relative/bootstrap.php');
    try {
        cpe_load_platform_bootstrap();
        throw new RuntimeException('A relative platform bootstrap path was accepted.');
    } catch (RuntimeException $e) {
        contract_assert(str_contains($e->getMessage(), 'readable absolute file'), 'Unexpected platform bootstrap validation failure.');
    } finally {
        putenv('CPE_PLATFORM_BOOTSTRAP');
    }

    try {
        HostedBootstrap::resolveHost('alpha.example.test');
        throw new RuntimeException('Hosted mode accepted a request without a resolver.');
    } catch (HostedResolutionException $e) {
        contract_assert($e->httpStatus() === 503, 'Missing resolver must fail closed.');
    }

    $provider = new SqliteConnectionProvider($databasePath);
    Database::useProvider($provider);
    (new Installer())->install([
        'college_name' => 'Alpha College',
        'site_name' => 'Alpha Placement Desk',
        'timezone' => 'UTC',
        'cycle_name' => 'Contract Cycle',
        'workflow' => 'default',
        'admin_name' => 'Contract Admin',
        'admin_email' => 'admin@alpha.example.test',
        'admin_password' => 'contract-password-123',
        'seed_demo' => '',
    ]);
    HostedBootstrap::bindInstalledDataPlane('tenant_alpha_contract');
    Database::reset();

    $tenant = new ResolvedTenant([
        'tenant_id' => 1,
        'tenant_public_id' => 'tenant_alpha_contract',
        'slug' => 'alpha-college',
        'entitlements' => ['placement' => true, 'advising' => false],
    ], $provider, null);
    $resolver = new ContractTenantResolver($tenant);
    HostedBootstrap::registerResolver($resolver);
    HostedBootstrap::resolveHost('alpha.example.test');

    contract_assert(HostedContext::current()->slug() === 'alpha-college', 'Resolved tenant context was not activated.');
    contract_assert(HostedContext::allowsModule('placement'), 'Entitled module was not allowed.');
    contract_assert(!HostedContext::allowsModule('advising'), 'Unentitled module was allowed.');

    $_SESSION = ['user_id' => 42, 'cpe_hosted_tenant' => 'tenant_other'];
    HostedBootstrap::bindSession();
    contract_assert(!isset($_SESSION['user_id']), 'Cross-tenant session identity was not cleared.');
    contract_assert($_SESSION['cpe_hosted_tenant'] === 'tenant_alpha_contract', 'Session was not bound to the resolved tenant.');

    try {
        HostedBootstrap::registerResolver(new ContractTenantResolver($tenant));
        throw new RuntimeException('A second resolver replaced the registered resolver.');
    } catch (RuntimeException $e) {
        contract_assert(str_contains($e->getMessage(), 'already registered'), 'Unexpected duplicate resolver failure.');
    }

    echo "PASS managed-hosting contract is injectable, tenant-bound, and fail-closed\n";
} finally {
    HostedBootstrap::resetResolver();
    HostedContext::reset();
    Database::reset();
    $_SESSION = [];
    putenv('CPE_PLATFORM_BOOTSTRAP');
    if (is_file($databasePath)) {
        unlink($databasePath);
    }
    foreach ([$databasePath . '-shm', $databasePath . '-wal'] as $sidecar) {
        if (is_file($sidecar)) {
            unlink($sidecar);
        }
    }
}
