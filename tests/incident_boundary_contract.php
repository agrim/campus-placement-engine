<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Http\UserVisibleException;
use App\Core\Events\DomainEventOutboxWorker;
use App\Core\Persistence\TransactionRollbackGuard;
use App\Domain\NotificationDeliveryService;
use App\Domain\SnapshotExporter;
use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Security\Csrf;
use App\Security\SetupHttp;
use App\Support\Auth;
use App\Support\ControllerFailure;
use App\Support\Database;
use App\Support\Flash;
use App\Support\IncidentReporter;
use App\Support\StructuredLogger;

function incident_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param mixed $actual @param mixed $expected */
function incident_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(
            $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')',
        );
    }
}

function incident_reserve_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if (!is_resource($socket)) {
        throw new RuntimeException('Could not reserve incident contract port: ' . $errorMessage, $errorNumber);
    }
    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr(strrchr($address, ':'), 1);
}

/**
 * @param array<string, string> $environment
 * @param array<string, string> $iniSettings
 * @return array{0: resource, 1: array<int, resource>, 2: int}
 */
function incident_start_server(array $environment, string $phpLogPath, array $iniSettings = []): array
{
    $port = incident_reserve_port();
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
        'CPE_METRICS_TOKEN',
    ] as $key) {
        unset($processEnvironment[$key]);
    }
    $processEnvironment = array_merge($processEnvironment, $environment);
    $command = [PHP_BINARY, '-d', 'display_errors=1'];
    foreach ($iniSettings as $name => $value) {
        $command[] = '-d';
        $command[] = $name . '=' . $value;
    }
    array_push($command, '-S', '127.0.0.1:' . $port, '-t', cpe_path('public'));
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $phpLogPath, 'a'], 2 => ['file', $phpLogPath, 'a']],
        $pipes,
        cpe_path(),
        $processEnvironment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start incident contract server.');
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
    throw new RuntimeException('Incident contract server did not become ready.');
}

/** @param resource|null $process @param array<int, resource> $pipes */
function incident_stop_server(mixed &$process, array &$pipes): void
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
function incident_request(
    int $port,
    string $method,
    string $path,
    array $headers = [],
    string $body = '',
): array {
    $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage, 3);
    if (!is_resource($socket)) {
        throw new RuntimeException('Could not connect to incident contract server: ' . $errorMessage, $errorNumber);
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
        throw new RuntimeException('Incident contract server returned an invalid HTTP response.');
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

function incident_cookie(array $response, string $current = ''): string
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
    $pairs = [];
    foreach ($cookies as $name => $value) {
        $pairs[] = $name . '=' . $value;
    }
    return implode('; ', $pairs);
}

function incident_cookie_value(string $cookie, string $name): ?string
{
    foreach (explode('; ', $cookie) as $pair) {
        if (!str_contains($pair, '=')) {
            continue;
        }
        [$candidate, $value] = explode('=', $pair, 2);
        if (hash_equals($name, $candidate)) {
            return $value;
        }
    }
    return null;
}

function incident_csrf(string $html): string
{
    if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $html, $matches) !== 1) {
        throw new RuntimeException('Incident contract could not find a CSRF token.');
    }
    return $matches[1];
}

/** @return list<array<string, mixed>> */
function incident_log_records(string $path): array
{
    $records = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (is_array($record)) {
            $records[] = $record;
        }
    }
    return $records;
}

/** @param array<string, string> $sentinels */
function incident_assert_absent(string $contents, array $sentinels, string $surface): void
{
    foreach ($sentinels as $label => $sentinel) {
        incident_true(!str_contains($contents, $sentinel), $surface . ' disclosed the ' . $label . ' sentinel.');
    }
}

final class IncidentFailStream
{
    public mixed $context = null;
    public static string $failure = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        throw new RuntimeException(self::$failure);
    }

    public function url_stat(string $path, int $flags): array|false
    {
        if (rtrim($path, '/') === 'incidentfail://outbox') {
            return ['mode' => 0040777, 2 => 0040777];
        }
        return false;
    }
}

final class IncidentRollbackFailurePdo extends PDO
{
    public static string $failure = '';

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function inTransaction(): bool
    {
        return true;
    }

    public function rollBack(): bool
    {
        throw new RuntimeException(self::$failure);
    }
}

/**
 * @param list<string> $command
 * @param array<string, string> $environment
 * @return array{0: int, 1: string, 2: string}
 */
function incident_run_command(array $command, array $environment): array
{
    $processEnvironment = getenv();
    if (!is_array($processEnvironment)) {
        $processEnvironment = [];
    }
    $processEnvironment = array_merge($processEnvironment, $environment);
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        cpe_path(),
        $processEnvironment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start incident contract command.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

$suffix = bin2hex(random_bytes(6));
$safeTmp = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
$sentinels = [
    'password' => 'incident-password-' . $suffix,
    'dsn' => 'postgresql://incident-user:incident-db-' . $suffix . '@127.0.0.1:1/placement',
    'email' => 'sentinel.' . $suffix . '@example.test',
    'url' => 'https://gateway.example.test/callback?access_token=' . $suffix,
    'person' => 'Sentinel Person ' . $suffix,
    'path' => '/private/incident-' . $suffix . '/candidate.json',
    'sqlstate' => 'SQLSTATE[23505] DETAIL: incident-detail-' . $suffix,
    'json_secret' => '{"client_secret":"incident-json-' . $suffix . '"}',
    'sql_identifier' => 'incident_secret_' . $suffix,
    'route_token' => 'routeToken_' . $suffix . '_opaque',
];
$rawFailure = implode(' | ', $sentinels);
$paths = [];
$process = null;
$pipes = [];
$streamRegistered = false;
$setupSessionDirectory = null;

try {
    $visible = new UserVisibleException('SETUP_CONTRACT_INVALID', 'Reviewed setup guidance.');
    incident_same($visible->publicCode(), 'SETUP_CONTRACT_INVALID', 'User-visible failure code changed.');
    incident_same($visible->publicMessage(), 'Reviewed setup guidance.', 'User-visible failure message changed.');

    $databasePath = sys_get_temp_dir() . '/cpe-incident-requirements-' . $suffix . '.sqlite';
    $paths[] = $databasePath;
    putenv('CPE_DB_PATH=' . $databasePath);
    Database::reset();
    $requirements = new SystemRequirements();
    $serializedChecks = json_encode($requirements->checks(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    incident_true(!str_contains($serializedChecks, $databasePath), 'System requirements exposed the database identifier.');
    ob_start();
    $requirementsOk = $requirements->isReady();
    require cpe_path('app/Views/install.php');
    $installHtml = ob_get_clean() ?: '';
    incident_true(!str_contains($installHtml, $databasePath), 'Installer HTML exposed the database identifier.');
    incident_true(!str_contains($installHtml, 'value</td>'), 'Installer HTML rendered diagnostic values.');

    putenv('CPE_DB_DRIVER=pgsql');
    putenv('CPE_DATABASE_URL=' . $sentinels['dsn']);
    Database::reset();
    $postgresChecks = json_encode((new SystemRequirements())->checks(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    incident_assert_absent($postgresChecks, $sentinels, 'PostgreSQL requirement diagnostics');
    incident_true(str_contains($postgresChecks, 'postgres_connection'), 'PostgreSQL requirement code was not fixed.');
    putenv('CPE_DB_DRIVER');
    putenv('CPE_DATABASE_URL');
    Database::reset();

    foreach ([
        [
            ['college_name' => 'College', 'timezone' => 'UTC', 'admin_name' => 'Admin', 'admin_email' => 'invalid', 'admin_password' => 'password123'],
            'SETUP_ADMIN_DETAILS_INVALID',
            'valid email',
        ],
        [
            ['college_name' => 'College', 'timezone' => 'Invalid/Timezone', 'admin_name' => 'Admin', 'admin_email' => 'admin@example.test', 'admin_password' => 'password123'],
            'SETUP_TIMEZONE_INVALID',
            'IANA timezone',
        ],
        [
            ['college_name' => 'College', 'timezone' => 'UTC', 'calendar_non_operating_weekdays' => 'Funday-' . $suffix, 'admin_name' => 'Admin', 'admin_email' => 'admin@example.test', 'admin_password' => 'password123'],
            'SETUP_WEEKDAY_INVALID',
            'Mon, Tue, Wed',
        ],
    ] as [$input, $code, $guidance]) {
        try {
            (new Installer())->install($input);
            throw new RuntimeException('Expected reviewed installer validation failure.');
        } catch (UserVisibleException $e) {
            incident_same($e->publicCode(), $code, 'Installer validation returned the wrong stable code.');
            incident_true(str_contains($e->publicMessage(), $guidance), 'Installer validation lost useful guidance.');
        }
    }

    $structuredLog = $safeTmp . '/cpe-incident-structured-' . $suffix . '.jsonl';
    $paths[] = $structuredLog;
    putenv('CPE_LOG_PATH=' . $structuredLog);
    $incidentId = IncidentReporter::report(
        new RuntimeException($rawFailure),
        'CPE_INCIDENT_CONTRACT_FAILED',
        'web',
        ['operation' => 'dispatch', 'route' => $sentinels['route_token'], 'unsafe' => $rawFailure],
    );
    incident_true(preg_match('/\Ainc_[a-f0-9]{32}\z/D', $incidentId) === 1, 'Incident ID was not random-shaped.');
    $record = incident_log_records($structuredLog)[0] ?? [];
    incident_same($record['context']['incident_id'] ?? null, $incidentId, 'Incident ID did not correlate to the structured record.');
    incident_same($record['context']['diagnostic_code'] ?? null, 'CPE_INCIDENT_CONTRACT_FAILED', 'Diagnostic code was not recorded.');
    incident_same($record['context']['exception_class'] ?? null, RuntimeException::class, 'Exception class was not recorded.');
    incident_same($record['context']['source_category'] ?? null, 'web', 'Safe source category was not recorded.');
    incident_true(str_starts_with((string) ($record['request_id'] ?? ''), 'req_'), 'Request ID was not recorded.');
    incident_same($record['context']['safe_context'] ?? null, ['operation' => 'dispatch'], 'Incident context was not serialized through the closed vocabulary.');
    incident_assert_absent((string) file_get_contents($structuredLog), $sentinels, 'Structured incident log');
    incident_same(fileperms($structuredLog) & 0777, 0600, 'Structured log permissions were not restrictive.');

    $rollbackPdo = new PDO('sqlite::memory:');
    $rollbackPdo->beginTransaction();
    $rollbackPrimary = new RuntimeException($rawFailure);
    try {
        TransactionRollbackGuard::rethrow($rollbackPdo, $rollbackPrimary, 'configuration.import', true);
        throw new RuntimeException('Expected a fixed rollback-complete exception.');
    } catch (UserVisibleException $e) {
        incident_same($e->publicCode(), 'CONFIGURATION_IMPORT_ROLLED_BACK', 'Successful cleanup used the wrong recovery code.');
        incident_same($e->getPrevious(), $rollbackPrimary, 'Successful cleanup did not retain primary causality.');
        incident_true(!$rollbackPdo->inTransaction(), 'Successful cleanup did not actually close the transaction.');
        incident_assert_absent($e->publicMessage(), $sentinels, 'Successful rollback guidance');
    }

    IncidentRollbackFailurePdo::$failure = $rawFailure;
    $rollbackPdo = new IncidentRollbackFailurePdo();
    $rollbackPrimary = new RuntimeException('primary ' . $rawFailure);
    try {
        TransactionRollbackGuard::rethrow($rollbackPdo, $rollbackPrimary, 'privacy.erasure', true);
        throw new RuntimeException('Expected an uncertain rollback exception.');
    } catch (UserVisibleException $e) {
        incident_same($e->publicCode(), 'RECOVERY_ROLLBACK_UNCERTAIN', 'Cleanup failure used the wrong recovery code.');
        incident_same($e->getPrevious(), $rollbackPrimary, 'Cleanup failure did not retain primary causality.');
        incident_same($rollbackPdo, null, 'Cleanup failure did not discard the uncertain connection.');
        incident_true(
            preg_match('/Reference: inc_[a-f0-9]{32}\z/D', $e->publicMessage()) === 1,
            'Cleanup failure did not retain safe incident correlation.',
        );
        incident_assert_absent($e->publicMessage(), $sentinels, 'Uncertain rollback guidance');
    }
    foreach ([
        'app/Domain/ConfigurationSnapshotService.php' => 'configuration.import',
        'app/Core/Portability/PortalPortabilityService.php' => 'portability.import',
        'app/Core/Privacy/PortalPrivacyService.php' => 'privacy.erasure',
    ] as $rollbackSource => $operation) {
        $source = (string) file_get_contents(cpe_path($rollbackSource));
        incident_true(
            str_contains($source, 'TransactionRollbackGuard::rethrow') && str_contains($source, "'{$operation}'"),
            $rollbackSource . ' does not use the guarded rollback boundary.',
        );
    }

    $controllerDatabase = sys_get_temp_dir() . '/cpe-incident-controller-' . $suffix . '.sqlite';
    array_push($paths, $controllerDatabase, $controllerDatabase . '-shm', $controllerDatabase . '-wal');
    putenv('CPE_DB_PATH=' . $controllerDatabase);
    Database::reset();
    Database::migrate(false);
    Database::connection()->exec(
        'CREATE TRIGGER incident_controller_abort BEFORE INSERT ON users '
        . 'BEGIN SELECT RAISE(ABORT, ' . Database::connection()->quote($rawFailure) . '); END',
    );
    $_SESSION['flash'] = [];
    try {
        Auth::createUser(
            $sentinels['person'],
            $sentinels['email'],
            $sentinels['password'],
            'admin',
        );
        throw new RuntimeException('Expected an unexpected controller-service failure.');
    } catch (\PDOException $unexpectedServiceFailure) {
        ControllerFailure::flash(
            $unexpectedServiceFailure,
            'CPE_CONTROLLER_CONTRACT_FAILED',
            'controller.contract',
        );
    }
    $unexpectedControllerFlash = Flash::pull()[0]['message'] ?? '';
    incident_true(
        preg_match('/\ARequest could not be completed\. Reference: (inc_[a-f0-9]{32})\z/D', $unexpectedControllerFlash, $controllerIncidentMatch) === 1,
        'Unexpected controller failure did not return an opaque incident reference.',
    );
    $controllerIncidentId = $controllerIncidentMatch[1];
    $controllerRecords = array_values(array_filter(
        incident_log_records($structuredLog),
        static fn (array $candidate): bool => ($candidate['context']['incident_id'] ?? null) === $controllerIncidentId,
    ));
    incident_same(count($controllerRecords), 1, 'Controller incident reference did not correlate to exactly one structured record.');
    incident_same(
        $controllerRecords[0]['context']['diagnostic_code'] ?? null,
        'CPE_CONTROLLER_CONTRACT_FAILED',
        'Controller incident used the wrong diagnostic code.',
    );
    incident_same(
        $controllerRecords[0]['context']['source_category'] ?? null,
        'controller',
        'Controller incident used the wrong source category.',
    );
    incident_assert_absent($unexpectedControllerFlash, $sentinels, 'Unexpected controller flash');
    incident_assert_absent((string) file_get_contents($structuredLog), $sentinels, 'Controller structured log');

    [$usageCode, $usageStdout, $usageStderr] = incident_run_command(
        [PHP_BINARY, cpe_path('placement'), $rawFailure],
        ['CPE_DB_PATH' => $controllerDatabase, 'CPE_LOG_PATH' => $structuredLog],
    );
    incident_same($usageCode, 1, 'Unknown CLI command must exit non-zero.');
    incident_true(
        str_contains($usageStderr, 'Unknown command. Run `php placement help` for available commands.'),
        'Reviewed CLI usage guidance was not preserved.',
    );
    incident_assert_absent($usageStdout . $usageStderr, $sentinels, 'CLI usage output');

    [$cliCode, $cliStdout, $cliStderr] = incident_run_command([
        PHP_BINARY,
        cpe_path('placement'),
        'install',
        '--college=Incident Contract College',
        '--timezone=UTC',
        '--admin-name=' . $sentinels['person'],
        '--admin-email=' . $sentinels['email'],
        '--admin-password=' . $sentinels['password'],
    ], [
        'CPE_DB_PATH' => $controllerDatabase,
        'CPE_LOG_PATH' => $structuredLog,
        'CPE_DB_DRIVER' => '',
        'CPE_DATABASE_URL' => '',
    ]);
    incident_same($cliCode, 1, 'Unexpected CLI service failure must exit non-zero.');
    incident_true(
        preg_match('/Error: Command failed\. CPE_CLI_COMMAND_FAILED Reference: (inc_[a-f0-9]{32})/', $cliStderr, $cliIncidentMatch) === 1,
        'Unexpected CLI service failure did not return an opaque incident reference.',
    );
    incident_assert_absent($cliStdout . $cliStderr, $sentinels, 'CLI output');
    $cliIncidentId = $cliIncidentMatch[1];
    $cliRecords = array_values(array_filter(
        incident_log_records($structuredLog),
        static fn (array $candidate): bool => ($candidate['context']['incident_id'] ?? null) === $cliIncidentId,
    ));
    incident_same(count($cliRecords), 1, 'CLI incident reference did not correlate to exactly one structured record.');
    incident_same($cliRecords[0]['context']['source_category'] ?? null, 'cli', 'CLI incident source category changed.');

    IncidentFailStream::$failure = $rawFailure;
    incident_true(stream_wrapper_register('incidentfail', IncidentFailStream::class), 'Could not register worker failure stream.');
    $streamRegistered = true;
    $pdo = Database::connection();
    $institutionId = (int) $pdo->query("SELECT id FROM institutions WHERE slug = 'default'")->fetchColumn();
    $eventPublicId = 'event_' . bin2hex(random_bytes(16));
    $eventInsert = $pdo->prepare(
        'INSERT INTO domain_event_outbox
         (public_id, event_name, aggregate_type, aggregate_public_id, institution_id, module_key,
          payload_json, occurred_at, available_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $eventInsert->execute([
        $eventPublicId,
        'placement.contract.failed',
        'contract',
        'contract_' . bin2hex(random_bytes(8)),
        $institutionId,
        'placement',
        '{}',
        cpe_now(),
        cpe_now(),
    ]);
    putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH=incidentfail://outbox/domain-event');
    putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS=1');
    $domainResult = (new DomainEventOutboxWorker($pdo))->work(1);
    incident_same($domainResult['failed'], 1, 'Domain-event worker failure count changed.');
    incident_same($domainResult['dead_lettered'], 1, 'Domain-event worker dead-letter semantics changed.');
    $domainError = (string) ($domainResult['rows'][0]['error'] ?? '');
    incident_true(
        preg_match('/\ACPE_DOMAIN_EVENT_DELIVERY_FAILED Reference: (inc_[a-f0-9]{32})\z/D', $domainError, $domainIncidentMatch) === 1,
        'Domain-event worker did not return an opaque incident reference.',
    );
    $eventError = (string) $pdo->query(
        'SELECT last_error FROM domain_event_outbox WHERE public_id = ' . $pdo->quote($eventPublicId)
    )->fetchColumn();
    incident_same($eventError, $domainError, 'Domain-event persisted failure did not match the safe incident reference.');
    incident_assert_absent(json_encode($domainResult, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . $eventError, $sentinels, 'Domain-event result and persistence');

    $notificationInsert = $pdo->prepare(
        'INSERT INTO notifications
         (recipient_role, channel, template_key, subject, body, status, source_type, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $notificationInsert->execute(['admin', 'file', 'contract', 'Contract', 'Contract', 'open', 'contract', cpe_now()]);
    $notificationId = Database::lastInsertId($pdo);
    $deliveryInsert = $pdo->prepare(
        'INSERT INTO notification_deliveries
         (notification_id, channel, target, status, payload_json, created_at, updated_at, available_at, idempotency_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $deliveryNow = cpe_now();
    $deliveryInsert->execute([
        $notificationId,
        'file',
        '[config:notification_file]',
        'queued',
        '{}',
        $deliveryNow,
        $deliveryNow,
        $deliveryNow,
        'ndk_' . bin2hex(random_bytes(16)),
    ]);
    $deliveryId = Database::lastInsertId($pdo);
    putenv('CPE_NOTIFICATION_FILE_OUTBOX_PATH=incidentfail://outbox/notification');
    $notificationResult = (new NotificationDeliveryService($pdo))->deliverPending('file', 1, false);
    incident_same($notificationResult['failed'], 1, 'Notification worker failure count changed.');
    $notificationError = (string) ($notificationResult['rows'][0]['error'] ?? '');
    incident_true(
        preg_match('/\ACPE_NOTIFICATION_DELIVERY_FAILED Reference: (inc_[a-f0-9]{32})\z/D', $notificationError, $notificationIncidentMatch) === 1,
        'Notification worker did not return an opaque incident reference.',
    );
    $persistedNotificationError = (string) $pdo->query(
        'SELECT last_error FROM notification_deliveries WHERE id = ' . $deliveryId
    )->fetchColumn();
    incident_same($persistedNotificationError, $notificationError, 'Notification persisted failure did not match the safe incident reference.');
    incident_assert_absent(
        json_encode($notificationResult, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . $persistedNotificationError,
        $sentinels,
        'Notification result and persistence',
    );

    putenv('CPE_NOTIFICATION_SMS_AUTHORIZATION=' . $rawFailure . "\r\nInjected: 1");
    $certification = (new NotificationDeliveryService($pdo))->certificationReport('sms', false);
    $certificationJson = json_encode($certification, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    incident_assert_absent($certificationJson, $sentinels, 'Notification certification result');
    incident_true(
        str_contains($certificationJson, 'authorization header cannot contain line breaks'),
        'Reviewed notification certification validation guidance was not preserved.',
    );
    putenv('CPE_NOTIFICATION_SMS_AUTHORIZATION');

    $exportDirectory = sys_get_temp_dir() . '/cpe-incident-export-' . $suffix;
    $exportResult = (new SnapshotExporter($pdo))->export($exportDirectory, 'full');
    foreach ($exportResult['files'] as $exportFile) {
        $paths[] = $exportDirectory . '/' . $exportFile['file'];
    }
    $paths[] = $exportDirectory . '/manifest.csv';
    $paths[] = $exportDirectory;
    $deliveryExport = (string) file_get_contents($exportDirectory . '/notification_deliveries.csv');
    incident_assert_absent($deliveryExport, $sentinels, 'Notification delivery export');
    incident_true(
        str_contains($deliveryExport, $notificationError),
        'Notification delivery export did not preserve the valid safe incident reference.',
    );
    $workerRecords = incident_log_records($structuredLog);
    foreach ([$domainIncidentMatch[1], $notificationIncidentMatch[1]] as $workerIncidentId) {
        $matches = array_values(array_filter(
            $workerRecords,
            static fn (array $candidate): bool => ($candidate['context']['incident_id'] ?? null) === $workerIncidentId,
        ));
        incident_same(count($matches), 1, 'Worker incident reference did not correlate to exactly one structured record.');
        incident_same($matches[0]['context']['source_category'] ?? null, 'worker', 'Worker incident source category changed.');
    }
    incident_assert_absent((string) file_get_contents($structuredLog), $sentinels, 'CLI and worker structured log');
    putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH');
    putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS');
    putenv('CPE_NOTIFICATION_FILE_OUTBOX_PATH');
    putenv('CPE_NOTIFICATION_SMS_AUTHORIZATION');
    stream_wrapper_unregister('incidentfail');
    $streamRegistered = false;
    Database::reset();
    putenv('CPE_DB_PATH=' . $databasePath);

    $_SESSION['csrf_token'] = str_repeat('a', 64);
    try {
        Csrf::verify(str_repeat('b', 64));
        throw new RuntimeException('Expected reviewed CSRF validation failure.');
    } catch (UserVisibleException $reviewedValidationFailure) {
        incident_same($reviewedValidationFailure->publicCode(), 'CSRF_MISMATCH', 'CSRF validation lost its stable public code.');
    }
    foreach ([
        $reviewedValidationFailure,
        new UserVisibleException('CONTROLLER_AUTHORIZATION_REVIEWED', 'Your role cannot create wanted alerts.'),
    ] as $reviewedControllerFailure) {
        ControllerFailure::flash($reviewedControllerFailure, 'CPE_CONTROLLER_CONTRACT_FAILED', 'controller.contract');
        incident_same(
            Flash::pull()[0]['message'] ?? null,
            $reviewedControllerFailure->publicMessage(),
            'Reviewed controller validation or authorization guidance was not preserved.',
        );
    }

    $controllerFiles = [
        'app/Controllers/AdminController.php',
        'app/Controllers/AuthController.php',
        'app/Controllers/BoardController.php',
        'app/Controllers/ImportController.php',
        'app/Controllers/ModuleController.php',
        'app/Controllers/NotificationController.php',
        'app/Controllers/PreferenceController.php',
        'app/Controllers/RecordsController.php',
        'app/Controllers/SystemController.php',
        'app/Controllers/WantedController.php',
        'app/Modules/Advising/Http/AdvisingController.php',
    ];
    foreach ($controllerFiles as $controllerFile) {
        $source = (string) file_get_contents(cpe_path($controllerFile));
        incident_true(!str_contains($source, '->getMessage('), $controllerFile . ' retained a raw exception-message sink.');
        if (str_contains($source, 'catch (\\Throwable')) {
            incident_true(
                str_contains($source, 'ControllerFailure::flash('),
                $controllerFile . ' retained an unformatted Throwable boundary.',
            );
        }
    }

    $fallbackLog = sys_get_temp_dir() . '/cpe-incident-fallback-' . $suffix . '.log';
    $paths[] = $fallbackLog;
    $oldErrorLog = ini_get('error_log');
    putenv('CPE_LOG_PATH');
    ini_set('log_errors', '1');
    ini_set('error_log', $fallbackLog);
    StructuredLogger::log('error', 'incident.fallback', [
        'nested' => [
            'password' => $sentinels['password'],
            'password ' => $sentinels['dsn'],
            "authorization\n" => $sentinels['url'],
        ],
        'note' => $rawFailure,
        'person_name' => $sentinels['person'],
        'route' => $sentinels['route_token'],
    ]);
    incident_assert_absent((string) file_get_contents($fallbackLog), $sentinels, 'PHP fallback log');
    ini_set('error_log', is_string($oldErrorLog) ? $oldErrorLog : '');

    $loggerRoot = $safeTmp . '/cpe-incident-logger-' . $suffix;
    $loggerReal = $loggerRoot . '/real';
    $loggerLink = $loggerRoot . '/linked';
    mkdir($loggerReal, 0700, true);
    symlink($loggerReal, $loggerLink);
    putenv('CPE_LOG_PATH=' . $loggerLink . '/structured.jsonl');
    StructuredLogger::log('error', 'incident.symlink_rejected', ['route' => 'unknown']);
    incident_true(!file_exists($loggerReal . '/structured.jsonl'), 'Structured logger followed a symlinked directory component.');
    unlink($loggerLink);

    $unsafeDirectory = $loggerRoot . '/unsafe';
    mkdir($unsafeDirectory, 0700);
    chmod($unsafeDirectory, 0777);
    putenv('CPE_LOG_PATH=' . $unsafeDirectory . '/structured.jsonl');
    StructuredLogger::log('error', 'incident.permissions_rejected', ['route' => 'unknown']);
    incident_true(!file_exists($unsafeDirectory . '/structured.jsonl'), 'Structured logger accepted an insecure writable directory.');
    chmod($unsafeDirectory, 0700);

    $hardlinkSource = $loggerRoot . '/source.log';
    $hardlinkTarget = $loggerRoot . '/hardlink.log';
    file_put_contents($hardlinkSource, "unchanged\n");
    chmod($hardlinkSource, 0600);
    link($hardlinkSource, $hardlinkTarget);
    putenv('CPE_LOG_PATH=' . $hardlinkTarget);
    StructuredLogger::log('error', 'incident.hardlink_rejected', ['route' => 'unknown']);
    incident_same((string) file_get_contents($hardlinkSource), "unchanged\n", 'Structured logger wrote through a multiply-linked file.');
    array_push($paths, $hardlinkTarget, $hardlinkSource, $unsafeDirectory, $loggerReal, $loggerRoot);
    putenv('CPE_LOG_PATH=' . $structuredLog);

    $platformBootstrap = sys_get_temp_dir() . '/cpe-incident-platform-' . $suffix . '.php';
    $platformPhpLog = sys_get_temp_dir() . '/cpe-incident-platform-' . $suffix . '.log';
    $platformStructuredLog = $safeTmp . '/cpe-incident-platform-' . $suffix . '.jsonl';
    array_push($paths, $platformBootstrap, $platformPhpLog, $platformStructuredLog);
    file_put_contents(
        $platformBootstrap,
        "<?php\nthrow new RuntimeException(" . var_export($rawFailure, true) . ");\n",
    );
    $metricsToken = 'incident-metrics-' . bin2hex(random_bytes(16));
    [$process, $pipes, $port] = incident_start_server([
        'CPE_HOSTED_MODE' => '1',
        'CPE_PLATFORM_BOOTSTRAP' => $platformBootstrap,
        'CPE_METRICS_TOKEN' => $metricsToken,
        'CPE_LOG_PATH' => $platformStructuredLog,
    ], $platformPhpLog);
    $siteFailure = incident_request($port, 'GET', '/');
    incident_same($siteFailure['status'], 503, 'Hosted bootstrap failure status changed.');
    incident_same($siteFailure['body'], "Hosted site temporarily unavailable.\n", 'Hosted bootstrap failure body changed.');
    $setupFailure = incident_request($port, 'GET', '/install.php');
    incident_same($setupFailure['status'], 503, 'Hosted setup failure status changed.');
    incident_same($setupFailure['body'], "Hosted setup is unavailable.\n", 'Hosted setup concealment body changed.');
    $liveness = incident_request($port, 'GET', '/health.php');
    $livenessPayload = json_decode($liveness['body'], true, 16, JSON_THROW_ON_ERROR);
    incident_same($liveness['status'], 200, 'Liveness status changed.');
    incident_same($livenessPayload['checks'] ?? null, ['process' => 'ok'], 'Liveness schema changed.');
    $readiness = incident_request(
        $port,
        'GET',
        '/health.php?ready=1',
        ['Authorization' => 'Bearer ' . $metricsToken],
    );
    $readinessPayload = json_decode($readiness['body'], true, 16, JSON_THROW_ON_ERROR);
    incident_same($readiness['status'], 503, 'Readiness failure status changed.');
    incident_same(array_keys($readinessPayload), ['status', 'mode', 'version', 'checks'], 'Readiness JSON schema changed.');
    incident_same($readinessPayload['status'] ?? null, 'unavailable', 'Readiness failure was not generic.');
    $metricsFailure = incident_request(
        $port,
        'GET',
        '/metrics.php',
        ['Authorization' => 'Bearer ' . $metricsToken],
    );
    incident_same($metricsFailure['status'], 404, 'Hosted metrics concealment status changed.');
    incident_same($metricsFailure['body'], "Not found.\n", 'Hosted metrics concealment body changed.');
    file_put_contents(
        $platformBootstrap,
        "<?php\ntrigger_error(" . var_export($rawFailure, true) . ", E_USER_ERROR);\n",
    );
    $fatalFailure = incident_request($port, 'GET', '/');
    incident_same($fatalFailure['status'], 503, 'Fatal hosted bootstrap boundary did not preserve its fixed failure status.');
    incident_same(
        $fatalFailure['body'],
        "Hosted site temporarily unavailable.\n",
        'Fatal hosted bootstrap boundary did not preserve its fixed failure body.',
    );
    incident_stop_server($process, $pipes);
    incident_assert_absent(
        $siteFailure['body'] . $setupFailure['body'] . $readiness['body'] . $metricsFailure['body'] . $fatalFailure['body'],
        $sentinels,
        'Operational HTTP response',
    );
    incident_assert_absent((string) file_get_contents($platformPhpLog), $sentinels, 'Operational PHP log');
    incident_assert_absent((string) file_get_contents($platformStructuredLog), $sentinels, 'Operational structured log');

    $metricsDatabase = sys_get_temp_dir() . '/cpe-incident-metrics-' . $suffix . '.sqlite';
    $metricsPhpLog = sys_get_temp_dir() . '/cpe-incident-metrics-' . $suffix . '.log';
    $metricsStructuredLog = $safeTmp . '/cpe-incident-metrics-' . $suffix . '.jsonl';
    array_push($paths, $metricsDatabase, $metricsPhpLog, $metricsStructuredLog);
    $metricsPdo = new PDO('sqlite:' . $metricsDatabase);
    $metricsPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $metricsPdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $metricsPdo->exec("INSERT INTO settings (key, value) VALUES ('installed_at', '2026-08-27 00:00:00')");
    $metricsPdo->exec(
        'CREATE VIEW module_installations AS SELECT '
        . "'placement' AS module_key, 1 AS enabled FROM " . $sentinels['sql_identifier'],
    );
    unset($metricsPdo);
    [$process, $pipes, $port] = incident_start_server([
        'CPE_DB_PATH' => $metricsDatabase,
        'CPE_METRICS_TOKEN' => $metricsToken,
        'CPE_LOG_PATH' => $metricsStructuredLog,
    ], $metricsPhpLog);
    $collectionFailure = incident_request(
        $port,
        'GET',
        '/metrics.php',
        ['Authorization' => 'Bearer ' . $metricsToken],
    );
    incident_same($collectionFailure['status'], 503, 'Authenticated metrics collection failure status changed.');
    incident_same($collectionFailure['body'], "Metrics unavailable.\n", 'Authenticated metrics failure body was not fixed.');
    incident_stop_server($process, $pipes);
    incident_assert_absent($collectionFailure['body'], $sentinels, 'Authenticated metrics response');
    incident_assert_absent((string) file_get_contents($metricsPhpLog), $sentinels, 'Metrics PHP log');
    incident_assert_absent((string) file_get_contents($metricsStructuredLog), $sentinels, 'Metrics structured log');
    $metricsRecords = incident_log_records($metricsStructuredLog);
    incident_same(
        $metricsRecords[0]['context']['diagnostic_code'] ?? null,
        'CPE_METRICS_COLLECTION_FAILED',
        'Metrics collection incident used the wrong diagnostic code.',
    );

    $setupDatabase = sys_get_temp_dir() . '/cpe-incident-setup-' . $suffix . '.sqlite';
    $setupPhpLog = sys_get_temp_dir() . '/cpe-incident-setup-' . $suffix . '.log';
    $setupStructuredLog = $safeTmp . '/cpe-incident-setup-' . $suffix . '.jsonl';
    $setupSessionDirectory = $safeTmp . '/cpe-incident-setup-sessions-' . $suffix;
    incident_true(
        mkdir($setupSessionDirectory, 0700),
        'Could not create private setup session storage.',
    );
    array_push($paths, $setupDatabase, $setupDatabase . '-shm', $setupDatabase . '-wal', $setupPhpLog, $setupStructuredLog);
    putenv('CPE_DB_PATH=' . $setupDatabase);
    Database::reset();
    Database::migrate(false);
    Database::connection()->exec(
        'CREATE TRIGGER incident_contract_abort BEFORE INSERT ON users '
        . 'BEGIN SELECT RAISE(ABORT, ' . Database::connection()->quote($rawFailure) . '); END',
    );
    Database::reset();
    putenv('CPE_DB_PATH');
    putenv('CPE_DB_DRIVER');
    putenv('CPE_DATABASE_URL');
    $setupToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $setupStatePath = cpe_data_path('setup/' . hash('sha256', "sqlite\0" . $setupDatabase) . '.json');
    $paths[] = $setupStatePath;
    [$process, $pipes, $port] = incident_start_server([
        'CPE_DB_PATH' => $setupDatabase,
        'CPE_SETUP_TOKEN' => $setupToken,
        'CPE_SESSION_SECURE' => 'force',
        'CPE_LOG_PATH' => $setupStructuredLog,
    ], $setupPhpLog, [
        'session.save_path' => $setupSessionDirectory,
        'session.gc_probability' => '0',
    ]);
    $cookie = '';
    $unlockForm = incident_request($port, 'GET', '/install.php');
    incident_same($unlockForm['status'], 200, 'Setup unlock form was unavailable.');
    $cookie = incident_cookie($unlockForm, $cookie);
    incident_true($cookie !== '', 'Setup unlock form did not establish a session cookie.');
    $unlockCookieHeaders = implode('; ', $unlockForm['headers']['set-cookie'] ?? []);
    incident_true(
        stripos($unlockCookieHeaders, 'secure') !== false,
        'Setup unlock server did not apply the forced secure-session transport policy.',
    );
    $setupSessionName = cpe_config('security.session_name', 'cpe_session');
    $setupSessionId = incident_cookie_value($cookie, $setupSessionName);
    incident_true(
        is_string($setupSessionId)
            && preg_match('/\A[A-Za-z0-9,-]{16,256}\z/D', $setupSessionId) === 1,
        'Setup unlock form returned an invalid session identifier.',
    );
    $setupSessionPath = $setupSessionDirectory . '/sess_' . $setupSessionId;
    incident_same(
        count(glob($setupSessionDirectory . '/sess_*') ?: []),
        1,
        'Setup CSRF state was not persisted in private session storage.',
    );
    $setupSessionStat = @lstat($setupSessionPath);
    incident_true(
        is_array($setupSessionStat)
            && (($setupSessionStat['mode'] ?? 0) & 0170000) === 0100000
            && is_int($setupSessionStat['size'] ?? null)
            && $setupSessionStat['size'] > 0
            && $setupSessionStat['size'] <= 4096,
        'Setup CSRF session storage was empty, unsafe, or oversized.',
    );
    $unlockCsrf = incident_csrf($unlockForm['body']);
    $setupSessionContents = @file_get_contents($setupSessionPath);
    incident_true(
        is_string($setupSessionContents) && str_contains($setupSessionContents, $unlockCsrf),
        'Setup CSRF state was not present in the session selected by the response cookie.',
    );
    $unlock = incident_request(
        $port,
        'POST',
        '/install.php',
        ['Cookie' => $cookie],
        http_build_query([
            '_setup_action' => 'unlock',
            '_token' => $unlockCsrf,
            'setup_token' => $setupToken,
        ]),
    );
    if ($unlock['status'] !== 303) {
        $unlockResponseCookie = incident_cookie($unlock, $cookie);
        $unlockResponseSessionId = incident_cookie_value($unlockResponseCookie, $setupSessionName);
        $stateStat = @lstat($setupStatePath);
        if (!is_array($stateStat)) {
            if ($unlockResponseSessionId !== $setupSessionId) {
                throw new RuntimeException(
                    'Setup unlock POST did not resume the submitted session before authorization.',
                );
            }
            throw new RuntimeException(
                'Setup unlock was denied before protected authorization-state creation despite persisted CSRF and forced transport.',
            );
        }
        $stateSize = is_int($stateStat['size'] ?? null) ? $stateStat['size'] : -1;
        $stateMode = ($stateStat['mode'] ?? 0) & 0777;
        if ($stateSize === 0 && $stateMode !== 0600) {
            throw new RuntimeException(
                'Setup unlock created authorization state but failed its protected file checks.',
            );
        }
        if ($stateSize === 0 && $unlockResponseSessionId === $setupSessionId) {
            throw new RuntimeException(
                'Setup unlock reached protected state creation but session regeneration did not complete.',
            );
        }
        if ($stateSize === 0) {
            throw new RuntimeException(
                'Setup unlock regenerated the session but failed before durable grant-state write.',
            );
        }
        throw new RuntimeException(
            'Setup unlock created non-empty authorization state but did not return the required redirect.',
        );
    }
    incident_same($unlock['status'], 303, 'Setup unlock no longer uses 303.');
    $cookie = incident_cookie($unlock, $cookie);
    $setupForm = incident_request($port, 'GET', '/install.php', ['Cookie' => $cookie]);
    incident_same($setupForm['status'], 200, 'Authorized setup form was unavailable.');
    incident_true(!str_contains($setupForm['body'], $setupDatabase), 'Setup form exposed the database path.');
    $validationCases = [
        ['admin_email' => 'invalid-email-' . $suffix, 'timezone' => 'UTC', 'calendar_non_operating_weekdays' => '', 'message' => 'valid email'],
        ['admin_email' => 'admin@example.test', 'timezone' => 'Invalid/Timezone-' . $suffix, 'calendar_non_operating_weekdays' => '', 'message' => 'IANA timezone'],
        ['admin_email' => 'admin@example.test', 'timezone' => 'UTC', 'calendar_non_operating_weekdays' => 'Funday-' . $suffix, 'message' => 'Mon, Tue, Wed'],
    ];
    foreach ($validationCases as $case) {
        $validation = incident_request(
            $port,
            'POST',
            '/install.php',
            ['Cookie' => $cookie],
            http_build_query([
                '_setup_action' => 'install',
                '_token' => incident_csrf($setupForm['body']),
                'college_name' => 'Incident Contract College',
                'timezone' => $case['timezone'],
                'calendar_non_operating_weekdays' => $case['calendar_non_operating_weekdays'],
                'workflow' => 'default',
                'admin_name' => 'Incident Contract Admin',
                'admin_email' => $case['admin_email'],
                'admin_password' => 'contract-password-123',
            ]),
        );
        incident_same($validation['status'], 303, 'Safe setup validation no longer uses 303 retry.');
        $setupForm = incident_request($port, 'GET', '/install.php', ['Cookie' => $cookie]);
        incident_same($setupForm['status'], 200, 'Safe setup validation consumed the setup grant.');
        incident_true(str_contains($setupForm['body'], $case['message']), 'Safe setup validation lost useful guidance.');
    }
    $unexpected = incident_request(
        $port,
        'POST',
        '/install.php',
        ['Cookie' => $cookie],
        http_build_query([
            '_setup_action' => 'install',
            '_token' => incident_csrf($setupForm['body']),
            'college_name' => 'Incident Contract College',
            'timezone' => 'UTC',
            'calendar_non_operating_weekdays' => 'sat,sun',
            'workflow' => 'default',
            'admin_name' => $sentinels['person'],
            'admin_email' => $sentinels['email'],
            'admin_password' => $sentinels['password'],
        ]),
    );
    incident_same($unexpected['status'], 303, 'Unexpected setup failure no longer uses the guided retry redirect.');
    $failureForm = incident_request($port, 'GET', '/install.php', ['Cookie' => $cookie]);
    incident_same($failureForm['status'], 200, 'Unexpected setup failure consumed the setup grant.');
    incident_true(
        preg_match('/Installation failed\. Reference: (inc_[a-f0-9]{32})/', $failureForm['body'], $incidentMatch) === 1,
        'Unexpected setup failure did not provide an opaque incident reference.',
    );
    $setupIncidentId = $incidentMatch[1];
    $setupRecords = incident_log_records($setupStructuredLog);
    $matchingRecords = array_values(array_filter(
        $setupRecords,
        static fn (array $record): bool => ($record['context']['incident_id'] ?? null) === $setupIncidentId,
    ));
    incident_same(count($matchingRecords), 1, 'Setup incident reference did not correlate to exactly one structured record.');
    incident_same(
        $matchingRecords[0]['context']['diagnostic_code'] ?? null,
        'CPE_SETUP_INSTALL_FAILED',
        'Setup incident used the wrong diagnostic code.',
    );
    incident_assert_absent($failureForm['body'], $sentinels, 'Setup HTML and flash');
    incident_assert_absent((string) file_get_contents($setupPhpLog), $sentinels, 'Setup PHP log');
    incident_assert_absent((string) file_get_contents($setupStructuredLog), $sentinels, 'Setup structured log');
    incident_stop_server($process, $pipes);

    $webDatabase = $safeTmp . '/cpe-incident-web-' . $suffix . '.sqlite';
    $webPhpLog = $safeTmp . '/cpe-incident-web-' . $suffix . '.log';
    $webStructuredLog = $safeTmp . '/cpe-incident-web-' . $suffix . '.jsonl';
    array_push($paths, $webDatabase, $webDatabase . '-shm', $webDatabase . '-wal', $webPhpLog, $webStructuredLog);
    putenv('CPE_DB_PATH=' . $webDatabase);
    Database::reset();
    (new Installer())->install([
        'college_name' => 'Incident Web College',
        'timezone' => 'UTC',
        'admin_name' => 'Incident Web Admin',
        'admin_email' => 'web-admin@example.test',
        'admin_password' => 'web-password-123',
    ]);
    $webPdo = Database::connection();
    Auth::createUser('Incident Auditor', 'web-auditor@example.test', 'auditor-password-123', 'auditor');
    $webPdo->exec("UPDATE settings SET value = 'both' WHERE key = 'audit_request_metadata'");
    $webPdo->exec("UPDATE settings SET value = '1' WHERE key = 'configuration_freeze'");
    Database::reset();
    [$process, $pipes, $port] = incident_start_server([
        'CPE_DB_PATH' => $webDatabase,
        'CPE_LOG_PATH' => $webStructuredLog,
    ], $webPhpLog);
    $encodedRouteToken = implode('', array_map(
        static fn (string $byte): string => sprintf('%%%02X', ord($byte)),
        str_split($sentinels['route_token']),
    ));
    $unknownRoute = incident_request($port, 'GET', '/?r=' . $encodedRouteToken);
    incident_true(in_array($unknownRoute['status'], [302, 303], true), 'Unknown token-shaped route no longer used the safe redirect boundary.');

    $cookie = '';
    $loginForm = incident_request($port, 'GET', '/?r=login');
    $cookie = incident_cookie($loginForm, $cookie);
    $login = incident_request(
        $port,
        'POST',
        '/?r=login',
        ['Cookie' => $cookie, 'User-Agent' => $sentinels['person'] . ' ' . $sentinels['email'] . ' ' . $sentinels['route_token']],
        http_build_query([
            '_token' => incident_csrf($loginForm['body']),
            'email' => 'web-admin@example.test',
            'password' => 'web-password-123',
        ]),
    );
    incident_true(in_array($login['status'], [302, 303], true), 'Web incident contract login failed.');
    $cookie = incident_cookie($login, $cookie);
    $systemPage = incident_request($port, 'GET', '/?r=system', ['Cookie' => $cookie]);
    incident_same($systemPage['status'], 200, 'Administrator System page was unavailable.');
    incident_true(!str_contains($systemPage['body'], $webDatabase), 'System page exposed the database path.');
    incident_true(!str_contains($systemPage['body'], 'SQLite'), 'System page exposed database identity.');

    $adminPage = incident_request($port, 'GET', '/?r=admin', ['Cookie' => $cookie]);
    $frozen = incident_request(
        $port,
        'POST',
        '/?r=admin',
        ['Cookie' => $cookie],
        http_build_query(['_token' => incident_csrf($adminPage['body']), 'configuration_freeze' => '1']),
    );
    incident_true(in_array($frozen['status'], [302, 303], true), 'Frozen settings controller did not redirect.');
    $frozenFlash = incident_request($port, 'GET', '/?r=admin', ['Cookie' => $cookie]);
    incident_true(
        str_contains($frozenFlash['body'], 'Configuration changes are frozen. Unfreeze configuration before changing settings.'),
        'Frozen settings controller lost its reviewed flash guidance.',
    );
    $changedWhileUnfreezing = incident_request(
        $port,
        'POST',
        '/?r=admin',
        ['Cookie' => $cookie],
        http_build_query(['_token' => incident_csrf($frozenFlash['body']), 'college_name' => 'Changed College']),
    );
    incident_true(in_array($changedWhileUnfreezing['status'], [302, 303], true), 'Unfreeze-only controller did not redirect.');
    $unfreezeFlash = incident_request($port, 'GET', '/?r=admin', ['Cookie' => $cookie]);
    incident_true(
        str_contains($unfreezeFlash['body'], 'Configuration changes are frozen. Unfreeze configuration before changing other settings.'),
        'Unfreeze-only controller lost its reviewed flash guidance.',
    );

    $auditorCookie = '';
    $auditorLoginForm = incident_request($port, 'GET', '/?r=login');
    $auditorCookie = incident_cookie($auditorLoginForm, $auditorCookie);
    $auditorLogin = incident_request(
        $port,
        'POST',
        '/?r=login',
        ['Cookie' => $auditorCookie],
        http_build_query([
            '_token' => incident_csrf($auditorLoginForm['body']),
            'email' => 'web-auditor@example.test',
            'password' => 'auditor-password-123',
        ]),
    );
    $auditorCookie = incident_cookie($auditorLogin, $auditorCookie);
    $auditorSystemPage = incident_request($port, 'GET', '/?r=system', ['Cookie' => $auditorCookie]);
    incident_same($auditorSystemPage['status'], 200, 'Auditor System page was unavailable.');
    incident_true(!str_contains($auditorSystemPage['body'], $webDatabase), 'Auditor System page exposed the database path.');
    incident_stop_server($process, $pipes);
    incident_assert_absent(
        $unknownRoute['body'] . $systemPage['body'] . $auditorSystemPage['body'] . $frozenFlash['body'] . $unfreezeFlash['body'],
        $sentinels,
        'Installed HTTP response',
    );
    incident_assert_absent((string) file_get_contents($webPhpLog), $sentinels, 'Installed HTTP PHP log');
    incident_assert_absent((string) file_get_contents($webStructuredLog), $sentinels, 'Installed HTTP structured log');
    $unknownTelemetry = array_values(array_filter(
        incident_log_records($webStructuredLog),
        static fn (array $candidate): bool => ($candidate['event'] ?? null) === 'http.request'
            && ($candidate['context']['route'] ?? null) === 'unknown',
    ));
    incident_true($unknownTelemetry !== [], 'Token-shaped HTTP route was not canonicalized to unknown telemetry.');

    putenv('CPE_DB_PATH=' . $webDatabase);
    Database::reset();
    $webPdo = Database::connection();
    $loginAudit = $webPdo->query("SELECT detail, ip_address, user_agent FROM audit_logs WHERE action = 'login' AND user_agent <> '' ORDER BY id DESC LIMIT 1")->fetch();
    incident_same((string) ($loginAudit['detail'] ?? ''), 'User signed in.', 'Audit persistence lost fixed reviewed detail.');
    incident_same((string) ($loginAudit['ip_address'] ?? ''), '127.0.0.0/24', 'Audit persistence did not coarsen the request IP.');
    incident_same((string) ($loginAudit['user_agent'] ?? ''), 'client.other', 'Audit persistence did not reduce the user agent to a fixed family.');
    incident_assert_absent(json_encode($loginAudit, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $sentinels, 'Audit persistence');

    $auditExportDirectory = $safeTmp . '/cpe-incident-audit-export-' . $suffix;
    $auditExport = (new SnapshotExporter($webPdo))->export($auditExportDirectory, 'full');
    foreach ($auditExport['files'] as $exportFile) {
        $paths[] = $auditExportDirectory . '/' . $exportFile['file'];
    }
    array_push($paths, $auditExportDirectory . '/manifest.csv', $auditExportDirectory);
    $auditCsv = (string) file_get_contents($auditExportDirectory . '/audit_logs.csv');
    incident_assert_absent($auditCsv, $sentinels, 'Audit snapshot export');
    incident_true(str_contains($auditCsv, 'Audit event recorded.'), 'Audit export lost its fixed safe event detail.');

    $pathSurfaceRoot = $safeTmp . $sentinels['path'];
    mkdir($pathSurfaceRoot, 0700, true);
    $configTarget = $pathSurfaceRoot . '/configuration-' . $suffix . '.json';
    $configResult = (new \App\Domain\ConfigurationSnapshotService($webPdo))->export($configTarget);
    incident_true(!str_contains(json_encode($configResult, JSON_THROW_ON_ERROR), $pathSurfaceRoot), 'Configuration result exposed its absolute target path.');
    [$backupCode, $backupOut, $backupErr] = incident_run_command(
        [PHP_BINARY, cpe_path('placement'), 'backup'],
        ['CPE_DB_PATH' => $webDatabase, 'CPE_BACKUP_DIR' => $pathSurfaceRoot],
    );
    incident_same($backupCode, 0, 'Backup CLI failed during path-surface contract: ' . $backupErr);
    incident_true(!str_contains($backupOut . $backupErr, $pathSurfaceRoot), 'Backup CLI exposed its configured absolute directory.');
    incident_true(str_contains($backupOut, 'configured backup directory'), 'Backup CLI lost configured-storage recovery guidance.');

    $cliConfigTarget = $pathSurfaceRoot . '/cli-config.json';
    [$configCode, $configOut, $configErr] = incident_run_command(
        [PHP_BINARY, cpe_path('placement'), 'config-export', $cliConfigTarget],
        ['CPE_DB_PATH' => $webDatabase],
    );
    incident_same($configCode, 0, 'Configuration CLI failed during path-surface contract: ' . $configErr);
    incident_assert_absent($configOut . $configErr, $sentinels, 'Configuration CLI output');

    $cliBundleTarget = $pathSurfaceRoot . '/cli-bundle';
    [$bundleCode, $bundleOut, $bundleErr] = incident_run_command(
        [PHP_BINARY, cpe_path('placement'), 'bundle-export', $cliBundleTarget],
        ['CPE_DB_PATH' => $webDatabase],
    );
    incident_same($bundleCode, 0, 'Portability CLI failed during path-surface contract: ' . $bundleErr);
    incident_assert_absent($bundleOut . $bundleErr, $sentinels, 'Portability CLI output');

    $cliWorkflowTarget = $pathSurfaceRoot . '/cli-workflow.json';
    [$workflowCode, $workflowOut, $workflowErr] = incident_run_command(
        [PHP_BINARY, cpe_path('placement'), 'workflow-export', '--target=' . $cliWorkflowTarget],
        ['CPE_DB_PATH' => $webDatabase],
    );
    incident_same($workflowCode, 0, 'Workflow CLI failed during path-surface contract: ' . $workflowErr);
    incident_assert_absent($workflowOut . $workflowErr, $sentinels, 'Workflow CLI output');

    $cliExportTarget = $pathSurfaceRoot . '/cli-export';
    [$exportCode, $exportOut, $exportErr] = incident_run_command(
        [PHP_BINARY, cpe_path('placement'), 'export', $cliExportTarget, '--profile=summary'],
        ['CPE_DB_PATH' => $webDatabase],
    );
    incident_same($exportCode, 0, 'Snapshot CLI failed during path-surface contract: ' . $exportErr);
    incident_assert_absent($exportOut . $exportErr, $sentinels, 'Snapshot CLI output');

    foreach (glob($pathSurfaceRoot . '/*') ?: [] as $pathSurfaceFile) {
        if (is_file($pathSurfaceFile)) {
            $paths[] = $pathSurfaceFile;
        }
    }
    foreach ([$cliBundleTarget . '/modules/placement.json', $cliBundleTarget . '/modules/advising.json', $cliBundleTarget . '/core.json', $cliBundleTarget . '/manifest.json'] as $bundleFile) {
        if (is_file($bundleFile)) {
            $paths[] = $bundleFile;
        }
    }
    foreach (glob($cliExportTarget . '/*') ?: [] as $exportFile) {
        if (is_file($exportFile)) {
            $paths[] = $exportFile;
        }
    }
    array_push(
        $paths,
        $cliBundleTarget . '/modules',
        $cliBundleTarget,
        $cliExportTarget,
        $pathSurfaceRoot,
        dirname($pathSurfaceRoot),
        dirname(dirname($pathSurfaceRoot)),
    );

    echo "PASS incident boundaries are opaque, correlated, and retry-safe\n";
} finally {
    incident_stop_server($process, $pipes);
    Database::reset();
    putenv('CPE_DB_PATH');
    putenv('CPE_LOG_PATH');
    putenv('CPE_HOSTED_MODE');
    putenv('CPE_PLATFORM_BOOTSTRAP');
    putenv('CPE_METRICS_TOKEN');
    putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH');
    putenv('CPE_DOMAIN_EVENT_MAX_ATTEMPTS');
    putenv('CPE_NOTIFICATION_FILE_OUTBOX_PATH');
    putenv('CPE_NOTIFICATION_SMS_AUTHORIZATION');
    if ($streamRegistered) {
        stream_wrapper_unregister('incidentfail');
    }
    if (is_string($setupSessionDirectory) && is_dir($setupSessionDirectory)) {
        foreach (glob($setupSessionDirectory . '/*') ?: [] as $sessionFile) {
            if (is_file($sessionFile) || is_link($sessionFile)) {
                unlink($sessionFile);
            }
        }
        rmdir($setupSessionDirectory);
    }
    foreach (array_unique($paths) as $path) {
        if (is_dir($path)) {
            rmdir($path);
            continue;
        }
        if (is_file($path)) {
            unlink($path);
        }
    }
}
