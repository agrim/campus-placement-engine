<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Infrastructure\Persistence\PostgresConnectionPolicy;
use App\Infrastructure\Persistence\PostgresConnectionProvider;
use App\Install\SystemRequirements;
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
    . '&sslrootcert=%2Ftmp%2Flegacy-ca.pem&connect_timeout=20&legacy_option=retained',
);
$legacyDiagnostics = $legacy->diagnostics();
postgres_policy_assert($legacyDiagnostics['strict_policy'] === false, 'Legacy fromUrl unexpectedly became strict.');
postgres_policy_assert($legacyDiagnostics['trusted_root_configured'] === true, 'Legacy fromUrl dropped sslrootcert.');
postgres_policy_assert($legacyDiagnostics['connect_timeout_seconds'] === 20, 'Legacy fromUrl dropped connect_timeout.');
postgres_policy_assert(!str_contains($legacy->identifier(), 'legacy-secret'), 'Legacy identity exposed its password.');
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

echo "PASS strict PostgreSQL connection policy and compatibility contract\n";
