<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/cpe-api-identity-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create API identity contract directory.');
}
$testRoot = realpath($testRoot) ?: $testRoot;
$postgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
if (!$postgres) {
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $testRoot . '/contract.sqlite');
}
putenv('CPE_LOG_PATH=' . $testRoot . '/structured.log');
$rootOne = str_repeat("\x41", 32);
$rootTwo = str_repeat("\x42", 32);
$encodedOne = rtrim(strtr(base64_encode($rootOne), '+/', '-_'), '=');
$encodedTwo = rtrim(strtr(base64_encode($rootTwo), '+/', '-_'), '=');
putenv('CPE_API_KEYRING=contract-v1=' . $encodedOne . ';contract-v2=' . $encodedTwo);
putenv('CPE_API_ACTIVE_KEY_VERSION=contract-v1');

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require $projectRoot . '/app/bootstrap.php';
require $projectRoot . '/tests/authorized_setup_recovery_fixture.php';

use App\Api\Operations\ApiHealthService;
use App\Api\Operations\ApiRateLimiter;
use App\Api\Operations\ApiRequestAuditService;
use App\Api\Operations\ApiRetentionService;
use App\Api\Security\ApiAuthenticationUnavailable;
use App\Api\Security\ApiAuthorizationUnavailable;
use App\Api\Security\ApiKeyring;
use App\Api\Security\ApiPrincipal;
use App\Api\Security\ApiScopePolicy;
use App\Api\Security\ApiServiceAccountService;
use App\Api\Security\ApiTokenAuthenticator;
use App\Api\Security\InvalidApiCredential;
use App\Core\Http\UserVisibleException;
use App\Core\Portal;
use App\Domain\ConfigurationSnapshotService;
use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Support\Database;

function api_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function api_contract_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function api_contract_rejects(callable $operation, string $message, ?string $class = null): Throwable
{
    try {
        $operation();
    } catch (Throwable $failure) {
        if ($class !== null && !$failure instanceof $class) {
            throw new RuntimeException($message . ' wrong_failure=' . get_class($failure), 0, $failure);
        }
        return $failure;
    }
    throw new RuntimeException($message);
}

function api_contract_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

function api_contract_binary(mixed $value): string
{
    if (is_resource($value)) {
        $contents = stream_get_contents($value);
        return is_string($contents) ? $contents : '';
    }
    return is_string($value) ? $value : '';
}

function api_contract_admin_audit_failure(PDO $pdo, bool $postgres): void
{
    if ($postgres) {
        $pdo->exec(
            "CREATE FUNCTION cpe_api_contract_audit_fail() RETURNS trigger LANGUAGE plpgsql AS $$
             BEGIN
                 IF NEW.action = 'api.service_account.create' THEN
                     RAISE EXCEPTION 'injected API audit failure';
                 END IF;
                 RETURN NEW;
             END;
             $$",
        );
        $pdo->exec(
            'CREATE TRIGGER api_contract_audit_fail BEFORE INSERT ON audit_logs
             FOR EACH ROW EXECUTE FUNCTION cpe_api_contract_audit_fail()',
        );
        return;
    }
    $pdo->exec(
        "CREATE TRIGGER api_contract_audit_fail BEFORE INSERT ON audit_logs
         WHEN NEW.action = 'api.service_account.create'
         BEGIN SELECT RAISE(ABORT, 'injected API audit failure'); END",
    );
}

function api_contract_drop_admin_audit_failure(PDO $pdo, bool $postgres): void
{
    if ($postgres) {
        $pdo->exec('DROP TRIGGER api_contract_audit_fail ON audit_logs');
        $pdo->exec('DROP FUNCTION cpe_api_contract_audit_fail()');
        return;
    }
    $pdo->exec('DROP TRIGGER api_contract_audit_fail');
}

try {
    (new SystemRequirements())->assertReady();

    putenv('CPE_API_KEYRING');
    putenv('CPE_API_ACTIVE_KEY_VERSION');
    api_contract_same(false, ApiKeyring::environmentStatus()['present'], 'Missing API keyring did not remain an optional disabled setup state.');
    putenv('CPE_API_KEYRING=contract-v1=' . $encodedOne . '=');
    putenv('CPE_API_ACTIVE_KEY_VERSION=contract-v1');
    api_contract_rejects(
        static fn (): ApiKeyring => ApiKeyring::fromEnvironment(),
        'Padded non-canonical API key material was accepted.',
    );
    putenv('CPE_API_KEYRING=contract-v1=' . $encodedOne . ';contract-v2=' . $encodedTwo);
    putenv('CPE_API_ACTIVE_KEY_VERSION=contract-v1');

    $keyring = ApiKeyring::fromEnvironment();
    $institutionOne = 'tenant_' . str_repeat('c', 32);
    $institutionTwo = 'tenant_' . str_repeat('d', 32);
    $lookupOne = str_repeat('1', 32);
    $lookupTwo = str_repeat('2', 32);
    $secret = str_repeat("\x5a", 32);
    $verifier = $keyring->tokenVerifier($secret, $institutionOne, $lookupOne, 'contract-v1');
    api_contract_same(32, strlen($verifier), 'API token verifier is not 32 bytes.');
    api_contract_assert(!hash_equals($verifier, $keyring->tokenVerifier($secret, $institutionTwo, $lookupOne, 'contract-v1')), 'Token verifier was not bound to institution identity.');
    api_contract_assert(!hash_equals($verifier, $keyring->tokenVerifier($secret, $institutionOne, $lookupTwo, 'contract-v1')), 'Token verifier was not bound to lookup ID.');
    api_contract_assert(!hash_equals($verifier, $keyring->tokenVerifier($secret, $institutionOne, $lookupOne, 'contract-v2')), 'Token verifier was not bound to key version.');
    api_contract_assert(!hash_equals($keyring->cursorMac('payload', $institutionOne), hex2bin($keyring->sourceFingerprint('payload', $institutionOne))), 'Cursor and source keys were not domain separated.');

    $emptyHealth = new PDO('sqlite::memory:');
    api_contract_same(0, (new ApiHealthService($emptyHealth))->snapshot()['service_accounts'], 'Absent API identity schema was not reported as uninstalled.');
    $partialHealth = new PDO('sqlite::memory:');
    $partialHealth->exec('CREATE TABLE api_service_accounts (id INTEGER PRIMARY KEY)');
    api_contract_rejects(static fn (): array => (new ApiHealthService($partialHealth))->snapshot(), 'Partial API identity storage was reported healthy.');

    Database::migrate();
    (new Installer())->installHosted([
        'college_name' => 'API Identity Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'API Contract Administrator',
        'admin_email' => 'api-contract@example.test',
        'admin_password' => 'api-contract-password-123',
        'seed_demo' => '0',
    ], $institutionOne, test_authorized_setup_recovery_authority());
    $pdo = Database::connection();
    $driver = Database::driver();
    $migration = $driver === 'pgsql' ? '016_api_identity_foundation.sql' : '052_api_identity_foundation.sql';
    $registered = $pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
    $registered->execute([$migration]);
    api_contract_same(1, (int) $registered->fetchColumn(), 'API identity migration was not registered.');
    api_contract_same('0', (string) $pdo->query("SELECT value FROM settings WHERE key = 'api_enabled'")->fetchColumn(), 'API did not default disabled.');
    foreach (['api_service_accounts', 'api_service_account_scopes', 'api_access_tokens', 'api_rate_limit_buckets', 'api_request_audit_events', 'api_command_idempotency_keys'] as $table) {
        api_contract_same(0, (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), 'Fresh install synthesized API identity state: ' . $table);
    }

    $tokenColumns = $driver === 'pgsql'
        ? $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'api_access_tokens'")->fetchAll(PDO::FETCH_COLUMN)
        : array_column($pdo->query("PRAGMA table_info('api_access_tokens')")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['token', 'secret', 'raw_token', 'plaintext_secret'] as $forbiddenColumn) {
        api_contract_assert(!in_array($forbiddenColumn, $tokenColumns, true), 'API schema contains a plaintext token column: ' . $forbiddenColumn);
    }
    foreach (['lookup_id', 'secret_verifier', 'key_version', 'expires_at', 'rotation_grace_expires_at', 'revoked_at', 'last_used_at'] as $requiredColumn) {
        api_contract_assert(in_array($requiredColumn, $tokenColumns, true), 'API token schema is missing ' . $requiredColumn);
    }

    $configPath = $testRoot . '/portable-config.json';
    (new ConfigurationSnapshotService($pdo))->export($configPath);
    $configurationJson = (string) file_get_contents($configPath);
    api_contract_assert(!str_contains($configurationJson, 'api_enabled'), 'Local API enabled state entered portable configuration.');
    $publicContract = json_decode((string) file_get_contents($projectRoot . '/contracts/public-integration.v1.json'), true, 32, JSON_THROW_ON_ERROR);
    api_contract_same(
        ['opportunities.read', 'applications.read', 'applications.transition'],
        $publicContract['api_scopes'] ?? null,
        'Public API scope declaration differs.',
    );
    api_contract_same(['v1'], $publicContract['engine_api'] ?? null, 'Public Engine API declaration differs.');
    $apiAccessView = (string) file_get_contents($projectRoot . '/app/Views/api-access.php');
    api_contract_assert(
        str_contains($apiAccessView, 'apply one controlled application status transition')
            && !str_contains($apiAccessView, 'future read-only API')
            && !str_contains($apiAccessView, 'event-only'),
        'API access administrator guidance does not match the supported v1 surface.',
    );

    $service = new ApiServiceAccountService($pdo, $keyring);
    $beforeCreate = time();
    $created = $service->create('Institution warehouse', ['applications.read', 'opportunities.read'], 1);
    $afterCreate = time();
    api_contract_assert(preg_match('/^apisa_[a-f0-9]{32}$/D', $created['service_account_id']) === 1, 'Service-account public ID is invalid.');
    api_contract_assert(preg_match('/^cpe_live_apitok_[a-f0-9]{32}\.[A-Za-z0-9_-]{43}$/D', $created['token']) === 1, 'Generated token format is invalid.');
    [, $encodedSecret] = explode('.', $created['token'], 2);
    api_contract_same(32, strlen((string) ApiKeyring::base64UrlDecode($encodedSecret)), 'Generated token secret is not 32 random bytes.');
    $expiryTimestamp = strtotime($created['expires_at'] . ' UTC');
    api_contract_assert($expiryTimestamp !== false && $expiryTimestamp >= $beforeCreate + 90 * 86400 && $expiryTimestamp <= $afterCreate + 90 * 86400 + 1, 'Default token expiry is not 90 days.');

    $storedQuery = $pdo->prepare('SELECT * FROM api_access_tokens WHERE lookup_id = ?');
    $storedQuery->execute([$created['token_lookup_id']]);
    $stored = $storedQuery->fetch(PDO::FETCH_ASSOC);
    api_contract_assert(is_array($stored), 'Generated token metadata was not stored.');
    api_contract_same(32, strlen(api_contract_binary($stored['secret_verifier'] ?? '')), 'Stored verifier is not 32 bytes.');
    foreach ($stored as $field => $value) {
        if ($field === 'secret_verifier') {
            continue;
        }
        api_contract_assert(!is_string($value) || !str_contains($value, $created['token']), 'Plaintext token entered API token metadata.');
        api_contract_assert(!is_string($value) || !str_contains($value, $encodedSecret), 'Plaintext token secret entered API token metadata.');
    }
    $adminListJson = json_encode($service->listForAdministrator(), JSON_THROW_ON_ERROR);
    api_contract_assert(!str_contains($adminListJson, $created['token']) && !str_contains($adminListJson, $encodedSecret), 'Administrator listing re-revealed a token.');
    api_contract_assert(!str_contains($adminListJson, 'secret_verifier'), 'Administrator listing exposed token verifier material.');
    $auditJson = json_encode($pdo->query("SELECT action, subject_type, detail FROM audit_logs WHERE action LIKE 'api.%'")->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
    api_contract_assert(!str_contains($auditJson, $created['token']) && !str_contains($auditJson, $encodedSecret), 'Management audit retained token material.');

    $authenticator = new ApiTokenAuthenticator($pdo, $keyring);
    api_contract_rejects(static fn (): ApiPrincipal => $authenticator->authenticate($created['token']), 'Disabled API accepted a valid token.', InvalidApiCredential::class);
    putenv('CPE_API_KEYRING');
    putenv('CPE_API_ACTIVE_KEY_VERSION');
    api_contract_rejects(
        static fn (): ApiPrincipal => (new ApiTokenAuthenticator($pdo))->authenticate($created['token']),
        'Disabled API depended on an external keyring to deny authentication.',
        InvalidApiCredential::class,
    );
    putenv('CPE_API_KEYRING=contract-v1=' . $encodedOne . ';contract-v2=' . $encodedTwo);
    putenv('CPE_API_ACTIVE_KEY_VERSION=contract-v1');
    $service->setApiEnabled(true, 1);
    api_contract_rejects(
        static fn (): int|false => $pdo->exec("DELETE FROM settings WHERE key = 'api_enabled'"),
        'Required local API enabled state could be deleted.',
    );
    api_contract_same('1', (string) $pdo->query("SELECT value FROM settings WHERE key = 'api_enabled'")->fetchColumn(), 'Rejected API setting deletion changed enabled state.');
    $principal = $authenticator->authenticate($created['token']);
    api_contract_same($created['service_account_id'], $principal->serviceAccountPublicId(), 'Authenticated principal references the wrong service account.');
    $scopePolicy = new ApiScopePolicy($pdo);
    api_contract_same(true, $scopePolicy->allows($principal, 'opportunities.read'), 'Exact opportunities scope was denied.');
    api_contract_same(true, $scopePolicy->allows($principal, 'applications.read'), 'Exact applications scope was denied.');
    api_contract_same(false, $scopePolicy->allows($principal, 'admin.all'), 'Unknown broad API scope was accepted.');
    $forged = new ApiPrincipal(
        $principal->institutionId(),
        $institutionTwo,
        $principal->serviceAccountId(),
        $principal->serviceAccountPublicId(),
        $principal->tokenId(),
        $principal->tokenLookupId(),
        $principal->scopes(),
    );
    api_contract_same(false, $scopePolicy->allows($forged, 'applications.read'), 'Cross-institution principal passed scope authorization.');
    $lastUsed = (string) $pdo->query('SELECT last_used_at FROM api_access_tokens WHERE id = ' . $principal->tokenId())->fetchColumn();
    api_contract_assert($lastUsed !== '', 'Successful authentication did not record last-used time.');
    $authenticator->authenticate($created['token']);
    api_contract_same($lastUsed, (string) $pdo->query('SELECT last_used_at FROM api_access_tokens WHERE id = ' . $principal->tokenId())->fetchColumn(), 'Last-used timestamp was not write-throttled.');
    api_contract_rejects(
        static fn (): int|false => $pdo->exec('UPDATE api_access_tokens SET last_used_at = NULL WHERE id = ' . $principal->tokenId()),
        'Token last-used evidence could be cleared.',
    );

    $unknown = preg_replace('/apitok_[a-f0-9]{32}/', 'apitok_' . str_repeat('f', 32), $created['token'], 1);
    api_contract_rejects(static fn (): ApiPrincipal => $authenticator->authenticate((string) $unknown), 'Unknown lookup ID was accepted.', InvalidApiCredential::class);
    api_contract_rejects(static fn (): ApiPrincipal => $authenticator->authenticate('not-a-token'), 'Malformed token was accepted.', InvalidApiCredential::class);

    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM role_capabilities WHERE capability = 'placement.records.view'");
        api_contract_same(false, (new ApiScopePolicy($pdo))->allows($principal, 'applications.read'), 'Missing durable capability did not fail closed.');
    } finally {
        $pdo->rollBack();
    }
    $pdo->exec("UPDATE module_installations SET enabled = 0 WHERE module_key = 'placement'");
    Portal::reset();
    api_contract_same(false, (new ApiScopePolicy($pdo))->allows($principal, 'applications.read'), 'Disabled Placement module retained API scope authorization.');
    $pdo->exec("UPDATE module_installations SET enabled = 1 WHERE module_key = 'placement'");
    Portal::reset();

    $rateLimiter = new ApiRateLimiter($pdo, $keyring);
    api_contract_same(true, $rateLimiter->consume($principal, 'applications.read', '198.51.100.7', 60, ['institution' => 2, 'token' => 2, 'source' => 2])['allowed'], 'First rate-limit request was denied.');
    api_contract_same(true, $rateLimiter->consume($principal, 'applications.read', '198.51.100.7', 60, ['institution' => 2, 'token' => 2, 'source' => 2])['allowed'], 'Second rate-limit request was denied.');
    api_contract_same(false, $rateLimiter->consume($principal, 'applications.read', '198.51.100.7', 60, ['institution' => 2, 'token' => 2, 'source' => 2])['allowed'], 'Rate limit did not stop the third request.');
    $bucketJson = json_encode($pdo->query('SELECT * FROM api_rate_limit_buckets')->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
    api_contract_assert(!str_contains($bucketJson, '198.51.100.7') && !str_contains($bucketJson, $created['token']), 'Rate-limit bucket retained raw source or token material.');

    $requestAudit = new ApiRequestAuditService($pdo, $keyring);
    $auditPublicId = $requestAudit->record($principal, 'applications.read', 'applications.read', 'succeeded', 200, '', '198.51.100.7');
    $requestAuditQuery = $pdo->prepare('SELECT * FROM api_request_audit_events WHERE public_id = ?');
    $requestAuditQuery->execute([$auditPublicId]);
    $requestAuditRow = $requestAuditQuery->fetch(PDO::FETCH_ASSOC);
    api_contract_assert(is_array($requestAuditRow), 'Redacted API request audit was not stored.');
    $requestAuditJson = json_encode($requestAuditRow, JSON_THROW_ON_ERROR);
    api_contract_assert(!str_contains($requestAuditJson, '198.51.100.7') && !str_contains($requestAuditJson, $created['token']), 'API request audit retained raw source or token material.');
    api_contract_same(64, strlen((string) $requestAuditRow['source_fingerprint']), 'API request audit source fingerprint is not a keyed digest.');
    api_contract_rejects(static fn (): bool => $pdo->prepare('UPDATE api_request_audit_events SET outcome = ? WHERE public_id = ?')->execute(['failed', $auditPublicId]), 'API request audit was mutable.');

    $otherInstitutionPublicId = 'inst_' . str_repeat('9', 32);
    $otherInstitution = $pdo->prepare(
        'INSERT INTO institutions (public_id, slug, name, timezone, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)',
    );
    $otherInstitution->execute([
        $otherInstitutionPublicId,
        'api-contract-other',
        'API Contract Other Institution',
        'UTC',
        cpe_now(),
        cpe_now(),
    ]);
    $otherInstitutionId = Database::lastInsertId($pdo);
    $defaultHealthCount = (new ApiHealthService($pdo))->snapshot()['service_accounts'];
    $otherAccount = $pdo->prepare(
        'INSERT INTO api_service_accounts
         (public_id, institution_id, name, status, disabled_at, revoked_at,
          created_by_user_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, ?)',
    );
    $otherAccount->execute([
        'apisa_' . str_repeat('9', 32),
        $otherInstitutionId,
        'Other institution sentinel',
        'enabled',
        1,
        cpe_now(),
        cpe_now(),
    ]);
    $otherAccountId = Database::lastInsertId($pdo);
    $otherTokenLookupId = str_repeat('9', 32);
    $otherTokenVerifier = $keyring->tokenVerifier(
        str_repeat("\x63", 32),
        $otherInstitutionPublicId,
        $otherTokenLookupId,
        'contract-v1',
    );
    $otherToken = $pdo->prepare(
        'INSERT INTO api_access_tokens
         (service_account_id, lookup_id, secret_verifier, key_version, expires_at,
          rotation_grace_expires_at, revoked_at, last_used_at,
          created_by_user_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?)',
    );
    $otherToken->bindValue(1, $otherAccountId, PDO::PARAM_INT);
    $otherToken->bindValue(2, $otherTokenLookupId, PDO::PARAM_STR);
    $otherToken->bindValue(3, $otherTokenVerifier, PDO::PARAM_LOB);
    $otherToken->bindValue(4, 'contract-v1', PDO::PARAM_STR);
    $otherToken->bindValue(5, '2099-01-01 00:00:00', PDO::PARAM_STR);
    $otherToken->bindValue(6, 1, PDO::PARAM_INT);
    $otherToken->bindValue(7, cpe_now(), PDO::PARAM_STR);
    $otherToken->bindValue(8, cpe_now(), PDO::PARAM_STR);
    $otherToken->execute();
    $crossInstitutionRevoke = api_contract_rejects(
        static function () use ($service, $otherTokenLookupId): void {
            $service->revokeToken($otherTokenLookupId, 1);
        },
        'A default-institution actor revoked another institution token.',
        UserVisibleException::class,
    );
    api_contract_same('API_TOKEN_NOT_FOUND', $crossInstitutionRevoke->publicCode(), 'Cross-institution token existence was disclosed.');
    $otherTokenState = $pdo->prepare('SELECT COUNT(*) FROM api_access_tokens WHERE lookup_id = ? AND revoked_at IS NULL');
    $otherTokenState->execute([$otherTokenLookupId]);
    api_contract_same(1, (int) $otherTokenState->fetchColumn(), 'Cross-institution token was mutated by revocation.');
    api_contract_same($defaultHealthCount, (new ApiHealthService($pdo))->snapshot()['service_accounts'], 'Aggregate API health crossed the current institution boundary.');
    $mismatchedBucket = $pdo->prepare(
        'INSERT INTO api_rate_limit_buckets
         (institution_id, token_id, dimension, bucket_key, route_class, window_started_at,
          window_seconds, request_count, expires_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 60, 1, ?, ?, ?)',
    );
    api_contract_rejects(
        static fn (): bool => $mismatchedBucket->execute([
            $otherInstitutionId,
            $principal->tokenId(),
            'token',
            str_repeat('8', 64),
            'applications.read',
            '2001-01-01 00:00:00',
            '2001-01-01 00:02:00',
            '2001-01-01 00:00:00',
            '2001-01-01 00:00:00',
        ]),
        'Rate-limit token could be assigned to another institution.',
    );
    $mismatchedAudit = $pdo->prepare(
        'INSERT INTO api_request_audit_events
         (public_id, institution_id, service_account_id, token_id, request_id, route_class,
          required_scope, outcome, status_code, detail_code, source_fingerprint, retention_until, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    api_contract_rejects(
        static fn (): bool => $mismatchedAudit->execute([
            'apiaud_' . str_repeat('8', 32),
            $otherInstitutionId,
            $principal->serviceAccountId(),
            $principal->tokenId(),
            'req_' . str_repeat('8', 32),
            'applications.read',
            'applications.read',
            'denied',
            403,
            'SCOPE_DENIED',
            '',
            '2001-01-02 00:00:00',
            '2001-01-01 00:00:00',
        ]),
        'Request audit references could cross institution boundaries.',
    );
    $pdo->exec("DELETE FROM api_service_accounts WHERE public_id = 'apisa_" . str_repeat('9', 32) . "'");

    $rotated = $service->rotateToken($created['service_account_id'], 1, 30);
    api_contract_same(2, (int) $pdo->query('SELECT COUNT(*) FROM api_access_tokens WHERE revoked_at IS NULL')->fetchColumn(), 'Rotation did not retain exactly current plus grace token.');
    $oldLifecycle = $pdo->prepare('SELECT rotation_grace_expires_at, expires_at FROM api_access_tokens WHERE lookup_id = ?');
    $oldLifecycle->execute([$created['token_lookup_id']]);
    $oldRow = $oldLifecycle->fetch(PDO::FETCH_ASSOC);
    api_contract_assert(is_array($oldRow) && $oldRow['rotation_grace_expires_at'] !== null, 'Prior token did not enter rotation grace.');
    $graceTimestamp = strtotime((string) $oldRow['rotation_grace_expires_at'] . ' UTC');
    api_contract_assert($graceTimestamp !== false && $graceTimestamp <= time() + ApiServiceAccountService::ROTATION_GRACE_SECONDS + 1, 'Rotation grace exceeded 24 hours.');
    $authenticator->authenticate($created['token']);
    $authenticator->authenticate($rotated['token']);

    $rotatedAgain = $service->rotateToken($created['service_account_id'], 1);
    api_contract_same(2, (int) $pdo->query('SELECT COUNT(*) FROM api_access_tokens WHERE revoked_at IS NULL')->fetchColumn(), 'Second rotation retained more than two unrevoked tokens.');
    api_contract_rejects(static fn (): ApiPrincipal => $authenticator->authenticate($created['token']), 'Oldest token survived a second rotation.', InvalidApiCredential::class);
    $authenticator->authenticate($rotated['token']);
    $latestPrincipal = $authenticator->authenticate($rotatedAgain['token']);
    $beforeInvalidExpiry = (int) $pdo->query('SELECT COUNT(*) FROM api_access_tokens')->fetchColumn();
    api_contract_rejects(static fn (): array => $service->rotateToken($created['service_account_id'], 1, 366), 'Token expiry over 365 days was accepted.');
    api_contract_same($beforeInvalidExpiry, (int) $pdo->query('SELECT COUNT(*) FROM api_access_tokens')->fetchColumn(), 'Rejected expiry mutated token lifecycle.');

    $service->setAccountEnabled($created['service_account_id'], false, 1);
    api_contract_rejects(static fn (): ApiPrincipal => $authenticator->authenticate($rotatedAgain['token']), 'Disabled service account remained usable.', InvalidApiCredential::class);
    $service->setAccountEnabled($created['service_account_id'], true, 1);
    $authenticator->authenticate($rotatedAgain['token']);
    $service->revokeToken($rotatedAgain['token_lookup_id'], 1);
    api_contract_rejects(static fn (): ApiPrincipal => $authenticator->authenticate($rotatedAgain['token']), 'Revoked token remained usable.', InvalidApiCredential::class);
    $replacement = $service->rotateToken($created['service_account_id'], 1);
    $authenticator->authenticate($replacement['token']);

    $missingKeyAccount = $service->create('Missing key readiness', ['applications.read'], 1);
    putenv('CPE_API_KEYRING=contract-v2=' . $encodedTwo);
    putenv('CPE_API_ACTIVE_KEY_VERSION=contract-v2');
    $missingHealth = (new ApiHealthService($pdo))->snapshot();
    api_contract_same('fail', $missingHealth['status'], 'Missing referenced API key version did not fail readiness.');
    api_contract_same(1, $missingHealth['missing_key_versions'], 'Missing referenced key versions were exposed or miscounted.');
    api_contract_rejects(
        static fn (): ApiPrincipal => (new ApiTokenAuthenticator($pdo, ApiKeyring::fromEnvironment()))->authenticate($missingKeyAccount['token']),
        'Unavailable referenced key version was treated as an invalid credential instead of readiness failure.',
        ApiAuthenticationUnavailable::class,
    );
    putenv('CPE_API_KEYRING=contract-v1=' . $encodedOne . ';contract-v2=' . $encodedTwo);
    putenv('CPE_API_ACTIVE_KEY_VERSION=contract-v1');

    $service->revokeAccount($created['service_account_id'], 1);
    api_contract_rejects(static fn (): ApiPrincipal => $authenticator->authenticate($replacement['token']), 'Revoked service account retained a usable token.', InvalidApiCredential::class);
    $revokedTokens = $pdo->prepare('SELECT COUNT(*) FROM api_access_tokens WHERE service_account_id = ? AND revoked_at IS NULL');
    $revokedTokens->execute([$latestPrincipal->serviceAccountId()]);
    api_contract_same(0, (int) $revokedTokens->fetchColumn(), 'Account revocation did not revoke every token.');
    api_contract_rejects(
        static function () use ($service, $created): void {
            $service->setAccountEnabled($created['service_account_id'], true, 1);
        },
        'Revoked service account was re-enabled.',
    );

    $accountsBeforeRollback = (int) $pdo->query('SELECT COUNT(*) FROM api_service_accounts')->fetchColumn();
    api_contract_admin_audit_failure($pdo, $postgres);
    try {
        api_contract_rejects(
            static fn (): array => $service->create('Rollback fixture', ['applications.read'], 1),
            'Injected audit failure did not reject service-account creation.',
        );
    } finally {
        api_contract_drop_admin_audit_failure($pdo, $postgres);
    }
    api_contract_same($accountsBeforeRollback, (int) $pdo->query('SELECT COUNT(*) FROM api_service_accounts')->fetchColumn(), 'Failed service-account audit left partial account state.');

    $old = '2000-01-01 00:00:00';
    $oldEnd = '2000-01-02 00:00:00';
    $institutionId = (int) $pdo->query("SELECT id FROM institutions WHERE slug = 'default'")->fetchColumn();
    $oldBucket = $pdo->prepare(
        'INSERT INTO api_rate_limit_buckets
         (institution_id, token_id, dimension, bucket_key, route_class, window_started_at,
          window_seconds, request_count, expires_at, created_at, updated_at)
         VALUES (?, NULL, ?, ?, ?, ?, 60, 1, ?, ?, ?)',
    );
    $oldBucket->execute([$institutionId, 'source', str_repeat('a', 64), 'authentication', $old, $oldEnd, $old, $old]);
    $oldAudit = $pdo->prepare(
        'INSERT INTO api_request_audit_events
         (public_id, institution_id, service_account_id, token_id, request_id, route_class,
          required_scope, outcome, status_code, detail_code, source_fingerprint, retention_until, created_at)
         VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $oldAudit->execute([
        'apiaud_' . str_repeat('a', 32),
        $institutionId,
        'req_' . str_repeat('b', 32),
        'authentication',
        '',
        'denied',
        401,
        'INVALID_CREDENTIAL',
        '',
        $oldEnd,
        $old,
    ]);
    $otherOldBucket = $pdo->prepare(
        'INSERT INTO api_rate_limit_buckets
         (institution_id, token_id, dimension, bucket_key, route_class, window_started_at,
          window_seconds, request_count, expires_at, created_at, updated_at)
         VALUES (?, NULL, ?, ?, ?, ?, 60, 1, ?, ?, ?)',
    );
    $otherOldBucket->execute([$otherInstitutionId, 'source', str_repeat('c', 64), 'authentication', $old, $oldEnd, $old, $old]);
    $otherOldAudit = $pdo->prepare(
        'INSERT INTO api_request_audit_events
         (public_id, institution_id, service_account_id, token_id, request_id, route_class,
          required_scope, outcome, status_code, detail_code, source_fingerprint, retention_until, created_at)
         VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $otherOldAudit->execute([
        'apiaud_' . str_repeat('c', 32),
        $otherInstitutionId,
        'req_' . str_repeat('d', 32),
        'authentication',
        '',
        'denied',
        401,
        'INVALID_CREDENTIAL',
        '',
        $oldEnd,
        $old,
    ]);
    $pruned = (new ApiRetentionService($pdo))->prune(1, 100);
    api_contract_assert($pruned['rate_limit_buckets'] >= 1 && $pruned['request_audit_events'] >= 1, 'API retention did not prune expired rows.');
    api_contract_assert(array_key_exists('command_idempotency_keys', $pruned), 'API retention omitted command-idempotency pruning outcome.');
    $otherBucketCount = $pdo->prepare('SELECT COUNT(*) FROM api_rate_limit_buckets WHERE institution_id = ? AND bucket_key = ?');
    $otherBucketCount->execute([$otherInstitutionId, str_repeat('c', 64)]);
    api_contract_same(1, (int) $otherBucketCount->fetchColumn(), 'API retention pruned another institution rate-limit bucket.');
    $otherAuditCount = $pdo->prepare('SELECT COUNT(*) FROM api_request_audit_events WHERE institution_id = ? AND public_id = ?');
    $otherAuditCount->execute([$otherInstitutionId, 'apiaud_' . str_repeat('c', 32)]);
    api_contract_same(1, (int) $otherAuditCount->fetchColumn(), 'API retention pruned another institution request audit.');

    $service->setApiEnabled(false, 1);
    api_contract_rejects(static fn (): ApiPrincipal => $authenticator->authenticate($missingKeyAccount['token']), 'Global API disable did not stop authentication.', InvalidApiCredential::class);
    $health = (new ApiHealthService($pdo))->snapshot();
    $healthJson = json_encode($health, JSON_THROW_ON_ERROR);
    foreach ([$created['service_account_id'], $missingKeyAccount['service_account_id'], $created['token_lookup_id'], 'Institution warehouse', $created['token']] as $privateValue) {
        api_contract_assert(!str_contains($healthJson, $privateValue), 'Aggregate API health exposed account or token identity.');
    }

    $controllerSource = (string) file_get_contents($projectRoot . '/app/Controllers/ApiAccessController.php');
    api_contract_assert(str_contains($controllerSource, 'Csrf::verify') && str_contains($controllerSource, "portal.integrations.manage"), 'API management UI is not CSRF and capability protected.');
    $apiSource = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        array_merge(
            glob($projectRoot . '/app/Api/Security/*.php') ?: [],
            glob($projectRoot . '/app/Api/Operations/*.php') ?: [],
            glob($projectRoot . '/app/Api/Commands/*.php') ?: [],
        ),
    ));
    foreach (['WebhookSecretCipher', 'OperationalBearerAuthorization', 'PlacementService', '../cloud', 'Cloud\\'] as $forbiddenDependency) {
        api_contract_assert(!str_contains($apiSource, $forbiddenDependency), 'API identity foundation uses forbidden dependency: ' . $forbiddenDependency);
    }
    $structuredLog = is_file($testRoot . '/structured.log') ? (string) file_get_contents($testRoot . '/structured.log') : '';
    foreach ([$created['token'], $rotated['token'], $rotatedAgain['token'], $replacement['token'], $missingKeyAccount['token']] as $plaintextToken) {
        api_contract_assert(!str_contains($structuredLog, $plaintextToken), 'Structured log retained a plaintext API token.');
    }
    api_contract_assert(
        str_contains((string) file_get_contents($projectRoot . '/placement'), 'Expired command-idempotency keys pruned:'),
        'API prune CLI omitted command-idempotency retention output.',
    );

    echo 'PASS API identity contract (' . $driver . ")\n";
} finally {
    Database::reset();
    putenv('CPE_API_KEYRING');
    putenv('CPE_API_ACTIVE_KEY_VERSION');
    putenv('CPE_LOG_PATH');
    api_contract_remove_tree($testRoot);
}
