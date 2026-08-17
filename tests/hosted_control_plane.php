<?php

declare(strict_types=1);

$suffix = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/cpe-hosted-contract-' . $suffix;
$controlPath = $root . '/control.sqlite';
$alphaPath = $root . '/alpha.sqlite';
$betaPath = $root . '/beta.sqlite';
$backupPath = $root . '/backups';
mkdir($root, 0775, true);

putenv('CPE_CONTROL_PLANE_DB_PATH=' . $controlPath);
putenv('CPE_HOSTED_ALLOW_SQLITE_TENANTS=1');
putenv('CPE_TENANT_DB_PATH_ALPHA_DB=' . $alphaPath);
putenv('CPE_TENANT_DB_PATH_BETA_DB=' . $betaPath);
putenv('CPE_HOSTED_BACKUP_DIR=' . $backupPath);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Modules\ModuleRegistry;
use App\Hosted\ControlPlane\HostedControlPlane;
use App\Hosted\HostedBootstrap;
use App\Hosted\HostedContext;
use App\Hosted\Operations\FleetUpgradeService;
use App\Hosted\Operations\ProvisioningService;
use App\Hosted\Tenant\HostedResolutionException;
use App\Hosted\Tenant\TenantDatabaseResolver;
use App\Support\Database;

function hosted_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function hosted_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $child = $path . '/' . $item;
        if (is_dir($child)) {
            hosted_remove_tree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}

try {
    $control = HostedControlPlane::fromEnvironment();
    $control->migrate();
    hosted_assert(
        (int) $control->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'candidates'")->fetchColumn() === 0,
        'Control plane must not contain placement records.'
    );

    $control->createTenant([
        'slug' => 'alpha-college',
        'name' => 'Alpha College',
        'domain' => 'alpha.example.test',
        'plan_key' => 'community',
        'database_reference' => 'ALPHA_DB',
        'region' => 'test',
    ], 'contract');
    $control->createTenant([
        'slug' => 'beta-college',
        'name' => 'Beta College',
        'domain' => 'beta.example.test',
        'plan_key' => 'career_services',
        'database_reference' => 'BETA_DB',
        'region' => 'test',
    ], 'contract');
    $control->createTenant([
        'slug' => 'missing-secret',
        'name' => 'Missing Secret College',
        'domain' => 'missing.example.test',
        'plan_key' => 'community',
        'database_reference' => 'MISSING_DB',
        'region' => 'test',
    ], 'contract');

    $resolver = new TenantDatabaseResolver($control);
    $provisioner = new ProvisioningService($control, $resolver);
    $provisioner->provision('alpha-college', [
        'admin_name' => 'Alpha Administrator',
        'admin_email' => 'admin@alpha.example.test',
        'admin_password' => 'alpha-password-123',
        'seed_demo' => true,
    ], 'contract');
    $provisioner->provision('beta-college', [
        'admin_name' => 'Beta Administrator',
        'admin_email' => 'admin@beta.example.test',
        'admin_password' => 'beta-password-123',
    ], 'contract');

    $alphaResolved = $resolver->resolveHost('alpha.example.test:443');
    $betaResolved = $resolver->resolveHost('beta.example.test');
    hosted_assert($alphaResolved->publicId() !== $betaResolved->publicId(), 'Tenant public IDs must differ.');
    hosted_assert($alphaResolved->allowsModule('placement'), 'Community plan must include placement.');
    hosted_assert(!$alphaResolved->allowsModule('advising'), 'Community plan must not include advising by default.');
    hosted_assert($betaResolved->allowsModule('advising'), 'Career Services plan must include advising.');

    Database::useProvider($alphaResolved->provider());
    HostedBootstrap::assertDataPlaneIdentity($alphaResolved->publicId());
    hosted_assert((string) Database::connection()->query("SELECT value FROM settings WHERE key = 'college_name'")->fetchColumn() === 'Alpha College', 'Alpha data plane has wrong institution.');
    hosted_assert((int) Database::connection()->query('SELECT COUNT(*) FROM candidates')->fetchColumn() === 5, 'Alpha demo data is missing.');
    hosted_assert((int) Database::connection()->query("SELECT enabled FROM module_installations WHERE module_key = 'advising'")->fetchColumn() === 0, 'Community tenant must keep Advising disabled.');
    hosted_assert((int) Database::connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'hosted_tenants'")->fetchColumn() === 0, 'Data plane must not contain control-plane tenant tables.');
    $alphaUserCount = (int) Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    Database::reset();

    Database::useProvider($betaResolved->provider());
    HostedBootstrap::assertDataPlaneIdentity($betaResolved->publicId());
    hosted_assert((string) Database::connection()->query("SELECT value FROM settings WHERE key = 'college_name'")->fetchColumn() === 'Beta College', 'Beta data plane has wrong institution.');
    hosted_assert((int) Database::connection()->query('SELECT COUNT(*) FROM candidates')->fetchColumn() === 0, 'Beta must not see Alpha candidates.');
    hosted_assert((int) Database::connection()->query("SELECT enabled FROM module_installations WHERE module_key = 'advising'")->fetchColumn() === 1, 'Career Services tenant must enable Advising.');
    Database::reset();

    $provisioner->provision('alpha-college', [
        'admin_name' => 'Ignored Administrator',
        'admin_email' => 'ignored@alpha.example.test',
        'admin_password' => 'ignored-password-123',
    ], 'contract');
    Database::useProvider($alphaResolved->provider());
    hosted_assert((int) Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn() === $alphaUserCount, 'Idempotent provisioning created duplicate users.');
    Database::reset();

    try {
        $resolver->resolveHost('unknown.example.test');
        throw new RuntimeException('Unknown domain unexpectedly resolved.');
    } catch (HostedResolutionException $e) {
        hosted_assert($e->httpStatus() === 404, 'Unknown hosted domains must fail with 404.');
    }

    $missing = $control->tenant('missing-secret') ?? throw new RuntimeException('Missing-secret tenant was not created.');
    $control->activateTenant((int) $missing['tenant_id'], (string) cpe_config('app.version'), 'contract');
    try {
        $resolver->resolveHost('missing.example.test');
        throw new RuntimeException('Tenant with absent database secret unexpectedly resolved.');
    } catch (HostedResolutionException $e) {
        hosted_assert($e->httpStatus() === 503, 'Absent tenant database secrets must fail closed.');
    }

    putenv('CPE_TENANT_DB_PATH_ALPHA_DB=' . $betaPath);
    $swapped = $resolver->resolveHost('alpha.example.test');
    Database::useProvider($swapped->provider());
    try {
        HostedBootstrap::assertDataPlaneIdentity($swapped->publicId());
        throw new RuntimeException('Swapped tenant database unexpectedly passed identity validation.');
    } catch (HostedResolutionException $e) {
        hosted_assert($e->httpStatus() === 503, 'Swapped database must fail identity validation.');
    }
    Database::reset();
    putenv('CPE_TENANT_DB_PATH_ALPHA_DB=' . $alphaPath);

    $control->setEntitlement('alpha-college', 'placement', false, 'contract');
    $alphaResolved = $resolver->resolveHost('alpha.example.test');
    Database::useProvider($alphaResolved->provider());
    HostedContext::activate($alphaResolved);
    $registry = new ModuleRegistry(cpe_config('modules', []), Database::connection());
    hosted_assert(!$registry->isEnabled('placement'), 'Hosted entitlement must suppress an enabled local module.');
    $_SESSION = ['user_id' => 99, 'cpe_hosted_tenant' => $betaResolved->publicId()];
    HostedBootstrap::bindSession();
    hosted_assert(!isset($_SESSION['user_id']), 'Cross-tenant session user must be cleared.');
    hosted_assert($_SESSION['cpe_hosted_tenant'] === $alphaResolved->publicId(), 'Session must bind to resolved tenant.');
    HostedContext::reset();
    Database::reset();
    $control->setEntitlement('alpha-college', 'placement', true, 'contract');

    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 hours')->format('Y-m-d H:i:s');
    $grant = $control->grantSupportAccess('alpha-college', 'support@example.test', 'Contract diagnosis', $expiresAt, 'contract');
    hosted_assert(count($control->activeSupportGrants('alpha-college')) === 1, 'Active support grant was not recorded.');
    $control->revokeSupportAccess($grant['public_id'], 'contract');
    hosted_assert($control->activeSupportGrants('alpha-college') === [], 'Revoked support grant remains active.');

    $alpha = $control->tenant('alpha-college') ?? throw new RuntimeException('Alpha tenant disappeared.');
    $stmt = $control->connection()->prepare("UPDATE hosted_deployments SET release_version = '0.0.9' WHERE id = ?");
    $stmt->execute([(int) $alpha['deployment_id']]);
    $fleet = new FleetUpgradeService($control, $resolver);
    $planned = $fleet->plan((string) cpe_config('app.version'));
    hosted_assert(count($planned) === 1, 'Fleet planner should create one upgrade job.');
    $fleet->plan((string) cpe_config('app.version'));
    hosted_assert((int) $control->connection()->query("SELECT COUNT(*) FROM hosted_jobs WHERE action = 'upgrade'")->fetchColumn() === 1, 'Fleet job idempotency key must prevent duplicate jobs.');
    $results = $fleet->run(10);
    hosted_assert(count($results) === 1 && $results[0]['status'] === 'complete', 'Fleet upgrade did not complete.');
    hosted_assert(is_file((string) $results[0]['backup']), 'Fleet upgrade did not create its backup first.');
    hosted_assert((int) $control->connection()->query("SELECT COUNT(*) FROM hosted_backup_records WHERE status = 'verified'")->fetchColumn() === 1, 'Verified backup metadata was not recorded.');

    echo "PASS hosted control plane isolates tenant data and operates backup-first\n";
} finally {
    HostedContext::reset();
    Database::reset();
    foreach (['CPE_CONTROL_PLANE_DB_PATH', 'CPE_HOSTED_ALLOW_SQLITE_TENANTS', 'CPE_TENANT_DB_PATH_ALPHA_DB', 'CPE_TENANT_DB_PATH_BETA_DB', 'CPE_HOSTED_BACKUP_DIR'] as $key) {
        putenv($key);
    }
    hosted_remove_tree($root);
}
