<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Infrastructure\Persistence\PostgresConnectionPolicy;
use App\Infrastructure\Persistence\PostgresConnectionProvider;
use App\Install\SystemRequirements;
use App\Install\Installer;
use App\Core\Backup\DatabaseBackupService;
use App\Core\Backup\DatabaseRestoreService;
use App\Core\Persistence\ConnectionProvider;
use App\Support\Database;

final class PostgresPolicyDiagnosticProvider implements ConnectionProvider
{
    private ?PDO $pdo;

    /** @param array<string, mixed> $diagnostics */
    public function __construct(
        private readonly array $diagnostics,
        private readonly bool $failDiagnostics = false,
    )
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function connection(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('Diagnostic provider was disconnected.');
        }
        return $this->pdo;
    }

    public function driver(): string
    {
        return 'pgsql';
    }

    public function identifier(): string
    {
        return 'redacted-diagnostic-provider';
    }

    public function diagnostics(): array
    {
        if ($this->failDiagnostics) {
            throw new RuntimeException('diagnostics-secret-must-not-escape');
        }
        return $this->diagnostics;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }
}

final class PostgresPolicyNoDiagnosticsProvider implements ConnectionProvider
{
    private ?PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function connection(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('Diagnostic provider was disconnected.');
        }
        return $this->pdo;
    }

    public function driver(): string
    {
        return 'pgsql';
    }

    public function identifier(): string
    {
        return 'redacted-no-diagnostics-provider';
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }
}

final class PostgresPolicyCommandProvider implements ConnectionProvider
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return 'pgsql';
    }

    public function identifier(): string
    {
        return 'redacted-command-provider';
    }

    public function disconnect(): void
    {
    }
}

function postgres_policy_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function postgres_policy_expect_failure(callable $operation, string $messagePart, string $label): void
{
    try {
        $operation();
    } catch (RuntimeException $e) {
        postgres_policy_assert(str_contains($e->getMessage(), $messagePart), $label . ' returned the wrong fixed diagnostic.');
        return;
    }
    throw new RuntimeException($label . ' should have failed.');
}

function postgres_policy_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

/** @return array{argv: list<string>, pg_environment: array<string, string>, sensitive_environment: array<string, bool>} */
function postgres_policy_command_record(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    postgres_policy_assert(is_array($decoded), 'Fake PostgreSQL tool record is invalid.');
    return $decoded;
}

/** @param array{argv: list<string>, pg_environment: array<string, string>, sensitive_environment: array<string, bool>} $record */
function postgres_policy_assert_command_record(
    array $record,
    string $password,
    string $rootCert,
    string $timeout,
    string $label,
): void {
    $argv = json_encode($record['argv'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    postgres_policy_assert(!str_contains($argv, $password), $label . ' exposed the PostgreSQL password in argv.');
    postgres_policy_assert(!str_contains($argv, $rootCert), $label . ' exposed the PostgreSQL root path in argv.');
    postgres_policy_assert(str_contains($argv, '--dbname=postgresql://policy%20user@db.example.edu:5432/placement%20db'), $label . ' did not use the password-free resolved URI.');
    $environment = $record['pg_environment'];
    postgres_policy_assert(($environment['PGPASSWORD'] ?? null) === $password, $label . ' did not override PGPASSWORD.');
    postgres_policy_assert(($environment['PGSSLMODE'] ?? null) === 'verify-full', $label . ' did not override PGSSLMODE.');
    postgres_policy_assert(($environment['PGSSLROOTCERT'] ?? null) === $rootCert, $label . ' did not carry the resolved root certificate.');
    postgres_policy_assert(($environment['PGCONNECT_TIMEOUT'] ?? null) === $timeout, $label . ' did not carry the resolved timeout.');
    $keys = array_keys($environment);
    sort($keys);
    postgres_policy_assert(
        $keys === ['PGCONNECT_TIMEOUT', 'PGPASSWORD', 'PGSSLMODE', 'PGSSLROOTCERT'],
        $label . ' inherited an unapproved ambient PG* variable.',
    );
    postgres_policy_assert(
        $record['sensitive_environment'] === ['CPE_DATABASE_URL' => false, 'CPE_PG_PASSWORD' => false],
        $label . ' inherited a duplicate CPE password source.',
    );
}

$constructor = new ReflectionMethod(PostgresConnectionProvider::class, '__construct');
$constructorParameters = $constructor->getParameters();
postgres_policy_assert(
    array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $constructorParameters)
        === ['host', 'port', 'database', 'username', 'password', 'sslMode'],
    'Cloud-fingerprinted PostgreSQL constructor parameters changed.'
);
postgres_policy_assert(count($constructorParameters) === 6, 'Cloud-fingerprinted PostgreSQL constructor arity changed.');
postgres_policy_assert($constructorParameters[5]->isDefaultValueAvailable(), 'PostgreSQL sslMode default was removed.');
postgres_policy_assert($constructorParameters[5]->getDefaultValue() === 'prefer', 'PostgreSQL sslMode default changed.');

$fromUrl = new ReflectionMethod(PostgresConnectionProvider::class, 'fromUrl');
$fromUrlParameters = $fromUrl->getParameters();
postgres_policy_assert(
    array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $fromUrlParameters)
        === ['url', 'label'],
    'Cloud-fingerprinted fromUrl parameters changed.'
);
postgres_policy_assert(count($fromUrlParameters) === 2, 'Cloud-fingerprinted fromUrl arity changed.');
postgres_policy_assert($fromUrlParameters[1]->getDefaultValue() === 'PostgreSQL URL', 'PostgreSQL fromUrl label default changed.');

$secure = PostgresConnectionPolicy::fromUrl(
    'postgresql://policy-user:policy-secret@db.example.edu:5432/placement'
    . '?sslmode=verify-full&sslrootcert=%2Fetc%2Fssl%2Fcerts%2Fca-certificates.crt&connect_timeout=10',
    'session',
);
$secureDiagnostics = $secure->diagnostics();
postgres_policy_assert($secureDiagnostics['strict_policy'] === true, 'Strict URL did not attach strict policy.');
postgres_policy_assert($secureDiagnostics['pool_mode'] === 'session', 'Session-affine pool mode was not retained.');
postgres_policy_assert($secureDiagnostics['ssl_mode'] === 'verify-full', 'Production TLS mode was not retained.');
postgres_policy_assert($secureDiagnostics['trusted_root_configured'] === true, 'Trusted root source was not retained.');
postgres_policy_assert($secureDiagnostics['connect_timeout_seconds'] === 10, 'Connect timeout was not retained.');
postgres_policy_assert($secureDiagnostics['persistent'] === false, 'Strict provider must be non-persistent.');
$secureCommand = $secure->commandConnectionSpec();
$secureCommandEnvironment = $secureCommand->childEnvironment([]);
postgres_policy_assert(($secureCommandEnvironment['PGSSLMODE'] ?? null) === 'verify-full', 'Strict URL TLS mode did not reach command policy.');
postgres_policy_assert(($secureCommandEnvironment['PGSSLROOTCERT'] ?? null) === '/etc/ssl/certs/ca-certificates.crt', 'Strict URL root certificate did not reach command policy.');
postgres_policy_assert(($secureCommandEnvironment['PGCONNECT_TIMEOUT'] ?? null) === '10', 'Strict URL timeout did not reach command policy.');
postgres_policy_assert(!str_contains($secureCommand->safeUri(), 'policy-secret'), 'Strict command URI exposed its password.');
$diagnosticJson = json_encode($secureDiagnostics, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
postgres_policy_assert(!str_contains($diagnosticJson, 'policy-user'), 'Diagnostics exposed the PostgreSQL username.');
postgres_policy_assert(!str_contains($diagnosticJson, 'policy-secret'), 'Diagnostics exposed the PostgreSQL password.');
postgres_policy_assert(!str_contains($diagnosticJson, '/etc/ssl'), 'Diagnostics exposed the trusted root path.');
$doctorPolicy = SystemRequirements::formatPostgresPolicyDiagnostics([
    ...$secureDiagnostics,
    'identity' => 'postgresql://policy-user:policy-secret@secret-host/secret-database',
    'root_path' => '/private/secret-root.pem',
    'secret_reference' => 'secret-manager://database/production',
    'negotiated_tls_verified' => true,
]);
postgres_policy_assert(
    $doctorPolicy === 'state=production-strict; strict=yes; pool=session; tls=verify-full; trusted_root=yes; connect_timeout=10s; persistent=no; negotiated_tls=yes',
    'Doctor production policy diagnostic was incomplete.'
);
foreach (['policy-user', 'policy-secret', 'secret-host', 'secret-database', 'secret-root', 'secret-manager'] as $secret) {
    postgres_policy_assert(!str_contains($doctorPolicy, $secret), 'Doctor policy diagnostic exposed a secret-bearing field.');
}
if (extension_loaded('pdo_pgsql')) {
    Database::useProvider(new PostgresPolicyDiagnosticProvider([
        ...$secureDiagnostics,
        'identity' => 'postgresql://policy-user:policy-secret@secret-host/secret-database',
        'root_path' => '/private/secret-root.pem',
        'negotiated_tls_verified' => true,
    ]));
    try {
        $requirementsChecks = (new SystemRequirements())->checks();
        $policyCheck = array_values(array_filter(
            $requirementsChecks,
            static fn (array $check): bool => ($check['key'] ?? null) === 'postgres_policy',
        ))[0] ?? null;
        postgres_policy_assert(is_array($policyCheck), 'System requirements omitted the PostgreSQL policy diagnostic.');
        postgres_policy_assert(($policyCheck['ok'] ?? null) === true, 'Production policy diagnostic should pass after connection initialization.');
        postgres_policy_assert(($policyCheck['value'] ?? null) === $doctorPolicy, 'System requirements changed the sanitized policy value.');
    } finally {
        Database::reset();
    }
}
postgres_policy_assert(
    SystemRequirements::postgresPolicyDiagnosticsAreAcceptable([
        ...$secureDiagnostics,
        'negotiated_tls_verified' => true,
    ]),
    'Strict verify-full diagnostics should satisfy the requirements gate.',
);
foreach ([
    ['trusted_root_configured' => false],
    ['connect_timeout_seconds' => 31],
    ['persistent' => true],
    ['negotiated_tls_verified' => false],
    ['pool_mode' => 'transaction'],
] as $invalidDiagnostics) {
    postgres_policy_assert(
        !SystemRequirements::postgresPolicyDiagnosticsAreAcceptable([
            ...$secureDiagnostics,
            'negotiated_tls_verified' => true,
            ...$invalidDiagnostics,
        ]),
        'Incomplete verify-full diagnostics should fail the requirements gate.',
    );
}

$components = PostgresConnectionPolicy::fromComponents([
    'host' => 'postgres.example.edu',
    'port' => '6432',
    'database' => 'placements',
    'username' => 'engine',
    'password' => 'component-secret',
    'sslmode' => 'verify-full',
    'sslrootcert' => '/etc/ssl/certs/ca-certificates.crt',
    'connect_timeout' => '7',
], 'direct');
postgres_policy_assert($components->diagnostics()['connect_timeout_seconds'] === 7, 'Component connect timeout was not retained.');
postgres_policy_assert($components->diagnostics()['pool_mode'] === 'direct', 'Direct pool mode was not retained.');

$local = PostgresConnectionPolicy::fromUrl(
    'postgresql://local:local@127.0.0.1:5432/placement?sslmode=disable',
    'direct',
    true,
);
postgres_policy_assert($local->diagnostics()['connect_timeout_seconds'] === 5, 'Local policy did not apply a bounded connect timeout.');
postgres_policy_assert($local->diagnostics()['ssl_mode'] === 'disable', 'Explicit loopback TLS disable was not retained.');
$localDoctorPolicy = SystemRequirements::formatPostgresPolicyDiagnostics($local->diagnostics());
postgres_policy_assert(
    $localDoctorPolicy === 'state=test-loopback-insecure; strict=yes; pool=direct; tls=disable; trusted_root=no; connect_timeout=5s; persistent=no; negotiated_tls=not-applicable',
    'Doctor did not clearly label the accepted loopback test policy.'
);
if (extension_loaded('pdo_pgsql')) {
    Database::useProvider(new PostgresPolicyDiagnosticProvider($local->diagnostics()));
    try {
        $localPolicyCheck = array_values(array_filter(
            (new SystemRequirements())->checks(),
            static fn (array $check): bool => ($check['key'] ?? null) === 'postgres_policy',
        ))[0] ?? null;
        postgres_policy_assert(is_array($localPolicyCheck), 'System requirements omitted the loopback test policy.');
        postgres_policy_assert(($localPolicyCheck['ok'] ?? null) === true, 'Accepted loopback test policy should not fail readiness.');
        postgres_policy_assert(($localPolicyCheck['value'] ?? null) === $localDoctorPolicy, 'System requirements did not clearly label loopback test policy.');
    } finally {
        Database::reset();
    }
}
postgres_policy_assert(
    SystemRequirements::postgresPolicyDiagnosticsAreAcceptable($local->diagnostics()),
    'Strict loopback test diagnostics should satisfy the requirements gate.',
);
$ipv6Command = PostgresConnectionPolicy::fromComponents([
    'host' => '::1',
    'port' => '5432',
    'database' => 'ipv6_database',
    'username' => 'ipv6_user',
    'password' => 'ipv6_secret',
    'sslmode' => 'disable',
], 'direct', true)->commandConnectionSpec();
postgres_policy_assert(
    $ipv6Command->safeUri() === 'postgresql://ipv6_user@[::1]:5432/ipv6_database',
    'Command policy did not bracket-canonicalize a component-style IPv6 host.',
);
foreach ([
    ['strict_policy' => false],
    ['pool_mode' => 'transaction'],
    ['trusted_root_configured' => true],
    ['connect_timeout_seconds' => 0],
    ['persistent' => true],
    ['negotiated_tls_verified' => true],
] as $invalidDiagnostics) {
    postgres_policy_assert(
        !SystemRequirements::postgresPolicyDiagnosticsAreAcceptable([
            ...$local->diagnostics(),
            ...$invalidDiagnostics,
        ]),
        'Incomplete loopback test diagnostics should fail the requirements gate.',
    );
}

if (extension_loaded('pdo_pgsql')) {
    foreach ([
        [new PostgresPolicyNoDiagnosticsProvider(), 'unavailable (provider diagnostics unsupported)'],
        [new PostgresPolicyDiagnosticProvider($local->diagnostics(), true), 'unavailable (provider diagnostics failed)'],
    ] as [$provider, $expectedValue]) {
        Database::useProvider($provider);
        try {
            $checks = (new SystemRequirements())->checks();
            $connectionCheck = array_values(array_filter(
                $checks,
                static fn (array $check): bool => ($check['key'] ?? null) === 'postgres_connection',
            ))[0] ?? null;
            $policyCheck = array_values(array_filter(
                $checks,
                static fn (array $check): bool => ($check['key'] ?? null) === 'postgres_policy',
            ))[0] ?? null;
            postgres_policy_assert(($connectionCheck['ok'] ?? null) === true, 'Diagnostic failure should not change connection reachability.');
            postgres_policy_assert(($policyCheck['ok'] ?? null) === false, 'Unavailable provider diagnostics should fail readiness.');
            postgres_policy_assert(($policyCheck['value'] ?? null) === $expectedValue, 'Unavailable provider diagnostics were not fixed and sanitized.');
            postgres_policy_assert(!str_contains((string) ($policyCheck['value'] ?? ''), 'diagnostics-secret'), 'Provider diagnostic exception escaped into doctor output.');
        } finally {
            Database::reset();
        }
    }
}

postgres_policy_expect_failure(
    static fn (): PostgresConnectionProvider => PostgresConnectionPolicy::fromUrl(
        'postgresql://local:local@127.0.0.1:5432/placement?sslmode=disable',
        'direct',
    ),
    'explicit local-test opt-in',
    'Loopback TLS disable without opt-in',
);
postgres_policy_expect_failure(
    static fn (): PostgresConnectionProvider => PostgresConnectionPolicy::fromUrl(
        'postgresql://user:secret@db.example.edu:5432/placement?sslmode=disable',
        'direct',
        true,
    ),
    'only for loopback',
    'Non-loopback TLS disable',
);
postgres_policy_expect_failure(
    static fn (): PostgresConnectionProvider => PostgresConnectionPolicy::fromUrl(
        'postgresql://user:secret@db.example.edu:5432/placement?sslmode=require&connect_timeout=5',
        'direct',
    ),
    'sslmode=verify-full',
    'Production weak TLS mode',
);
postgres_policy_expect_failure(
    static fn (): PostgresConnectionProvider => PostgresConnectionPolicy::fromUrl(
        'postgresql://user:secret@db.example.edu:5432/placement?sslmode=verify-full&connect_timeout=5',
        'direct',
    ),
    'explicit sslrootcert',
    'Production missing root certificate',
);
postgres_policy_expect_failure(
    static fn (): PostgresConnectionProvider => PostgresConnectionPolicy::fromUrl(
        'postgresql://user:secret@db.example.edu:5432/placement?sslmode=verify-full&sslrootcert=%2Fetc%2Fssl%2Fcert.pem',
        'direct',
    ),
    'bounded connect_timeout',
    'Production missing timeout',
);
postgres_policy_expect_failure(
    static fn (): PostgresConnectionProvider => PostgresConnectionPolicy::fromUrl(
        'postgresql://user:secret@db.example.edu:5432/placement?sslmode=verify-full'
        . '&sslrootcert=%2Fetc%2Fssl%2Fcert.pem&connect_timeout=31',
        'direct',
    ),
    'between 1 and 30',
    'Unbounded production timeout',
);
postgres_policy_expect_failure(
    static fn (): PostgresConnectionProvider => PostgresConnectionPolicy::fromUrl(
        'postgresql://user:secret@db.example.edu:5432/placement?sslmode=verify-full'
        . '&sslmode=verify-full&sslrootcert=%2Fetc%2Fssl%2Fcert.pem&connect_timeout=5',
        'direct',
    ),
    'duplicate query parameter',
    'Duplicate URL query parameter',
);
postgres_policy_expect_failure(
    static fn (): PostgresConnectionProvider => PostgresConnectionPolicy::fromUrl(
        'postgresql://user:secret@db.example.edu:5432/placement?sslmode=verify-full'
        . '&sslrootcert=%2Fetc%2Fssl%2Fcert.pem&connect_timeout=5&application_name=engine',
        'direct',
    ),
    'unknown query parameter',
    'Unknown URL query parameter',
);
postgres_policy_expect_failure(
    static fn (): PostgresConnectionProvider => PostgresConnectionPolicy::fromUrl(
        'postgresql://user:secret@db.example.edu:5432/placement%3Bsslmode%3Ddisable?sslmode=verify-full'
        . '&sslrootcert=%2Fetc%2Fssl%2Fcert.pem&connect_timeout=5',
        'direct',
    ),
    'invalid database',
    'DSN injection in database name',
);
postgres_policy_expect_failure(
    static fn (): PostgresConnectionProvider => PostgresConnectionPolicy::fromUrl(
        'postgresql://user:secret@db.example.edu:5432/placement?sslmode=verify-full'
        . '&sslrootcert=%2Fetc%2Fssl%2Fcert.pem%0Ahost%3Devil&connect_timeout=5',
        'direct',
    ),
    'control character',
    'Control-character injection',
);
postgres_policy_expect_failure(
    static fn (): PostgresConnectionProvider => PostgresConnectionPolicy::fromUrl(
        'postgresql://user:secret@db.example.edu:5432/placement?sslmode=verify-full'
        . '&sslrootcert=%2Fetc%2Fssl%2Fcert.pem&connect_timeout=5',
        'transaction',
    ),
    'direct or session',
    'Transaction pool mode',
);

$legacy = PostgresConnectionProvider::fromUrl(
    'postgresql://legacy-user:legacy-secret@127.0.0.1:5432/legacy?sslmode=disable'
    . '&sslrootcert=%2Ftmp%2Flegacy-ca.pem&connect_timeout=20&application_name=retained%20application',
);
$legacyDiagnostics = $legacy->diagnostics();
postgres_policy_assert($legacyDiagnostics['strict_policy'] === false, 'Legacy fromUrl unexpectedly became strict.');
postgres_policy_assert($legacyDiagnostics['trusted_root_configured'] === true, 'Legacy fromUrl dropped sslrootcert.');
postgres_policy_assert($legacyDiagnostics['connect_timeout_seconds'] === 20, 'Legacy fromUrl dropped connect_timeout.');
postgres_policy_assert(!str_contains($legacy->identifier(), 'legacy-secret'), 'Legacy identity exposed its password.');
$legacyCommand = $legacy->commandConnectionSpec();
$legacyCommandEnvironment = $legacyCommand->childEnvironment([
    'PATH' => '/usr/bin',
    'PGPASSWORD' => 'hostile-password',
    'PGSSLMODE' => 'verify-full',
    'PGSSLROOTCERT' => '/hostile/root.pem',
    'PGCONNECT_TIMEOUT' => '999',
    'PGSERVICE' => 'hostile-service',
]);
postgres_policy_assert(
    $legacyCommand->safeUri() === 'postgresql://legacy-user@127.0.0.1:5432/legacy?application_name=retained%20application',
    'Legacy command compatibility URI lost a valid option or retained a secret.',
);
postgres_policy_assert(($legacyCommandEnvironment['PGPASSWORD'] ?? null) === 'legacy-secret', 'Legacy command compatibility lost its URL password.');
postgres_policy_assert(($legacyCommandEnvironment['PGSSLMODE'] ?? null) === 'disable', 'Legacy command compatibility lost its URL TLS mode.');
postgres_policy_assert(($legacyCommandEnvironment['PGSSLROOTCERT'] ?? null) === '/tmp/legacy-ca.pem', 'Legacy command compatibility lost its URL root certificate.');
postgres_policy_assert(($legacyCommandEnvironment['PGCONNECT_TIMEOUT'] ?? null) === '20', 'Legacy command compatibility lost its URL timeout.');
postgres_policy_assert(!isset($legacyCommandEnvironment['PGSERVICE']), 'Legacy command compatibility inherited ambient libpq service configuration.');
postgres_policy_expect_failure(
    static fn (): PostgresConnectionProvider => PostgresConnectionProvider::fromUrl(
        'postgresql://legacy-user:legacy-secret@127.0.0.1:5432/legacy?sslmode=disable&password=argv-secret',
    ),
    'password-bearing query option',
    'Legacy command password query option',
);
if (extension_loaded('pdo_pgsql')) {
    Database::useProvider(new PostgresPolicyDiagnosticProvider($legacyDiagnostics));
    try {
        $legacyChecks = (new SystemRequirements())->checks();
        $legacyConnectionCheck = array_values(array_filter(
            $legacyChecks,
            static fn (array $check): bool => ($check['key'] ?? null) === 'postgres_connection',
        ))[0] ?? null;
        $legacyPolicyCheck = array_values(array_filter(
            $legacyChecks,
            static fn (array $check): bool => ($check['key'] ?? null) === 'postgres_policy',
        ))[0] ?? null;
        postgres_policy_assert(($legacyConnectionCheck['ok'] ?? null) === true, 'Connected legacy provider should remain reachable.');
        postgres_policy_assert(($legacyPolicyCheck['ok'] ?? null) === false, 'Connected legacy provider should fail the strict policy gate.');
        postgres_policy_assert(
            str_starts_with((string) ($legacyPolicyCheck['value'] ?? ''), 'state=legacy-compatibility; strict=no;'),
            'Legacy policy failure diagnostic was not sanitized and explicit.',
        );
        postgres_policy_assert(in_array('postgres_policy', (new SystemRequirements())->failures(), true), 'Legacy provider did not fail requirements readiness.');
    } finally {
        Database::reset();
    }
}

$environmentNames = [
    'CPE_DATABASE_URL',
    'CPE_PG_HOST',
    'CPE_PG_PORT',
    'CPE_PG_DATABASE',
    'CPE_PG_USER',
    'CPE_PG_PASSWORD',
    'CPE_PG_SSLMODE',
    'CPE_PG_SSLROOTCERT',
    'CPE_PG_CONNECT_TIMEOUT',
    'CPE_POSTGRES_POOL_MODE',
    'CPE_POSTGRES_ALLOW_INSECURE_LOOPBACK',
];
$savedEnvironment = [];
foreach ($environmentNames as $name) {
    $savedEnvironment[$name] = getenv($name);
    putenv($name);
}
try {
    putenv('CPE_DATABASE_URL=postgresql://local:local@localhost:5432/environment?sslmode=disable');
    putenv('CPE_POSTGRES_POOL_MODE=session');
    putenv('CPE_POSTGRES_ALLOW_INSECURE_LOOPBACK=1');
    $environmentProvider = PostgresConnectionProvider::fromEnvironment();
    postgres_policy_assert($environmentProvider->diagnostics()['strict_policy'] === true, 'Runtime environment bypassed strict policy.');
    postgres_policy_assert($environmentProvider->diagnostics()['pool_mode'] === 'session', 'Runtime environment dropped pool mode.');

    $environmentCommand = PostgresConnectionPolicy::commandConnectionFromEnvironment();
    postgres_policy_assert(
        $environmentCommand->safeUri() === 'postgresql://local@localhost:5432/environment',
        'Runtime command URI was not derived from the strict policy fields.',
    );
    $environmentCommandValues = $environmentCommand->childEnvironment([
        'PATH' => '/usr/bin',
        'PGPASSWORD' => 'hostile-password',
        'PGSSLMODE' => 'verify-full',
        'PGSSLROOTCERT' => '/hostile/root.pem',
        'PGCONNECT_TIMEOUT' => '999',
        'PGSERVICE' => 'hostile-service',
        'PGPASSFILE' => '/hostile/passfile',
    ]);
    postgres_policy_assert(($environmentCommandValues['PGPASSWORD'] ?? null) === 'local', 'Runtime command password did not come from strict policy.');
    postgres_policy_assert(($environmentCommandValues['PGSSLMODE'] ?? null) === 'disable', 'Runtime command TLS mode did not come from strict policy.');
    postgres_policy_assert(($environmentCommandValues['PGCONNECT_TIMEOUT'] ?? null) === '5', 'Runtime command timeout did not come from strict policy.');
    postgres_policy_assert(!isset($environmentCommandValues['PGSSLROOTCERT']), 'Loopback command inherited a hostile root certificate.');
    postgres_policy_assert(!isset($environmentCommandValues['PGSERVICE'], $environmentCommandValues['PGPASSFILE']), 'Runtime command inherited ambient libpq configuration.');

    putenv('CPE_DATABASE_URL');
    putenv('CPE_PG_HOST=127.0.0.1');
    putenv('CPE_PG_PORT=5432');
    putenv('CPE_PG_DATABASE=component_environment');
    putenv('CPE_PG_USER=component_user');
    putenv('CPE_PG_PASSWORD=component_password');
    putenv('CPE_PG_SSLMODE=disable');
    putenv('CPE_POSTGRES_POOL_MODE=direct');
    $componentEnvironmentProvider = PostgresConnectionProvider::fromEnvironment();
    postgres_policy_assert($componentEnvironmentProvider->diagnostics()['strict_policy'] === true, 'Component environment bypassed strict policy.');
    postgres_policy_assert($componentEnvironmentProvider->diagnostics()['connect_timeout_seconds'] === 5, 'Component environment did not apply local timeout.');
} finally {
    foreach ($savedEnvironment as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
}

$commandEnvironmentNames = [
    ...$environmentNames,
    'CPE_DB_DRIVER',
    'CPE_DB_PATH',
    'CPE_BACKUP_DIR',
    'CPE_PG_DUMP_BINARY',
    'CPE_PG_RESTORE_BINARY',
    'PGPASSWORD',
    'PGSSLMODE',
    'PGSSLROOTCERT',
    'PGCONNECT_TIMEOUT',
    'PGSERVICE',
    'PGPASSFILE',
];
$savedCommandEnvironment = [];
foreach ($commandEnvironmentNames as $name) {
    $savedCommandEnvironment[$name] = getenv($name);
    putenv($name);
}
$commandRoot = sys_get_temp_dir() . '/cpe-postgres-command-policy-' . bin2hex(random_bytes(6));
postgres_policy_assert(mkdir($commandRoot, 0700), 'Could not create PostgreSQL command policy fixture directory.');
try {
    $fakeTool = $commandRoot . '/fake-pg-tool';
    $fakeToolSource = <<<'PHP'
#!/usr/bin/env php
<?php
$environment = getenv();
$pgEnvironment = [];
foreach (is_array($environment) ? $environment : [] as $name => $value) {
    if (str_starts_with((string) $name, 'PG')) {
        $pgEnvironment[(string) $name] = (string) $value;
    }
}
ksort($pgEnvironment);
file_put_contents((string) getenv('CPE_FAKE_PG_RECORD'), json_encode([
    'argv' => array_values(array_slice($argv, 1)),
    'pg_environment' => $pgEnvironment,
    'sensitive_environment' => [
        'CPE_DATABASE_URL' => getenv('CPE_DATABASE_URL') !== false,
        'CPE_PG_PASSWORD' => getenv('CPE_PG_PASSWORD') !== false,
    ],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--file=')) {
        file_put_contents(substr($argument, strlen('--file=')), 'synthetic PostgreSQL custom archive');
    }
}
PHP;
    postgres_policy_assert(file_put_contents($fakeTool, $fakeToolSource) !== false && chmod($fakeTool, 0700), 'Could not create fake PostgreSQL command tool.');

    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DB_PATH=' . $commandRoot . '/identity.sqlite');
    Database::reset();
    (new Installer())->install([
        'college_name' => 'Command Policy College',
        'timezone' => 'UTC',
        'admin_name' => 'Command Policy Administrator',
        'admin_email' => 'command-policy@example.test',
        'admin_password' => 'command-policy-password-123',
    ]);
    $identityPdo = Database::connection();
    Database::useProvider(new PostgresPolicyCommandProvider($identityPdo));

    $policyPassword = 'runtime:policy-secret';
    $policyRoot = $commandRoot . '/policy-ca.pem';
    putenv('CPE_DATABASE_URL=postgresql://policy%20user:runtime%3Apolicy-secret@db.example.edu:5432/placement%20db?sslmode=verify-full');
    putenv('CPE_PG_SSLROOTCERT=' . $policyRoot);
    putenv('CPE_PG_CONNECT_TIMEOUT=12');
    putenv('CPE_POSTGRES_POOL_MODE=direct');
    putenv('CPE_PG_DUMP_BINARY=' . $fakeTool);
    putenv('CPE_PG_RESTORE_BINARY=' . $fakeTool);
    putenv('PGPASSWORD=hostile-password');
    putenv('PGSSLMODE=disable');
    putenv('PGSSLROOTCERT=/hostile/root.pem');
    putenv('PGCONNECT_TIMEOUT=999');
    putenv('PGSERVICE=hostile-service');
    putenv('PGPASSFILE=/hostile/passfile');
    putenv('CPE_PG_PASSWORD=hostile-component-password');

    $dumpRecordPath = $commandRoot . '/dump-record.json';
    putenv('CPE_FAKE_PG_RECORD=' . $dumpRecordPath);
    (new DatabaseBackupService($identityPdo))->create('policy', $commandRoot . '/backups');
    postgres_policy_assert_command_record(
        postgres_policy_command_record($dumpRecordPath),
        $policyPassword,
        $policyRoot,
        '12',
        'pg_dump',
    );

    $restoreRecordPath = $commandRoot . '/restore-record.json';
    putenv('CPE_FAKE_PG_RECORD=' . $restoreRecordPath);
    Database::useProvider(new PostgresPolicyCommandProvider($identityPdo));
    $restoreMethod = new ReflectionMethod(DatabaseRestoreService::class, 'restorePostgres');
    $restoreMethod->invoke(new DatabaseRestoreService(), $commandRoot . '/synthetic.pgdump');
    postgres_policy_assert_command_record(
        postgres_policy_command_record($restoreRecordPath),
        $policyPassword,
        $policyRoot,
        '12',
        'pg_restore',
    );

    $compatibilityUrl = 'postgresql://legacy%20user:legacy%3Asecret@127.0.0.1:5432/legacy%20db'
        . '?sslmode=disable&sslrootcert=%2Ftmp%2Flegacy-ca.pem&connect_timeout=20&application_name=backup%20operator';
    putenv('CPE_DATABASE_URL=not-a-runtime-url');
    $compatibilityRecords = [];
    $compatibilityDumpRecord = $commandRoot . '/compatibility-dump-record.json';
    putenv('CPE_FAKE_PG_RECORD=' . $compatibilityDumpRecord);
    Database::useProvider(new PostgresPolicyCommandProvider($identityPdo));
    (new DatabaseBackupService($identityPdo, $compatibilityUrl))->create('compatibility', $commandRoot . '/backups');
    $compatibilityRecords['explicit pg_dump'] = postgres_policy_command_record($compatibilityDumpRecord);
    $compatibilityRestoreRecord = $commandRoot . '/compatibility-restore-record.json';
    putenv('CPE_FAKE_PG_RECORD=' . $compatibilityRestoreRecord);
    Database::useProvider(new PostgresPolicyCommandProvider($identityPdo));
    $restoreMethod->invoke(new DatabaseRestoreService($compatibilityUrl), $commandRoot . '/synthetic.pgdump');
    $compatibilityRecords['explicit pg_restore'] = postgres_policy_command_record($compatibilityRestoreRecord);
    foreach ($compatibilityRecords as $label => $record) {
        $argv = json_encode($record['argv'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        postgres_policy_assert(!str_contains($argv, 'legacy:secret'), $label . ' exposed its password in argv.');
        postgres_policy_assert(str_contains($argv, '--dbname=postgresql://legacy%20user@127.0.0.1:5432/legacy%20db?application_name=backup%20operator'), $label . ' did not retain a valid explicit constructor option.');
        postgres_policy_assert(!str_contains($argv, 'sslrootcert'), $label . ' exposed its CA path through argv.');
        postgres_policy_assert(($record['pg_environment']['PGPASSWORD'] ?? null) === 'legacy:secret', $label . ' lost its explicit constructor password.');
        postgres_policy_assert(($record['pg_environment']['PGSSLMODE'] ?? null) === 'disable', $label . ' lost its explicit constructor TLS mode.');
        postgres_policy_assert(($record['pg_environment']['PGSSLROOTCERT'] ?? null) === '/tmp/legacy-ca.pem', $label . ' lost its explicit constructor root certificate.');
        postgres_policy_assert(($record['pg_environment']['PGCONNECT_TIMEOUT'] ?? null) === '20', $label . ' lost its explicit constructor timeout.');
        postgres_policy_assert($record['sensitive_environment'] === ['CPE_DATABASE_URL' => false, 'CPE_PG_PASSWORD' => false], $label . ' inherited a duplicate CPE password source.');
    }

    putenv('CPE_DATABASE_URL=postgresql://policy%20user:runtime%3Apolicy-secret@db.example.edu:5432/placement%20db?sslmode=verify-full&sslrootcert=%2Furl-ca.pem');
    try {
        PostgresConnectionPolicy::commandConnectionFromEnvironment();
        throw new RuntimeException('Duplicate URL and environment root certificate was accepted for command tools.');
    } catch (RuntimeException $e) {
        postgres_policy_assert(str_contains($e->getMessage(), 'configures sslrootcert more than once'), 'Command policy duplicate root certificate used the wrong failure.');
    }
    putenv('CPE_PG_SSLROOTCERT');
    putenv('CPE_PG_CONNECT_TIMEOUT=12');
    putenv('CPE_DATABASE_URL=postgresql://policy%20user:runtime%3Apolicy-secret@db.example.edu:5432/placement%20db?sslmode=verify-full&sslrootcert=%2Furl-ca.pem&connect_timeout=9');
    try {
        PostgresConnectionPolicy::commandConnectionFromEnvironment();
        throw new RuntimeException('Duplicate URL and environment timeout was accepted for command tools.');
    } catch (RuntimeException $e) {
        postgres_policy_assert(str_contains($e->getMessage(), 'configures connect_timeout more than once'), 'Command policy duplicate timeout used the wrong failure.');
    }
} finally {
    Database::reset();
    putenv('CPE_FAKE_PG_RECORD');
    foreach ($savedCommandEnvironment as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
    postgres_policy_remove_tree($commandRoot);
}

echo "PASS strict PostgreSQL connection policy and compatibility contract\n";
