<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/cpe-api-http-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create API HTTP contract directory.');
}
$postgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
if (!$postgres) {
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $testRoot . '/contract.sqlite');
}
$rootKey = str_repeat("\x61", 32);
$encodedRootKey = rtrim(strtr(base64_encode($rootKey), '+/', '-_'), '=');
putenv('CPE_API_KEYRING=http-v1=' . $encodedRootKey);
putenv('CPE_API_ACTIVE_KEY_VERSION=http-v1');
putenv('CPE_LOG_PATH=' . $testRoot . '/structured.log');

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require $projectRoot . '/app/bootstrap.php';
require $projectRoot . '/tests/authorized_setup_recovery_fixture.php';

use App\Api\Http\ApiCursorCodec;
use App\Api\Operations\ApiRateLimiter;
use App\Api\Security\ApiKeyring;
use App\Api\Security\ApiServiceAccountService;
use App\Core\Portal;
use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Support\Database;

function api_http_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function api_http_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

/** @param array<string, mixed> $value @param list<string> $expected */
function api_http_keys(array $value, array $expected, string $message): void
{
    $actual = array_keys($value);
    sort($actual);
    sort($expected);
    api_http_same($expected, $actual, $message);
}

function api_http_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
}

/**
 * @param array<string, string|null> $overrides
 * @return array{0: resource, 1: array<int, resource>, 2: int}
 */
function api_http_start_server(string $projectRoot, string $logPath, array $overrides = []): array
{
    $reservation = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
    if (!is_resource($reservation)) {
        throw new RuntimeException('Could not reserve an API contract port: ' . $message, $number);
    }
    $address = (string) stream_socket_get_name($reservation, false);
    fclose($reservation);
    $port = (int) substr(strrchr($address, ':'), 1);
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    foreach ($overrides as $name => $value) {
        if ($value === null) {
            unset($environment[$name]);
        } else {
            $environment[$name] = $value;
        }
    }
    $process = proc_open(
        [
            PHP_BINARY,
            '-d',
            'display_errors=1',
            '-S',
            '127.0.0.1:' . $port,
            '-t',
            $projectRoot . '/public',
            $projectRoot . '/public/router.php',
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $logPath, 'a'],
            2 => ['file', $logPath, 'a'],
        ],
        $pipes,
        $projectRoot,
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the API contract server.');
    }
    fclose($pipes[0]);
    unset($pipes[0]);
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $connectNumber, $connectMessage, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            return [$process, $pipes, $port];
        }
        $status = proc_get_status($process);
        if (!is_array($status) || !($status['running'] ?? false)) {
            break;
        }
        usleep(20_000);
    }
    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('API contract server did not become ready.');
}

/** @param resource|null $process @param array<int, resource> $pipes */
function api_http_stop_server(mixed &$process, array &$pipes): void
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
function api_http_request(int $port, string $method, string $path, array $headers = [], string $body = ''): array
{
    $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $number, $message, 3);
    if (!is_resource($socket)) {
        throw new RuntimeException('Could not connect to the API contract server: ' . $message, $number);
    }
    $request = strtoupper($method) . ' ' . $path . " HTTP/1.1\r\n"
        . 'Host: 127.0.0.1:' . $port . "\r\n"
        . "Connection: close\r\n";
    foreach ($headers as $name => $value) {
        $request .= $name . ': ' . $value . "\r\n";
    }
    if ($body !== '') {
        $hasContentType = false;
        $hasContentLength = false;
        foreach (array_keys($headers) as $headerName) {
            $hasContentType = $hasContentType || strcasecmp($headerName, 'Content-Type') === 0;
            $hasContentLength = $hasContentLength || strcasecmp($headerName, 'Content-Length') === 0;
        }
        if (!$hasContentType) {
            $request .= 'Content-Type: application/json' . "\r\n";
        }
        if (!$hasContentLength) {
            $request .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
    }
    fwrite($socket, $request . "\r\n" . $body);
    $response = stream_get_contents($socket);
    fclose($socket);
    if (!is_string($response)
        || preg_match('/\AHTTP\/1\.[01] ([0-9]{3})[^\r\n]*\r?\n/', $response, $matches) !== 1) {
        throw new RuntimeException('API contract server returned an invalid HTTP response.');
    }
    $parts = preg_split("/\r?\n\r?\n/", $response, 2);
    $headerLines = preg_split("/\r?\n/", (string) ($parts[0] ?? '')) ?: [];
    array_shift($headerLines);
    $parsed = [];
    foreach ($headerLines as $line) {
        $separator = strpos($line, ':');
        if ($separator === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $separator)));
        $parsed[$name][] = trim(substr($line, $separator + 1));
    }
    return ['status' => (int) $matches[1], 'headers' => $parsed, 'body' => (string) ($parts[1] ?? '')];
}

/** @param array{status: int, headers: array<string, list<string>>, body: string} $response */
function api_http_assert_boundary(array $response, bool $json = true): void
{
    api_http_assert(!isset($response['headers']['set-cookie']), 'API response started a browser session.');
    api_http_assert(!isset($response['headers']['location']), 'API response used a browser redirect.');
    foreach (['access-control-allow-origin', 'access-control-allow-credentials', 'access-control-allow-headers', 'access-control-allow-methods'] as $header) {
        api_http_assert(!isset($response['headers'][$header]), 'API response enabled CORS: ' . $header);
    }
    api_http_assert(str_contains(strtolower((string) ($response['headers']['cache-control'][0] ?? '')), 'no-store'), 'API response is cacheable.');
    api_http_same('nosniff', strtolower((string) ($response['headers']['x-content-type-options'][0] ?? '')), 'API response omitted nosniff.');
    api_http_assert(preg_match('/\Areq_[a-f0-9]{32}\z/D', (string) ($response['headers']['x-request-id'][0] ?? '')) === 1, 'API response request ID is invalid.');
    if ($json) {
        api_http_assert(str_starts_with(strtolower((string) ($response['headers']['content-type'][0] ?? '')), 'application/json'), 'API response content type is not JSON.');
    }
}

/** @return array<string, mixed> */
function api_http_json(array $response): array
{
    $decoded = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('API response JSON is not an object.');
    }
    return $decoded;
}

/** @return array{code: string, message: string} */
function api_http_error_shape(array $response): array
{
    $payload = api_http_json($response);
    api_http_keys($payload, ['error'], 'API error envelope differs.');
    $error = $payload['error'] ?? null;
    api_http_assert(is_array($error), 'API error is not an object.');
    api_http_keys($error, ['code', 'message', 'request_id'], 'API public error fields differ.');
    api_http_assert(hash_equals((string) ($response['headers']['x-request-id'][0] ?? ''), (string) ($error['request_id'] ?? '')), 'API error request IDs differ.');
    return ['code' => (string) ($error['code'] ?? ''), 'message' => (string) ($error['message'] ?? '')];
}

/**
 * @param array{status: int, headers: array<string, list<string>>, body: string} $response
 */
function api_http_assert_audit_classification(
    PDO $pdo,
    array $response,
    string $routeClass,
    string $requiredScope,
    int $statusCode,
    string $detailCode,
    string $message,
): void {
    $requestId = (string) ($response['headers']['x-request-id'][0] ?? '');
    $query = $pdo->prepare(
        'SELECT route_class, required_scope, outcome, status_code, detail_code
         FROM api_request_audit_events WHERE request_id = ? ORDER BY id',
    );
    $query->execute([$requestId]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $query->closeCursor();
    api_http_same(1, count($rows), $message . ' audit row count differs.');
    $row = $rows[0];
    api_http_same($routeClass, (string) $row['route_class'], $message . ' audit route differs.');
    api_http_same($requiredScope, (string) $row['required_scope'], $message . ' audit scope differs.');
    api_http_same('denied', (string) $row['outcome'], $message . ' audit outcome differs.');
    api_http_same($statusCode, (int) $row['status_code'], $message . ' audit status differs.');
    api_http_same($detailCode, (string) $row['detail_code'], $message . ' audit detail differs.');
}

/** @return array{status: string, version: int, events: int, workflow: int, outbox: int, audits: int} */
function api_http_transition_evidence(PDO $pdo, int $applicationId): array
{
    $application = $pdo->prepare('SELECT current_status, aggregate_version FROM applications WHERE id = ?');
    $application->execute([$applicationId]);
    $row = $application->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('HTTP transition evidence application is missing.');
    }
    $count = static function (string $sql) use ($pdo, $applicationId): int {
        $query = $pdo->prepare($sql);
        $query->execute([$applicationId]);
        return (int) $query->fetchColumn();
    };
    return [
        'status' => (string) $row['current_status'],
        'version' => (int) $row['aggregate_version'],
        'events' => $count('SELECT COUNT(*) FROM events WHERE application_id = ?'),
        'workflow' => $count('SELECT COUNT(*) FROM workflow_transition_events WHERE application_id = ?'),
        'outbox' => $count(
            "SELECT COUNT(*) FROM domain_event_outbox event
             JOIN applications application ON application.public_id = event.aggregate_public_id
             WHERE application.id = ? AND event.event_name = 'placement.application.transitioned'",
        ),
        'audits' => $count(
            "SELECT COUNT(*) FROM audit_logs
             WHERE action = 'transition' AND subject_type = 'application' AND subject_id = ?",
        ),
    ];
}

/** @param array<string, string> $authorization */
function api_http_assert_disabled_classification(int $port, array $authorization, string $label): void
{
    $valid = api_http_request($port, 'GET', '/api/v1', $authorization);
    $missing = api_http_request($port, 'GET', '/api/v1');
    api_http_same(401, $valid['status'], $label . ' valid credential was not denied uniformly.');
    api_http_same(401, $missing['status'], $label . ' missing credential was not denied uniformly.');
    api_http_same(api_http_error_shape($valid), api_http_error_shape($missing), $label . ' credential failures differ.');

    $unknown = api_http_request($port, 'GET', '/api/v1/candidates', $authorization);
    api_http_same(404, $unknown['status'], $label . ' unknown route classification differs.');
    api_http_same('not_found', api_http_error_shape($unknown)['code'], $label . ' unknown route error differs.');

    $method = api_http_request($port, 'POST', '/api/v1', $authorization);
    api_http_same(405, $method['status'], $label . ' unsupported method classification differs.');
    api_http_same('GET, HEAD', (string) ($method['headers']['allow'][0] ?? ''), $label . ' 405 Allow header differs.');

    $query = api_http_request($port, 'GET', '/api/v1?unknown=1', $authorization);
    api_http_same(400, $query['status'], $label . ' invalid query classification differs.');
    api_http_same('invalid_query', api_http_error_shape($query)['code'], $label . ' invalid query error differs.');
    $parameter = api_http_request($port, 'GET', '/api/v1/opportunities?limit=0', $authorization);
    api_http_same(400, $parameter['status'], $label . ' invalid parameter classification differs.');
    api_http_same('invalid_limit', api_http_error_shape($parameter)['code'], $label . ' invalid parameter error differs.');
    $allowedParameter = api_http_request($port, 'GET', '/api/v1/opportunities?limit=1', $authorization);
    api_http_same(401, $allowedParameter['status'], $label . ' allowed bounded parameter did not reach uniform denial.');
    api_http_same('invalid_credentials', api_http_error_shape($allowedParameter)['code'], $label . ' allowed parameter denial differs.');

    $body = api_http_request($port, 'GET', '/api/v1', $authorization, '{}');
    api_http_same(400, $body['status'], $label . ' request body classification differs.');
    api_http_same('request_body_not_allowed', api_http_error_shape($body)['code'], $label . ' request body error differs.');

    $target = api_http_request(
        $port,
        'GET',
        '/api/v1/opportunities?updated_after=' . str_repeat('x', 2100),
        $authorization,
    );
    api_http_same(414, $target['status'], $label . ' oversized target classification differs.');
    api_http_same('request_target_too_large', api_http_error_shape($target)['code'], $label . ' oversized target error differs.');

    $headers = api_http_request(
        $port,
        'GET',
        '/api/v1',
        [...$authorization, 'X-Oversized' => str_repeat('x', 5000)],
    );
    api_http_same(431, $headers['status'], $label . ' oversized header classification differs.');
    api_http_same('request_headers_too_large', api_http_error_shape($headers)['code'], $label . ' oversized header error differs.');
}

function api_http_insert_cross_institution(PDO $pdo): array
{
    $now = '2020-01-01 00:00:00';
    $ids = [
        'institution' => 'inst_' . str_repeat('e', 32),
        'cycle' => 'cycle_' . str_repeat('e', 32),
        'organization' => 'organization_' . str_repeat('e', 32),
        'opportunity' => 'opportunity_' . str_repeat('e', 32),
        'participant' => 'participant_' . str_repeat('e', 32),
        'application' => 'application_' . str_repeat('e', 32),
    ];
    $statement = $pdo->prepare('INSERT INTO institutions (public_id, slug, name, timezone, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    $statement->execute([$ids['institution'], 'cross-institution', 'Cross Institution', 'UTC', $now, $now]);
    $institutionId = Database::lastInsertId($pdo);
    $pdo->prepare('INSERT INTO placement_cycles (public_id, institution_id, cycle_key, name, cycle_type, starts_on, ends_on, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ids['cycle'], $institutionId, 'cross-cycle', 'Cross Cycle', 'final', '', '', 'active', $now, $now]);
    $cycleId = Database::lastInsertId($pdo);
    $pdo->prepare('INSERT INTO candidates (external_id, name, public_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
        ->execute(['CROSS-CANDIDATE', 'Private Cross Person', 'candidate_' . str_repeat('e', 32), $now, $now]);
    $candidateId = Database::lastInsertId($pdo);
    $pdo->prepare('INSERT INTO people (public_id, institution_id, legacy_candidate_id, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['person_' . str_repeat('e', 32), $institutionId, $candidateId, 'Private Cross Person', $now, $now]);
    $personId = Database::lastInsertId($pdo);
    $pdo->prepare('INSERT INTO student_profiles (public_id, institution_id, person_id, external_id, program, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute(['student_' . str_repeat('e', 32), $institutionId, $personId, 'PRIVATE-CROSS-ID', 'Private Program', $now, $now]);
    $profileId = Database::lastInsertId($pdo);
    $pdo->prepare('INSERT INTO placement_cycle_participants (public_id, cycle_id, student_profile_id, legacy_candidate_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$ids['participant'], $cycleId, $profileId, $candidateId, $now, $now]);
    $participantId = Database::lastInsertId($pdo);
    $pdo->prepare('INSERT INTO companies (code, name, public_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
        ->execute(['CROSS-ORG', 'Private Cross Organization', 'company_' . str_repeat('e', 32), $now, $now]);
    $companyId = Database::lastInsertId($pdo);
    $pdo->prepare('INSERT INTO organizations (public_id, institution_id, legacy_company_id, code, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ids['organization'], $institutionId, $companyId, 'CROSS-ORG', 'Private Cross Organization', $now, $now]);
    $organizationId = Database::lastInsertId($pdo);
    $pdo->prepare('INSERT INTO placement_opportunities (public_id, cycle_id, organization_id, legacy_company_id, opportunity_key, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ids['opportunity'], $cycleId, $organizationId, $companyId, 'cross-private', 'Private Cross Opportunity', 'open', $now, $now]);
    $opportunityId = Database::lastInsertId($pdo);
    $pdo->prepare('INSERT INTO applications (candidate_id, company_id, current_status, public_id, participant_id, opportunity_id, aggregate_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$candidateId, $companyId, 'idle', $ids['application'], $participantId, $opportunityId, 1, $now, $now]);
    return $ids;
}

function api_http_install_audit_failure(PDO $pdo, bool $postgres): void
{
    if ($postgres) {
        $pdo->exec(
            "CREATE FUNCTION cpe_api_http_audit_fail() RETURNS trigger LANGUAGE plpgsql AS $$
             BEGIN RAISE EXCEPTION 'synthetic API audit failure'; END;
             $$",
        );
        $pdo->exec(
            'CREATE TRIGGER cpe_api_http_audit_fail BEFORE INSERT ON api_request_audit_events
             FOR EACH ROW EXECUTE FUNCTION cpe_api_http_audit_fail()',
        );
        return;
    }
    $pdo->exec(
        "CREATE TRIGGER cpe_api_http_audit_fail BEFORE INSERT ON api_request_audit_events
         BEGIN SELECT RAISE(ABORT, 'synthetic API audit failure'); END",
    );
}

function api_http_drop_audit_failure(PDO $pdo, bool $postgres): void
{
    if ($postgres) {
        $pdo->exec('DROP TRIGGER cpe_api_http_audit_fail ON api_request_audit_events');
        $pdo->exec('DROP FUNCTION cpe_api_http_audit_fail()');
        return;
    }
    $pdo->exec('DROP TRIGGER cpe_api_http_audit_fail');
}

/** @param array{0: string, 1: string} $windows */
function api_http_pre_auth_source_count(
    PDO $pdo,
    int $institutionId,
    string $sourceKey,
    array $windows,
): int {
    $query = $pdo->prepare(
        'SELECT MAX(request_count) FROM api_rate_limit_buckets
         WHERE institution_id = ? AND dimension = ? AND bucket_key = ? AND route_class = ?
           AND window_started_at IN (?, ?) AND window_seconds = 60',
    );
    $query->execute([
        $institutionId,
        'source',
        $sourceKey,
        ApiRateLimiter::PRE_AUTH_ROUTE_CLASS,
        $windows[0],
        $windows[1],
    ]);
    $count = (int) $query->fetchColumn();
    $query->closeCursor();
    return $count;
}

function api_http_validate_contract_documents(string $projectRoot): void
{
    $python = trim((string) (getenv('CPE_TEST_SCHEMA_PYTHON') ?: 'python3'));
    try {
        $process = proc_open(
            [$python, $projectRoot . '/tests/validate_public_api_contracts.py'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $projectRoot,
        );
    } catch (Throwable $failure) {
        throw new RuntimeException('Pinned public API validation tooling is unavailable.', 0, $failure);
    }
    if (!is_resource($process)) {
        throw new RuntimeException('Pinned public API validation tooling is unavailable.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException(
            'Public API contract validation failed closed.'
                . (trim((string) $stderr) !== '' ? ' ' . trim((string) $stderr) : ''),
        );
    }
    api_http_same(
        'PASS OpenAPI 3.1 and Draft 2020-12 public API contracts',
        trim((string) $stdout),
        'Public API validator did not return its exact success proof.',
    );
}

$server = null;
$pipes = [];
$missingKeyServer = null;
$missingKeyPipes = [];
$incidentServer = null;
$incidentPipes = [];
try {
    (new SystemRequirements())->assertReady();
    api_http_validate_contract_documents($projectRoot);
    Database::migrate();
    $adminId = (new Installer())->install([
        'college_name' => 'API HTTP Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'API HTTP Administrator',
        'admin_email' => 'api-http@example.test',
        'admin_password' => 'api-http-password-123',
        'seed_demo' => '1',
    ], test_authorized_setup_recovery_authority());
    $pdo = Database::connection();
    $keyring = ApiKeyring::fromEnvironment();
    $service = new ApiServiceAccountService($pdo, $keyring);
    $full = $service->create(
        'API HTTP governed connector',
        ['opportunities.read', 'applications.read', 'applications.transition'],
        $adminId,
    );
    $opportunitiesOnly = $service->create('API HTTP opportunity reader', ['opportunities.read'], $adminId);
    $revocable = $service->create('API HTTP revocable reader', ['opportunities.read'], $adminId);
    $cross = api_http_insert_cross_institution($pdo);

    $driverMigration = Database::driver() === 'pgsql'
        ? '017_api_read_pagination_indexes.sql'
        : '053_api_read_pagination_indexes.sql';
    $migration = $pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
    $migration->execute([$driverMigration]);
    api_http_same(1, (int) $migration->fetchColumn(), 'API pagination migration was not registered.');
    $migration->closeCursor();
    unset($migration);

    [$server, $pipes, $port] = api_http_start_server($projectRoot, $testRoot . '/server.log');
    $authorization = ['Authorization' => 'Bearer ' . $full['token']];

    $static = api_http_request($port, 'GET', '/assets/app.css');
    api_http_same(200, $static['status'], 'Development router did not preserve static asset serving.');
    api_http_assert(str_contains($static['body'], ':root'), 'Development router returned the wrong static asset.');

    $disabledAuditCount = (int) $pdo->query('SELECT COUNT(*) FROM api_request_audit_events')->fetchColumn();
    $disabledBucketCount = (int) $pdo->query('SELECT COUNT(*) FROM api_rate_limit_buckets')->fetchColumn();
    api_http_assert_disabled_classification($port, $authorization, 'Disabled API with keyring');
    api_http_same(
        $disabledAuditCount,
        (int) $pdo->query('SELECT COUNT(*) FROM api_request_audit_events')->fetchColumn(),
        'Disabled API with keyring inserted request audits.',
    );
    api_http_same(
        $disabledBucketCount,
        (int) $pdo->query('SELECT COUNT(*) FROM api_rate_limit_buckets')->fetchColumn(),
        'Disabled API with keyring consumed rate-limit buckets.',
    );

    $service->setApiEnabled(true, $adminId);
    $missing = api_http_request($port, 'GET', '/api/v1');
    $malformed = api_http_request($port, 'GET', '/api/v1', ['Authorization' => 'bearer malformed']);
    $cookieOnly = api_http_request($port, 'GET', '/api/v1', ['Cookie' => 'cpe_api_token=ignored']);
    foreach ([$missing, $malformed, $cookieOnly] as $denied) {
        api_http_same(401, $denied['status'], 'Invalid API credential source was not denied.');
        api_http_assert_boundary($denied);
        api_http_same(['code' => 'invalid_credentials', 'message' => 'Valid API credentials are required.'], api_http_error_shape($denied), 'API credential denial leaked classification.');
    }
    $headUnauthorized = api_http_request($port, 'HEAD', '/api/v1');
    api_http_same(401, $headUnauthorized['status'], 'Unauthorized API HEAD did not return 401.');
    api_http_same('', $headUnauthorized['body'], 'Unauthorized API HEAD returned a body.');
    api_http_same(
        'Bearer realm="Campus Placement Engine API"',
        (string) ($headUnauthorized['headers']['www-authenticate'][0] ?? ''),
        'Unauthorized API HEAD omitted WWW-Authenticate.',
    );

    $queryCredential = api_http_request($port, 'GET', '/api/v1?access_token=ignored', [], '');
    api_http_same(400, $queryCredential['status'], 'Query-string credentials were not rejected as an unknown query.');
    api_http_same('invalid_query', api_http_error_shape($queryCredential)['code'], 'Query-string credential rejection differs.');
    api_http_assert_audit_classification(
        $pdo,
        $queryCredential,
        'api.v1.root',
        '',
        400,
        'QUERY_INVALID',
        'Known-route invalid query',
    );

    $root = api_http_request($port, 'GET', '/api/v1', $authorization);
    api_http_same(200, $root['status'], 'Authenticated API root failed.');
    api_http_assert_boundary($root);
    $rootJson = api_http_json($root);
    api_http_keys($rootJson, ['data', 'meta'], 'API root envelope differs.');
    api_http_keys($rootJson['data'], ['name', 'resources', 'version'], 'API root service keys differ.');
    api_http_same(['opportunities', 'applications'], $rootJson['data']['resources'], 'API root resources differ.');

    $wrongScope = api_http_request(
        $port,
        'GET',
        '/api/v1/applications',
        ['Authorization' => 'Bearer ' . $opportunitiesOnly['token']],
    );
    api_http_same(403, $wrongScope['status'], 'Missing exact API scope was not denied.');
    api_http_same('insufficient_scope', api_http_error_shape($wrongScope)['code'], 'Missing-scope error differs.');

    $pdo->exec("UPDATE module_installations SET enabled = 0 WHERE module_key = 'placement'");
    Portal::reset();
    $moduleDisabled = api_http_request($port, 'GET', '/api/v1/opportunities', $authorization);
    api_http_same(403, $moduleDisabled['status'], 'Disabled Placement module did not fail API scope policy closed.');
    $pdo->exec("UPDATE module_installations SET enabled = 1 WHERE module_key = 'placement'");
    Portal::reset();

    $opportunities = api_http_request($port, 'GET', '/api/v1/opportunities?limit=100', $authorization);
    api_http_same(200, $opportunities['status'], 'Opportunity collection failed.');
    api_http_assert_boundary($opportunities);
    $opportunityJson = api_http_json($opportunities);
    api_http_keys($opportunityJson, ['data', 'meta', 'page'], 'Opportunity collection envelope differs.');
    api_http_keys($opportunityJson['page'], ['has_more', 'next_cursor'], 'Opportunity page shape differs.');
    api_http_assert($opportunityJson['data'] !== [], 'Opportunity collection is empty.');
    $opportunity = $opportunityJson['data'][0];
    api_http_keys(
        $opportunity,
        ['id', 'cycle_id', 'organization_id', 'organization_code', 'organization_name', 'opportunity_key', 'title', 'status', 'created_at', 'updated_at'],
        'Opportunity public field allowlist differs.',
    );
    api_http_assert(!str_contains(json_encode($opportunityJson, JSON_THROW_ON_ERROR), $cross['opportunity']), 'Cross-institution opportunity entered the collection.');

    $applications = api_http_request($port, 'GET', '/api/v1/applications?limit=100', $authorization);
    api_http_same(200, $applications['status'], 'Application collection failed.');
    $applicationJson = api_http_json($applications);
    api_http_assert($applicationJson['data'] !== [], 'Application collection is empty.');
    $application = $applicationJson['data'][0];
    api_http_keys(
        $application,
        ['id', 'participant_id', 'opportunity_id', 'status', 'aggregate_version', 'created_at', 'updated_at'],
        'Application public field allowlist differs.',
    );
    $applicationEncoded = json_encode($applicationJson, JSON_THROW_ON_ERROR);
    api_http_assert(!str_contains($applicationEncoded, $cross['application']), 'Cross-institution application entered the collection.');
    foreach (['candidate', 'name', 'email', 'external_id', 'program', 'tags', 'accommodation', 'notes', 'waitlist', 'offer', 'custom_fields', 'legacy'] as $forbidden) {
        api_http_assert(!str_contains(strtolower($applicationEncoded), '"' . $forbidden . '"'), 'Application response exposed forbidden field ' . $forbidden . '.');
    }

    $opportunityId = (string) $opportunity['id'];
    $opportunityItem = api_http_request($port, 'GET', '/api/v1/opportunities/' . $opportunityId, $authorization);
    api_http_same(200, $opportunityItem['status'], 'Opportunity item failed.');
    $itemJson = api_http_json($opportunityItem);
    api_http_same($opportunity, $itemJson['data'], 'Opportunity list and item projections differ.');
    $etag = (string) ($opportunityItem['headers']['etag'][0] ?? '');
    api_http_assert(preg_match('/\A"[a-f0-9]{64}"\z/D', $etag) === 1, 'Opportunity ETag is invalid.');
    $notModified = api_http_request($port, 'GET', '/api/v1/opportunities/' . $opportunityId, [...$authorization, 'If-None-Match' => $etag]);
    api_http_same(304, $notModified['status'], 'Matching ETag did not return 304.');
    api_http_same('', $notModified['body'], '304 response returned a body.');
    api_http_assert_boundary($notModified, false);
    api_http_same($etag, (string) ($notModified['headers']['etag'][0] ?? ''), '304 response changed its ETag.');

    $itemHead = api_http_request($port, 'HEAD', '/api/v1/opportunities/' . $opportunityId, $authorization);
    api_http_same(200, $itemHead['status'], 'Opportunity HEAD failed.');
    api_http_same('', $itemHead['body'], 'Opportunity HEAD returned a body.');
    api_http_assert_boundary($itemHead);
    api_http_same($etag, (string) ($itemHead['headers']['etag'][0] ?? ''), 'Opportunity HEAD ETag differs.');
    api_http_assert((int) ($itemHead['headers']['content-length'][0] ?? 0) > 0, 'Opportunity HEAD omitted representation length.');
    $collectionHead = api_http_request($port, 'HEAD', '/api/v1/applications?limit=1', $authorization);
    api_http_same(200, $collectionHead['status'], 'Application collection HEAD failed.');
    api_http_same('', $collectionHead['body'], 'Application collection HEAD returned a body.');

    foreach ([
        ['/api/v1/opportunities/' . $cross['opportunity'], 'opportunity'],
        ['/api/v1/applications/' . $cross['application'], 'application'],
    ] as [$path, $label]) {
        $crossItem = api_http_request($port, 'GET', $path, $authorization);
        api_http_same(404, $crossItem['status'], 'Cross-institution ' . $label . ' item was visible.');
        api_http_same('not_found', api_http_error_shape($crossItem)['code'], 'Cross-institution item denial differs.');
    }

    $commandTarget = $pdo->query(
        "SELECT application.id, application.public_id, application.current_status,
                transition.transition_key, transition.to_state_key
         FROM applications application
         JOIN placement_cycle_participants participant ON participant.id = application.participant_id
         JOIN placement_cycles cycle ON cycle.id = participant.cycle_id
         JOIN workflow_instances instance ON instance.application_id = application.id
         JOIN workflow_transitions transition
           ON transition.workflow_version_id = instance.workflow_version_id
          AND transition.from_state_key = application.current_status
          AND transition.is_correction = 0
          AND transition.required_capability = 'placement.application.transition'
         WHERE cycle.institution_id = (SELECT id FROM institutions WHERE slug = 'default')
           AND application.opportunity_id IS NOT NULL
         ORDER BY application.id LIMIT 1",
    )->fetch(PDO::FETCH_ASSOC);
    api_http_assert(is_array($commandTarget), 'HTTP command fixture has no ordinary transition target.');
    $commandPath = '/api/v1/applications/' . $commandTarget['public_id'] . '/transitions';
    $commandItem = api_http_request(
        $port,
        'GET',
        '/api/v1/applications/' . $commandTarget['public_id'],
        $authorization,
    );
    api_http_same(200, $commandItem['status'], 'HTTP command target GET failed.');
    $commandBeforePayload = api_http_json($commandItem);
    $commandBeforeEtag = (string) ($commandItem['headers']['etag'][0] ?? '');
    api_http_assert(
        preg_match('/\A"[a-f0-9]{64}"\z/D', $commandBeforeEtag) === 1,
        'HTTP command target GET omitted a strong ETag.',
    );
    $commandBody = json_encode([
        'transition_key' => (string) $commandTarget['transition_key'],
        'target_status' => (string) $commandTarget['to_state_key'],
        'note' => 'HTTP governed transition',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $commandKey = str_repeat('a', 32);
    $commandHeaders = [
        ...$authorization,
        'Idempotency-Key' => $commandKey,
        'If-Match' => $commandBeforeEtag,
    ];

    foreach ([
        ['GET', $commandPath],
        ['HEAD', $commandPath],
        ['OPTIONS', $commandPath],
    ] as [$unsupportedMethod, $unsupportedPath]) {
        $unsupported = api_http_request($port, $unsupportedMethod, $unsupportedPath, $authorization);
        api_http_same(405, $unsupported['status'], 'Transition resource accepted an unsupported method.');
        api_http_same('POST', (string) ($unsupported['headers']['allow'][0] ?? ''), 'Transition Allow header differs.');
        if ($unsupportedMethod === 'HEAD') {
            api_http_same('', $unsupported['body'], 'Transition HEAD 405 returned a body.');
        }
    }
    $postItem = api_http_request(
        $port,
        'POST',
        '/api/v1/applications/' . $commandTarget['public_id'],
        $commandHeaders,
        $commandBody,
    );
    api_http_same(405, $postItem['status'], 'Application item accepted POST outside the transition resource.');
    api_http_same('GET, HEAD', (string) ($postItem['headers']['allow'][0] ?? ''), 'Application item Allow header differs.');

    $crossCommand = api_http_request(
        $port,
        'POST',
        '/api/v1/applications/' . $cross['application'] . '/transitions',
        [
            ...$authorization,
            'Idempotency-Key' => str_repeat('b', 32),
            'If-Match' => '"' . str_repeat('1', 64) . '"',
        ],
        $commandBody,
    );
    api_http_same(404, $crossCommand['status'], 'Cross-institution application command was distinguishable.');
    api_http_same('not_found', api_http_error_shape($crossCommand)['code'], 'Cross-institution command error differs.');
    $malformedCommand = api_http_request(
        $port,
        'POST',
        '/api/v1/applications/application_bad/transitions',
        [
            ...$authorization,
            'Idempotency-Key' => str_repeat('c', 32),
            'If-Match' => '"' . str_repeat('1', 64) . '"',
        ],
        $commandBody,
    );
    api_http_same(404, $malformedCommand['status'], 'Malformed application public ID did not use item 404 semantics.');

    $parserFailures = [
        api_http_request($port, 'POST', $commandPath . '?unknown=1', $commandHeaders, $commandBody),
        api_http_request($port, 'POST', $commandPath, [...$commandHeaders, 'Content-Type' => 'text/plain'], $commandBody),
        api_http_request($port, 'POST', $commandPath, [...$authorization, 'If-Match' => $commandBeforeEtag], $commandBody),
        api_http_request($port, 'POST', $commandPath, [...$authorization, 'Idempotency-Key' => str_repeat('d', 32)], $commandBody),
        api_http_request(
            $port,
            'POST',
            $commandPath,
            [...$commandHeaders, 'If-Match' => 'W/' . $commandBeforeEtag],
            $commandBody,
        ),
        api_http_request(
            $port,
            'POST',
            $commandPath,
            $commandHeaders,
            '{"transition_key":"x","transition_key":"y","target_status":"z"}',
        ),
        api_http_request(
            $port,
            'POST',
            $commandPath,
            $commandHeaders,
            '{"transition_key":"x","target_status":"y","unknown":"z"}',
        ),
    ];
    api_http_same(
        [400, 415, 400, 428, 400, 400, 400],
        array_column($parserFailures, 'status'),
        'Strict HTTP command parser status map differs.',
    );
    foreach ($parserFailures as $parserFailure) {
        api_http_assert_boundary($parserFailure);
    }
    $oversizedCommand = api_http_request(
        $port,
        'POST',
        $commandPath,
        $commandHeaders,
        '{"transition_key":"x","target_status":"y","note":"' . str_repeat('q', 17000) . '"}',
    );
    api_http_same(413, $oversizedCommand['status'], 'Oversized command body was accepted.');

    $wrongCommandScope = api_http_request(
        $port,
        'POST',
        $commandPath,
        [
            'Authorization' => 'Bearer ' . $opportunitiesOnly['token'],
            'Idempotency-Key' => str_repeat('e', 32),
            'If-Match' => $commandBeforeEtag,
        ],
        $commandBody,
    );
    api_http_same(403, $wrongCommandScope['status'], 'Missing applications.transition scope was not denied.');
    api_http_same('insufficient_scope', api_http_error_shape($wrongCommandScope)['code'], 'Command scope error differs.');

    $commandBefore = api_http_transition_evidence($pdo, (int) $commandTarget['id']);
    $commandSuccess = api_http_request($port, 'POST', $commandPath, $commandHeaders, $commandBody);
    api_http_same(200, $commandSuccess['status'], 'Governed application transition command failed.');
    api_http_assert_boundary($commandSuccess);
    $commandSuccessPayload = api_http_json($commandSuccess);
    api_http_keys($commandSuccessPayload, ['data', 'meta'], 'Command success envelope differs.');
    api_http_keys(
        $commandSuccessPayload['data'],
        ['id', 'participant_id', 'opportunity_id', 'status', 'aggregate_version', 'created_at', 'updated_at'],
        'Command application field allowlist differs.',
    );
    $commandResponseRequestId = (string) ($commandSuccess['headers']['x-request-id'][0] ?? '');
    api_http_same(
        $commandResponseRequestId,
        (string) ($commandSuccessPayload['meta']['request_id'] ?? ''),
        'Command response body and header request IDs differ.',
    );
    $commandResponseEtag = (string) ($commandSuccess['headers']['etag'][0] ?? '');
    api_http_assert(
        preg_match('/\A"[a-f0-9]{64}"\z/D', $commandResponseEtag) === 1
            && !hash_equals($commandBeforeEtag, $commandResponseEtag),
        'Command response ETag did not reflect the new public representation.',
    );
    api_http_same(
        (string) $commandTarget['to_state_key'],
        (string) ($commandSuccessPayload['data']['status'] ?? ''),
        'Command response returned the wrong status.',
    );
    $commandGetAfter = api_http_request(
        $port,
        'GET',
        '/api/v1/applications/' . $commandTarget['public_id'],
        $authorization,
    );
    api_http_same($commandResponseEtag, (string) ($commandGetAfter['headers']['etag'][0] ?? ''), 'GET and command ETags differ.');
    $commandResponseData = $commandSuccessPayload['data'];
    $commandGetData = api_http_json($commandGetAfter)['data'];
    ksort($commandResponseData, SORT_STRING);
    ksort($commandGetData, SORT_STRING);
    api_http_same(
        $commandResponseData,
        $commandGetData,
        'GET and command application representations differ.',
    );
    $commandAfter = api_http_transition_evidence($pdo, (int) $commandTarget['id']);
    api_http_same($commandBefore['version'] + 1, $commandAfter['version'], 'HTTP command version increment differs.');
    foreach (['events', 'workflow', 'outbox', 'audits'] as $evidenceKey) {
        api_http_same(
            $commandBefore[$evidenceKey] + 1,
            $commandAfter[$evidenceKey],
            'HTTP command evidence differs for ' . $evidenceKey . '.',
        );
    }
    $fullAccountIdQuery = $pdo->prepare('SELECT id FROM api_service_accounts WHERE public_id = ?');
    $fullAccountIdQuery->execute([$full['service_account_id']]);
    $fullAccountId = (int) $fullAccountIdQuery->fetchColumn();
    $fullAccountIdQuery->closeCursor();
    foreach (['events', 'workflow_transition_events'] as $table) {
        $commandActor = $pdo->prepare(
            "SELECT actor_user_id, actor_service_account_id, actor_role
             FROM {$table} WHERE application_id = ? ORDER BY id DESC LIMIT 1",
        );
        $commandActor->execute([(int) $commandTarget['id']]);
        $commandActorRow = $commandActor->fetch(PDO::FETCH_ASSOC);
        $commandActor->closeCursor();
        api_http_same(
            [
                'actor_user_id' => null,
                'actor_service_account_id' => $fullAccountId,
                'actor_role' => 'service_account',
            ],
            $commandActorRow,
            'HTTP command attribution differs in ' . $table . '.',
        );
    }
    $commandAudit = $pdo->prepare(
        'SELECT route_class, required_scope, outcome, status_code, detail_code
         FROM api_request_audit_events WHERE request_id = ? ORDER BY id DESC LIMIT 1',
    );
    $commandAudit->execute([$commandResponseRequestId]);
    $commandAuditRow = $commandAudit->fetch(PDO::FETCH_ASSOC);
    $commandAudit->closeCursor();
    api_http_same(
        [
            'route_class' => 'api.v1.applications.transition',
            'required_scope' => 'applications.transition',
            'outcome' => 'succeeded',
            'status_code' => 200,
            'detail_code' => 'OK',
        ],
        array_map(
            static fn (mixed $value): int|string => is_numeric($value) && (string) $value === '200'
                ? 200
                : (string) $value,
            $commandAuditRow,
        ),
        'HTTP command request audit classification differs.',
    );
    $browserCommandKey = $pdo->prepare('SELECT COUNT(*) FROM idempotency_keys WHERE key = ?');
    $browserCommandKey->execute([$commandKey]);
    api_http_same(0, (int) $browserCommandKey->fetchColumn(), 'HTTP command wrote browser form idempotency state.');
    $browserCommandKey->closeCursor();

    $commandReplay = api_http_request($port, 'POST', $commandPath, $commandHeaders, $commandBody);
    api_http_same(
        200,
        $commandReplay['status'],
        'Identical HTTP command replay failed: ' . $commandReplay['body'] . ' state=' . json_encode(
            $pdo->query(
                'SELECT operation, key_version, lifecycle_state, response_status, response_etag
                 FROM api_command_idempotency_keys ORDER BY id',
            )->fetchAll(PDO::FETCH_ASSOC),
            JSON_THROW_ON_ERROR,
        ),
    );
    api_http_same($commandSuccess['body'], $commandReplay['body'], 'HTTP command replay body changed.');
    api_http_same($commandResponseEtag, (string) ($commandReplay['headers']['etag'][0] ?? ''), 'HTTP command replay ETag changed.');
    api_http_same(
        $commandResponseRequestId,
        (string) ($commandReplay['headers']['x-request-id'][0] ?? ''),
        'HTTP command replay did not retain the original response request ID.',
    );
    api_http_same(
        $commandAfter,
        api_http_transition_evidence($pdo, (int) $commandTarget['id']),
        'HTTP command replay duplicated domain evidence.',
    );
    api_http_same(
        1,
        (int) $pdo->query(
            "SELECT COUNT(*) FROM api_request_audit_events
             WHERE route_class = 'api.v1.applications.transition'
               AND detail_code = 'IDEMPOTENT_REPLAY'",
        )->fetchColumn(),
        'HTTP command replay audit classification differs.',
    );

    $changedCommandBody = json_encode([
        'transition_key' => (string) $commandTarget['transition_key'],
        'target_status' => (string) $commandTarget['to_state_key'],
        'note' => 'changed idempotent request',
    ], JSON_THROW_ON_ERROR);
    $changedCommand = api_http_request($port, 'POST', $commandPath, $commandHeaders, $changedCommandBody);
    api_http_same(409, $changedCommand['status'], 'Same command key with changed body was accepted.');
    api_http_same('idempotency_conflict', api_http_error_shape($changedCommand)['code'], 'Command key conflict error differs.');
    $staleCommand = api_http_request(
        $port,
        'POST',
        $commandPath,
        [...$authorization, 'Idempotency-Key' => str_repeat('f', 32), 'If-Match' => $commandBeforeEtag],
        $commandBody,
    );
    api_http_same(409, $staleCommand['status'], 'Stale command precondition was accepted.');
    api_http_same('transition_conflict', api_http_error_shape($staleCommand)['code'], 'Stale command error differs.');

    $correctionRow = $pdo->prepare(
        'SELECT transition.transition_key, transition.to_state_key
         FROM applications application
         JOIN workflow_transitions transition
           ON transition.workflow_version_id = application.workflow_version_id
          AND transition.from_state_key = application.current_status
          AND transition.is_correction = 1
         WHERE application.id = ? ORDER BY transition.id LIMIT 1',
    );
    $correctionRow->execute([(int) $commandTarget['id']]);
    $correction = $correctionRow->fetch(PDO::FETCH_ASSOC);
    $correctionRow->closeCursor();
    api_http_assert(is_array($correction), 'HTTP command fixture has no correction transition.');
    $correctionBody = json_encode([
        'transition_key' => (string) $correction['transition_key'],
        'target_status' => (string) $correction['to_state_key'],
    ], JSON_THROW_ON_ERROR);
    $correctionDenied = api_http_request(
        $port,
        'POST',
        $commandPath,
        [...$authorization, 'Idempotency-Key' => str_repeat('1', 32), 'If-Match' => $commandResponseEtag],
        $correctionBody,
    );
    api_http_same(422, $correctionDenied['status'], 'HTTP command exposed a correction transition.');
    api_http_same('transition_rejected', api_http_error_shape($correctionDenied)['code'], 'Command domain-rule error differs.');

    $scopeDelete = $pdo->prepare(
        'DELETE FROM api_service_account_scopes WHERE service_account_id = ? AND scope = ?',
    );
    $scopeDelete->execute([$fullAccountId, 'applications.transition']);
    $scopeDrift = api_http_request(
        $port,
        'POST',
        $commandPath,
        [...$authorization, 'Idempotency-Key' => str_repeat('2', 32), 'If-Match' => $commandResponseEtag],
        $correctionBody,
    );
    api_http_same(403, $scopeDrift['status'], 'Command scope drift did not fail closed.');
    $pdo->prepare(
        'INSERT INTO api_service_account_scopes
         (service_account_id, scope, created_by_user_id, created_at) VALUES (?, ?, ?, ?)',
    )->execute([$fullAccountId, 'applications.transition', $adminId, cpe_now()]);

    $auditFailureTargetQuery = $pdo->prepare(
        "SELECT application.id, application.public_id, transition.transition_key,
                transition.to_state_key
         FROM applications application
         JOIN placement_cycle_participants participant ON participant.id = application.participant_id
         JOIN placement_cycles cycle ON cycle.id = participant.cycle_id
         JOIN workflow_instances instance ON instance.application_id = application.id
         JOIN workflow_transitions transition
           ON transition.workflow_version_id = instance.workflow_version_id
          AND transition.from_state_key = application.current_status
          AND transition.is_correction = 0
          AND transition.required_capability = 'placement.application.transition'
         WHERE cycle.institution_id = (SELECT id FROM institutions WHERE slug = 'default')
           AND application.opportunity_id IS NOT NULL AND application.id <> ?
         ORDER BY application.id LIMIT 1",
    );
    $auditFailureTargetQuery->execute([(int) $commandTarget['id']]);
    $auditFailureTarget = $auditFailureTargetQuery->fetch(PDO::FETCH_ASSOC);
    $auditFailureTargetQuery->closeCursor();
    api_http_assert(is_array($auditFailureTarget), 'HTTP command fixture has no post-commit audit target.');
    $auditFailureItem = api_http_request(
        $port,
        'GET',
        '/api/v1/applications/' . $auditFailureTarget['public_id'],
        $authorization,
    );
    api_http_same(200, $auditFailureItem['status'], 'Post-commit audit target GET failed.');
    $auditFailureEtag = (string) ($auditFailureItem['headers']['etag'][0] ?? '');
    $auditFailureBody = json_encode([
        'transition_key' => (string) $auditFailureTarget['transition_key'],
        'target_status' => (string) $auditFailureTarget['to_state_key'],
    ], JSON_THROW_ON_ERROR);
    $auditFailureHeaders = [
        ...$authorization,
        'Idempotency-Key' => str_repeat('3', 32),
        'If-Match' => $auditFailureEtag,
    ];
    $auditFailureEvidence = api_http_transition_evidence($pdo, (int) $auditFailureTarget['id']);
    api_http_install_audit_failure($pdo, $postgres);
    try {
        $committedDespiteAudit = api_http_request(
            $port,
            'POST',
            '/api/v1/applications/' . $auditFailureTarget['public_id'] . '/transitions',
            $auditFailureHeaders,
            $auditFailureBody,
        );
        api_http_same(200, $committedDespiteAudit['status'], 'Post-commit request-audit failure replaced command success.');
        $replayedDespiteAudit = api_http_request(
            $port,
            'POST',
            '/api/v1/applications/' . $auditFailureTarget['public_id'] . '/transitions',
            $auditFailureHeaders,
            $auditFailureBody,
        );
        api_http_same(200, $replayedDespiteAudit['status'], 'Post-commit request-audit failure replaced exact replay.');
        api_http_same($committedDespiteAudit['body'], $replayedDespiteAudit['body'], 'Audit-failed replay body changed.');
        api_http_same(
            (string) ($committedDespiteAudit['headers']['x-request-id'][0] ?? ''),
            (string) ($replayedDespiteAudit['headers']['x-request-id'][0] ?? ''),
            'Audit-failed replay request ID changed.',
        );
    } finally {
        api_http_drop_audit_failure($pdo, $postgres);
    }
    $auditFailureAfter = api_http_transition_evidence($pdo, (int) $auditFailureTarget['id']);
    api_http_same($auditFailureEvidence['version'] + 1, $auditFailureAfter['version'], 'Audit-failed command did not commit once.');
    foreach (['events', 'workflow', 'outbox', 'audits'] as $evidenceKey) {
        api_http_same(
            $auditFailureEvidence[$evidenceKey] + 1,
            $auditFailureAfter[$evidenceKey],
            'Audit-failed command evidence differs for ' . $evidenceKey . '.',
        );
    }

    $auditSafeCommandRows = json_encode(
        $pdo->query(
            "SELECT * FROM api_request_audit_events
             WHERE route_class = 'api.v1.applications.transition'",
        )->fetchAll(PDO::FETCH_ASSOC),
        JSON_THROW_ON_ERROR,
    );
    foreach ([$commandKey, $commandBody, $commandPath, $full['token']] as $forbiddenCommandAuditValue) {
        api_http_assert(
            !str_contains($auditSafeCommandRows, $forbiddenCommandAuditValue),
            'Command request audit retained clear key, body, path, or token material.',
        );
    }

    $firstPage = api_http_request($port, 'GET', '/api/v1/opportunities?limit=1', $authorization);
    $firstPageJson = api_http_json($firstPage);
    api_http_same(true, $firstPageJson['page']['has_more'], 'Opportunity pagination did not produce a next page.');
    $cursor = (string) $firstPageJson['page']['next_cursor'];
    api_http_assert($cursor !== '', 'Opportunity pagination omitted its cursor.');
    $secondPage = api_http_request($port, 'GET', '/api/v1/opportunities?limit=1&cursor=' . rawurlencode($cursor), $authorization);
    api_http_same(200, $secondPage['status'], 'Valid opportunity cursor failed.');
    $secondPageJson = api_http_json($secondPage);
    api_http_assert($secondPageJson['data'][0]['id'] !== $firstPageJson['data'][0]['id'], 'Opportunity cursor repeated its last tuple.');
    $tampered = substr($cursor, 0, -1) . (substr($cursor, -1) === 'A' ? 'B' : 'A');
    $tamperResponse = api_http_request($port, 'GET', '/api/v1/opportunities?cursor=' . rawurlencode($tampered), $authorization);
    api_http_same(400, $tamperResponse['status'], 'Tampered cursor was accepted.');
    api_http_same('invalid_cursor', api_http_error_shape($tamperResponse)['code'], 'Tampered cursor error differs.');
    $wrongRouteCursor = api_http_request($port, 'GET', '/api/v1/applications?cursor=' . rawurlencode($cursor), $authorization);
    api_http_same(400, $wrongRouteCursor['status'], 'Cross-route cursor was accepted.');
    $incompatible = api_http_request($port, 'GET', '/api/v1/opportunities?cursor=' . rawurlencode($cursor) . '&updated_after=2020-01-01T00%3A00%3A00Z', $authorization);
    api_http_same(400, $incompatible['status'], 'Cursor and updated_after were accepted together.');
    $decodedCursor = (new ApiCursorCodec($keyring))->decode($cursor, (string) $pdo->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn(), 'api.v1.opportunities.list', 'opportunities');
    api_http_assert(is_array($decodedCursor['snapshot']), 'Valid cursor could not be decoded for its institution.');
    try {
        (new ApiCursorCodec($keyring))->decode($cursor, $cross['institution'], 'api.v1.opportunities.list', 'opportunities');
        throw new RuntimeException('Cross-institution cursor was accepted.');
    } catch (\App\Api\Http\ApiHttpException) {
    }

    $cycleId = (int) $pdo->query("SELECT id FROM placement_cycles WHERE institution_id = (SELECT id FROM institutions WHERE slug = 'default') ORDER BY id LIMIT 1")->fetchColumn();
    $organizationId = (int) $pdo->query("SELECT id FROM organizations WHERE institution_id = (SELECT id FROM institutions WHERE slug = 'default') ORDER BY id LIMIT 1")->fetchColumn();
    $snapshotNewId = 'opportunity_' . str_repeat('f', 32);
    $pdo->prepare('INSERT INTO placement_opportunities (public_id, cycle_id, organization_id, opportunity_key, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$snapshotNewId, $cycleId, $organizationId, 'snapshot-later', 'Snapshot Later', 'open', '2099-01-01 00:00:00', '2099-01-01 00:00:00']);
    $continuation = api_http_request($port, 'GET', '/api/v1/opportunities?limit=100&cursor=' . rawurlencode($cursor), $authorization);
    api_http_assert(!str_contains($continuation['body'], $snapshotNewId), 'Cursor continuation crossed its snapshot upper bound.');
    $freshCollection = api_http_request($port, 'GET', '/api/v1/opportunities?limit=100', $authorization);
    api_http_assert(str_contains($freshCollection['body'], $snapshotNewId), 'Fresh collection did not recover a post-snapshot opportunity.');

    $updatedAfter = api_http_request($port, 'GET', '/api/v1/opportunities?updated_after=2098-01-01T00%3A00%3A00Z', $authorization);
    $updatedJson = api_http_json($updatedAfter);
    api_http_same([$snapshotNewId], array_column($updatedJson['data'], 'id'), 'updated_after filtering differs.');
    foreach ([
        '/api/v1/opportunities?limit=0',
        '/api/v1/opportunities?limit=101',
        '/api/v1/opportunities?limit=1&limit=2',
        '/api/v1/opportunities?unknown=1',
        '/api/v1/opportunities?updated_after=2020-01-01T00%3A00%3A00%2B00%3A00',
        '/api/v1/opportunities?cursor[]=x',
    ] as $badQuery) {
        $response = api_http_request($port, 'GET', $badQuery, $authorization);
        api_http_same(400, $response['status'], 'Invalid bounded API query was accepted.');
    }

    foreach ([['POST', '/api/v1/opportunities'], ['OPTIONS', '/api/v1'], ['DELETE', '/api/v1/applications/' . $application['id']]] as [$method, $path]) {
        $response = api_http_request($port, $method, $path, $authorization);
        api_http_same(405, $response['status'], 'Unsupported API method was not rejected.');
        api_http_same('GET, HEAD', (string) ($response['headers']['allow'][0] ?? ''), '405 response Allow header differs.');
        api_http_assert_boundary($response);
        if ($method === 'POST') {
            api_http_assert_audit_classification(
                $pdo,
                $response,
                'api.v1.opportunities.list',
                'opportunities.read',
                405,
                'METHOD_NOT_ALLOWED',
                'Known-route unsupported method',
            );
        }
    }
    $unknown = api_http_request($port, 'GET', '/api/v1/candidates', $authorization);
    api_http_same(404, $unknown['status'], 'Unknown or forbidden API resource was not 404.');
    api_http_assert_audit_classification(
        $pdo,
        $unknown,
        'api.v1.unknown',
        '',
        404,
        'ROUTE_NOT_FOUND',
        'Truly unknown route',
    );
    $bodyRejected = api_http_request($port, 'GET', '/api/v1/opportunities', $authorization, '{}');
    api_http_same(400, $bodyRejected['status'], 'GET request body was accepted.');
    api_http_same('request_body_not_allowed', api_http_error_shape($bodyRejected)['code'], 'Body rejection differs.');
    api_http_assert_audit_classification(
        $pdo,
        $bodyRejected,
        'api.v1.opportunities.list',
        'opportunities.read',
        400,
        'BODY_NOT_ALLOWED',
        'Known-route request body',
    );
    $largeQuery = api_http_request($port, 'GET', '/api/v1/opportunities?updated_after=' . str_repeat('x', 2100), $authorization);
    api_http_same(414, $largeQuery['status'], 'Oversized API query was accepted.');
    $largeHeader = api_http_request($port, 'GET', '/api/v1', [...$authorization, 'X-Oversized' => str_repeat('x', 5000)]);
    api_http_same(431, $largeHeader['status'], 'Oversized API header was accepted.');
    $largeQueryHead = api_http_request($port, 'HEAD', '/api/v1/opportunities?updated_after=' . str_repeat('x', 2100), $authorization);
    api_http_same(414, $largeQueryHead['status'], 'Oversized API HEAD query was accepted.');
    api_http_same('', $largeQueryHead['body'], 'Oversized API HEAD query returned a body.');
    $largeHeaderHead = api_http_request($port, 'HEAD', '/api/v1', [...$authorization, 'X-Oversized' => str_repeat('x', 5000)]);
    api_http_same(431, $largeHeaderHead['status'], 'Oversized API HEAD header was accepted.');
    api_http_same('', $largeHeaderHead['body'], 'Oversized API HEAD header returned a body.');

    $service->setAccountEnabled($revocable['service_account_id'], false, $adminId);
    $disabledAccount = api_http_request($port, 'GET', '/api/v1/opportunities', ['Authorization' => 'Bearer ' . $revocable['token']]);
    api_http_same(401, $disabledAccount['status'], 'Disabled service account remained usable.');
    $service->setAccountEnabled($revocable['service_account_id'], true, $adminId);
    $service->revokeToken($revocable['token_lookup_id'], $adminId);
    $revokedToken = api_http_request($port, 'GET', '/api/v1/opportunities', ['Authorization' => 'Bearer ' . $revocable['token']]);
    api_http_same(401, $revokedToken['status'], 'Revoked token remained usable.');

    $institutionId = (int) $pdo->query("SELECT id FROM institutions WHERE slug = 'default'")->fetchColumn();
    $institutionPublicId = (string) $pdo->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn();
    $preAuthLimiter = new ApiRateLimiter($pdo, $keyring);
    $preAuthAggregateFirst = $preAuthLimiter->consumePreAuth(
        '198.51.100.10',
        'req_' . str_repeat('1', 32),
        61,
        ['institution' => 1, 'source' => ApiRateLimiter::PRE_AUTH_LIMITS['source']],
    );
    $preAuthAggregateSecond = $preAuthLimiter->consumePreAuth(
        '198.51.100.11',
        'req_' . str_repeat('2', 32),
        61,
        ['institution' => 1, 'source' => ApiRateLimiter::PRE_AUTH_LIMITS['source']],
    );
    $preAuthAggregateThird = $preAuthLimiter->consumePreAuth(
        '198.51.100.12',
        'req_' . str_repeat('3', 32),
        61,
        ['institution' => 1, 'source' => ApiRateLimiter::PRE_AUTH_LIMITS['source']],
    );
    api_http_same(true, $preAuthAggregateFirst['allowed'], 'Initial aggregate pre-auth request was denied.');
    api_http_same(false, $preAuthAggregateSecond['allowed'], 'Institution aggregate pre-auth ceiling was not enforced.');
    api_http_same('institution', $preAuthAggregateSecond['limited_dimension'], 'Aggregate pre-auth dimension differs.');
    api_http_same(true, $preAuthAggregateSecond['audit_threshold_crossing'], 'Aggregate pre-auth threshold was not marked once.');
    api_http_same(false, $preAuthAggregateThird['audit_threshold_crossing'], 'Aggregate pre-auth threshold was marked repeatedly.');
    $aggregateSourceBuckets = $pdo->prepare(
        'SELECT COUNT(*) FROM api_rate_limit_buckets
         WHERE institution_id = ? AND route_class = ? AND dimension = ? AND window_seconds = 61',
    );
    $aggregateSourceBuckets->execute([$institutionId, ApiRateLimiter::PRE_AUTH_ROUTE_CLASS, 'source']);
    api_http_same(1, (int) $aggregateSourceBuckets->fetchColumn(), 'Rotating peers created source buckets after the institution aggregate limit.');
    $aggregateSourceBuckets->closeCursor();
    unset($aggregateSourceBuckets);
    $windowEpoch = intdiv(time(), 60) * 60;
    $window = gmdate('Y-m-d H:i:s', $windowEpoch);
    $sourceKey = $keyring->sourceFingerprint('source|127.0.0.1', $institutionPublicId);
    $routeClass = 'api.v1.applications.item';
    $updateBucket = $pdo->prepare(
        'UPDATE api_rate_limit_buckets SET request_count = 300, updated_at = ?
         WHERE institution_id = ? AND dimension = ? AND bucket_key = ? AND route_class = ?
           AND window_started_at = ? AND window_seconds = 60',
    );
    $updateBucket->execute([cpe_now(), $institutionId, 'source', $sourceKey, $routeClass, $window]);
    if ($updateBucket->rowCount() === 0) {
        $pdo->prepare(
            'INSERT INTO api_rate_limit_buckets
             (institution_id, token_id, dimension, bucket_key, route_class, window_started_at,
              window_seconds, request_count, expires_at, created_at, updated_at)
             VALUES (?, NULL, ?, ?, ?, ?, 60, 300, ?, ?, ?)',
        )->execute([
            $institutionId,
            'source',
            $sourceKey,
            $routeClass,
            $window,
            gmdate('Y-m-d H:i:s', $windowEpoch + 120),
            cpe_now(),
            cpe_now(),
        ]);
    }
    $limited = api_http_request($port, 'GET', '/api/v1/applications/' . $application['id'], $authorization);
    api_http_same(429, $limited['status'], 'Atomic source rate limit was not enforced.');
    api_http_assert((int) ($limited['headers']['retry-after'][0] ?? 0) >= 1, 'Rate-limit response omitted Retry-After.');
    api_http_same('rate_limit_exceeded', api_http_error_shape($limited)['code'], 'Rate-limit error differs.');

    $forwarded = api_http_request($port, 'GET', '/api/v1', [...$authorization, 'X-Forwarded-For' => '203.0.113.99']);
    api_http_same(200, $forwarded['status'], 'Direct-peer source request failed.');
    $forwardedRequestId = (string) ($forwarded['headers']['x-request-id'][0] ?? '');
    $lastAudit = $pdo->query("SELECT request_id, source_fingerprint FROM api_request_audit_events WHERE route_class = 'api.v1.root' AND outcome = 'succeeded' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    api_http_assert(is_array($lastAudit), 'Successful API request audit was not retained.');
    api_http_same($forwardedRequestId, (string) $lastAudit['request_id'], 'Response and audit API request IDs do not correlate.');
    api_http_same($keyring->sourceFingerprint('127.0.0.1', $institutionPublicId), (string) $lastAudit['source_fingerprint'], 'API source fingerprint trusted a forwarded peer.');
    $auditJson = json_encode($pdo->query('SELECT * FROM api_request_audit_events')->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
    foreach ([$full['token'], $opportunitiesOnly['token'], '127.0.0.1', '203.0.113.99', '/api/v1/', 'updated_after'] as $secretOrRawValue) {
        api_http_assert(!str_contains($auditJson, $secretOrRawValue), 'API request audit retained a credential or raw request value.');
    }

    api_http_install_audit_failure($pdo, $postgres);
    $auditFailure = api_http_request($port, 'GET', '/api/v1', $authorization);
    api_http_same(503, $auditFailure['status'], 'Request audit failure silently allowed API success.');
    api_http_same('service_unavailable', api_http_error_shape($auditFailure)['code'], 'Audit failure response differs.');
    api_http_drop_audit_failure($pdo, $postgres);

    $preAuthWindowEpoch = intdiv(time(), 60) * 60;
    $preAuthSourceKey = $keyring->sourceFingerprint('preauth|source|127.0.0.1', $institutionPublicId);
    $preAuthSourceLimit = ApiRateLimiter::PRE_AUTH_LIMITS['source'];
    $preAuthBucket = $pdo->prepare(
        'INSERT INTO api_rate_limit_buckets
         (institution_id, token_id, dimension, bucket_key, route_class, window_started_at,
          window_seconds, request_count, expires_at, created_at, updated_at)
         VALUES (?, NULL, ?, ?, ?, ?, 60, ?, ?, ?, ?)
         ON CONFLICT(institution_id, dimension, bucket_key, route_class, window_started_at, window_seconds)
         DO UPDATE SET request_count = excluded.request_count, updated_at = excluded.updated_at',
    );
    $preAuthWindows = [];
    foreach ([$preAuthWindowEpoch, $preAuthWindowEpoch + 60] as $seedWindowEpoch) {
        $preAuthWindow = gmdate('Y-m-d H:i:s', $seedWindowEpoch);
        $preAuthWindows[] = $preAuthWindow;
        $preAuthBucket->execute([
            $institutionId,
            'source',
            $preAuthSourceKey,
            ApiRateLimiter::PRE_AUTH_ROUTE_CLASS,
            $preAuthWindow,
            $preAuthSourceLimit - 2,
            gmdate('Y-m-d H:i:s', $seedWindowEpoch + 120),
            cpe_now(),
            cpe_now(),
        ]);
    }
    $auditBeforePreAuth = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM api_request_audit_events')->fetchColumn();
    $invalidBeforeLimit = api_http_request($port, 'GET', '/api/v1', ['Authorization' => 'Bearer invalid-token']);
    $unknownBeforeLimit = api_http_request($port, 'GET', '/api/v1/candidates', ['Authorization' => 'Bearer invalid-token']);
    api_http_same(401, $invalidBeforeLimit['status'], 'Pre-auth gate rejected a request before its source threshold.');
    api_http_same(404, $unknownBeforeLimit['status'], 'Pre-auth gate changed an allowed unknown-route response.');
    api_http_same(
        $preAuthSourceLimit,
        api_http_pre_auth_source_count($pdo, $institutionId, $preAuthSourceKey, $preAuthWindows),
        'Pre-auth source bucket did not reach its threshold.',
    );

    api_http_install_audit_failure($pdo, $postgres);
    $thresholdAuditFailureOne = api_http_request($port, 'GET', '/api/v1', ['Authorization' => 'Bearer another-invalid-token']);
    api_http_same(503, $thresholdAuditFailureOne['status'], 'Failed threshold audit did not fail the request closed.');
    api_http_same('service_unavailable', api_http_error_shape($thresholdAuditFailureOne)['code'], 'Failed threshold audit error differs.');
    api_http_same(
        $preAuthSourceLimit,
        api_http_pre_auth_source_count($pdo, $institutionId, $preAuthSourceKey, $preAuthWindows),
        'Failed threshold audit committed its sentinel.',
    );
    $thresholdAuditFailureTwo = api_http_request($port, 'GET', '/api/v1/candidates');
    api_http_same(503, $thresholdAuditFailureTwo['status'], 'Repeated failed threshold audit bypassed fail-closed behavior.');
    api_http_same('service_unavailable', api_http_error_shape($thresholdAuditFailureTwo)['code'], 'Repeated threshold audit failure differs.');
    api_http_same(
        $preAuthSourceLimit,
        api_http_pre_auth_source_count($pdo, $institutionId, $preAuthSourceKey, $preAuthWindows),
        'Repeated failed threshold audit committed its sentinel.',
    );
    api_http_same(
        2,
        (int) $pdo->query('SELECT COUNT(*) FROM api_request_audit_events WHERE id > ' . $auditBeforePreAuth)->fetchColumn(),
        'Failed threshold audit retained a partial aggregate row.',
    );
    api_http_drop_audit_failure($pdo, $postgres);

    $thresholdLimited = api_http_request($port, 'GET', '/api/v1', ['Authorization' => 'Bearer another-invalid-token']);
    $suppressedUnknown = api_http_request($port, 'GET', '/api/v1/candidates');
    $suppressedInvalid = api_http_request($port, 'GET', '/api/v1', ['Authorization' => 'Bearer still-invalid']);
    foreach ([$thresholdLimited, $suppressedUnknown, $suppressedInvalid] as $preAuthLimited) {
        api_http_same(429, $preAuthLimited['status'], 'Pre-auth gate did not rate-limit invalid or unknown traffic.');
        api_http_assert((int) ($preAuthLimited['headers']['retry-after'][0] ?? 0) >= 1, 'Pre-auth 429 omitted Retry-After.');
        api_http_same(
            ['code' => 'rate_limit_exceeded', 'message' => 'The API rate limit has been exceeded.'],
            api_http_error_shape($preAuthLimited),
            'Pre-auth 429 disclosed route or credential classification.',
        );
    }
    $preAuthAudits = $pdo->query(
        'SELECT route_class, outcome, status_code, detail_code, source_fingerprint
         FROM api_request_audit_events WHERE id > ' . $auditBeforePreAuth . ' ORDER BY id',
    )->fetchAll(PDO::FETCH_ASSOC);
    api_http_same(3, count($preAuthAudits), 'Over-limit pre-auth traffic caused unbounded durable audit growth.');
    api_http_same(
        ['CREDENTIALS_INVALID', 'ROUTE_NOT_FOUND', 'PREAUTH_RATE_LIMITED_SOURCE'],
        array_column($preAuthAudits, 'detail_code'),
        'Pre-auth audit suppression or threshold aggregation differs.',
    );
    api_http_same(
        ApiRateLimiter::PRE_AUTH_ROUTE_CLASS,
        (string) $preAuthAudits[2]['route_class'],
        'Pre-auth threshold audit route class differs.',
    );
    api_http_same('rate_limited', (string) $preAuthAudits[2]['outcome'], 'Pre-auth threshold audit outcome differs.');
    api_http_same(429, (int) $preAuthAudits[2]['status_code'], 'Pre-auth threshold audit status differs.');
    foreach ($preAuthAudits as $preAuthAudit) {
        api_http_same(
            $keyring->sourceFingerprint('127.0.0.1', $institutionPublicId),
            (string) $preAuthAudit['source_fingerprint'],
            'Pre-auth audit did not retain only the keyed direct-peer fingerprint.',
        );
    }
    api_http_same(
        $preAuthSourceLimit + 1,
        api_http_pre_auth_source_count($pdo, $institutionId, $preAuthSourceKey, $preAuthWindows),
        'Pre-auth bucket did not cap its threshold marker.',
    );

    api_http_stop_server($server, $pipes);
    [$missingKeyServer, $missingKeyPipes, $missingKeyPort] = api_http_start_server(
        $projectRoot,
        $testRoot . '/missing-key-server.log',
        ['CPE_API_KEYRING' => null, 'CPE_API_ACTIVE_KEY_VERSION' => null],
    );
    $missingKey = api_http_request($missingKeyPort, 'GET', '/api/v1', $authorization);
    api_http_same(503, $missingKey['status'], 'Missing referenced API keyring did not fail readiness closed.');
    api_http_same('service_unavailable', api_http_error_shape($missingKey)['code'], 'Missing-keyring response differs.');
    api_http_assert_boundary($missingKey);
    $missingKeyCommand = api_http_request(
        $missingKeyPort,
        'POST',
        $commandPath,
        [
            ...$authorization,
            'Idempotency-Key' => str_repeat('4', 32),
            'If-Match' => $commandResponseEtag,
        ],
        $correctionBody,
    );
    api_http_same(503, $missingKeyCommand['status'], 'Missing command keyring did not fail closed.');

    $service->setApiEnabled(false, $adminId);
    $disabledMissingKeyAuditCount = (int) $pdo->query('SELECT COUNT(*) FROM api_request_audit_events')->fetchColumn();
    $disabledMissingKeyBucketCount = (int) $pdo->query('SELECT COUNT(*) FROM api_rate_limit_buckets')->fetchColumn();
    api_http_assert_disabled_classification($missingKeyPort, $authorization, 'Disabled API without keyring');
    $disabledCommand = api_http_request(
        $missingKeyPort,
        'POST',
        $commandPath,
        [
            ...$authorization,
            'Idempotency-Key' => str_repeat('5', 32),
            'If-Match' => $commandResponseEtag,
        ],
        $correctionBody,
    );
    api_http_same(401, $disabledCommand['status'], 'Disabled command route depended on the optional keyring.');
    api_http_same('invalid_credentials', api_http_error_shape($disabledCommand)['code'], 'Disabled command denial differs.');
    api_http_same(
        $disabledMissingKeyAuditCount,
        (int) $pdo->query('SELECT COUNT(*) FROM api_request_audit_events')->fetchColumn(),
        'Disabled API with no keyring inserted unauthenticated audit rows.',
    );
    api_http_same(
        $disabledMissingKeyBucketCount,
        (int) $pdo->query('SELECT COUNT(*) FROM api_rate_limit_buckets')->fetchColumn(),
        'Disabled API with no keyring consumed rate-limit buckets.',
    );
    $service->setApiEnabled(true, $adminId);
    api_http_stop_server($missingKeyServer, $missingKeyPipes);

    $throwingBootstrap = $testRoot . '/throwing-platform-bootstrap.php';
    if (file_put_contents(
        $throwingBootstrap,
        "<?php\n\ndeclare(strict_types=1);\n\nthrow new RuntimeException('synthetic API bootstrap failure');\n",
    ) === false) {
        throw new RuntimeException('Could not create synthetic API bootstrap failure fixture.');
    }
    [$incidentServer, $incidentPipes, $incidentPort] = api_http_start_server(
        $projectRoot,
        $testRoot . '/incident-server.log',
        ['CPE_PLATFORM_BOOTSTRAP' => $throwingBootstrap],
    );
    $incident = api_http_request($incidentPort, 'GET', '/api/v1', $authorization);
    api_http_same(500, $incident['status'], 'Unexpected API bootstrap failure was not opaque.');
    api_http_assert_boundary($incident);
    $incidentPayload = api_http_json($incident);
    api_http_keys($incidentPayload, ['error'], 'Unexpected API incident envelope differs.');
    api_http_assert(is_array($incidentPayload['error'] ?? null), 'Unexpected API incident error is not an object.');
    api_http_keys(
        $incidentPayload['error'],
        ['code', 'incident_id', 'message', 'request_id'],
        'Unexpected API incident fields differ.',
    );
    $incidentRequestId = (string) ($incident['headers']['x-request-id'][0] ?? '');
    api_http_same($incidentRequestId, (string) $incidentPayload['error']['request_id'], 'Unexpected API incident request IDs differ.');
    api_http_assert(
        preg_match('/\Ainc_[a-f0-9]{32}\z/D', (string) $incidentPayload['error']['incident_id']) === 1,
        'Unexpected API incident ID is invalid.',
    );
    api_http_stop_server($incidentServer, $incidentPipes);

    $structured = is_file($testRoot . '/structured.log') ? (string) file_get_contents($testRoot . '/structured.log') : '';
    foreach ([$testRoot . '/server.log', $testRoot . '/missing-key-server.log', $testRoot . '/incident-server.log'] as $serverLog) {
        foreach (is_file($serverLog) ? (file($serverLog, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            $jsonStart = strpos($line, '{"timestamp"');
            if ($jsonStart !== false) {
                $structured .= substr($line, $jsonStart) . "\n";
            }
        }
    }
    foreach ([$full['token'], $opportunitiesOnly['token'], $revocable['token'], $opportunityId, (string) $application['id']] as $sensitive) {
        api_http_assert(!str_contains($structured, $sensitive), 'Structured API telemetry retained raw token or resource identity.');
    }
    api_http_assert(str_contains($structured, 'api-v1-opportunities-item'), 'Structured API telemetry omitted safe route classes.');
    $responseTelemetryCorrelated = false;
    $incidentTelemetryCorrelated = false;
    foreach (explode("\n", $structured) as $structuredLine) {
        $record = json_decode($structuredLine, true);
        if (!is_array($record) || !is_array($record['context'] ?? null)) {
            continue;
        }
        if (($record['event'] ?? null) === 'http.request'
            && ($record['context']['api_request_id'] ?? null) === $forwardedRequestId) {
            $responseTelemetryCorrelated = true;
        }
        if (($record['event'] ?? null) === 'incident.reported'
            && is_array($record['context']['safe_context'] ?? null)
            && ($record['context']['safe_context']['api_request_id'] ?? null) === $incidentRequestId) {
            $incidentTelemetryCorrelated = true;
        }
    }
    api_http_assert($responseTelemetryCorrelated, 'Response/audit API request ID was not discoverable in structured request telemetry.');
    api_http_assert($incidentTelemetryCorrelated, 'Unexpected API incident omitted its authoritative API request ID from safe telemetry context.');

    echo 'PASS public API HTTP contract (' . Database::driver() . ' ' . Database::serverVersion() . ")\n";
} finally {
    api_http_stop_server($server, $pipes);
    api_http_stop_server($missingKeyServer, $missingKeyPipes);
    api_http_stop_server($incidentServer, $incidentPipes);
    Database::reset();
    api_http_remove_tree($testRoot);
}
