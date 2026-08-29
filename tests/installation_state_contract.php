<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$testRoot = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir())
    . '/cpe-installation-state-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create installation-state contract root.');
}
$databasePath = $testRoot . '/engine.sqlite';
$requestedDriver = strtolower(trim((string) (getenv('CPE_DB_DRIVER') ?: 'sqlite')));
$postgres = in_array($requestedDriver, ['pgsql', 'postgresql'], true)
    || trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== '';
if (!$postgres) {
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $databasePath);
}

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require $projectRoot . '/app/bootstrap.php';
require $projectRoot . '/tests/authorized_setup_recovery_fixture.php';

use App\Core\Install\InstallationState;
use App\Core\Install\InstallationStateUnavailable;
use App\Hosted\HostedBootstrap;
use App\Hosted\HostedContext;
use App\Hosted\Tenant\HostedResolutionException;
use App\Hosted\Tenant\ResolvedTenant;
use App\Hosted\Tenant\TenantResolver;
use App\Infrastructure\Persistence\SqliteConnectionProvider;
use App\Install\InstallationStepObserver;
use App\Install\Installer;
use App\Support\Database;

final class InstallationStateTenantResolver implements TenantResolver
{
    public function __construct(private readonly ResolvedTenant $tenant)
    {
    }

    public function resolveHost(string $host): ResolvedTenant
    {
        if ($host !== 'state.example.test') {
            throw new HostedResolutionException('Unknown installation-state host.', 404);
        }
        return $this->tenant;
    }
}

function installation_state_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function installation_state_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')',
        );
    }
}

function installation_state_unavailable(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (InstallationStateUnavailable $failure) {
        installation_state_same(
            'Installation state is temporarily unavailable.',
            $failure->getMessage(),
            $label . ' did not use the fixed typed message.',
        );
        installation_state_true($failure->getPrevious() === null, $label . ' retained its raw failure.');
        return;
    }
    throw new RuntimeException($label . ' did not raise InstallationStateUnavailable.');
}

function installation_state_hosted_unavailable(ResolvedTenant $tenant, string $label): void
{
    HostedBootstrap::registerResolver(new InstallationStateTenantResolver($tenant));
    try {
        HostedBootstrap::resolveHost('state.example.test');
    } catch (HostedResolutionException $failure) {
        installation_state_same(503, $failure->httpStatus(), $label . ' did not return hosted 503.');
        installation_state_same(
            'Tenant installation state is unavailable.',
            $failure->getMessage(),
            $label . ' did not use the fixed hosted message.',
        );
        installation_state_true($failure->getPrevious() === null, $label . ' retained its raw hosted failure.');
        return;
    } finally {
        HostedBootstrap::resetResolver();
        HostedContext::reset();
    }
    throw new RuntimeException($label . ' did not fail closed in hosted resolution.');
}

function installation_state_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path . '/' . $entry;
        if (is_dir($child)) {
            installation_state_remove_tree($child);
        } elseif (is_file($child)) {
            unlink($child);
        }
    }
    rmdir($path);
}

try {
    $provider = Database::provider();
    $freshReplayPath = $testRoot . '/fresh-replay.sqlite';
    Database::useProvider(new SqliteConnectionProvider($freshReplayPath));
    Database::migrate(false);
    $freshReplayAuthority = test_authorized_setup_recovery_authority();
    Database::reset();
    if (is_file($freshReplayPath)) {
        unlink($freshReplayPath);
    }
    Database::useProvider(new SqliteConnectionProvider($freshReplayPath));
    installation_state_unavailable(
        static fn (): int => (new Installer())->install([
            'college_name' => 'Fresh Replay Rejection College',
            'timezone' => 'UTC',
            'admin_name' => 'Fresh Replay Rejection Administrator',
            'admin_email' => 'fresh-replay@example.test',
            'admin_password' => 'fresh-replay-password-123',
        ], $freshReplayAuthority),
        'Recovery authority replay against its now-fresh exact target',
    );
    installation_state_true(
        !is_file($freshReplayPath),
        'Rejected recovery authority replay initialized its now-fresh target.',
    );
    Database::useProvider($provider);
    $setupAuthorization = test_authorized_setup_authorization();
    $tenantId = 'tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    $tenant = new ResolvedTenant([
        'tenant_id' => 7,
        'tenant_public_id' => $tenantId,
        'slug' => 'state-contract',
        'entitlements' => ['placement' => true, 'advising' => true],
    ], $provider, null);

    installation_state_same(InstallationState::FRESH, Database::installationStateStrict(), 'Empty target was not fresh.');
    $originalScriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
    $_SERVER['SCRIPT_FILENAME'] = cpe_path('placement');
    installation_state_true(
        !method_exists(\App\Security\SetupRecoveryAuthority::class, 'forTrustedCliEntrypoint')
            && !method_exists(\App\Security\SetupRecoveryAuthority::class, 'forFreshTarget'),
        'Recovery authority still trusts process metadata or permits fresh-state issuance.',
    );
    if ($originalScriptFilename === null) {
        unset($_SERVER['SCRIPT_FILENAME']);
    } else {
        $_SERVER['SCRIPT_FILENAME'] = $originalScriptFilename;
    }
    installation_state_unavailable(
        static fn (): \App\Security\SetupRecoveryAuthority => $setupAuthorization->issueRecoveryAuthority(),
        'Authorized fresh target recovery authority issuance',
    );
    HostedBootstrap::registerResolver(new InstallationStateTenantResolver($tenant));
    HostedBootstrap::resolveHost('state.example.test');
    installation_state_same($tenantId, HostedContext::current()->publicId(), 'Fresh hosted target did not resolve for provisioning.');
    HostedBootstrap::resetResolver();
    HostedContext::reset();

    $pdo = Database::connection();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $pdo->exec('CREATE TABLE ambiguous_partial_schema (value TEXT NOT NULL)');
    installation_state_unavailable(
        static fn (): string => Database::installationStateStrict(),
        'Nonempty schema without settings',
    );
    installation_state_unavailable(
        static fn (): \App\Security\SetupRecoveryAuthority => $setupAuthorization->issueRecoveryAuthority(),
        'Setup against non-Engine partial schema',
    );
    installation_state_hosted_unavailable($tenant, 'Hosted nonempty schema without settings');
    $pdo->exec('DROP TABLE ambiguous_partial_schema');

    $pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    installation_state_unavailable(
        static fn (): string => Database::installationStateStrict(),
        'Settings-only schema without marker',
    );
    installation_state_unavailable(
        static fn (): \App\Security\SetupRecoveryAuthority => $setupAuthorization->issueRecoveryAuthority(),
        'Setup against unowned settings-only schema',
    );
    installation_state_hosted_unavailable($tenant, 'Hosted settings-only schema without marker');
    $pdo->exec('DROP TABLE settings');

    Database::migrate(false);
    $recoveryAuthority = $setupAuthorization->issueRecoveryAuthority();
    try {
        serialize($recoveryAuthority);
        throw new RuntimeException('Recovery authority was serializable and replayable.');
    } catch (InstallationStateUnavailable $failure) {
        installation_state_same(
            'Installation state is temporarily unavailable.',
            $failure->getMessage(),
            'Recovery authority serialization did not fail with the fixed typed error.',
        );
    }
    installation_state_unavailable(
        static fn (): string => Database::installationStateStrict(),
        'Runtime Engine-owned markerless schema',
    );
    installation_state_same(
        InstallationState::RECOVERABLE,
        Database::installationStateForAuthorizedSetupStrict($recoveryAuthority),
        'Authorized setup did not recognize exact Engine-owned recovery state.',
    );
    installation_state_hosted_unavailable($tenant, 'Hosted Engine-owned markerless schema');

    $pdo->exec('ALTER TABLE settings RENAME TO settings_damaged');
    installation_state_unavailable(
        static fn (): string => Database::installationStateStrict(),
        'Runtime Engine schema missing settings',
    );
    installation_state_unavailable(
        static fn (): string => Database::installationStateForAuthorizedSetupStrict($recoveryAuthority),
        'Setup Engine schema missing settings',
    );
    installation_state_hosted_unavailable($tenant, 'Hosted Engine schema missing settings');
    $pdo->exec('ALTER TABLE settings_damaged RENAME TO settings');

    $marker = $pdo->prepare("INSERT INTO settings (key, value) VALUES ('installed_at', ?)");
    foreach (['', 'not-a-timestamp', '2026-02-30 01:02:03', '2026-01-02T03:04:05Z'] as $malformed) {
        $pdo->exec("DELETE FROM settings WHERE key = 'installed_at'");
        $marker->execute([$malformed]);
        installation_state_unavailable(
            static fn (): string => Database::installationStateStrict(),
            'Runtime malformed installed marker',
        );
        installation_state_unavailable(
            static fn (): string => Database::installationStateForAuthorizedSetupStrict($recoveryAuthority),
            'Setup malformed installed marker',
        );
    }
    installation_state_hosted_unavailable($tenant, 'Hosted malformed installed marker');
    $pdo->exec("DELETE FROM settings WHERE key = 'installed_at'");

    $input = [
        'college_name' => 'Installation State Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'Installation State Administrator',
        'admin_email' => 'installation-state@example.test',
        'admin_password' => 'installation-state-password-123',
    ];
    installation_state_unavailable(
        static fn (): int => (new Installer())->installHosted($input, $tenantId),
        'Direct Installer recovery without setup authority',
    );
    installation_state_unavailable(
        static fn (): int => (new Installer())->install($input),
        'Direct self-hosted Installer recovery without setup authority',
    );
    installation_state_same(
        0,
        (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key = 'installed_at'")->fetchColumn(),
        'Rejected direct Installer recovery wrote an installation marker.',
    );
    $throwingObserver = new class implements InstallationStepObserver {
        public function observe(string $stage): void
        {
            if ($stage === InstallationStepObserver::AFTER_IDENTITY) {
                throw new RuntimeException('forced installation transaction failure');
            }
        }
    };
    try {
        (new Installer($throwingObserver))->installHosted($input, $tenantId, $recoveryAuthority);
        throw new RuntimeException('Expected installation transaction failure.');
    } catch (RuntimeException $failure) {
        installation_state_same('forced installation transaction failure', $failure->getMessage(), 'Installation failure fixture returned the wrong exception.');
    }
    installation_state_same(
        InstallationState::RECOVERABLE,
        Database::installationStateForAuthorizedSetupStrict($recoveryAuthority),
        'Failed installation transaction did not retain an authorized recovery path.',
    );
    installation_state_unavailable(
        static fn (): string => Database::installationStateStrict(),
        'Runtime failed installation recovery target',
    );

    $adminId = (new Installer())->installHosted($input, $tenantId, $recoveryAuthority);
    installation_state_true($adminId > 0, 'Authorized setup recovery did not create its administrator.');
    installation_state_same(InstallationState::INSTALLED, Database::installationStateStrict(), 'Complete installation was not strict-installed.');
    installation_state_true(Database::hasInstalledMarkerStrict(), 'Complete installation marker was not accepted.');
    installation_state_unavailable(
        static fn (): string => Database::installationStateForAuthorizedSetupStrict($recoveryAuthority),
        'Recovery authority replay after installation',
    );

    HostedBootstrap::registerResolver(new InstallationStateTenantResolver($tenant));
    HostedBootstrap::resolveHost('state.example.test');
    installation_state_same($tenantId, HostedContext::current()->publicId(), 'Installed hosted identity did not resolve.');
    HostedBootstrap::assertDataPlaneIdentity($tenantId);
    HostedBootstrap::resetResolver();
    HostedContext::reset();

    echo 'Installation state contract passed (' . $driver . ").\n";
} finally {
    HostedBootstrap::resetResolver();
    HostedContext::reset();
    Database::reset();
    installation_state_remove_tree($testRoot);
}
