<?php

declare(strict_types=1);

$databasePath = sys_get_temp_dir() . '/cpe-managed-hosting-' . bin2hex(random_bytes(4)) . '.sqlite';
$platformBootstrapPath = sys_get_temp_dir() . '/cpe-platform-bootstrap-' . bin2hex(random_bytes(4)) . '.php';
$platformMarkerPath = sys_get_temp_dir() . '/cpe-platform-bootstrap-' . bin2hex(random_bytes(4)) . '.loaded';
$resolverMarkerPath = sys_get_temp_dir() . '/cpe-platform-resolver-' . bin2hex(random_bytes(4)) . '.loaded';
$providerMarkerPath = sys_get_temp_dir() . '/cpe-platform-provider-' . bin2hex(random_bytes(4)) . '.loaded';
$serverLogPath = sys_get_temp_dir() . '/cpe-hosted-server-' . bin2hex(random_bytes(4)) . '.log';
$metricsToken = 'contract-metrics-' . bin2hex(random_bytes(16));
$serverProcess = null;
$serverPipes = [];

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Hosted\HostedBootstrap;
use App\Hosted\HostedContext;
use App\Hosted\Tenant\HostedResolutionException;
use App\Hosted\Tenant\ResolvedTenant;
use App\Hosted\Tenant\TenantResolver;
use App\Infrastructure\Persistence\SqliteConnectionProvider;
use App\Install\Installer;
use App\Security\OperationalBearerAuthorization;
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

function contract_web_request(int $port, string $path, string $host, array $headers = []): array
{
    $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errorNumber, $errorMessage, 3);
    if (!is_resource($socket)) {
        throw new RuntimeException('Unable to connect to contract web server: ' . $errorMessage, $errorNumber);
    }
    $request = "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n";
    foreach ($headers as $name => $value) {
        $request .= $name . ': ' . $value . "\r\n";
    }
    fwrite($socket, $request . "\r\n");
    $response = stream_get_contents($socket);
    fclose($socket);
    return contract_parse_web_response($response);
}

function contract_parse_web_response(mixed $response): array
{
    if (!is_string($response) || preg_match('/^HTTP\/1\.[01] ([0-9]{3})/', $response, $matches) !== 1) {
        throw new RuntimeException('Contract web server returned an invalid HTTP response.');
    }
    $parts = preg_split("/\r?\n\r?\n/", $response, 2);
    $headerLines = preg_split("/\r?\n/", (string) ($parts[0] ?? '')) ?: [];
    array_shift($headerLines);
    $responseHeaders = [];
    foreach ($headerLines as $headerLine) {
        if (!str_contains($headerLine, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $headerLine, 2);
        $responseHeaders[strtolower(trim($name))][] = trim($value);
    }
    return [
        'status' => (int) $matches[1],
        'headers' => $responseHeaders,
        'body' => (string) ($parts[1] ?? ''),
    ];
}

/** @param list<array{path: string, host: string, headers: array<string, string>}> $requests */
function contract_web_request_burst(int $port, array $requests): array
{
    $sockets = [];
    try {
        foreach ($requests as $request) {
            $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errorNumber, $errorMessage, 3);
            if (!is_resource($socket)) {
                throw new RuntimeException('Unable to connect a contract burst request: ' . $errorMessage, $errorNumber);
            }
            stream_set_timeout($socket, 5);
            $http = "GET {$request['path']} HTTP/1.1\r\nHost: {$request['host']}\r\nConnection: close\r\n";
            foreach ($request['headers'] as $name => $value) {
                $http .= $name . ': ' . $value . "\r\n";
            }
            fwrite($socket, $http . "\r\n");
            $sockets[] = $socket;
        }
        $responses = [];
        foreach ($sockets as $socket) {
            $responses[] = contract_parse_web_response(stream_get_contents($socket));
            fclose($socket);
        }
        $sockets = [];
        return $responses;
    } finally {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}

function contract_unlink_marker(string $markerPath): void
{
    if (is_file($markerPath) && !unlink($markerPath)) {
        throw new RuntimeException('Unable to clear the platform-adapter marker.');
    }
}

/** @param list<string> $markerPaths */
function contract_unlink_markers(array $markerPaths): void
{
    foreach ($markerPaths as $markerPath) {
        contract_unlink_marker($markerPath);
    }
}

function contract_request_without_adapter(
    int $port,
    string $path,
    string $host,
    string $markerPath,
    array $headers = [],
): array {
    contract_unlink_marker($markerPath);
    $response = contract_web_request($port, $path, $host, $headers);
    contract_assert(!is_file($markerPath), "{$host}{$path} unexpectedly loaded the platform adapter.");
    return $response;
}

function contract_assert_concealed_not_found(array $response, string $message): void
{
    contract_assert($response['status'] === 404, $message . ' must return 404.');
    contract_assert($response['body'] === "Not found.\n", $message . ' must return the concealed text body.');
    contract_assert(
        ($response['headers']['content-type'] ?? []) === ['text/plain; charset=UTF-8'],
        $message . ' must return the concealed text content type.',
    );
    contract_assert(
        !isset($response['headers']['www-authenticate']),
        $message . ' must not emit WWW-Authenticate.',
    );
}

function contract_stop_web_server(mixed &$process, array &$pipes): void
{
    if (!is_resource($process)) {
        return;
    }
    proc_terminate($process);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($process);
    $process = null;
    $pipes = [];
}

function contract_start_web_server(string $logPath): array
{
    $reservation = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if (!is_resource($reservation)) {
        throw new RuntimeException('Unable to reserve a contract web-server port: ' . $errorMessage, $errorNumber);
    }
    $address = (string) stream_socket_get_name($reservation, false);
    fclose($reservation);
    $port = (int) substr(strrchr($address, ':'), 1);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ];
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', cpe_path('public')],
        $descriptors,
        $pipes,
        cpe_path(),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the contract web server.');
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
        unset($pipes[0]);
    }
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $connectError, $connectMessage, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            return [$process, $pipes, $port];
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(50_000);
    }
    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('Contract web server did not become ready.');
}

try {
    contract_assert(HostedBootstrap::CONTRACT_VERSION === 2, 'Unexpected managed-hosting contract version.');
    contract_assert(Installer::HOSTED_INSTALL_CONTRACT_VERSION === 1, 'Unexpected hosted-install contract version.');
    contract_assert(
        OperationalBearerAuthorization::MINIMUM_TOKEN_LENGTH === 24,
        'Unexpected operational Bearer token minimum.',
    );
    contract_assert(
        OperationalBearerAuthorization::authorizes(
            ['HTTP_AUTHORIZATION' => ' Bearer ' . $metricsToken . ' '],
            $metricsToken,
        ),
        'The existing metrics Bearer contract no longer authorizes its exact token.',
    );
    foreach ([
        [],
        ['HTTP_AUTHORIZATION' => 'Bearer'],
        ['HTTP_AUTHORIZATION' => 'bearer ' . $metricsToken],
        ['HTTP_AUTHORIZATION' => 'Bearer  ' . $metricsToken],
        ['HTTP_AUTHORIZATION' => 'Bearer invalid-token'],
        ['QUERY_STRING' => 'token=' . rawurlencode($metricsToken)],
        ['HTTP_COOKIE' => 'CPE_METRICS_TOKEN=' . $metricsToken],
        ['HTTP_X_FORWARDED_AUTHORIZATION' => 'Bearer ' . $metricsToken],
        ['HTTP_X_FORWARDED_FOR' => '127.0.0.1'],
    ] as $serverInput) {
        contract_assert(
            !OperationalBearerAuthorization::authorizes($serverInput, $metricsToken),
            'Operational authorization accepted a non-contract credential source or malformed Bearer value.',
        );
    }
    contract_assert(
        !OperationalBearerAuthorization::authorizes(
            ['HTTP_AUTHORIZATION' => 'Bearer too-short'],
            'too-short',
        ),
        'Operational authorization accepted a weak configured token.',
    );

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
    Database::migrate();
    contract_assert(
        str_starts_with(
            (string) Database::connection()->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn(),
            'unbound_',
        ),
        'Pre-migrated hosted data planes must remain explicitly unbound.',
    );
    (new Installer())->installHosted([
        'college_name' => 'Alpha College',
        'site_name' => 'Alpha Placement Desk',
        'timezone' => 'UTC',
        'cycle_name' => 'Contract Cycle',
        'workflow' => 'default',
        'admin_name' => 'Contract Admin',
        'admin_email' => 'admin@alpha.example.test',
        'admin_password' => 'contract-password-123',
        'seed_demo' => '',
    ], 'tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    HostedBootstrap::assertDataPlaneIdentity('tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    Database::reset();

    $tenant = new ResolvedTenant([
        'tenant_id' => 1,
        'tenant_public_id' => 'tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'slug' => 'alpha-college',
        'entitlements' => ['placement' => true, 'advising' => false],
    ], $provider, null);
    $resolver = new ContractTenantResolver($tenant);
    $platformBootstrap = <<<'PHP'
<?php
$provider = new class(__DATABASE_PATH__, __PROVIDER_MARKER_PATH__) implements \App\Core\Persistence\ConnectionProvider {
    private \App\Infrastructure\Persistence\SqliteConnectionProvider $delegate;

    public function __construct(string $databasePath, private readonly string $markerPath)
    {
        $this->delegate = new \App\Infrastructure\Persistence\SqliteConnectionProvider($databasePath);
    }

    private function mark(): void
    {
        file_put_contents($this->markerPath, "provider\n", FILE_APPEND);
    }

    public function connection(): \PDO
    {
        $this->mark();
        return $this->delegate->connection();
    }

    public function driver(): string
    {
        $this->mark();
        return $this->delegate->driver();
    }

    public function identifier(): string
    {
        $this->mark();
        return $this->delegate->identifier();
    }

    public function disconnect(): void
    {
        $this->delegate->disconnect();
    }
};
file_put_contents(__BOOTSTRAP_MARKER_PATH__, "bootstrap\n", FILE_APPEND);
$tenant = new \App\Hosted\Tenant\ResolvedTenant([
    'tenant_id' => 1,
    'tenant_public_id' => 'tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'slug' => 'alpha-college',
    'entitlements' => ['placement' => true, 'advising' => false],
], $provider, null);
\App\Hosted\HostedBootstrap::registerResolver(
    new class($tenant, __RESOLVER_MARKER_PATH__) implements \App\Hosted\Tenant\TenantResolver {
        public function __construct(
            private readonly \App\Hosted\Tenant\ResolvedTenant $tenant,
            private readonly string $markerPath,
        )
        {
        }

        public function resolveHost(string $host): \App\Hosted\Tenant\ResolvedTenant
        {
            file_put_contents($this->markerPath, "resolver\n", FILE_APPEND);
            if ($host !== 'alpha.example.test') {
                throw new \App\Hosted\Tenant\HostedResolutionException('Unknown contract host.', 404);
            }
            return $this->tenant;
        }
    },
    );
PHP;
    $platformBootstrap = str_replace(
        [
            '__BOOTSTRAP_MARKER_PATH__',
            '__RESOLVER_MARKER_PATH__',
            '__PROVIDER_MARKER_PATH__',
            '__DATABASE_PATH__',
        ],
        [
            var_export($platformMarkerPath, true),
            var_export($resolverMarkerPath, true),
            var_export($providerMarkerPath, true),
            var_export($databasePath, true),
        ],
        $platformBootstrap,
    );
    file_put_contents($platformBootstrapPath, $platformBootstrap);
    putenv('CPE_HOSTED_MODE=1');
    putenv('CPE_PLATFORM_BOOTSTRAP=' . $platformBootstrapPath);
    putenv('CPE_METRICS_TOKEN=' . $metricsToken);
    $_SERVER['HTTP_HOST'] = 'alpha.example.test';
    cpe_resolve_hosted_http_request();
    HostedBootstrap::resolveHost('alpha.example.test');

    contract_assert(is_file($platformMarkerPath), 'Shared hosted request bootstrap was not loaded.');
    contract_assert(is_file($resolverMarkerPath), 'Shared hosted request resolver was not invoked.');
    contract_assert(is_file($providerMarkerPath), 'Shared hosted request provider was not used.');
    foreach (['public/health.php', 'public/metrics.php'] as $probePath) {
        $probeSource = file_get_contents(cpe_path($probePath));
        contract_assert(
            is_string($probeSource) && str_contains($probeSource, 'cpe_resolve_hosted_http_request();'),
            $probePath . ' must use the same hosted bootstrap path as normal requests.'
        );
    }

    contract_assert(HostedContext::current()->slug() === 'alpha-college', 'Resolved tenant context was not activated.');
    contract_assert(HostedContext::allowsModule('placement'), 'Entitled module was not allowed.');
    contract_assert(!HostedContext::allowsModule('advising'), 'Unentitled module was allowed.');

    contract_unlink_markers([$platformMarkerPath, $resolverMarkerPath, $providerMarkerPath]);
    [$serverProcess, $serverPipes, $serverPort] = contract_start_web_server($serverLogPath);
    foreach (['alpha.example.test', 'unknown.example.test'] as $host) {
        $liveness = contract_request_without_adapter(
            $serverPort,
            '/health.php',
            $host,
            $platformMarkerPath,
        );
        contract_assert($liveness['status'] === 200, 'Hosted process liveness must remain tenant-independent.');
        $livenessPayload = json_decode($liveness['body'], true, 32, JSON_THROW_ON_ERROR);
        contract_assert(($livenessPayload['mode'] ?? '') === 'liveness', 'Process liveness returned the wrong mode.');
        contract_assert(($livenessPayload['checks'] ?? []) === ['process' => 'ok'], 'Process liveness touched operational checks.');
    }

    $unauthorizedReadinessCases = [
        'missing Authorization' => ['/health.php?ready=1', []],
        'invalid Bearer token' => ['/health.php?ready=1', ['Authorization' => 'Bearer invalid-token']],
        'Bearer without a token' => ['/health.php?ready=1', ['Authorization' => 'Bearer']],
        'lowercase bearer scheme' => ['/health.php?ready=1', ['Authorization' => 'bearer ' . $metricsToken]],
        'Basic credentials' => ['/health.php?ready=1', ['Authorization' => 'Basic ' . base64_encode('monitor:' . $metricsToken)]],
        'double-space Bearer credentials' => ['/health.php?ready=1', ['Authorization' => 'Bearer  ' . $metricsToken]],
        'query, cookie, and forwarded identity attempts' => [
            '/health.php?ready=1&token=' . rawurlencode($metricsToken) . '&access_token=' . rawurlencode($metricsToken),
            [
                'Cookie' => 'CPE_METRICS_TOKEN=' . $metricsToken . '; Authorization=Bearer%20' . $metricsToken,
                'X-Forwarded-Authorization' => 'Bearer ' . $metricsToken,
                'X-Original-Authorization' => 'Bearer ' . $metricsToken,
                'X-Forwarded-For' => '127.0.0.1',
            ],
        ],
    ];
    $concealedReadinessSignature = null;
    foreach (['alpha.example.test', 'unknown.example.test'] as $host) {
        foreach ($unauthorizedReadinessCases as $case => [$path, $headers]) {
            $response = contract_request_without_adapter(
                $serverPort,
                $path,
                $host,
                $platformMarkerPath,
                $headers,
            );
            contract_assert_concealed_not_found($response, "Hosted readiness with {$case} on {$host}");
            if (in_array($case, ['missing Authorization', 'invalid Bearer token'], true)) {
                $signature = [
                    $response['status'],
                    $response['headers']['content-type'] ?? [],
                    isset($response['headers']['www-authenticate']),
                    $response['body'],
                ];
                $concealedReadinessSignature ??= $signature;
                contract_assert(
                    $signature === $concealedReadinessSignature,
                    'Missing and invalid readiness credentials must not vary by tenant Host.',
                );
            }
        }
    }

    $unauthorizedMetrics = contract_request_without_adapter(
        $serverPort,
        '/metrics.php?token=' . rawurlencode($metricsToken),
        'alpha.example.test',
        $platformMarkerPath,
        [
            'Authorization' => 'Bearer invalid-token',
            'Cookie' => 'CPE_METRICS_TOKEN=' . $metricsToken,
            'X-Forwarded-Authorization' => 'Bearer ' . $metricsToken,
            'X-Forwarded-For' => '127.0.0.1',
        ],
    );
    contract_assert_concealed_not_found($unauthorizedMetrics, 'Metrics with invalid credentials');

    $burstRequests = [];
    for ($attempt = 0; $attempt < 16; $attempt++) {
        $endpoint = $attempt % 2 === 0 ? '/health.php?ready=1' : '/metrics.php';
        $host = $attempt % 4 < 2 ? 'alpha.example.test' : 'unknown.example.test';
        $headers = match ($attempt % 3) {
            0 => [],
            1 => ['Authorization' => 'Bearer'],
            default => ['Authorization' => 'Bearer wrong-' . $attempt],
        };
        $burstRequests[] = ['path' => $endpoint, 'host' => $host, 'headers' => $headers];
    }
    contract_unlink_markers([$platformMarkerPath, $resolverMarkerPath, $providerMarkerPath]);
    $burstResponses = contract_web_request_burst($serverPort, $burstRequests);
    $burstSignature = null;
    foreach ($burstResponses as $index => $response) {
        contract_assert_concealed_not_found($response, 'Unauthorized managed probe burst request ' . $index);
        $signature = [
            $response['status'],
            $response['headers']['content-type'] ?? [],
            isset($response['headers']['www-authenticate']),
            $response['body'],
        ];
        $burstSignature ??= $signature;
        contract_assert(
            $signature === $burstSignature,
            'Repeated unauthorized readiness and metrics requests must have one concealed signature.',
        );
    }
    foreach ([$platformMarkerPath, $resolverMarkerPath, $providerMarkerPath] as $markerPath) {
        contract_assert(
            !is_file($markerPath),
            'Unauthorized managed probe burst reached platform bootstrap, resolver, or provider work.',
        );
    }

    contract_unlink_marker($platformMarkerPath);
    $readiness = contract_web_request(
        $serverPort,
        '/health.php?ready=1',
        'alpha.example.test',
        ['Authorization' => 'Bearer ' . $metricsToken],
    );
    contract_assert($readiness['status'] === 200, 'Hosted readiness did not resolve the tenant data plane.');
    $readinessPayload = json_decode($readiness['body'], true, 32, JSON_THROW_ON_ERROR);
    contract_assert(($readinessPayload['checks']['runtime'] ?? '') === 'ok', 'Hosted readiness did not verify mandatory runtime extensions.');
    contract_assert(($readinessPayload['checks']['database'] ?? '') === 'ok', 'Hosted readiness did not verify the tenant database.');
    contract_assert(($readinessPayload['checks']['migrations'] ?? '') === 'ok', 'Hosted readiness reported pending tenant migrations.');
    contract_assert(is_file($platformMarkerPath), 'Hosted readiness did not load the platform adapter.');

    contract_unlink_marker($platformMarkerPath);
    $metrics = contract_web_request(
        $serverPort,
        '/metrics.php',
        'alpha.example.test',
        ['Authorization' => 'Bearer ' . $metricsToken],
    );
    contract_assert($metrics['status'] === 200, 'Authenticated hosted metrics did not resolve the tenant data plane.');
    contract_assert(str_contains($metrics['body'], 'cpe_module_enabled{module="placement"} 1'), 'Hosted metrics did not report the resolved tenant.');
    contract_assert(is_file($platformMarkerPath), 'Authenticated metrics did not load the platform adapter.');

    contract_unlink_marker($platformMarkerPath);
    $unknownReadiness = contract_web_request(
        $serverPort,
        '/health.php?ready=1',
        'unknown.example.test',
        ['Authorization' => 'Bearer ' . $metricsToken],
    );
    contract_assert($unknownReadiness['status'] === 503, 'Unknown hosted readiness must fail closed.');
    $unknownReadinessPayload = json_decode($unknownReadiness['body'], true, 32, JSON_THROW_ON_ERROR);
    contract_assert(($unknownReadinessPayload['status'] ?? '') === 'unavailable', 'Unknown hosted readiness must remain generic.');
    contract_assert(!str_contains($unknownReadiness['body'], 'unknown.example.test'), 'Unknown hosted readiness disclosed the tenant Host.');
    contract_assert(is_file($platformMarkerPath), 'Authenticated unknown-host readiness did not reach the platform adapter.');

    contract_unlink_marker($platformMarkerPath);
    $unknownMetrics = contract_web_request(
        $serverPort,
        '/metrics.php',
        'unknown.example.test',
        ['Authorization' => 'Bearer ' . $metricsToken],
    );
    contract_assert($unknownMetrics['status'] === 404, 'Unknown hosted metrics must fail closed without disclosure.');
    contract_assert(is_file($platformMarkerPath), 'Authenticated unknown-host metrics did not reach the platform adapter.');

    contract_stop_web_server($serverProcess, $serverPipes);

    putenv('CPE_HOSTED_MODE');
    putenv('CPE_PLATFORM_BOOTSTRAP=' . $platformBootstrapPath);
    putenv('CPE_METRICS_TOKEN=' . $metricsToken);
    [$serverProcess, $serverPipes, $serverPort] = contract_start_web_server($serverLogPath);
    $bootstrapOnlyReadiness = contract_request_without_adapter(
        $serverPort,
        '/health.php?ready=1',
        'alpha.example.test',
        $platformMarkerPath,
    );
    contract_assert_concealed_not_found(
        $bootstrapOnlyReadiness,
        'Readiness with a configured platform bootstrap and missing credentials',
    );
    contract_stop_web_server($serverProcess, $serverPipes);

    putenv('CPE_HOSTED_MODE=1');
    putenv('CPE_PLATFORM_BOOTSTRAP');
    [$serverProcess, $serverPipes, $serverPort] = contract_start_web_server($serverLogPath);
    $hostedOnlyReadiness = contract_request_without_adapter(
        $serverPort,
        '/health.php?ready=1',
        'unknown.example.test',
        $platformMarkerPath,
        ['Authorization' => 'Bearer invalid-token'],
    );
    contract_assert_concealed_not_found(
        $hostedOnlyReadiness,
        'Readiness with hosted mode and invalid credentials',
    );
    contract_stop_web_server($serverProcess, $serverPipes);

    putenv('CPE_PLATFORM_BOOTSTRAP=' . $platformBootstrapPath);
    putenv('CPE_METRICS_TOKEN=too-short');
    [$serverProcess, $serverPipes, $serverPort] = contract_start_web_server($serverLogPath);
    $weakConfigurationReadiness = contract_request_without_adapter(
        $serverPort,
        '/health.php?ready=1',
        'alpha.example.test',
        $platformMarkerPath,
        ['Authorization' => 'Bearer too-short'],
    );
    contract_assert_concealed_not_found(
        $weakConfigurationReadiness,
        'Readiness with a weak operational token configuration',
    );
    contract_stop_web_server($serverProcess, $serverPipes);

    putenv('CPE_METRICS_TOKEN');
    [$serverProcess, $serverPipes, $serverPort] = contract_start_web_server($serverLogPath);
    $missingConfigurationReadiness = contract_request_without_adapter(
        $serverPort,
        '/health.php?ready=1',
        'alpha.example.test',
        $platformMarkerPath,
        ['Authorization' => 'Bearer ' . $metricsToken],
    );
    contract_assert_concealed_not_found(
        $missingConfigurationReadiness,
        'Readiness with a missing operational token configuration',
    );
    contract_stop_web_server($serverProcess, $serverPipes);

    putenv('CPE_HOSTED_MODE');
    putenv('CPE_PLATFORM_BOOTSTRAP');
    putenv('CPE_METRICS_TOKEN=' . $metricsToken);
    putenv('CPE_DB_PATH=' . $databasePath);
    [$serverProcess, $serverPipes, $serverPort] = contract_start_web_server($serverLogPath);
    $selfHostedReadiness = contract_request_without_adapter(
        $serverPort,
        '/health.php?ready=1',
        'self-hosted.example.test',
        $platformMarkerPath,
    );
    contract_assert($selfHostedReadiness['status'] === 200, 'Self-hosted readiness must not require an operational token.');
    $selfHostedPayload = json_decode($selfHostedReadiness['body'], true, 32, JSON_THROW_ON_ERROR);
    contract_assert(($selfHostedPayload['checks']['database'] ?? '') === 'ok', 'Self-hosted readiness no longer checks its database.');
    contract_assert(($selfHostedPayload['checks']['migrations'] ?? '') === 'ok', 'Self-hosted readiness reported pending migrations.');
    contract_stop_web_server($serverProcess, $serverPipes);
    putenv('CPE_DB_PATH');

    $_SESSION = ['user_id' => 42, 'cpe_hosted_tenant' => 'tenant_other'];
    HostedBootstrap::bindSession();
    contract_assert(!isset($_SESSION['user_id']), 'Cross-tenant session identity was not cleared.');
    contract_assert($_SESSION['cpe_hosted_tenant'] === 'tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'Session was not bound to the resolved tenant.');

    try {
        HostedBootstrap::registerResolver(new ContractTenantResolver($tenant));
        throw new RuntimeException('A second resolver replaced the registered resolver.');
    } catch (RuntimeException $e) {
        contract_assert(str_contains($e->getMessage(), 'already registered'), 'Unexpected duplicate resolver failure.');
    }

    echo "PASS managed-hosting contract is injectable, tenant-bound, and fail-closed\n";
} finally {
    contract_stop_web_server($serverProcess, $serverPipes);
    HostedBootstrap::resetResolver();
    HostedContext::reset();
    Database::reset();
    $_SESSION = [];
    unset($_SERVER['HTTP_HOST']);
    putenv('CPE_HOSTED_MODE');
    putenv('CPE_PLATFORM_BOOTSTRAP');
    putenv('CPE_METRICS_TOKEN');
    putenv('CPE_DB_PATH');
    foreach ([$platformBootstrapPath, $platformMarkerPath, $resolverMarkerPath, $providerMarkerPath, $serverLogPath] as $temporaryPath) {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
    if (is_file($databasePath)) {
        unlink($databasePath);
    }
    foreach ([$databasePath . '-shm', $databasePath . '-wal'] as $sidecar) {
        if (is_file($sidecar)) {
            unlink($sidecar);
        }
    }
}
