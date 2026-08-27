<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Security\SetupAuthorization;
use App\Security\SetupAuthorizationDenied;
use App\Security\SetupHttp;

function setup_b64(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** @param mixed $actual @param mixed $expected */
function setup_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(
            $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')',
        );
    }
}

function setup_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param callable(): mixed $callback */
function setup_denied(callable $callback, string $reason, string $message): void
{
    try {
        $callback();
    } catch (SetupAuthorizationDenied $e) {
        setup_same($e->reason(), $reason, $message);
        return;
    }
    throw new RuntimeException($message . ' (authorization unexpectedly succeeded)');
}

/**
 * @param array<string, mixed> $session
 * @param array<string, mixed> $server
 */
function setup_auth(
    string $directory,
    string $target,
    mixed $environmentToken,
    mixed $localCapability,
    array &$session,
    array $server,
    int &$now,
    string &$sessionId,
    int &$sessionRotations,
    int &$csrfRotations,
    string $randomByte = "\xA5",
): SetupAuthorization {
    return new SetupAuthorization(
        environmentToken: $environmentToken,
        localCapability: $localCapability,
        stateDirectory: $directory,
        targetIdentity: $target,
        session: $session,
        server: $server,
        clock: static function () use (&$now): int {
            return $now;
        },
        randomBytes: static fn (int $length): string => str_repeat($randomByte, $length),
        sessionIdProvider: static function () use (&$sessionId): string {
            return $sessionId;
        },
        sessionRegenerator: static function () use (&$sessionId, &$sessionRotations): void {
            $sessionRotations++;
            $sessionId = 'rotated-session-' . $sessionRotations;
        },
        csrfRotator: static function () use (&$csrfRotations): void {
            $csrfRotations++;
        },
    );
}

function setup_state_path(string $directory, string $target): string
{
    return $directory . '/' . hash('sha256', $target) . '.json';
}

/** @return array<string, mixed> */
function setup_read_state(string $directory, string $target): array
{
    $contents = file_get_contents(setup_state_path($directory, $target));
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read setup authorization fixture state.');
    }
    $state = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($state)) {
        throw new RuntimeException('Setup authorization fixture state was not an object.');
    }
    return $state;
}

function setup_wait_for_file(string $path, string $label, int $timeoutMilliseconds = 10000): void
{
    $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
    while (!is_file($path)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for setup authorization ' . $label . '.');
        }
        usleep(1000);
    }
}

function setup_reserve_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if (!is_resource($socket)) {
        throw new RuntimeException('Could not reserve setup HTTP port: ' . $errorMessage, $errorNumber);
    }
    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr(strrchr($address, ':'), 1);
}

/**
 * @param array<string, string> $iniSettings
 * @return array{0: resource, 1: array<int, resource>, 2: int}
 */
function setup_start_server(array $environment, string $logPath, ?int $port = null, array $iniSettings = []): array
{
    $port ??= setup_reserve_port();
    $processEnvironment = getenv();
    if (!is_array($processEnvironment)) {
        $processEnvironment = [];
    }
    foreach ([
        'CPE_DB_PATH',
        'CPE_DATABASE_URL',
        'CPE_DB_DRIVER',
        'CPE_SETUP_TOKEN',
        SetupHttp::INTERNAL_CAPABILITY_ENV,
        SetupHttp::INTERNAL_ADDRESS_ENV,
        SetupHttp::INTERNAL_EXPIRES_ENV,
        'CPE_HOSTED_MODE',
        'CPE_PLATFORM_BOOTSTRAP',
        'CPE_SESSION_DRIVER',
        'CPE_SESSION_SECURE',
        'CPE_TRUST_PROXY_HEADERS',
        'CPE_LOG_PATH',
    ] as $key) {
        unset($processEnvironment[$key]);
    }
    $processEnvironment = array_merge($processEnvironment, $environment);
    $command = [PHP_BINARY];
    foreach ($iniSettings as $name => $value) {
        $command[] = '-d';
        $command[] = $name . '=' . $value;
    }
    array_push($command, '-S', '127.0.0.1:' . $port, '-t', cpe_path('public'));
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
        $pipes,
        cpe_path(),
        $processEnvironment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start setup HTTP contract server.');
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
    throw new RuntimeException('Setup HTTP contract server did not become ready.');
}

/** @param resource|null $process @param array<int, resource> $pipes */
function setup_stop_server(mixed &$process, array &$pipes): void
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
 * @return array{status: int, headers: array<string, list<string>>, body: string, raw: string}
 */
function setup_http_request(
    int $port,
    string $method,
    string $path,
    string $host,
    array $headers = [],
    string $body = '',
): array {
    $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage, 3);
    if (!is_resource($socket)) {
        throw new RuntimeException('Could not connect to setup HTTP contract server: ' . $errorMessage, $errorNumber);
    }
    $request = strtoupper($method) . ' ' . $path . " HTTP/1.1\r\n"
        . 'Host: ' . $host . "\r\n"
        . "User-Agent: CPE-Setup-Contract/1.0\r\n"
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
        throw new RuntimeException('Setup HTTP contract server returned an invalid response.');
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
        'raw' => $response,
    ];
}

/** @param array{headers: array<string, list<string>>} $response */
function setup_header(array $response, string $name): ?string
{
    $values = $response['headers'][strtolower($name)] ?? [];
    return $values === [] ? null : $values[count($values) - 1];
}

/** @param array{headers: array<string, list<string>>} $response */
function setup_update_cookie(array $response, string &$cookie, array &$sessionIds): void
{
    foreach ($response['headers']['set-cookie'] ?? [] as $setCookie) {
        $pair = explode(';', $setCookie, 2)[0];
        if (!str_contains($pair, '=')) {
            continue;
        }
        $cookie = $pair;
        [, $value] = explode('=', $pair, 2);
        if ($value !== '') {
            $sessionIds[] = $value;
        }
    }
}

function setup_csrf_from_html(string $html): string
{
    if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $html, $matches) !== 1) {
        throw new RuntimeException('Setup HTTP response did not contain the expected CSRF field.');
    }
    return $matches[1];
}

/** @return array{0: int, 1: string, 2: string} */
function setup_run_cli(array $args, array $environment = []): array
{
    $processEnvironment = getenv();
    if (!is_array($processEnvironment)) {
        $processEnvironment = [];
    }
    $processEnvironment = array_merge($processEnvironment, $environment);
    $process = proc_open(
        [PHP_BINARY, cpe_path('placement'), ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        cpe_path(),
        $processEnvironment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not run setup CLI contract command.');
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

function setup_default_state_path(string $databasePath): string
{
    return cpe_data_path('setup/' . hash('sha256', "sqlite\0" . $databasePath) . '.json');
}

if (($argv[1] ?? '') === '--worker') {
    $directory = (string) getenv('CPE_SETUP_TEST_DIRECTORY');
    $target = (string) getenv('CPE_SETUP_TEST_TARGET');
    $token = (string) getenv('CPE_SETUP_TEST_TOKEN');
    $ready = (string) getenv('CPE_SETUP_TEST_READY');
    $start = (string) getenv('CPE_SETUP_TEST_START');
    $worker = (string) getenv('CPE_SETUP_TEST_WORKER');
    $session = [];
    $now = 1700000000;
    $sessionId = 'worker-session-' . $worker;
    $sessionRotations = 0;
    $csrfRotations = 0;
    try {
        $authorization = setup_auth(
            $directory,
            $target,
            $token,
            null,
            $session,
            ['REMOTE_ADDR' => '192.0.2.' . $worker, 'HTTP_USER_AGENT' => 'setup-worker'],
            $now,
            $sessionId,
            $sessionRotations,
            $csrfRotations,
            chr(64 + (int) $worker),
        );
        if ($ready === '' || file_put_contents($ready, "ready\n") === false) {
            throw new RuntimeException('Could not publish worker readiness.');
        }
        setup_wait_for_file($start, 'worker start');
        $authorization->unlockWithEnvironmentToken($token);
        fwrite(STDOUT, "authorized\n");
        exit(0);
    } catch (SetupAuthorizationDenied $e) {
        fwrite(STDOUT, $e->reason() . "\n");
        exit($e->reason() === SetupAuthorizationDenied::ACTIVE_LEASE ? 0 : 2);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    }
}

$root = sys_get_temp_dir() . '/cpe-setup-authorization-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700, true)) {
    throw new RuntimeException('Could not create setup authorization test root.');
}

$token = setup_b64(str_repeat("\x11", 32));
$rotatedToken = setup_b64(str_repeat("\x22", 32));
$localCapability = setup_b64(str_repeat("\x33", 32));
$rotatedCapability = setup_b64(str_repeat("\x44", 32));
$server = [
    'REMOTE_ADDR' => '203.0.113.41',
    'HTTP_USER_AGENT' => "Contract\tBrowser 1.0",
    'HTTP_X_FORWARDED_FOR' => '198.51.100.200',
    'HTTP_FORWARDED' => 'for=198.51.100.200',
];

// Canonical token configuration and request validation.
foreach ([
    [],
    true,
    123,
    '',
    str_repeat('A', 42),
    str_repeat('A', 129),
    $token . '=',
    '+' . substr($token, 1),
    '/' . substr($token, 1),
    ' ' . $token,
    $token . "\n",
    setup_b64(str_repeat("\x11", 31)),
] as $invalidConfiguration) {
    setup_denied(
        static fn (): SetupAuthorization => new SetupAuthorization(
            environmentToken: $invalidConfiguration,
            stateDirectory: $root . '/invalid-config',
            targetIdentity: 'invalid-config-target',
        ),
        SetupAuthorizationDenied::INVALID_CONFIGURATION,
        'Malformed or weak configured setup credentials must fail closed',
    );
}

$validationSession = [];
$validationNow = 1000;
$validationSessionId = 'validation-session';
$validationSessionRotations = 0;
$validationCsrfRotations = 0;
$validation = setup_auth(
    $root . '/validation',
    'validation-target',
    $token,
    null,
    $validationSession,
    $server,
    $validationNow,
    $validationSessionId,
    $validationSessionRotations,
    $validationCsrfRotations,
);
$defaultTargetSession = [];
$defaultTarget = new SetupAuthorization(
    environmentToken: $token,
    stateDirectory: $root . '/default-target',
    session: $defaultTargetSession,
    server: $server,
    clock: static fn (): int => 1000,
    randomBytes: static fn (int $length): string => str_repeat("\x55", $length),
    sessionIdProvider: static fn (): string => 'default-target-session',
    sessionRegenerator: static function (): void {
    },
    csrfRotator: static function (): void {
    },
);
setup_true(
    $defaultTarget instanceof SetupAuthorization,
    'Default construction must derive a stable target from the current connection provider',
);
foreach ([[], true, 123, '', str_repeat('A', 42), str_repeat('A', 129), $token . '=', ' ' . $token] as $invalidProvided) {
    setup_denied(
        static fn (): array => $validation->unlockWithEnvironmentToken($invalidProvided),
        SetupAuthorizationDenied::INVALID_CREDENTIAL,
        'Malformed provided credentials must fail closed without coercion or trimming',
    );
}
setup_denied(
    static fn (): array => $validation->unlockWithEnvironmentToken($rotatedToken),
    SetupAuthorizationDenied::INVALID_CREDENTIAL,
    'A canonical but incorrect credential must fail closed',
);

$source = file_get_contents(__DIR__ . '/../app/Security/SetupAuthorization.php');
setup_true(is_string($source), 'Setup authorization source must be readable for contract inspection');
setup_true(
    str_contains($source, 'hash_equals($comparisonDigest, $providedDigest)'),
    'Credential comparison must use hash_equals on fixed-length digests',
);
setup_true(
    str_contains($source, "hash('sha256', self::TOKEN_COMPARE_DOMAIN . \"\\0\" . \$credential, true)"),
    'Credential comparison digests must be binary SHA-256 values with a domain separator',
);
setup_true(
    !str_contains($source, 'trim($credential)') && !str_contains($source, 'trim($provided)'),
    'Setup credentials must never be silently trimmed',
);

// Grant rotation, fixed expiry, fingerprinting, resumption, and safe storage.
$directory = $root . '/primary';
$target = 'sqlite\0/var/lib/cpe/institution.sqlite';
$session = [];
$now = 1000;
$sessionId = 'pre-grant-session';
$sessionRotations = 0;
$csrfRotations = 0;
$authorization = setup_auth(
    $directory,
    $target,
    $token,
    $localCapability,
    $session,
    $server,
    $now,
    $sessionId,
    $sessionRotations,
    $csrfRotations,
);
$grantState = $authorization->unlockWithEnvironmentToken($token);
setup_same($grantState['state'], SetupAuthorization::ACCESS_AUTHORIZED, 'A correct environment token should authorize setup');
setup_same($grantState['mode'], SetupAuthorization::MODE_ENVIRONMENT_TOKEN, 'The grant must record its authorization mode');
setup_same($grantState['issued'], 1000, 'The grant must record its absolute issue time');
setup_same($grantState['expires'], 2200, 'The grant must have an absolute twenty-minute expiry');
setup_same($sessionRotations, 1, 'A new grant must regenerate the session id once');
setup_same($csrfRotations, 1, 'A new grant must rotate CSRF once');
setup_same(count($session), 1, 'Setup authorization must occupy one namespaced session slot');
$sessionGrant = array_values($session)[0];
setup_true(is_array($sessionGrant), 'The setup session grant must be a structured value');
$sessionKeys = array_keys($sessionGrant);
sort($sessionKeys);
setup_same(
    $sessionKeys,
    ['expires', 'fingerprint', 'issued', 'mode', 'state', 'version'],
    'The setup session grant must contain only the versioned contract fields',
);
setup_same(strlen((string) $sessionGrant['state']), 43, 'The grant id must contain 32 base64url-encoded random bytes');

$lease = setup_read_state($directory, $target);
$leaseKeys = array_keys($lease);
sort($leaseKeys);
setup_same(
    $leaseKeys,
    ['expires', 'fingerprint', 'grant_hash', 'issued', 'mode', 'source_proof', 'state', 'version'],
    'The active lease must contain only the versioned digest fields',
);
setup_same(fileperms($directory) & 0777, 0700, 'The setup state directory must be mode 0700');
setup_same(fileperms(setup_state_path($directory, $target)) & 0777, 0600, 'The setup state file must be mode 0600');

$serializedSession = json_encode($session, JSON_THROW_ON_ERROR);
$serializedLease = json_encode($lease, JSON_THROW_ON_ERROR);
foreach ([$token, '203.0.113.41', "Contract\tBrowser 1.0", 'Contract Browser 1.0'] as $secretOrMetadata) {
    setup_true(
        !str_contains($serializedSession, $secretOrMetadata) && !str_contains($serializedLease, $secretOrMetadata),
        'Raw token, IP address, and user agent must be absent from session and lease state',
    );
}

$now = 1100;
setup_same($authorization->accessState()['expires'], 2200, 'Reading a grant must not slide its expiry');
$resumed = $authorization->unlockWithEnvironmentToken($token);
setup_same($resumed['expires'], 2200, 'The same source and session must resume the existing absolute lease');
setup_same($sessionRotations, 1, 'Resuming a lease must not regenerate the session id');
setup_same($csrfRotations, 1, 'Resuming a lease must not rotate CSRF');

$proxyOnlyServer = $server;
$proxyOnlyServer['HTTP_X_FORWARDED_FOR'] = '10.0.0.8';
$proxyOnlyServer['HTTP_FORWARDED'] = 'for=10.0.0.8';
$proxyCheck = setup_auth(
    $directory,
    $target,
    $token,
    $localCapability,
    $session,
    $proxyOnlyServer,
    $now,
    $sessionId,
    $sessionRotations,
    $csrfRotations,
);
setup_same(
    $proxyCheck->accessState()['state'],
    SetupAuthorization::ACCESS_AUTHORIZED,
    'Forwarding headers must not influence the setup grant fingerprint',
);

$globalBeforeMismatch = file_get_contents(setup_state_path($directory, $target));
$mismatch = setup_auth(
    $directory,
    $target,
    $token,
    $localCapability,
    $session,
    ['REMOTE_ADDR' => '203.0.113.42', 'HTTP_USER_AGENT' => 'Contract Browser 1.0'],
    $now,
    $sessionId,
    $sessionRotations,
    $csrfRotations,
);
setup_same($mismatch->accessState()['state'], SetupAuthorization::ACCESS_LOCKED, 'A peer fingerprint mismatch must revoke the caller grant');
setup_same($session, [], 'A fingerprint mismatch must clear only the caller session grant');
setup_same(
    file_get_contents(setup_state_path($directory, $target)),
    $globalBeforeMismatch,
    'A fingerprint mismatch must not clear or replace the global lease',
);

// The original active lease excludes a second session with the same source.
$otherSession = [];
$otherSessionId = 'other-session';
$otherSessionRotations = 0;
$otherCsrfRotations = 0;
$other = setup_auth(
    $directory,
    $target,
    $token,
    null,
    $otherSession,
    $server,
    $now,
    $otherSessionId,
    $otherSessionRotations,
    $otherCsrfRotations,
);
setup_denied(
    static fn (): array => $other->unlockWithEnvironmentToken($token),
    SetupAuthorizationDenied::ACTIVE_LEASE,
    'A second browser session must not claim an active lease with the same source',
);

// Rotating the current source proof replaces the prior lease.
$rotatedSession = [];
$rotatedSessionId = 'rotated-source-session';
$rotatedSessionRotations = 0;
$rotatedCsrfRotations = 0;
$rotated = setup_auth(
    $directory,
    $target,
    $rotatedToken,
    null,
    $rotatedSession,
    $server,
    $now,
    $rotatedSessionId,
    $rotatedSessionRotations,
    $rotatedCsrfRotations,
    "\xB6",
);
$sourceProofBefore = $lease['source_proof'];
$rotated->unlockWithEnvironmentToken($rotatedToken);
$rotatedLease = setup_read_state($directory, $target);
setup_true(!hash_equals($sourceProofBefore, $rotatedLease['source_proof']), 'Environment-token rotation must replace the source proof');
setup_same($rotatedSessionRotations, 1, 'Environment-token rotation must create one fresh session grant');

// A local capability has identical exclusivity and rotation semantics.
$localDirectory = $root . '/local';
$localTarget = 'local-target';
$localSession = [];
$localNow = 2000;
$localSessionId = 'local-session';
$localSessionRotations = 0;
$localCsrfRotations = 0;
$local = setup_auth(
    $localDirectory,
    $localTarget,
    $token,
    $localCapability,
    $localSession,
    $server,
    $localNow,
    $localSessionId,
    $localSessionRotations,
    $localCsrfRotations,
);
$local->authorizeLocalCapability($localCapability);
setup_same(setup_read_state($localDirectory, $localTarget)['mode'], SetupAuthorization::MODE_LOCAL, 'Local capability grants must have local mode');
$localRotatedSession = [];
$localRotatedSessionId = 'local-rotated-session';
$localRotatedSessionRotations = 0;
$localRotatedCsrfRotations = 0;
$localRotated = setup_auth(
    $localDirectory,
    $localTarget,
    $token,
    $rotatedCapability,
    $localRotatedSession,
    $server,
    $localNow,
    $localRotatedSessionId,
    $localRotatedSessionRotations,
    $localRotatedCsrfRotations,
    "\xC7",
);
$oldLocalProof = setup_read_state($localDirectory, $localTarget)['source_proof'];
$localRotated->authorizeLocalCapability($rotatedCapability);
setup_true(
    !hash_equals($oldLocalProof, setup_read_state($localDirectory, $localTarget)['source_proof']),
    'Local-capability rotation must replace the prior lease',
);

// Callback failure is retryable; callback success consumes the persistent inode.
$callbackDirectory = $root . '/callback';
$callbackTarget = 'callback-target';
$callbackSession = [];
$callbackNow = 3000;
$callbackSessionId = 'callback-session';
$callbackSessionRotations = 0;
$callbackCsrfRotations = 0;
$callbackAuthorization = setup_auth(
    $callbackDirectory,
    $callbackTarget,
    $token,
    null,
    $callbackSession,
    $server,
    $callbackNow,
    $callbackSessionId,
    $callbackSessionRotations,
    $callbackCsrfRotations,
);
$callbackAuthorization->unlockWithEnvironmentToken($token);
$callbackPath = setup_state_path($callbackDirectory, $callbackTarget);
$inodeBefore = fileinode($callbackPath);
$failedCalls = 0;
try {
    $callbackAuthorization->runAuthorized(static function () use (&$failedCalls): void {
        $failedCalls++;
        throw new RuntimeException('synthetic install failure');
    });
    throw new RuntimeException('A failing authorized callback unexpectedly succeeded.');
} catch (RuntimeException $e) {
    setup_same($e->getMessage(), 'synthetic install failure', 'The callback failure must be preserved');
}
setup_same($failedCalls, 1, 'The failing callback must run exactly once');
setup_same(setup_read_state($callbackDirectory, $callbackTarget)['state'], 'active', 'Callback failure must leave the lease retryable');
setup_true($callbackSession !== [], 'Callback failure must preserve the session grant for retry');
$successfulCalls = 0;
$callbackResult = $callbackAuthorization->runAuthorized(static function () use (&$successfulCalls): string {
    $successfulCalls++;
    return 'installed';
});
setup_same($callbackResult, 'installed', 'The authorized callback result must be returned');
setup_same($successfulCalls, 1, 'The successful callback must run exactly once');
setup_same(setup_read_state($callbackDirectory, $callbackTarget), ['version' => 1, 'state' => 'consumed'], 'Success must overwrite a minimal consumed marker');
setup_same(fileinode($callbackPath), $inodeBefore, 'Consumption must preserve the state-file inode');
setup_same($callbackSession, [], 'Success must clear the caller session grant');
$afterConsumeSession = [];
$afterConsumeSessionId = 'after-consume-session';
$afterConsumeRotations = 0;
$afterConsumeCsrf = 0;
$afterConsume = setup_auth(
    $callbackDirectory,
    $callbackTarget,
    $rotatedToken,
    null,
    $afterConsumeSession,
    $server,
    $callbackNow,
    $afterConsumeSessionId,
    $afterConsumeRotations,
    $afterConsumeCsrf,
);
setup_denied(
    static fn (): array => $afterConsume->unlockWithEnvironmentToken($rotatedToken),
    SetupAuthorizationDenied::NOT_AUTHORIZED,
    'A consumed setup target must remain closed even after source rotation',
);
setup_same(fileinode($callbackPath), $inodeBefore, 'A denied post-consume unlock must preserve the state-file inode');

// Expiry is exact and does not slide.
$expiryDirectory = $root . '/expiry';
$expirySession = [];
$expiryNow = 4000;
$expirySessionId = 'expiry-session';
$expirySessionRotations = 0;
$expiryCsrfRotations = 0;
$expiry = setup_auth(
    $expiryDirectory,
    'expiry-target',
    $token,
    null,
    $expirySession,
    $server,
    $expiryNow,
    $expirySessionId,
    $expirySessionRotations,
    $expiryCsrfRotations,
);
$expiry->unlockWithEnvironmentToken($token);
$expiryNow = 5199;
setup_same($expiry->accessState()['state'], SetupAuthorization::ACCESS_AUTHORIZED, 'The grant must remain valid immediately before expiry');
$expiryNow = 5200;
setup_same($expiry->accessState()['state'], SetupAuthorization::ACCESS_EXPIRED, 'The grant must expire exactly at its absolute cutoff');
setup_same($expirySession, [], 'Expiry must clear the caller session grant');

// Exact target identity isolates leases.
$isolatedDirectory = $root . '/isolated';
$isolatedNow = 6000;
$isolatedSessionA = [];
$isolatedSessionIdA = 'isolation-a';
$isolatedRotationsA = 0;
$isolatedCsrfA = 0;
$isolatedA = setup_auth($isolatedDirectory, 'target-a', $token, null, $isolatedSessionA, $server, $isolatedNow, $isolatedSessionIdA, $isolatedRotationsA, $isolatedCsrfA);
$isolatedSessionB = [];
$isolatedSessionIdB = 'isolation-b';
$isolatedRotationsB = 0;
$isolatedCsrfB = 0;
$isolatedB = setup_auth($isolatedDirectory, 'target-b', $token, null, $isolatedSessionB, $server, $isolatedNow, $isolatedSessionIdB, $isolatedRotationsB, $isolatedCsrfB);
$isolatedA->unlockWithEnvironmentToken($token);
$isolatedB->unlockWithEnvironmentToken($token);
setup_true(setup_state_path($isolatedDirectory, 'target-a') !== setup_state_path($isolatedDirectory, 'target-b'), 'Distinct target identities must map to distinct lease paths');
setup_true(is_file(setup_state_path($isolatedDirectory, 'target-a')) && is_file(setup_state_path($isolatedDirectory, 'target-b')), 'Distinct target leases must coexist');

// Unsafe filesystem state fails closed without following or replacing it.
foreach (['symlink', 'nonregular', 'oversize', 'malformed', 'empty'] as $case) {
    $unsafeDirectory = $root . '/unsafe-' . $case;
    if (!mkdir($unsafeDirectory, 0700, true)) {
        throw new RuntimeException('Could not create unsafe state fixture.');
    }
    $unsafeTarget = 'unsafe-' . $case;
    $unsafePath = setup_state_path($unsafeDirectory, $unsafeTarget);
    if ($case === 'symlink') {
        $symlinkTarget = $root . '/symlink-target';
        file_put_contents($symlinkTarget, "{}\n");
        if (!symlink($symlinkTarget, $unsafePath)) {
            throw new RuntimeException('Could not create setup state symlink fixture.');
        }
    } elseif ($case === 'nonregular') {
        if (!mkdir($unsafePath, 0700)) {
            throw new RuntimeException('Could not create nonregular setup state fixture.');
        }
    } elseif ($case === 'oversize') {
        file_put_contents($unsafePath, str_repeat('x', 8193));
    } elseif ($case === 'malformed') {
        file_put_contents($unsafePath, "{not-json\n");
    } else {
        file_put_contents($unsafePath, '');
    }
    $unsafeSession = [];
    $unsafeNow = 7000;
    $unsafeSessionId = 'unsafe-session';
    $unsafeRotations = 0;
    $unsafeCsrf = 0;
    $unsafe = setup_auth($unsafeDirectory, $unsafeTarget, $token, null, $unsafeSession, $server, $unsafeNow, $unsafeSessionId, $unsafeRotations, $unsafeCsrf);
    setup_denied(
        static fn (): array => $unsafe->unlockWithEnvironmentToken($token),
        SetupAuthorizationDenied::INVALID_STATE,
        'Unsafe ' . $case . ' setup state must fail closed',
    );
}

$symlinkDirectoryTarget = $root . '/real-setup-directory';
mkdir($symlinkDirectoryTarget, 0700);
$symlinkDirectory = $root . '/setup-directory-link';
symlink($symlinkDirectoryTarget, $symlinkDirectory);
$symlinkDirectorySession = [];
$symlinkDirectoryNow = 7100;
$symlinkDirectorySessionId = 'symlink-directory-session';
$symlinkDirectoryRotations = 0;
$symlinkDirectoryCsrf = 0;
$symlinkDirectoryAuthorization = setup_auth(
    $symlinkDirectory,
    'symlink-directory-target',
    $token,
    null,
    $symlinkDirectorySession,
    $server,
    $symlinkDirectoryNow,
    $symlinkDirectorySessionId,
    $symlinkDirectoryRotations,
    $symlinkDirectoryCsrf,
);
setup_denied(
    static fn (): array => $symlinkDirectoryAuthorization->unlockWithEnvironmentToken($token),
    SetupAuthorizationDenied::INVALID_STATE,
    'A symlinked setup state directory must fail closed',
);

// Two independent PHP processes must serialize the same target lease.
$concurrentDirectory = $root . '/concurrent';
$concurrentTarget = 'concurrent-target';
$start = $root . '/concurrent-start';
$readyA = $root . '/concurrent-ready-a';
$readyB = $root . '/concurrent-ready-b';
$command = [PHP_BINARY, __FILE__, '--worker'];
$baseEnvironment = getenv();
if (!is_array($baseEnvironment)) {
    $baseEnvironment = [];
}
$processes = [];
foreach ([1 => $readyA, 2 => $readyB] as $worker => $ready) {
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__ . '/..',
        array_merge($baseEnvironment, [
            'CPE_SETUP_TEST_DIRECTORY' => $concurrentDirectory,
            'CPE_SETUP_TEST_TARGET' => $concurrentTarget,
            'CPE_SETUP_TEST_TOKEN' => $token,
            'CPE_SETUP_TEST_READY' => $ready,
            'CPE_SETUP_TEST_START' => $start,
            'CPE_SETUP_TEST_WORKER' => (string) $worker,
        ]),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start setup authorization worker.');
    }
    fclose($pipes[0]);
    $processes[] = [$process, $pipes];
}
setup_wait_for_file($readyA, 'worker A readiness');
setup_wait_for_file($readyB, 'worker B readiness');
file_put_contents($start, "start\n");
$workerResults = [];
foreach ($processes as [$process, $pipes]) {
    $stdout = trim((string) stream_get_contents($pipes[1]));
    $stderr = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    setup_same($exit, 0, 'Setup authorization worker must exit successfully: ' . $stderr);
    $workerResults[] = $stdout;
}
sort($workerResults);
setup_same(
    $workerResults,
    [SetupAuthorizationDenied::ACTIVE_LEASE, 'authorized'],
    'Exactly one independent process must acquire a target lease',
);

// Setup transport and local authority never trust forwarding headers or Host as
// positive proof.
$originalSessionSecure = getenv('CPE_SESSION_SECURE');
$originalTrustProxy = getenv('CPE_TRUST_PROXY_HEADERS');
putenv('CPE_SESSION_SECURE');
putenv('CPE_TRUST_PROXY_HEADERS=1');
setup_true(
    !SetupHttp::environmentUnlockTransportAllowed([
        'REMOTE_ADDR' => '198.51.100.20',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_FORWARDED' => 'proto=https',
        'SERVER_PORT' => '80',
    ]),
    'Forwarded HTTPS headers must not authorize a non-loopback token exchange',
);
setup_true(
    !SetupHttp::environmentUnlockTransportAllowed([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_FORWARDED' => 'proto=https',
        'SERVER_PORT' => '80',
    ]),
    'Loopback and forwarded HTTPS headers must not authorize an environment-token exchange',
);
setup_true(
    SetupHttp::environmentUnlockTransportAllowed([
        'REMOTE_ADDR' => '198.51.100.20',
        'HTTPS' => 'on',
        'SERVER_PORT' => '443',
    ]),
    'Direct HTTPS must authorize a non-loopback token exchange',
);
putenv('CPE_SESSION_SECURE=force');
setup_true(
    SetupHttp::environmentUnlockTransportAllowed([
        'REMOTE_ADDR' => '198.51.100.20',
        'SERVER_PORT' => '80',
    ]),
    'Explicit forced-secure cookies must authorize a non-loopback token exchange behind reviewed TLS termination',
);
if ($originalSessionSecure === false) {
    putenv('CPE_SESSION_SECURE');
} else {
    putenv('CPE_SESSION_SECURE=' . $originalSessionSecure);
}
if ($originalTrustProxy === false) {
    putenv('CPE_TRUST_PROXY_HEADERS');
} else {
    putenv('CPE_TRUST_PROXY_HEADERS=' . $originalTrustProxy);
}

setup_same(SetupHttp::normalizeSetupAddress('localhost:8123'), '127.0.0.1:8123', 'Localhost setup must normalize to the numeric loopback authority');
foreach (['0.0.0.0:8000', '192.168.1.5:8000', 'placement.example.edu:443', '[::1]:8000'] as $unsupportedAddress) {
    try {
        SetupHttp::normalizeSetupAddress($unsupportedAddress);
        throw new RuntimeException('Unsafe setup address was accepted: ' . $unsupportedAddress);
    } catch (RuntimeException $e) {
        setup_true(str_contains($e->getMessage(), 'unsupported') || str_contains($e->getMessage(), 'loopback'), 'Unsafe setup address must fail with loopback guidance');
    }
}

$authorityCapability = setup_b64(str_repeat("\x66", 32));
$authorityAddress = '127.0.0.1:8123';
SetupHttp::setInternalEnvironment($authorityCapability, $authorityAddress, time() + 1200);
setup_same(
    SetupHttp::localCapabilityModeAvailable([
        'REMOTE_ADDR' => '198.51.100.20',
        'SERVER_ADDR' => '127.0.0.1',
        'HTTP_HOST' => $authorityAddress,
        'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
        'HTTP_FORWARDED' => 'for=127.0.0.1',
    ]),
    false,
    'Forwarding headers must not turn a public peer into a local setup caller',
);
setup_same(
    SetupHttp::localCapabilityModeAvailable([
        'REMOTE_ADDR' => '127.0.0.1',
        'SERVER_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'attacker.example',
    ]),
    false,
    'A mismatched Host must reject internal setup authority as a rebinding defense',
);
SetupHttp::scrubInternalEnvironment();
setup_same(getenv(SetupHttp::INTERNAL_CAPABILITY_ENV), false, 'Generic serving must be able to scrub inherited internal capability state');
setup_same(getenv(SetupHttp::INTERNAL_ADDRESS_ENV), false, 'Generic serving must be able to scrub inherited internal address state');
setup_same(getenv(SetupHttp::INTERNAL_EXPIRES_ENV), false, 'Generic serving must be able to scrub inherited internal expiry state');

$placementSource = file_get_contents(cpe_path('placement'));
$scrubPosition = is_string($placementSource) ? strpos($placementSource, 'SetupHttp::scrubInternalEnvironment();') : false;
$servePosition = is_string($placementSource) ? strpos($placementSource, 'passthru(') : false;
setup_true(
    $scrubPosition !== false
        && $servePosition !== false
        && $scrubPosition < $servePosition,
    'The generic serve path must scrub internal setup variables before launching PHP',
);
setup_true(SetupHttp::isLoopbackAddress('::ffff:127.0.0.1'), 'IPv4-mapped loopback must remain valid for local setup');
setup_true(!SetupHttp::isLoopbackAddress('::ffff:192.0.2.1'), 'IPv4-mapped non-loopback must not gain local setup authority');
[$unsafeSetupCode, , $unsafeSetupError] = setup_run_cli(
    ['setup', '--check'],
    ['CPE_SERVE_ADDRESS' => '0.0.0.0:8123'],
);
setup_same($unsafeSetupCode, 1, 'Setup must reject an inherited wildcard bind even in check-only mode');
setup_true(str_contains($unsafeSetupError, 'unsupported'), 'Rejected wildcard setup must explain the loopback-only boundary');
[$localCheckCode, $localCheckOutput, $localCheckError] = setup_run_cli(['setup', 'localhost:8123', '--check']);
setup_same($localCheckCode, 0, 'Loopback setup check must remain available: ' . $localCheckError);
setup_true(str_contains($localCheckOutput, 'Setup check complete'), 'Loopback setup check must remain non-mutating');

// Real HTTP: no configured source is concealed and cannot reach installation.
$noAuthDatabase = sys_get_temp_dir() . '/cpe-setup-http-no-auth-' . bin2hex(random_bytes(5)) . '.sqlite';
$noAuthLog = sys_get_temp_dir() . '/cpe-setup-http-no-auth-' . bin2hex(random_bytes(5)) . '.log';
$noAuthProcess = null;
$noAuthPipes = [];
try {
    [$noAuthProcess, $noAuthPipes, $noAuthPort] = setup_start_server(
        ['CPE_DB_PATH' => $noAuthDatabase],
        $noAuthLog,
    );
    $noAuthHost = '127.0.0.1:' . $noAuthPort;
    $noAuthGet = setup_http_request($noAuthPort, 'GET', '/install.php', $noAuthHost);
    setup_same($noAuthGet['status'], 404, 'Unconfigured browser setup must be concealed');
    setup_same($noAuthGet['body'], "Not found.\n", 'Unconfigured browser setup must use the fixed denial body');
    setup_same(setup_header($noAuthGet, 'Referrer-Policy'), 'no-referrer', 'Setup responses must suppress referrers');
    setup_same(setup_header($noAuthGet, 'X-Robots-Tag'), 'noindex, nofollow, noarchive', 'Setup responses must prohibit indexing and archives');
    foreach (['admin_name', 'admin_email', 'admin_password', '_setup_action'] as $forbiddenField) {
        setup_true(!str_contains($noAuthGet['body'], $forbiddenField), 'Unauthorized setup must not reveal install field ' . $forbiddenField);
    }
    $noAuthPost = setup_http_request(
        $noAuthPort,
        'POST',
        '/install.php',
        $noAuthHost,
        [],
        http_build_query([
            '_setup_action' => 'install',
            '_token' => str_repeat('a', 64),
            'admin_email' => 'attacker@example.test',
            'admin_password' => 'attacker-password',
        ]),
    );
    setup_same($noAuthPost['status'], 404, 'Unauthorized install POST must be concealed before CSRF validation');
    setup_same($noAuthPost['body'], $noAuthGet['body'], 'Unauthorized GET and POST must have the same fixed denial body');
    setup_true(!is_file($noAuthDatabase), 'Unauthorized setup requests must not create or mutate the database');
} finally {
    setup_stop_server($noAuthProcess, $noAuthPipes);
}

// A loopback peer or reverse-proxy-looking headers do not make an environment
// token safe to exchange over plain HTTP.
$plainTokenDatabase = sys_get_temp_dir() . '/cpe-setup-http-plain-token-' . bin2hex(random_bytes(5)) . '.sqlite';
$plainTokenLog = sys_get_temp_dir() . '/cpe-setup-http-plain-token-' . bin2hex(random_bytes(5)) . '.log';
$plainTokenProcess = null;
$plainTokenPipes = [];
$plainToken = setup_b64(random_bytes(32));
$plainTokenStatePath = setup_default_state_path($plainTokenDatabase);
try {
    [$plainTokenProcess, $plainTokenPipes, $plainTokenPort] = setup_start_server([
        'CPE_DB_PATH' => $plainTokenDatabase,
        'CPE_SETUP_TOKEN' => $plainToken,
    ], $plainTokenLog);
    $plainTokenHost = '127.0.0.1:' . $plainTokenPort;
    $plainTokenGet = setup_http_request(
        $plainTokenPort,
        'GET',
        '/install.php',
        $plainTokenHost,
        ['X-Forwarded-Proto' => 'https', 'Forwarded' => 'proto=https'],
    );
    $plainTokenCookie = '';
    $plainTokenSessionIds = [];
    setup_update_cookie($plainTokenGet, $plainTokenCookie, $plainTokenSessionIds);
    $plainTokenCsrf = setup_csrf_from_html($plainTokenGet['body']);
    $plainTokenUnlock = setup_http_request(
        $plainTokenPort,
        'POST',
        '/install.php',
        $plainTokenHost,
        [
            'Cookie' => $plainTokenCookie,
            'X-Forwarded-Proto' => 'https',
            'Forwarded' => 'proto=https',
        ],
        http_build_query([
            '_setup_action' => 'unlock',
            '_token' => $plainTokenCsrf,
            'setup_token' => $plainToken,
        ]),
    );
    setup_same($plainTokenUnlock['status'], 404, 'Plain-HTTP loopback environment-token exchange must be concealed');
    setup_same($plainTokenUnlock['body'], "Not found.\n", 'Plain-HTTP token denial must use the fixed body');
    setup_true(!is_file($plainTokenDatabase), 'Rejected plain-HTTP token exchange must not initialize the database');
} finally {
    setup_stop_server($plainTokenProcess, $plainTokenPipes);
    foreach ([$plainTokenDatabase, $plainTokenLog, $plainTokenStatePath] as $plainTokenPath) {
        if (is_file($plainTokenPath)) {
            unlink($plainTokenPath);
        }
    }
}

// Real HTTP: environment-token unlock, validation retry, installation, and replay closure.
$httpDatabase = sys_get_temp_dir() . '/cpe-setup-http-token-' . bin2hex(random_bytes(5)) . '.sqlite';
$httpLog = sys_get_temp_dir() . '/cpe-setup-http-token-' . bin2hex(random_bytes(5)) . '.log';
$httpProcess = null;
$httpPipes = [];
$httpCookie = '';
$httpSessionIds = [];
$httpToken = setup_b64(random_bytes(32));
$httpStatePath = setup_default_state_path($httpDatabase);
try {
    [$httpProcess, $httpPipes, $httpPort] = setup_start_server([
        'CPE_DB_PATH' => $httpDatabase,
        'CPE_SETUP_TOKEN' => $httpToken,
        'CPE_SESSION_SECURE' => 'force',
        'CPE_LOG_PATH' => $httpLog . '.jsonl',
    ], $httpLog);
    $httpHost = '127.0.0.1:' . $httpPort;
    $queryAttempt = setup_http_request(
        $httpPort,
        'GET',
        '/install.php?setup_token=' . rawurlencode(setup_b64(str_repeat("\x44", 32))),
        $httpHost,
    );
    setup_same($queryAttempt['status'], 200, 'A query credential must not unlock setup');
    setup_true(str_contains($queryAttempt['body'], 'Authorize first-run setup'), 'Configured token GET must render only the unlock view');
    foreach (['admin_name', 'admin_email', 'admin_password', 'System checks', '_setup_action" value="install'] as $installField) {
        setup_true(!str_contains($queryAttempt['body'], $installField), 'Pre-grant GET must not reveal install content: ' . $installField);
    }
    setup_true(!str_contains($queryAttempt['body'], $httpToken), 'The setup token must not be reflected into HTML');
    setup_update_cookie($queryAttempt, $httpCookie, $httpSessionIds);
    setup_true($httpCookie !== '', 'Unlock GET must establish a setup session cookie');
    $unlockCookieHeader = implode('; ', $queryAttempt['headers']['set-cookie'] ?? []);
    setup_true(str_contains($unlockCookieHeader, 'HttpOnly'), 'Setup cookie must be HttpOnly');
    setup_true(str_contains($unlockCookieHeader, 'SameSite=Strict'), 'Setup cookie must be SameSite=Strict');
    setup_true(!preg_match('/(?:^|;)\s*Domain=/i', $unlockCookieHeader), 'Setup cookie must remain host-only');
    $unlockCsrf = setup_csrf_from_html($queryAttempt['body']);

    $wrongCsrf = setup_http_request(
        $httpPort,
        'POST',
        '/install.php',
        $httpHost,
        ['Cookie' => $httpCookie],
        http_build_query([
            '_setup_action' => 'unlock',
            '_token' => str_repeat('0', 64),
            'setup_token' => $httpToken,
        ]),
    );
    $wrongToken = setup_http_request(
        $httpPort,
        'POST',
        '/install.php',
        $httpHost,
        ['Cookie' => $httpCookie],
        http_build_query([
            '_setup_action' => 'unlock',
            '_token' => $unlockCsrf,
            'setup_token' => setup_b64(str_repeat("\x77", 32)),
        ]),
    );
    setup_same($wrongCsrf['status'], 404, 'Wrong unlock CSRF must be concealed');
    setup_same($wrongToken['status'], $wrongCsrf['status'], 'Wrong token and wrong CSRF must use the same status');
    setup_same($wrongToken['body'], $wrongCsrf['body'], 'Wrong token and wrong CSRF must use the same fixed body');
    setup_true(!is_file($httpDatabase), 'Rejected unlock attempts must not initialize the database');

    $unlock = setup_http_request(
        $httpPort,
        'POST',
        '/install.php',
        $httpHost,
        ['Cookie' => $httpCookie],
        http_build_query([
            '_setup_action' => 'unlock',
            '_token' => $unlockCsrf,
            'setup_token' => $httpToken,
        ]),
    );
    setup_same($unlock['status'], 303, 'Correct token unlock must use a clean 303 redirect');
    setup_same(setup_header($unlock, 'Location'), '/install.php', 'Correct token unlock must remove the credential from navigation');
    setup_true(!str_contains((string) setup_header($unlock, 'Location'), $httpToken), 'Unlock redirect must not contain the setup token');
    setup_update_cookie($unlock, $httpCookie, $httpSessionIds);

    $installForm = setup_http_request(
        $httpPort,
        'GET',
        '/install.php',
        $httpHost,
        ['Cookie' => $httpCookie],
    );
    setup_same($installForm['status'], 200, 'An authorized browser must receive the guided install form');
    foreach (['name="admin_name"', 'name="admin_email"', 'name="admin_password"', 'name="_setup_action" value="install"'] as $requiredField) {
        setup_true(str_contains($installForm['body'], $requiredField), 'Authorized install form must include ' . $requiredField);
    }
    setup_true(!str_contains($installForm['body'], $httpToken), 'Authorized install HTML must not contain the setup token');
    $installCsrf = setup_csrf_from_html($installForm['body']);

    $validationFailure = setup_http_request(
        $httpPort,
        'POST',
        '/install.php',
        $httpHost,
        ['Cookie' => $httpCookie],
        http_build_query([
            '_setup_action' => 'install',
            '_token' => $installCsrf,
            'college_name' => 'HTTP Contract College',
            'timezone' => 'UTC',
            'workflow' => 'default',
            'admin_name' => 'HTTP Contract Admin',
            'admin_email' => 'not-an-email',
            'admin_password' => 'contract-password-123',
            'setup_token' => $httpToken,
            'unexpected_admin_override' => 'must-not-pass',
        ]),
    );
    setup_same($validationFailure['status'], 303, 'Ordinary installer validation failure must redirect back to setup');
    setup_same(setup_header($validationFailure, 'Location'), '/install.php', 'Validation failure must preserve the guided setup path');
    setup_true(setup_read_state(cpe_data_path('setup'), "sqlite\0" . $httpDatabase)['state'] === 'active', 'Validation failure must leave the browser grant active');

    $retryForm = setup_http_request(
        $httpPort,
        'GET',
        '/install.php',
        $httpHost,
        ['Cookie' => $httpCookie],
    );
    setup_same($retryForm['status'], 200, 'Validation failure must leave the authorized form retryable');
    $retryCsrf = setup_csrf_from_html($retryForm['body']);
    $install = setup_http_request(
        $httpPort,
        'POST',
        '/install.php',
        $httpHost,
        ['Cookie' => $httpCookie],
        http_build_query([
            '_setup_action' => 'install',
            '_token' => $retryCsrf,
            'college_name' => 'HTTP Contract College',
            'site_name' => 'HTTP Placement Desk',
            'timezone' => 'UTC',
            'cycle_name' => 'HTTP Contract Cycle',
            'cycle_type' => 'final',
            'workflow' => 'default',
            'admin_name' => 'HTTP Contract Admin',
            'admin_email' => 'http-admin@example.test',
            'admin_password' => 'contract-password-123',
            'seed_demo' => '',
            'setup_token' => $httpToken,
            'unexpected_admin_override' => 'must-not-pass',
        ]),
    );
    setup_same($install['status'], 303, 'Successful authorized installation must redirect with 303');
    setup_same(setup_header($install, 'Location'), '/', 'Successful installation must redirect to the application root');
    setup_update_cookie($install, $httpCookie, $httpSessionIds);

    $pdo = new PDO('sqlite:' . $httpDatabase);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    setup_same((string) $pdo->query("SELECT value FROM settings WHERE key = 'installed_at'")->fetchColumn() !== '', true, 'Authorized HTTP install must set installed_at');
    setup_same((int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'http-admin@example.test' AND role = 'admin'")->fetchColumn(), 1, 'Authorized HTTP install must create exactly one administrator');
    $persisted = json_encode([
        'settings' => $pdo->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_ASSOC),
        'audit' => $pdo->query('SELECT action, detail, ip_address, user_agent FROM audit_logs')->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_THROW_ON_ERROR);
    setup_true(!str_contains($persisted, $httpToken), 'The setup token must not enter settings or audit records');
    $consumedState = file_get_contents($httpStatePath);
    setup_true(is_string($consumedState) && trim($consumedState) === '{"version":1,"state":"consumed"}', 'Successful HTTP install must leave only the minimal consumed marker');
    setup_true(!str_contains($consumedState, $httpToken), 'The setup token must not enter lease state');

    $installedGet = setup_http_request($httpPort, 'GET', '/install.php', $httpHost, ['Cookie' => $httpCookie]);
    setup_same($installedGet['status'], 303, 'Installed setup GET must never reopen the form');
    setup_same(setup_header($installedGet, 'Location'), '/', 'Installed setup GET must redirect to the application root');
    $installedHead = setup_http_request($httpPort, 'HEAD', '/install.php', $httpHost, ['Cookie' => $httpCookie]);
    setup_same($installedHead['status'], 303, 'Installed setup HEAD must redirect to the application root');
    $installedPost = setup_http_request(
        $httpPort,
        'POST',
        '/install.php',
        $httpHost,
        ['Cookie' => $httpCookie],
        http_build_query(['_setup_action' => 'install', 'setup_token' => $httpToken]),
    );
    setup_same($installedPost['status'], 409, 'Installed setup POST replay must return a fixed conflict');
    setup_true(!str_contains($installedPost['body'], $httpToken), 'Installed replay response must not reflect the token');
} finally {
    setup_stop_server($httpProcess, $httpPipes);
}
$httpLogContents = is_file($httpLog) ? (string) file_get_contents($httpLog) : '';
$httpJsonLogContents = is_file($httpLog . '.jsonl') ? (string) file_get_contents($httpLog . '.jsonl') : '';
setup_true(!str_contains($httpLogContents . $httpJsonLogContents, $httpToken), 'The setup token must not enter HTTP or structured logs');
$sessionSavePath = (string) ini_get('session.save_path');
if (str_contains($sessionSavePath, ';')) {
    $sessionSavePath = (string) substr(strrchr($sessionSavePath, ';'), 1);
}
$sessionSavePath = $sessionSavePath !== '' ? $sessionSavePath : sys_get_temp_dir();
foreach (array_unique($httpSessionIds) as $sessionId) {
    $sessionFile = rtrim($sessionSavePath, '/') . '/sess_' . $sessionId;
    if (is_file($sessionFile)) {
        setup_true(!str_contains((string) file_get_contents($sessionFile), $httpToken), 'The setup token must not enter the PHP session payload');
    }
}

// Real HTTP: exact loopback topology exposes only a code exchange. Possessing
// the terminal-displayed capability remains mandatory.
$localHttpDatabase = sys_get_temp_dir() . '/cpe-setup-http-local-' . bin2hex(random_bytes(5)) . '.sqlite';
$localHttpLog = sys_get_temp_dir() . '/cpe-setup-http-local-' . bin2hex(random_bytes(5)) . '.log';
$localHttpPort = setup_reserve_port();
$localHttpAuthority = '127.0.0.1:' . $localHttpPort;
$localHttpCapability = setup_b64(random_bytes(32));
$localHttpExpiresAt = time() + SetupAuthorization::GRANT_TTL_SECONDS;
$localHttpProcess = null;
$localHttpPipes = [];
$localSessionIds = [];
try {
    [$localHttpProcess, $localHttpPipes] = setup_start_server([
        'CPE_DB_PATH' => $localHttpDatabase,
        SetupHttp::INTERNAL_CAPABILITY_ENV => $localHttpCapability,
        SetupHttp::INTERNAL_ADDRESS_ENV => $localHttpAuthority,
        SetupHttp::INTERNAL_EXPIRES_ENV => (string) $localHttpExpiresAt,
    ], $localHttpLog, $localHttpPort);
    $rebound = setup_http_request($localHttpPort, 'GET', '/install.php', 'attacker.example');
    setup_same($rebound['status'], 404, 'A mismatched Host must not activate internal setup');
    $localUnlock = setup_http_request(
        $localHttpPort,
        'GET',
        '/install.php?setup_token=' . rawurlencode(setup_b64(str_repeat("\x67", 32))),
        $localHttpAuthority,
        ['X-Forwarded-For' => '198.51.100.99', 'Forwarded' => 'for=198.51.100.99'],
    );
    setup_same($localUnlock['status'], 200, 'Exact loopback topology must render only the local code exchange');
    setup_true(str_contains($localUnlock['body'], 'one-time setup code'), 'Local setup must explain the terminal code exchange');
    foreach (['admin_name', 'admin_email', 'admin_password', '_setup_action" value="install'] as $installField) {
        setup_true(!str_contains($localUnlock['body'], $installField), 'Topology alone must not reveal install content: ' . $installField);
    }
    setup_true(!str_contains($localUnlock['body'], $localHttpCapability), 'The local code must not be reflected into the unlock page');
    $localCookie = '';
    setup_update_cookie($localUnlock, $localCookie, $localSessionIds);
    $localCsrf = setup_csrf_from_html($localUnlock['body']);

    $proxyCookie = '';
    $proxyUnlock = setup_http_request($localHttpPort, 'GET', '/install.php', $localHttpAuthority);
    setup_update_cookie($proxyUnlock, $proxyCookie, $localSessionIds);
    $proxyCsrf = setup_csrf_from_html($proxyUnlock['body']);
    $proxyWithoutCode = setup_http_request(
        $localHttpPort,
        'POST',
        '/install.php',
        $localHttpAuthority,
        [
            'Cookie' => $proxyCookie,
            'X-Forwarded-For' => '203.0.113.9',
            'Forwarded' => 'for=203.0.113.9;proto=https',
        ],
        http_build_query([
            '_setup_action' => 'unlock',
            '_token' => $proxyCsrf,
            'setup_token' => setup_b64(str_repeat("\x68", 32)),
        ]),
    );
    setup_same($proxyWithoutCode['status'], 404, 'A loopback proxy without the terminal code must not authorize setup');
    setup_true(!is_file($localHttpDatabase), 'A topology-only local request must not initialize the database');

    $localExchange = setup_http_request(
        $localHttpPort,
        'POST',
        '/install.php',
        $localHttpAuthority,
        ['Cookie' => $localCookie],
        http_build_query([
            '_setup_action' => 'unlock',
            '_token' => $localCsrf,
            'setup_token' => $localHttpCapability,
        ]),
    );
    setup_same($localExchange['status'], 303, 'The exact terminal code POST must authorize local setup');
    setup_same(setup_header($localExchange, 'Location'), '/install.php', 'Local code exchange must redirect to a clean URL');
    setup_true(!str_contains((string) setup_header($localExchange, 'Location'), $localHttpCapability), 'Local redirect must not contain the setup code');
    setup_update_cookie($localExchange, $localCookie, $localSessionIds);

    $proxyReplay = setup_http_request(
        $localHttpPort,
        'POST',
        '/install.php',
        $localHttpAuthority,
        ['Cookie' => $proxyCookie],
        http_build_query([
            '_setup_action' => 'unlock',
            '_token' => $proxyCsrf,
            'setup_token' => $localHttpCapability,
        ]),
    );
    setup_same($proxyReplay['status'], 404, 'A terminal code already exchanged into another active grant must not create a second grant');

    $authorizedRebound = setup_http_request(
        $localHttpPort,
        'GET',
        '/install.php',
        'attacker.example',
        ['Cookie' => $localCookie],
    );
    setup_same($authorizedRebound['status'], 404, 'A local grant must retain the exact Host rebinding defense');

    $localForm = setup_http_request($localHttpPort, 'GET', '/install.php', $localHttpAuthority, ['Cookie' => $localCookie]);
    setup_same($localForm['status'], 200, 'The internal loopback grant must render the guided form');
    setup_true(str_contains($localForm['body'], 'name="admin_email"'), 'The internal loopback form must contain the administrator step');
    setup_true(!str_contains($localForm['body'], $localHttpCapability), 'The internal capability must never enter HTML');
    $localState = file_get_contents(setup_default_state_path($localHttpDatabase));
    setup_true(is_string($localState) && !str_contains($localState, $localHttpCapability), 'The internal capability must never enter lease state');
} finally {
    setup_stop_server($localHttpProcess, $localHttpPipes);
}
setup_true(!str_contains((string) file_get_contents($localHttpLog), $localHttpCapability), 'The internal capability must never enter the server log');
foreach (array_unique($localSessionIds) as $sessionId) {
    $sessionFile = rtrim($sessionSavePath, '/') . '/sess_' . $sessionId;
    if (is_file($sessionFile)) {
        setup_true(!str_contains((string) file_get_contents($sessionFile), $localHttpCapability), 'The local setup code must not enter the PHP session payload');
    }
}

$expiredLocalDatabase = sys_get_temp_dir() . '/cpe-setup-http-local-expired-' . bin2hex(random_bytes(5)) . '.sqlite';
$expiredLocalLog = sys_get_temp_dir() . '/cpe-setup-http-local-expired-' . bin2hex(random_bytes(5)) . '.log';
$expiredLocalPort = setup_reserve_port();
$expiredLocalProcess = null;
$expiredLocalPipes = [];
try {
    [$expiredLocalProcess, $expiredLocalPipes] = setup_start_server([
        'CPE_DB_PATH' => $expiredLocalDatabase,
        SetupHttp::INTERNAL_CAPABILITY_ENV => setup_b64(random_bytes(32)),
        SetupHttp::INTERNAL_ADDRESS_ENV => '127.0.0.1:' . $expiredLocalPort,
        SetupHttp::INTERNAL_EXPIRES_ENV => (string) (time() - 1),
    ], $expiredLocalLog, $expiredLocalPort);
    $expiredLocal = setup_http_request(
        $expiredLocalPort,
        'GET',
        '/install.php',
        '127.0.0.1:' . $expiredLocalPort,
    );
    setup_same($expiredLocal['status'], 404, 'An expired terminal setup code must not expose an unlock surface');
    setup_true(!is_file($expiredLocalDatabase), 'Expired local capability mode must not initialize the database');
} finally {
    setup_stop_server($expiredLocalProcess, $expiredLocalPipes);
}

// Configured database-backed sessions fail closed before browser setup.
$databaseSessionDb = sys_get_temp_dir() . '/cpe-setup-http-db-session-' . bin2hex(random_bytes(5)) . '.sqlite';
$databaseSessionLog = sys_get_temp_dir() . '/cpe-setup-http-db-session-' . bin2hex(random_bytes(5)) . '.log';
$databaseSessionProcess = null;
$databaseSessionPipes = [];
try {
    [$databaseSessionProcess, $databaseSessionPipes, $databaseSessionPort] = setup_start_server([
        'CPE_DB_PATH' => $databaseSessionDb,
        'CPE_SETUP_TOKEN' => $token,
        'CPE_SESSION_DRIVER' => 'database',
    ], $databaseSessionLog);
    $databaseSessionResponse = setup_http_request(
        $databaseSessionPort,
        'GET',
        '/install.php',
        '127.0.0.1:' . $databaseSessionPort,
    );
    setup_same($databaseSessionResponse['status'], 503, 'Preinstall database session mode must fail closed');
    setup_true(str_contains($databaseSessionResponse['body'], 'php placement install'), 'Database session refusal must provide CLI guidance');
    setup_true(!str_contains($databaseSessionResponse['body'], 'admin_email'), 'Database session refusal must not reveal the installer');
} finally {
    setup_stop_server($databaseSessionProcess, $databaseSessionPipes);
}

// An already-active PHP session cannot bypass the setup-specific cookie and
// storage policy.
$autoSessionDb = sys_get_temp_dir() . '/cpe-setup-http-auto-session-' . bin2hex(random_bytes(5)) . '.sqlite';
$autoSessionLog = sys_get_temp_dir() . '/cpe-setup-http-auto-session-' . bin2hex(random_bytes(5)) . '.log';
$autoSessionProcess = null;
$autoSessionPipes = [];
try {
    [$autoSessionProcess, $autoSessionPipes, $autoSessionPort] = setup_start_server(
        ['CPE_DB_PATH' => $autoSessionDb, 'CPE_SETUP_TOKEN' => $token],
        $autoSessionLog,
        null,
        ['session.auto_start' => '1'],
    );
    $autoSessionResponse = setup_http_request(
        $autoSessionPort,
        'GET',
        '/install.php',
        '127.0.0.1:' . $autoSessionPort,
    );
    setup_same($autoSessionResponse['status'], 503, 'A pre-started PHP session must fail browser setup closed');
    setup_true(str_contains($autoSessionResponse['body'], 'php placement install'), 'Pre-started session refusal must retain CLI guidance');
    setup_true(!str_contains($autoSessionResponse['body'], 'admin_email'), 'Pre-started session refusal must not reveal the installer');
} finally {
    setup_stop_server($autoSessionProcess, $autoSessionPipes);
}

// Hosted mode refuses browser setup before sessions or credentials are used.
$hostedDatabase = sys_get_temp_dir() . '/cpe-setup-http-hosted-' . bin2hex(random_bytes(5)) . '.sqlite';
$hostedBootstrap = sys_get_temp_dir() . '/cpe-setup-http-platform-' . bin2hex(random_bytes(5)) . '.php';
$hostedLog = sys_get_temp_dir() . '/cpe-setup-http-hosted-' . bin2hex(random_bytes(5)) . '.log';
$hostedSource = <<<'PHP'
<?php
$tenant = new \App\Hosted\Tenant\ResolvedTenant([
    'tenant_id' => 1,
    'tenant_public_id' => 'tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'slug' => 'setup-contract',
    'entitlements' => ['placement' => true],
], new \App\Infrastructure\Persistence\SqliteConnectionProvider(__DATABASE_PATH__), null);
\App\Hosted\HostedBootstrap::registerResolver(
    new class($tenant) implements \App\Hosted\Tenant\TenantResolver {
        public function __construct(private readonly \App\Hosted\Tenant\ResolvedTenant $tenant) {}
        public function resolveHost(string $host): \App\Hosted\Tenant\ResolvedTenant
        {
            if ($host !== 'hosted.example.test') {
                throw new \App\Hosted\Tenant\HostedResolutionException('Unknown host.', 404);
            }
            return $this->tenant;
        }
    },
);
PHP;
file_put_contents($hostedBootstrap, str_replace('__DATABASE_PATH__', var_export($hostedDatabase, true), $hostedSource));
$hostedProcess = null;
$hostedPipes = [];
try {
    $hostedPort = setup_reserve_port();
    [$hostedProcess, $hostedPipes] = setup_start_server([
        'CPE_HOSTED_MODE' => '1',
        'CPE_PLATFORM_BOOTSTRAP' => $hostedBootstrap,
        'CPE_SETUP_TOKEN' => $token,
        SetupHttp::INTERNAL_CAPABILITY_ENV => $localHttpCapability,
        SetupHttp::INTERNAL_ADDRESS_ENV => '127.0.0.1:' . $hostedPort,
        SetupHttp::INTERNAL_EXPIRES_ENV => (string) (time() + SetupAuthorization::GRANT_TTL_SECONDS),
    ], $hostedLog, $hostedPort);
    $hostedGet = setup_http_request($hostedPort, 'GET', '/install.php', 'hosted.example.test');
    $hostedPost = setup_http_request(
        $hostedPort,
        'POST',
        '/install.php',
        'hosted.example.test',
        [],
        http_build_query(['_setup_action' => 'unlock', 'setup_token' => $token]),
    );
    setup_same($hostedGet['status'], 503, 'Hosted setup GET must return the fixed unavailable response');
    setup_same($hostedPost['status'], 503, 'Hosted setup POST must ignore credentials and return the fixed unavailable response');
    setup_same($hostedPost['body'], $hostedGet['body'], 'Hosted setup denial must be method- and credential-independent');
    setup_true(!is_file($hostedDatabase), 'Hosted browser denial must not initialize the tenant database');
} finally {
    setup_stop_server($hostedProcess, $hostedPipes);
}

$unresolvedHostedDb = sys_get_temp_dir() . '/cpe-setup-http-hosted-unresolved-' . bin2hex(random_bytes(5)) . '.sqlite';
$unresolvedHostedLog = sys_get_temp_dir() . '/cpe-setup-http-hosted-unresolved-' . bin2hex(random_bytes(5)) . '.log';
$unresolvedHostedProcess = null;
$unresolvedHostedPipes = [];
try {
    [$unresolvedHostedProcess, $unresolvedHostedPipes, $unresolvedHostedPort] = setup_start_server([
        'CPE_DB_PATH' => $unresolvedHostedDb,
        'CPE_HOSTED_MODE' => '1',
        'CPE_SETUP_TOKEN' => $token,
    ], $unresolvedHostedLog);
    $unresolvedHosted = setup_http_request(
        $unresolvedHostedPort,
        'GET',
        '/install.php',
        'hosted.example.test',
    );
    setup_same($unresolvedHosted['status'], 503, 'Unresolved hosted setup must fail closed');
    setup_same($unresolvedHosted['body'], $hostedGet['body'], 'Every hosted bootstrap failure must use the fixed setup denial');
    setup_true(!is_file($unresolvedHostedDb), 'Unresolved hosted setup must not initialize a database');
} finally {
    setup_stop_server($unresolvedHostedProcess, $unresolvedHostedPipes);
}

// Trusted CLI install paths remain independent from HTTP authorization.
$cliDatabase = sys_get_temp_dir() . '/cpe-setup-cli-install-' . bin2hex(random_bytes(5)) . '.sqlite';
[$cliInstallCode, $cliInstallOutput, $cliInstallError] = setup_run_cli([
    'install',
    '--college=CLI Contract College',
    '--timezone=UTC',
    '--cycle-name=CLI Contract Cycle',
    '--admin-name=CLI Contract Admin',
    '--admin-email=cli-contract@example.test',
], [
    'CPE_DB_PATH' => $cliDatabase,
    'CPE_ADMIN_PASSWORD' => 'contract-password-123',
]);
setup_same($cliInstallCode, 0, 'Trusted CLI install must remain independent from HTTP authorization: ' . $cliInstallError);
setup_true(str_contains($cliInstallOutput, 'Installed app.'), 'Trusted CLI install must complete normally');
$cliDemoDatabase = sys_get_temp_dir() . '/cpe-setup-cli-demo-' . bin2hex(random_bytes(5)) . '.sqlite';
[$cliDemoCode, $cliDemoOutput, $cliDemoError] = setup_run_cli(['install-demo'], ['CPE_DB_PATH' => $cliDemoDatabase]);
setup_same($cliDemoCode, 0, 'Trusted CLI demo install must remain independent from HTTP authorization: ' . $cliDemoError);
setup_true(str_contains($cliDemoOutput, 'Installed demo app.'), 'Trusted CLI demo install must complete normally');

foreach ([$httpStatePath, setup_default_state_path($localHttpDatabase)] as $generatedStatePath) {
    if (is_file($generatedStatePath) && !unlink($generatedStatePath)) {
        throw new RuntimeException('Could not remove generated setup authorization state: ' . $generatedStatePath);
    }
}

echo "Setup authorization contract passed.\n";
