<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$safeTempRoot = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
$testRoot = $safeTempRoot . '/cpe-authorization-failure-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create authorization contract test root.');
}

$databasePath = $testRoot . '/engine.sqlite';
$structuredLogPath = $testRoot . '/structured.log';
$serverLogPath = $testRoot . '/server.log';
putenv('CPE_DB_DRIVER=sqlite');
putenv('CPE_DATABASE_URL');
putenv('CPE_DB_PATH=' . $databasePath);
putenv('CPE_LOG_PATH=' . $structuredLogPath);
putenv('CPE_HOSTED_MODE');
putenv('CPE_PLATFORM_BOOTSTRAP');
putenv('CPE_SESSION_DRIVER=files');
putenv('CPE_SESSION_SECURE=0');

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require $projectRoot . '/app/bootstrap.php';
require $projectRoot . '/tests/authorized_setup_recovery_fixture.php';

use App\Core\Modules\ModuleRegistry;
use App\Core\Portal;
use App\Core\Security\AuthorizationUnavailable;
use App\Core\Security\CapabilityService;
use App\Core\Install\InstallationState;
use App\Core\Install\InstallationStateUnavailable;
use App\Install\Installer;
use App\Support\Auth;
use App\Support\ControllerFailure;
use App\Support\Database;

function authorization_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function authorization_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')',
        );
    }
}

function authorization_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function authorization_create_grants(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE role_capabilities (role_key TEXT NOT NULL, capability TEXT NOT NULL)');
}

function authorization_create_modules(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE module_installations (module_key TEXT NOT NULL, version TEXT NOT NULL, enabled INTEGER NOT NULL)');
}

function authorization_registry(PDO $pdo): ModuleRegistry
{
    return new ModuleRegistry(cpe_config('modules', []), $pdo);
}

function authorization_expect_unavailable(
    callable $operation,
    string $reason,
    string $label,
    string $sentinel = '',
): AuthorizationUnavailable {
    try {
        $operation();
    } catch (AuthorizationUnavailable $exception) {
        authorization_same($reason, $exception->reason(), $label . ' returned the wrong typed reason.');
        authorization_same(
            'Authorization state is temporarily unavailable.',
            $exception->getMessage(),
            $label . ' exposed a non-fixed exception message.',
        );
        authorization_true($exception->getPrevious() === null, $label . ' retained the raw database failure.');
        if ($sentinel !== '') {
            authorization_true(
                !str_contains($exception->getMessage(), $sentinel),
                $label . ' exposed the database sentinel.',
            );
        }
        return $exception;
    }
    throw new RuntimeException($label . ' did not raise AuthorizationUnavailable.');
}

function authorization_expect_failure(callable $operation, string $label): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    throw new RuntimeException($label . ' did not fail.');
}

function authorization_reserve_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if (!is_resource($socket)) {
        throw new RuntimeException('Could not reserve authorization contract port: ' . $errorMessage, $errorNumber);
    }
    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr(strrchr($address, ':'), 1);
}

/** @return array{0: resource, 1: array<int, resource>, 2: int} */
function authorization_start_server(
    string $projectRoot,
    string $databasePath,
    string $structuredLogPath,
    string $serverLogPath,
): array {
    $port = authorization_reserve_port();
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    foreach (['CPE_DATABASE_URL', 'CPE_HOSTED_MODE', 'CPE_PLATFORM_BOOTSTRAP'] as $key) {
        unset($environment[$key]);
    }
    $environment = array_merge($environment, [
        'CPE_DB_DRIVER' => 'sqlite',
        'CPE_DB_PATH' => $databasePath,
        'CPE_LOG_PATH' => $structuredLogPath,
        'CPE_SESSION_DRIVER' => 'files',
        'CPE_SESSION_SECURE' => '0',
    ]);
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $projectRoot . '/public'],
        [0 => ['pipe', 'r'], 1 => ['file', $serverLogPath, 'a'], 2 => ['file', $serverLogPath, 'a']],
        $pipes,
        $projectRoot,
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start authorization contract server.');
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
        unset($pipes[0]);
    }
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $connectError, $connectMessage, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            return [$process, $pipes, $port];
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(20_000);
    }
    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('Authorization contract server did not become ready.');
}

/** @param resource|null $process @param array<int, resource> $pipes */
function authorization_stop_server(mixed &$process, array &$pipes): void
{
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    $process = null;
    $pipes = [];
}

/**
 * @param array<string, string> $headers
 * @return array{status: int, headers: array<string, list<string>>, body: string}
 */
function authorization_request(
    int $port,
    string $method,
    string $path,
    array $headers = [],
    string $body = '',
): array {
    $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage, 3);
    if (!is_resource($socket)) {
        throw new RuntimeException('Could not connect to authorization contract server: ' . $errorMessage, $errorNumber);
    }
    $request = strtoupper($method) . ' ' . $path . " HTTP/1.1\r\n"
        . 'Host: 127.0.0.1:' . $port . "\r\n"
        . "Connection: close\r\n";
    foreach ($headers as $name => $value) {
        $request .= $name . ': ' . $value . "\r\n";
    }
    if ($body !== '') {
        $request .= "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n";
    }
    fwrite($socket, $request . "\r\n" . $body);
    $response = stream_get_contents($socket);
    fclose($socket);
    if (!is_string($response) || preg_match('/\AHTTP\/1\.[01] ([0-9]{3})[^\r\n]*\r?\n/', $response, $matches) !== 1) {
        throw new RuntimeException('Authorization contract server returned an invalid HTTP response.');
    }
    $parts = preg_split("/\r?\n\r?\n/", $response, 2);
    $headerLines = preg_split("/\r?\n/", (string) ($parts[0] ?? '')) ?: [];
    array_shift($headerLines);
    $parsedHeaders = [];
    foreach ($headerLines as $line) {
        $separator = strpos($line, ':');
        if ($separator === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $separator)));
        $parsedHeaders[$name][] = trim(substr($line, $separator + 1));
    }
    return [
        'status' => (int) $matches[1],
        'headers' => $parsedHeaders,
        'body' => (string) ($parts[1] ?? ''),
    ];
}

function authorization_cookie(array $response, string $current = ''): string
{
    $cookies = [];
    if ($current !== '') {
        foreach (explode('; ', $current) as $pair) {
            if (str_contains($pair, '=')) {
                [$name, $value] = explode('=', $pair, 2);
                $cookies[$name] = $value;
            }
        }
    }
    foreach ($response['headers']['set-cookie'] ?? [] as $header) {
        $pair = explode(';', $header, 2)[0];
        if (str_contains($pair, '=')) {
            [$name, $value] = explode('=', $pair, 2);
            $cookies[$name] = $value;
        }
    }
    return implode('; ', array_map(
        static fn (string $name, string $value): string => $name . '=' . $value,
        array_keys($cookies),
        array_values($cookies),
    ));
}

function authorization_csrf(string $html): string
{
    if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $html, $matches) !== 1) {
        throw new RuntimeException('Authorization contract could not find a CSRF token.');
    }
    return $matches[1];
}

/** @return array<string, string> */
function authorization_move_fields(string $html): array
{
    if (preg_match('/<form method="post" action="\/\?r=move"[^>]*>(.*?)<\/form>/s', $html, $formMatch) !== 1) {
        throw new RuntimeException('Authorization contract could not find an executable board move.');
    }
    $fields = [];
    foreach (['_token', 'idempotency_key', 'application_id', 'expected_status', 'transition_key', 'to_status'] as $name) {
        if (preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $formMatch[1], $fieldMatch) !== 1) {
            throw new RuntimeException('Authorization contract move omitted ' . $name . '.');
        }
        $fields[$name] = html_entity_decode($fieldMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $fields;
}

/** @return array<string, int|string> */
function authorization_mutation_snapshot(PDO $pdo, int $applicationId): array
{
    $status = $pdo->prepare('SELECT current_status FROM applications WHERE id = ?');
    $status->execute([$applicationId]);
    return [
        'application_status' => (string) $status->fetchColumn(),
        'events' => (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(),
        'audit_logs' => (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn(),
        'domain_event_outbox' => (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn(),
        'idempotency_keys' => (int) $pdo->query('SELECT COUNT(*) FROM idempotency_keys')->fetchColumn(),
    ];
}

function authorization_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $item) {
        $path = $directory . '/' . $item;
        if (is_dir($path)) {
            authorization_remove_tree($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}

$server = null;
$serverPipes = [];
try {
    // A readable empty durable grant set is authoritative, including for the configured admin role.
    $pdo = authorization_pdo();
    authorization_create_grants($pdo);
    authorization_create_modules($pdo);
    $pdo->exec("INSERT INTO module_installations VALUES ('placement', '0.1.0', 1)");
    $capabilities = CapabilityService::fromDatabase($pdo, authorization_registry($pdo));
    authorization_true(
        !$capabilities->allows(['role' => 'admin', 'active' => 1], 'placement.application.transition'),
        'Readable empty durable grants restored the static administrator wildcard.',
    );

    // Missing and malformed durable capability relations are typed outages, never grants or silent denials.
    $pdo = authorization_pdo();
    authorization_create_modules($pdo);
    authorization_expect_unavailable(
        static fn (): CapabilityService => CapabilityService::fromDatabase($pdo, authorization_registry($pdo)),
        AuthorizationUnavailable::CAPABILITY_STATE,
        'Missing role_capabilities',
    );
    $capabilitySentinel = 'role_capabilities_password_dsn_candidate_secret';
    $pdo = authorization_pdo();
    authorization_create_modules($pdo);
    $pdo->exec(
        'CREATE VIEW role_capabilities AS SELECT role_key, capability FROM ' . $capabilitySentinel,
    );
    authorization_expect_unavailable(
        static fn (): CapabilityService => CapabilityService::fromDatabase($pdo, authorization_registry($pdo)),
        AuthorizationUnavailable::CAPABILITY_STATE,
        'Unreadable role_capabilities',
        $capabilitySentinel,
    );

    // Missing, malformed, or absent durable module state cannot reactivate enabled-by-default modules.
    $pdo = authorization_pdo();
    authorization_create_grants($pdo);
    $pdo->exec("INSERT INTO role_capabilities VALUES ('control', 'placement.application.transition')");
    $capabilities = CapabilityService::fromDatabase($pdo, authorization_registry($pdo));
    authorization_expect_unavailable(
        static fn (): bool => $capabilities->allows(
            ['role' => 'control', 'active' => 1],
            'placement.application.transition',
        ),
        AuthorizationUnavailable::MODULE_STATE,
        'Missing module_installations',
    );
    $moduleSentinel = 'module_installations_password_dsn_candidate_secret';
    $pdo = authorization_pdo();
    authorization_create_grants($pdo);
    $pdo->exec("INSERT INTO role_capabilities VALUES ('control', 'placement.application.transition')");
    $pdo->exec(
        'CREATE VIEW module_installations AS SELECT module_key, version, enabled FROM ' . $moduleSentinel,
    );
    $capabilities = CapabilityService::fromDatabase($pdo, authorization_registry($pdo));
    authorization_expect_unavailable(
        static fn (): bool => $capabilities->allows(
            ['role' => 'control', 'active' => 1],
            'placement.application.transition',
        ),
        AuthorizationUnavailable::MODULE_STATE,
        'Unreadable module_installations',
        $moduleSentinel,
    );
    $pdo = authorization_pdo();
    authorization_create_grants($pdo);
    authorization_create_modules($pdo);
    $pdo->exec("INSERT INTO role_capabilities VALUES ('control', 'placement.application.transition')");
    $capabilities = CapabilityService::fromDatabase($pdo, authorization_registry($pdo));
    authorization_true(
        !$capabilities->allows(['role' => 'control', 'active' => 1], 'placement.application.transition'),
        'A readable missing module row used enabled_by_default at installed runtime.',
    );

    // Every durable row is validated before enablement is converted to a boolean.
    foreach ([2, -1, 'truthy', '1x'] as $invalidEnabled) {
        $pdo = authorization_pdo();
        authorization_create_grants($pdo);
        authorization_create_modules($pdo);
        $pdo->exec("INSERT INTO role_capabilities VALUES ('control', 'placement.application.transition')");
        $insert = $pdo->prepare('INSERT INTO module_installations VALUES (?, ?, ?)');
        $insert->execute(['placement', '0.1.0', $invalidEnabled]);
        if (is_string($invalidEnabled)) {
            authorization_same(
                'text',
                $pdo->query("SELECT typeof(enabled) FROM module_installations WHERE module_key = 'placement'")->fetchColumn(),
                'SQLite did not preserve the malformed string-like enabled fixture.',
            );
        }
        $capabilities = CapabilityService::fromDatabase($pdo, authorization_registry($pdo));
        authorization_expect_unavailable(
            static fn (): bool => $capabilities->allows(
                ['role' => 'control', 'active' => 1],
                'placement.application.transition',
            ),
            AuthorizationUnavailable::MODULE_STATE,
            'Invalid module enabled value ' . var_export($invalidEnabled, true),
        );
    }
    foreach (['', 'not-semver', '1.0'] as $invalidVersion) {
        $pdo = authorization_pdo();
        authorization_create_grants($pdo);
        authorization_create_modules($pdo);
        $pdo->exec("INSERT INTO role_capabilities VALUES ('control', 'placement.application.transition')");
        $insert = $pdo->prepare('INSERT INTO module_installations VALUES (?, ?, 1)');
        $insert->execute(['placement', $invalidVersion]);
        $capabilities = CapabilityService::fromDatabase($pdo, authorization_registry($pdo));
        authorization_expect_unavailable(
            static fn (): bool => $capabilities->allows(
                ['role' => 'control', 'active' => 1],
                'placement.application.transition',
            ),
            AuthorizationUnavailable::MODULE_STATE,
            'Invalid module version ' . var_export($invalidVersion, true),
        );
    }
    $pdo = authorization_pdo();
    authorization_create_grants($pdo);
    $pdo->exec("INSERT INTO role_capabilities VALUES ('control', 'placement.application.transition')");
    $pdo->exec('CREATE TABLE module_installations (module_key TEXT NOT NULL, enabled INTEGER NOT NULL)');
    $pdo->exec("INSERT INTO module_installations VALUES ('placement', 1)");
    $capabilities = CapabilityService::fromDatabase($pdo, authorization_registry($pdo));
    authorization_expect_unavailable(
        static fn (): bool => $capabilities->allows(
            ['role' => 'control', 'active' => 1],
            'placement.application.transition',
        ),
        AuthorizationUnavailable::MODULE_STATE,
        'Module row missing version',
    );
    $pdo = authorization_pdo();
    authorization_create_grants($pdo);
    authorization_create_modules($pdo);
    $pdo->exec("INSERT INTO role_capabilities VALUES ('control', 'placement.application.transition')");
    $pdo->exec("INSERT INTO module_installations VALUES ('placement', '0.1.0', 1)");
    $pdo->exec("INSERT INTO module_installations VALUES ('placement', '0.1.0', 0)");
    $capabilities = CapabilityService::fromDatabase($pdo, authorization_registry($pdo));
    authorization_expect_unavailable(
        static fn (): bool => $capabilities->allows(
            ['role' => 'control', 'active' => 1],
            'placement.application.transition',
        ),
        AuthorizationUnavailable::MODULE_STATE,
        'Duplicate module rows',
    );

    // The additive SQLite guard preserves a legacy invalid row for fail-closed diagnosis,
    // while rejecting every subsequent invalid enabled write and accepting exact 0/1.
    $pdo = authorization_pdo();
    authorization_create_grants($pdo);
    authorization_create_modules($pdo);
    $pdo->exec("INSERT INTO role_capabilities VALUES ('control', 'placement.application.transition')");
    $pdo->exec("INSERT INTO module_installations VALUES ('placement', '0.1.0', 2)");
    $pdo->exec((string) file_get_contents($projectRoot . '/database/migrations/048_module_enabled_constraint.sql'));
    authorization_same(
        2,
        $pdo->query("SELECT enabled FROM module_installations WHERE module_key = 'placement'")->fetchColumn(),
        'SQLite constraint migration silently coerced a legacy invalid enabled value.',
    );
    $capabilities = CapabilityService::fromDatabase($pdo, authorization_registry($pdo));
    authorization_expect_unavailable(
        static fn (): bool => $capabilities->allows(
            ['role' => 'control', 'active' => 1],
            'placement.application.transition',
        ),
        AuthorizationUnavailable::MODULE_STATE,
        'Legacy invalid enabled row after migration',
    );
    foreach ([
        static fn (): int|false => $pdo->exec("UPDATE module_installations SET enabled = -1 WHERE module_key = 'placement'"),
        static fn (): int|false => $pdo->exec("INSERT INTO module_installations VALUES ('advising', '0.1.0', 'truthy')"),
    ] as $index => $invalidWrite) {
        authorization_expect_failure($invalidWrite, 'SQLite invalid enabled write ' . $index);
    }
    $pdo->exec("UPDATE module_installations SET enabled = 0 WHERE module_key = 'placement'");
    $capabilities = CapabilityService::fromDatabase($pdo, authorization_registry($pdo));
    authorization_true(
        !$capabilities->allows(['role' => 'control', 'active' => 1], 'placement.application.transition'),
        'Exact durable enabled value 0 was not treated as disabled.',
    );
    $pdo->exec("UPDATE module_installations SET enabled = 1 WHERE module_key = 'placement'");
    $capabilities = CapabilityService::fromDatabase($pdo, authorization_registry($pdo));
    authorization_true(
        $capabilities->allows(['role' => 'control', 'active' => 1], 'placement.application.transition'),
        'Exact durable enabled value 1 was not treated as enabled.',
    );

    // Explicit revocation and disablement remain ordinary denials.
    $pdo = authorization_pdo();
    authorization_create_grants($pdo);
    authorization_create_modules($pdo);
    $pdo->exec("INSERT INTO role_capabilities VALUES ('control', 'placement.application.transition')");
    $pdo->exec("INSERT INTO role_capabilities VALUES ('auditor', 'portal.access')");
    $pdo->exec("INSERT INTO module_installations VALUES ('placement', '0.1.0', 1)");
    $capabilities = CapabilityService::fromDatabase($pdo, authorization_registry($pdo));
    authorization_true(
        $capabilities->allows(['role' => 'control', 'active' => 1], 'placement.application.transition'),
        'Durable control grant was not honored.',
    );
    $pdo->exec("DELETE FROM role_capabilities WHERE role_key = 'control'");
    $capabilities = CapabilityService::fromDatabase($pdo, authorization_registry($pdo));
    authorization_true(
        !$capabilities->allows(['role' => 'control', 'active' => 1], 'placement.application.transition'),
        'Removed durable role grant was reconstructed.',
    );
    $pdo->exec("INSERT INTO role_capabilities VALUES ('control', 'placement.application.transition')");
    $pdo->exec("UPDATE module_installations SET enabled = 0 WHERE module_key = 'placement'");
    $capabilities = CapabilityService::fromDatabase($pdo, authorization_registry($pdo));
    authorization_true(
        !$capabilities->allows(['role' => 'control', 'active' => 1], 'placement.application.transition'),
        'Disabled module capability was granted.',
    );
    authorization_true(
        !$capabilities->allows(['role' => 'control', 'active' => 1], 'placement.unknown'),
        'Unknown capability was granted.',
    );

    $unavailable = AuthorizationUnavailable::capabilityState();
    authorization_expect_unavailable(
        static fn (): null => ControllerFailure::flash($unavailable, 'CPE_TEST_FAILURE', 'authorization'),
        AuthorizationUnavailable::CAPABILITY_STATE,
        'Controller failure boundary',
    );

    // Strict installation-state reads distinguish a fresh target, an authorized
    // setup recovery target, and every ambiguous or malformed runtime state.
    authorization_true(!Database::hasInstalledMarkerStrict(), 'A missing self-hosted database was reported installed.');
    $markerDatabase = Database::connection();
    $markerDatabase->exec('CREATE TABLE unrelated_partial_schema (value TEXT NOT NULL)');
    $partialFailure = authorization_expect_failure(
        static fn (): string => Database::installationStateStrict(),
        'Nonempty self-hosted schema without settings',
    );
    authorization_true(
        $partialFailure instanceof InstallationStateUnavailable,
        'Nonempty self-hosted schema did not raise the typed installation outage.',
    );
    authorization_expect_failure(
        static fn (): \App\Security\SetupRecoveryAuthority => test_authorized_setup_recovery_authority(),
        'Unauthorized recovery of a non-Engine schema',
    );
    [$server, $serverPipes, $partialPort] = authorization_start_server(
        $projectRoot,
        $databasePath,
        $structuredLogPath,
        $serverLogPath,
    );
    $partialHttpFailure = authorization_request($partialPort, 'GET', '/');
    authorization_same(503, $partialHttpFailure['status'], 'Partial self-hosted schema did not fail at the HTTP runtime boundary.');
    authorization_true(
        preg_match('/^Authorization temporarily unavailable\. Reference: inc_[a-f0-9]{32}\n$/D', $partialHttpFailure['body']) === 1,
        'Partial self-hosted schema did not return the fixed opaque HTTP response.',
    );
    authorization_stop_server($server, $serverPipes);
    Database::reset();
    foreach ([$databasePath, $databasePath . '-shm', $databasePath . '-wal'] as $markerDatabaseFile) {
        if (is_file($markerDatabaseFile)) {
            unlink($markerDatabaseFile);
        }
    }

    Database::migrate(false);
    $setupRecoveryAuthority = test_authorized_setup_recovery_authority();
    $markerDatabase = Database::connection();
    authorization_same(
        InstallationState::RECOVERABLE,
        Database::installationStateForAuthorizedSetupStrict($setupRecoveryAuthority),
        'Authorized setup did not recognize an exact Engine-owned markerless recovery target.',
    );
    authorization_true(
        authorization_expect_failure(
            static fn (): string => Database::installationStateStrict(),
            'Runtime markerless Engine schema',
        ) instanceof InstallationStateUnavailable,
        'Runtime markerless Engine schema did not fail closed.',
    );
    $markerInsert = $markerDatabase->prepare("INSERT INTO settings (key, value) VALUES ('installed_at', ?)");
    foreach (['', 'not-a-timestamp', '2026-02-30 01:02:03', '2026-01-02T03:04:05Z'] as $invalidMarker) {
        $markerDatabase->exec("DELETE FROM settings WHERE key = 'installed_at'");
        $markerInsert->execute([$invalidMarker]);
        $failure = authorization_expect_failure(
            static fn (): bool => Database::hasInstalledMarkerStrict(),
            'Malformed self-hosted installed_at ' . var_export($invalidMarker, true),
        );
        if ($invalidMarker !== '') {
            authorization_true(
                !str_contains($failure->getMessage(), $invalidMarker),
                'Strict self-hosted marker failure exposed the stored marker.',
            );
        }
        authorization_true(
            authorization_expect_failure(
                static fn (): string => Database::installationStateForAuthorizedSetupStrict($setupRecoveryAuthority),
                'Malformed authorized setup marker ' . var_export($invalidMarker, true),
            ) instanceof InstallationStateUnavailable,
            'Authorized setup weakened malformed installed_at handling.',
        );
    }
    $markerDatabase->exec("DELETE FROM settings WHERE key = 'installed_at'");
    $markerInsert->execute(['2026-01-02 03:04:05']);
    authorization_true(
        authorization_expect_failure(
            static fn (): string => Database::installationStateStrict(),
            'Canonical marker without a bound institution identity',
        ) instanceof InstallationStateUnavailable,
        'Canonical marker bypassed missing installed institution identity.',
    );
    $markerDatabase->exec("DELETE FROM settings WHERE key = 'installed_at'");

    // Exercise the installed browser boundary with an authenticated, otherwise valid mutation request.
    $adminId = (new Installer())->install([
        'college_name' => 'Authorization Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'Authorization Admin',
        'admin_email' => 'authorization@example.test',
        'admin_password' => 'Authorization-Test-Password-42',
        'workflow' => 'default',
        'seed_demo' => true,
    ], $setupRecoveryAuthority);
    $database = Database::connection();
    authorization_true(Database::hasInstalledMarkerStrict(), 'A complete installation was not accepted by the strict state probe.');
    [$server, $serverPipes, $port] = authorization_start_server(
        $projectRoot,
        $databasePath,
        $structuredLogPath,
        $serverLogPath,
    );

    $loginPage = authorization_request($port, 'GET', '/?r=login');
    authorization_same(200, $loginPage['status'], 'Login page did not render.');
    $cookie = authorization_cookie($loginPage);
    $login = authorization_request(
        $port,
        'POST',
        '/?r=login',
        ['Cookie' => $cookie],
        http_build_query([
            '_token' => authorization_csrf($loginPage['body']),
            'email' => 'authorization@example.test',
            'password' => 'Authorization-Test-Password-42',
        ]),
    );
    authorization_same(302, $login['status'], 'Valid administrator login did not redirect.');
    $cookie = authorization_cookie($login, $cookie);
    $board = authorization_request($port, 'GET', '/?r=board', ['Cookie' => $cookie]);
    authorization_same(200, $board['status'], 'Authenticated board did not render.');
    $cookie = authorization_cookie($board, $cookie);
    $moveFields = authorization_move_fields($board['body']);
    $applicationId = (int) $moveFields['application_id'];
    authorization_true($applicationId > 0, 'Board mutation fixture had no application.');
    $beforeMutation = authorization_mutation_snapshot($database, $applicationId);

    $installedMarker = (string) $database
        ->query("SELECT value FROM settings WHERE key = 'installed_at'")
        ->fetchColumn();
    $installationMarkerSentinel = 'installed_at_password_dsn_candidate_secret';
    $updateMarker = $database->prepare("UPDATE settings SET value = ? WHERE key = 'installed_at'");
    $updateMarker->execute([$installationMarkerSentinel]);
    $installationFailure = authorization_request(
        $port,
        'POST',
        '/?r=move',
        ['Cookie' => $cookie],
        http_build_query([...$moveFields, 'note' => 'installation marker must not write']),
    );
    authorization_same(503, $installationFailure['status'], 'Installation-state outage did not return HTTP 503.');
    authorization_true(
        preg_match(
            '/\AAuthorization temporarily unavailable\. Reference: (inc_[a-f0-9]{32})\n\z/D',
            $installationFailure['body'],
            $installationIncidentMatch,
        ) === 1,
        'Installation-state outage did not return the fixed opaque response.',
    );
    authorization_true(
        !isset($installationFailure['headers']['location']),
        'Installation-state outage redirected instead of returning its 503 boundary.',
    );
    authorization_true(
        !str_contains($installationFailure['body'], $installationMarkerSentinel),
        'Installation-state outage exposed its malformed marker.',
    );
    authorization_same(
        $beforeMutation,
        authorization_mutation_snapshot($database, $applicationId),
        'Installation-state outage changed protected placement state.',
    );
    $updateMarker->execute([$installedMarker]);

    $removedAdminGrant = $database->exec("DELETE FROM role_capabilities WHERE role_key = 'admin' AND capability = '*'");
    authorization_same(1, $removedAdminGrant, 'Authorization fixture did not remove the durable administrator grant.');
    $ordinaryDenial = authorization_request($port, 'GET', '/?r=modules', ['Cookie' => $cookie]);
    authorization_same(403, $ordinaryDenial['status'], 'Ordinary capability denial did not return HTTP 403.');
    authorization_same("Access denied.\n", $ordinaryDenial['body'], 'Ordinary capability denial changed its fixed body.');
    authorization_true(
        !str_contains($ordinaryDenial['body'], 'Reference:'),
        'Ordinary capability denial was mislabeled as an authorization outage.',
    );
    $database->exec("INSERT INTO role_capabilities (role_key, capability) VALUES ('admin', '*')");

    $database->exec('ALTER TABLE role_capabilities RENAME TO role_capabilities_valid');
    $database->exec(
        'CREATE VIEW role_capabilities AS SELECT role_key, capability FROM ' . $capabilitySentinel,
    );
    $capabilityFailure = authorization_request(
        $port,
        'POST',
        '/?r=move',
        ['Cookie' => $cookie],
        http_build_query([...$moveFields, 'note' => 'must not be written']),
    );
    authorization_same(503, $capabilityFailure['status'], 'Capability-state outage did not return HTTP 503.');
    authorization_true(
        preg_match(
            '/\AAuthorization temporarily unavailable\. Reference: (inc_[a-f0-9]{32})\n\z/D',
            $capabilityFailure['body'],
            $capabilityIncidentMatch,
        ) === 1,
        'Capability-state outage did not return the fixed opaque response.',
    );
    authorization_true(
        !isset($capabilityFailure['headers']['location']),
        'Capability-state outage redirected instead of returning its 503 boundary.',
    );
    authorization_same(
        $beforeMutation,
        authorization_mutation_snapshot($database, $applicationId),
        'Capability-state outage changed protected placement state.',
    );
    $database->exec('DROP VIEW role_capabilities');
    $database->exec('ALTER TABLE role_capabilities_valid RENAME TO role_capabilities');

    $database->exec('ALTER TABLE module_installations RENAME TO module_installations_valid');
    $database->exec(
        'CREATE VIEW module_installations AS SELECT module_key, version, enabled FROM ' . $moduleSentinel,
    );
    $moduleFailure = authorization_request(
        $port,
        'POST',
        '/?r=move',
        ['Cookie' => $cookie],
        http_build_query([...$moveFields, 'note' => 'must not be written']),
    );
    authorization_same(503, $moduleFailure['status'], 'Module-state outage did not return HTTP 503.');
    authorization_true(
        preg_match(
            '/\AAuthorization temporarily unavailable\. Reference: (inc_[a-f0-9]{32})\n\z/D',
            $moduleFailure['body'],
            $moduleIncidentMatch,
        ) === 1,
        'Module-state outage did not return the fixed opaque response.',
    );
    authorization_same(
        $beforeMutation,
        authorization_mutation_snapshot($database, $applicationId),
        'Module-state outage changed protected placement state.',
    );
    $database->exec('DROP VIEW module_installations');
    $database->exec('ALTER TABLE module_installations_valid RENAME TO module_installations');

    usleep(50_000);
    $structuredLog = is_file($structuredLogPath) ? (string) file_get_contents($structuredLogPath) : '';
    $serverLog = is_file($serverLogPath) ? (string) file_get_contents($serverLogPath) : '';
    foreach ([$installationMarkerSentinel, $capabilitySentinel, $moduleSentinel, 'must not be written', 'installation marker must not write'] as $sentinel) {
        authorization_true(!str_contains($installationFailure['body'], $sentinel), 'Installation 503 exposed a sentinel.');
        authorization_true(!str_contains($capabilityFailure['body'], $sentinel), 'Capability 503 exposed a sentinel.');
        authorization_true(!str_contains($moduleFailure['body'], $sentinel), 'Module 503 exposed a sentinel.');
        authorization_true(!str_contains($structuredLog, $sentinel), 'Structured incident log exposed a sentinel.');
        authorization_true(!str_contains($serverLog, $sentinel), 'PHP server log exposed a sentinel.');
    }
    authorization_true(
        str_contains($structuredLog, $installationIncidentMatch[1])
            && str_contains($structuredLog, 'CPE_AUTHORIZATION_INSTALLATION_STATE_UNAVAILABLE'),
        'Installation outage incident was not correlated in the protected log.',
    );
    authorization_true(
        str_contains($structuredLog, $capabilityIncidentMatch[1])
            && str_contains($structuredLog, 'CPE_AUTHORIZATION_CAPABILITY_STATE_UNAVAILABLE'),
        'Capability outage incident was not correlated in the protected log.',
    );
    authorization_true(
        str_contains($structuredLog, $moduleIncidentMatch[1])
            && str_contains($structuredLog, 'CPE_AUTHORIZATION_MODULE_STATE_UNAVAILABLE'),
        'Module outage incident was not correlated in the protected log.',
    );
    authorization_true($adminId > 0, 'Authorization fixture administrator was not created.');
} finally {
    authorization_stop_server($server, $serverPipes);
    Database::reset();
    authorization_remove_tree($testRoot);
}

echo "Authorization failure contract passed.\n";
