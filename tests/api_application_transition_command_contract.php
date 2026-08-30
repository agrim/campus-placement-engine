<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/cpe-api-command-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create API command contract directory.');
}
$testRoot = realpath($testRoot) ?: $testRoot;
$postgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
if (!$postgres) {
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $testRoot . '/command.sqlite');
}
putenv('CPE_LOG_PATH=' . $testRoot . '/structured.log');
$rootOne = str_repeat("\x61", 32);
$rootTwo = str_repeat("\x62", 32);
$encodedOne = rtrim(strtr(base64_encode($rootOne), '+/', '-_'), '=');
$encodedTwo = rtrim(strtr(base64_encode($rootTwo), '+/', '-_'), '=');
putenv('CPE_API_KEYRING=command-v1=' . $encodedOne . ';command-v2=' . $encodedTwo);
putenv('CPE_API_ACTIVE_KEY_VERSION=command-v1');

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require $projectRoot . '/app/bootstrap.php';
require $projectRoot . '/tests/authorized_setup_recovery_fixture.php';

use App\Api\Commands\ApiCommandConflict;
use App\Api\Commands\ApiCommandHasher;
use App\Api\Commands\ApiCommandIdempotencyStore;
use App\Api\Commands\ApiCommandUnavailable;
use App\Api\Commands\InvalidApiCommandInput;
use App\Api\Operations\ApiRetentionService;
use App\Api\Security\ApiKeyring;
use App\Api\Security\ApiScopePolicy;
use App\Api\Security\ApiServiceAccountService;
use App\Core\Persistence\WriteTransaction;
use App\Install\Installer;
use App\Support\Database;

function api_command_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function api_command_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function api_command_rejects(callable $operation, string $message, ?string $class = null): Throwable
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

function api_command_remove_tree(string $path): void
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

/** @return array<string, mixed> */
function api_command_application(PDO $pdo): array
{
    $row = $pdo->query(
        'SELECT application.id, application.public_id, application.candidate_id, application.company_id,
                instance.id AS workflow_instance_id, instance.workflow_version_id,
                instance.current_state_key
         FROM applications application
         JOIN placement_cycle_participants participant ON participant.id = application.participant_id
         JOIN placement_cycles cycle ON cycle.id = participant.cycle_id
         JOIN workflow_instances instance ON instance.application_id = application.id
         WHERE cycle.institution_id = (SELECT id FROM institutions WHERE slug = \'default\')
         ORDER BY application.id LIMIT 1',
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('API command fixture has no durable application.');
    }
    return $row;
}

/** @return array{id: int, public_id: string, application_id: int} */
function api_command_other_institution(PDO $pdo, int $actorUserId): array
{
    $now = cpe_now();
    $institutionPublicId = 'inst_' . str_repeat('9', 32);
    $pdo->prepare(
        'INSERT INTO institutions (public_id, slug, name, timezone, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)',
    )->execute([$institutionPublicId, 'api-command-other', 'Other command institution', 'UTC', $now, $now]);
    $institutionId = Database::lastInsertId($pdo);
    $pdo->prepare(
        'INSERT INTO placement_cycles
         (public_id, institution_id, cycle_key, name, cycle_type, starts_on, ends_on,
          status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    )->execute(['cycle_' . str_repeat('9', 32), $institutionId, 'other', 'Other cycle', 'final', '', '', 'active', $now, $now]);
    $cycleId = Database::lastInsertId($pdo);
    $pdo->prepare(
        'INSERT INTO people
         (public_id, institution_id, legacy_candidate_id, display_name, anonymized_at, created_at, updated_at)
         VALUES (?, ?, NULL, ?, NULL, ?, ?)',
    )->execute(['person_' . str_repeat('9', 32), $institutionId, 'Other person', $now, $now]);
    $personId = Database::lastInsertId($pdo);
    $pdo->prepare(
        'INSERT INTO student_profiles
         (public_id, institution_id, person_id, external_id, program, tags,
          accommodation_notes, custom_fields_json, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    )->execute(['student_' . str_repeat('9', 32), $institutionId, $personId, 'OTHER-1', '', '', '', '{}', $now, $now]);
    $profileId = Database::lastInsertId($pdo);
    $pdo->prepare(
        'INSERT INTO placement_cycle_participants
         (public_id, cycle_id, student_profile_id, legacy_candidate_id,
          participation_status, opted_out, created_at, updated_at)
         VALUES (?, ?, ?, NULL, ?, 0, ?, ?)',
    )->execute(['participant_' . str_repeat('9', 32), $cycleId, $profileId, 'active', $now, $now]);
    $participantId = Database::lastInsertId($pdo);
    $pdo->prepare(
        'INSERT INTO candidates (external_id, name, program, current_location, public_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
    )->execute(['OTHER-CANDIDATE', 'Other candidate', '', 'CP', 'candidate_' . str_repeat('9', 32), $now, $now]);
    $candidateId = Database::lastInsertId($pdo);
    $pdo->prepare(
        'INSERT INTO companies (code, name, public_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?)',
    )->execute(['OTHER-COMPANY', 'Other company', 'company_' . str_repeat('9', 32), $now, $now]);
    $companyId = Database::lastInsertId($pdo);
    $applicationPublicId = 'application_' . str_repeat('9', 32);
    $pdo->prepare(
        'INSERT INTO applications
         (candidate_id, company_id, current_status, public_id, participant_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
    )->execute([$candidateId, $companyId, 'idle', $applicationPublicId, $participantId, $now, $now]);
    $applicationId = Database::lastInsertId($pdo);
    $pdo->prepare(
        'INSERT INTO api_service_accounts
         (public_id, institution_id, name, status, disabled_at, revoked_at,
          created_by_user_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, ?)',
    )->execute(['apisa_' . str_repeat('9', 32), $institutionId, 'Other command account', 'enabled', $actorUserId, $now, $now]);
    return [
        'id' => Database::lastInsertId($pdo),
        'public_id' => 'apisa_' . str_repeat('9', 32),
        'application_id' => $applicationId,
    ];
}

try {
    Database::migrate();
    $institutionPublicId = 'tenant_' . str_repeat('7', 32);
    (new Installer())->installHosted([
        'college_name' => 'API Command Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'API Command Administrator',
        'admin_email' => 'api-command@example.test',
        'admin_password' => 'api-command-password-123',
        'seed_demo' => '1',
    ], $institutionPublicId, test_authorized_setup_recovery_authority());
    $pdo = Database::connection();
    $driver = Database::driver();
    $migration = $driver === 'pgsql'
        ? '018_api_application_transition_commands.sql'
        : '054_api_application_transition_commands.sql';
    $registered = $pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
    $registered->execute([$migration]);
    api_command_same(1, (int) $registered->fetchColumn(), 'Phase 4B migration was not registered.');

    $keyring = ApiKeyring::fromEnvironment();
    $accountService = new ApiServiceAccountService($pdo, $keyring);
    $firstAccount = $accountService->create('Transition connector one', ['applications.read'], 1);
    $secondAccount = $accountService->create('Transition connector two', ['applications.read'], 1);
    $accountIdQuery = $pdo->prepare('SELECT id FROM api_service_accounts WHERE public_id = ?');
    $accountIdQuery->execute([$firstAccount['service_account_id']]);
    $firstAccountId = (int) $accountIdQuery->fetchColumn();
    $accountIdQuery->execute([$secondAccount['service_account_id']]);
    $secondAccountId = (int) $accountIdQuery->fetchColumn();
    $application = api_command_application($pdo);
    $sameInstitutionApplication = $pdo->prepare(
        'SELECT application.id
         FROM applications application
         JOIN placement_cycle_participants participant ON participant.id = application.participant_id
         JOIN placement_cycles cycle ON cycle.id = participant.cycle_id
         WHERE cycle.institution_id = (SELECT id FROM institutions WHERE slug = \'default\')
           AND application.id <> ?
         ORDER BY application.id LIMIT 1',
    );
    $sameInstitutionApplication->execute([(int) $application['id']]);
    $sameInstitutionApplicationId = (int) $sameInstitutionApplication->fetchColumn();
    api_command_assert($sameInstitutionApplicationId > 0, 'API command fixture has no second same-institution application.');

    $pdo->prepare(
        'INSERT INTO api_service_account_scopes
         (service_account_id, scope, created_by_user_id, created_at) VALUES (?, ?, ?, ?)',
    )->execute([$firstAccountId, 'applications.transition', 1, cpe_now()]);
    api_command_assert(
        !in_array('applications.transition', ApiScopePolicy::supportedScopes(), true),
        'Phase 4B prematurely advertised the transition scope in runtime policy.',
    );
    api_command_rejects(
        static fn (): bool => $pdo->prepare(
            'INSERT INTO api_service_account_scopes
             (service_account_id, scope, created_by_user_id, created_at) VALUES (?, ?, ?, ?)',
        )->execute([$secondAccountId, 'applications.admin', 1, cpe_now()]),
        'API scope storage accepted an unknown broad scope.',
    );
    $pdo->prepare(
        'INSERT INTO api_request_audit_events
         (public_id, institution_id, service_account_id, token_id, request_id, route_class,
          required_scope, outcome, status_code, detail_code, source_fingerprint, retention_until, created_at)
         VALUES (?, (SELECT id FROM institutions WHERE slug = \'default\'), ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    )->execute([
        'apiaud_' . str_repeat('7', 32),
        $firstAccountId,
        'req_' . str_repeat('7', 32),
        'api.v1.applications.transition',
        'applications.transition',
        'authorized',
        200,
        '',
        '',
        '2099-01-01 00:00:00',
        cpe_now(),
    ]);

    $other = api_command_other_institution($pdo, 1);
    $now = cpe_now();
    $eventInsert = $pdo->prepare(
        'INSERT INTO events
         (application_id, candidate_id, company_id, from_status, to_status,
          actor_user_id, actor_service_account_id, actor_role, note, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $eventInsert->execute([
        $application['id'], $application['candidate_id'], $application['company_id'],
        'idle', 'applied', null, $firstAccountId, 'service_account', '', $now,
    ]);
    $serviceEventId = Database::lastInsertId($pdo);
    api_command_rejects(
        static fn (): bool => $eventInsert->execute([
            $application['id'], $application['candidate_id'], $application['company_id'],
            'idle', 'applied', 1, $firstAccountId, 'service_account', '', $now,
        ]),
        'Movement event accepted both a user and service-account actor.',
    );
    api_command_rejects(
        static fn (): bool => $eventInsert->execute([
            $application['id'], $application['candidate_id'], $application['company_id'],
            'idle', 'applied', null, $other['id'], 'service_account', '', $now,
        ]),
        'Movement event accepted a cross-institution service-account actor.',
    );
    api_command_rejects(
        static fn (): bool => $pdo->prepare('UPDATE events SET actor_service_account_id = ? WHERE id = ?')
            ->execute([$secondAccountId, $serviceEventId]),
        'Movement event service-account actor was mutable.',
    );
    api_command_rejects(
        static fn (): bool => $pdo->prepare('UPDATE events SET application_id = ? WHERE id = ?')
            ->execute([$sameInstitutionApplicationId, $serviceEventId]),
        'Service-attributed movement event aggregate changed within its institution.',
    );
    $pdo->prepare('UPDATE events SET note = ? WHERE id = ?')->execute(['privacy rewrite', $serviceEventId]);

    $pdo->prepare(
        'INSERT INTO workflow_transition_events
         (public_id, workflow_instance_id, application_id, workflow_version_id,
          workflow_transition_id, transition_key, from_state_key, to_state_key,
          actor_user_id, actor_service_account_id, actor_role, reason, note, context_json, occurred_at)
         VALUES (?, ?, ?, ?, NULL, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)',
    )->execute([
        'workflow_event_' . str_repeat('7', 32),
        $application['workflow_instance_id'],
        $application['id'],
        $application['workflow_version_id'],
        'api_contract_transition',
        $application['current_state_key'],
        $application['current_state_key'],
        $firstAccountId,
        'service_account',
        '',
        '',
        '{}',
        $now,
    ]);
    $workflowEventPublicId = 'workflow_event_' . str_repeat('7', 32);
    api_command_rejects(
        static fn (): bool => $pdo->prepare(
            'UPDATE workflow_transition_events SET application_id = ? WHERE public_id = ?',
        )->execute([$sameInstitutionApplicationId, $workflowEventPublicId]),
        'Service-attributed workflow event aggregate changed within its institution.',
    );
    $pdo->prepare('UPDATE workflow_transition_events SET note = ? WHERE public_id = ?')
        ->execute(['privacy rewrite', $workflowEventPublicId]);
    api_command_rejects(
        static fn (): bool => $pdo->prepare(
            'INSERT INTO workflow_transition_events
             (public_id, workflow_instance_id, application_id, workflow_version_id,
              workflow_transition_id, transition_key, from_state_key, to_state_key,
              actor_user_id, actor_service_account_id, actor_role, reason, note, context_json, occurred_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)',
        )->execute([
            'workflow_event_' . str_repeat('8', 32),
            $application['workflow_instance_id'], $application['id'], $application['workflow_version_id'],
            'api_cross_institution', $application['current_state_key'], $application['current_state_key'],
            $other['id'], 'service_account', '', '', '{}', $now,
        ]),
        'Workflow event accepted a cross-institution service-account actor.',
    );

    $auditInsert = $pdo->prepare(
        'INSERT INTO audit_logs
         (actor_user_id, actor_service_account_id, action, subject_type, subject_id,
          detail, ip_address, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $auditInsert->execute([null, $firstAccountId, 'transition', 'application', $application['id'], '', '', '', $now]);
    $serviceAuditId = Database::lastInsertId($pdo);
    api_command_rejects(
        static fn (): bool => $pdo->prepare('UPDATE audit_logs SET subject_id = ? WHERE id = ?')
            ->execute([$sameInstitutionApplicationId, $serviceAuditId]),
        'Service-attributed transition audit aggregate changed within its institution.',
    );
    $pdo->prepare('UPDATE audit_logs SET detail = ? WHERE id = ?')->execute(['privacy rewrite', $serviceAuditId]);
    api_command_rejects(
        static fn (): bool => $auditInsert->execute([null, $other['id'], 'transition', 'application', $application['id'], '', '', '', $now]),
        'Audit log accepted a cross-institution service-account actor.',
    );
    api_command_rejects(
        static fn (): bool => $auditInsert->execute([null, $firstAccountId, 'settings.update', 'system', null, '', '', '', $now]),
        'Audit log accepted service-account attribution outside application transition.',
    );
    $auditInsert->execute([1, null, 'transition', 'application', $application['id'], '', '', '', $now]);
    $auditInsert->execute([null, null, 'system.fixture', 'system', null, '', '', '', $now]);

    $hasher = new ApiCommandHasher($keyring);
    $clearKey = str_repeat('a', 32);
    $requestSentinel = 'command-body-secret-must-not-persist';
    $request = [
        'transition_key' => 'advance',
        'to_status' => 'applied',
        'note' => $requestSentinel,
        'expected_etag' => '"' . str_repeat('1', 64) . '"',
    ];
    $fingerprint = $hasher->fingerprintApplicationTransition(
        $clearKey,
        $institutionPublicId,
        $firstAccount['service_account_id'],
        (string) $application['public_id'],
        $request,
    );
    $reordered = $hasher->fingerprintApplicationTransition(
        $clearKey,
        $institutionPublicId,
        $firstAccount['service_account_id'],
        (string) $application['public_id'],
        array_reverse($request, true),
    );
    api_command_same($fingerprint->requestHash(), $reordered->requestHash(), 'Canonical request hash depends on object key order.');
    api_command_same($fingerprint->activeKeyHash(), $reordered->activeKeyHash(), 'Idempotency key hash changed with request field order.');
    api_command_assert(
        !hash_equals($fingerprint->activeKeyHash(), $keyring->sourceFingerprint($clearKey, $institutionPublicId)),
        'Command and source HMAC domains were not separated.',
    );
    api_command_rejects(
        static fn () => $hasher->fingerprintApplicationTransition(
            'short', $institutionPublicId, $firstAccount['service_account_id'], (string) $application['public_id'], $request,
        ),
        'Malformed command idempotency key was accepted.',
        InvalidApiCommandInput::class,
    );
    api_command_rejects(
        static fn () => $hasher->fingerprintApplicationTransition(
            str_repeat('b', 32), $institutionPublicId, $firstAccount['service_account_id'],
            (string) $application['public_id'], ['amount' => 1.5],
        ),
        'Ambiguous floating-point canonical request value was accepted.',
        InvalidApiCommandInput::class,
    );

    $store = new ApiCommandIdempotencyStore($pdo, $keyring);
    api_command_rejects(
        static fn () => $store->reserve($firstAccountId, $fingerprint),
        'Command store operated outside an active write transaction.',
        RuntimeException::class,
    );
    $response = ['meta' => ['request_id' => 'req_' . str_repeat('4', 32)], 'data' => ['status' => 'applied']];
    $etag = '"' . str_repeat('4', 64) . '"';
    $completed = WriteTransaction::run($pdo, static function () use (
        $store, $firstAccountId, $fingerprint, $response, $etag,
    ) {
        $reservation = $store->reserve($firstAccountId, $fingerprint);
        api_command_assert(!$reservation->isReplay(), 'First command reservation was unexpectedly a replay.');
        return $store->complete($firstAccountId, $fingerprint, $reservation, $response, 200, $etag);
    });
    api_command_assert($completed->isReplay(), 'Completed command result was not replayable.');
    $replay = WriteTransaction::run(
        $pdo,
        static fn () => $store->reserve($firstAccountId, $fingerprint),
    );
    api_command_same($completed->responseJson(), $replay->responseJson(), 'Identical retry did not return the exact stored response.');
    api_command_same(200, $replay->responseStatus(), 'Identical retry changed the stored response status.');
    api_command_same($etag, $replay->responseEtag(), 'Identical retry changed the stored ETag.');

    $changedFingerprint = $hasher->fingerprintApplicationTransition(
        $clearKey,
        $institutionPublicId,
        $firstAccount['service_account_id'],
        (string) $application['public_id'],
        [...$request, 'to_status' => 'interview'],
    );
    $changedConflict = api_command_rejects(
        static fn () => WriteTransaction::run($pdo, static fn () => $store->reserve($firstAccountId, $changedFingerprint)),
        'Same idempotency key with a changed request was accepted.',
        ApiCommandConflict::class,
    );
    api_command_same(ApiCommandConflict::REQUEST, $changedConflict->reason(), 'Changed request returned the wrong conflict classification.');
    $secondFingerprint = $hasher->fingerprintApplicationTransition(
        $clearKey,
        $institutionPublicId,
        $secondAccount['service_account_id'],
        (string) $application['public_id'],
        $request,
    );
    $accountConflict = api_command_rejects(
        static fn () => WriteTransaction::run($pdo, static fn () => $store->reserve($secondAccountId, $secondFingerprint)),
        'Same idempotency key under a changed service account was accepted.',
        ApiCommandConflict::class,
    );
    api_command_same(ApiCommandConflict::ACCOUNT, $accountConflict->reason(), 'Changed account returned the wrong conflict classification.');

    $rotatedKeyring = new ApiKeyring(['command-v1' => $rootOne, 'command-v2' => $rootTwo], 'command-v2');
    $rotatedFingerprint = (new ApiCommandHasher($rotatedKeyring))->fingerprintApplicationTransition(
        $clearKey,
        $institutionPublicId,
        $firstAccount['service_account_id'],
        (string) $application['public_id'],
        $request,
    );
    $rotatedReplay = WriteTransaction::run(
        $pdo,
        static fn () => (new ApiCommandIdempotencyStore($pdo, $rotatedKeyring))->reserve($firstAccountId, $rotatedFingerprint),
    );
    api_command_same($replay->responseJson(), $rotatedReplay->responseJson(), 'Key rotation lost exact completed replay.');
    $missingOldKeyring = new ApiKeyring(['command-v2' => $rootTwo], 'command-v2');
    $missingOldFingerprint = (new ApiCommandHasher($missingOldKeyring))->fingerprintApplicationTransition(
        str_repeat('b', 32),
        $institutionPublicId,
        $firstAccount['service_account_id'],
        (string) $application['public_id'],
        $request,
    );
    api_command_rejects(
        static fn () => WriteTransaction::run(
            $pdo,
            static fn () => (new ApiCommandIdempotencyStore($pdo, $missingOldKeyring))->reserve($firstAccountId, $missingOldFingerprint),
        ),
        'Missing referenced command key version did not fail closed.',
        ApiCommandUnavailable::class,
    );

    $rollbackFingerprint = $hasher->fingerprintApplicationTransition(
        str_repeat('c', 32),
        $institutionPublicId,
        $firstAccount['service_account_id'],
        (string) $application['public_id'],
        $request,
    );
    api_command_rejects(
        static function () use ($pdo, $store, $firstAccountId, $rollbackFingerprint): void {
            WriteTransaction::run($pdo, static function () use ($store, $firstAccountId, $rollbackFingerprint): void {
                $store->reserve($firstAccountId, $rollbackFingerprint);
                throw new RuntimeException('forced command rollback');
            });
        },
        'Forced command rollback did not fail.',
        RuntimeException::class,
    );
    $rollbackCount = $pdo->prepare('SELECT COUNT(*) FROM api_command_idempotency_keys WHERE key_hash = ?');
    $rollbackCount->execute([$rollbackFingerprint->activeKeyHash()]);
    api_command_same(0, (int) $rollbackCount->fetchColumn(), 'Rolled-back reservation remained durably pending.');

    $completionFingerprintA = $hasher->fingerprintApplicationTransition(
        str_repeat('5', 32),
        $institutionPublicId,
        $firstAccount['service_account_id'],
        (string) $application['public_id'],
        $request,
    );
    $completionFingerprintB = $hasher->fingerprintApplicationTransition(
        str_repeat('6', 32),
        $institutionPublicId,
        $firstAccount['service_account_id'],
        (string) $application['public_id'],
        $request,
    );
    api_command_rejects(
        static function () use (
            $pdo,
            $store,
            $firstAccountId,
            $completionFingerprintA,
            $completionFingerprintB,
            $response,
            $etag,
        ): void {
            WriteTransaction::run($pdo, static function () use (
                $store,
                $firstAccountId,
                $completionFingerprintA,
                $completionFingerprintB,
                $response,
                $etag,
            ): void {
                $reservation = $store->reserve($firstAccountId, $completionFingerprintA);
                $store->complete(
                    $firstAccountId,
                    $completionFingerprintB,
                    $reservation,
                    $response,
                    200,
                    $etag,
                );
            });
        },
        'A reservation was completed with a different clear-key fingerprint.',
        ApiCommandUnavailable::class,
    );
    $completionMismatchCount = $pdo->prepare(
        'SELECT COUNT(*) FROM api_command_idempotency_keys WHERE key_hash = ?',
    );
    $completionMismatchCount->execute([$completionFingerprintA->activeKeyHash()]);
    api_command_same(0, (int) $completionMismatchCount->fetchColumn(), 'Failed mismatched-key completion retained its reservation.');

    $pendingFingerprint = $hasher->fingerprintApplicationTransition(
        str_repeat('d', 32),
        $institutionPublicId,
        $firstAccount['service_account_id'],
        (string) $application['public_id'],
        $request,
    );
    WriteTransaction::run(
        $pdo,
        static fn () => $store->reserve($firstAccountId, $pendingFingerprint),
    );
    api_command_rejects(
        static fn () => WriteTransaction::run($pdo, static fn () => $store->reserve($firstAccountId, $pendingFingerprint)),
        'Committed pending command row was treated as permission to retry.',
        ApiCommandUnavailable::class,
    );

    $tableRows = json_encode(
        $pdo->query('SELECT * FROM api_command_idempotency_keys')->fetchAll(PDO::FETCH_ASSOC),
        JSON_THROW_ON_ERROR,
    );
    foreach ([$clearKey, $requestSentinel, $firstAccount['token'], 'Authorization', '/api/v1/applications'] as $secret) {
        api_command_assert(!str_contains($tableRows, $secret), 'Command idempotency table retained clear request or credential material.');
    }

    $old = '2001-01-01 00:00:00';
    $oldExpiry = '2001-01-03 00:00:00';
    $expiredInsert = $pdo->prepare(
        'INSERT INTO api_command_idempotency_keys
         (institution_id, service_account_id, operation, key_version, key_hash, request_hash,
          aggregate_public_id, lifecycle_state, created_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $defaultInstitutionId = (int) $pdo->query("SELECT id FROM institutions WHERE slug = 'default'")->fetchColumn();
    $expiredInsert->execute([
        $defaultInstitutionId, $firstAccountId, ApiCommandHasher::OPERATION, 'command-v1',
        str_repeat('e', 64), str_repeat('f', 64), $application['public_id'], 'pending', $old, $oldExpiry,
    ]);
    $otherApplicationPublicId = 'application_' . str_repeat('9', 32);
    $expiredInsert->execute([
        (int) $pdo->query("SELECT id FROM institutions WHERE slug = 'api-command-other'")->fetchColumn(),
        $other['id'], ApiCommandHasher::OPERATION, 'command-v1', str_repeat('1', 64),
        str_repeat('2', 64), $otherApplicationPublicId, 'pending', $old, $oldExpiry,
    ]);
    $pruned = (new ApiRetentionService($pdo))->prune(1, 1000);
    api_command_assert($pruned['command_idempotency_keys'] >= 1, 'API retention did not prune expired current-institution command keys.');
    $otherExpired = $pdo->prepare(
        'SELECT COUNT(*) FROM api_command_idempotency_keys WHERE institution_id = ? AND key_hash = ?',
    );
    $otherExpired->execute([
        (int) $pdo->query("SELECT id FROM institutions WHERE slug = 'api-command-other'")->fetchColumn(),
        str_repeat('1', 64),
    ]);
    api_command_same(1, (int) $otherExpired->fetchColumn(), 'API retention pruned another institution command key.');
    api_command_rejects(
        static fn (): bool => $expiredInsert->execute([
            $defaultInstitutionId, $firstAccountId, ApiCommandHasher::OPERATION, 'command-v1',
            str_repeat('3', 64), str_repeat('4', 64), $application['public_id'], 'pending',
            $old, '2001-01-02 23:59:59',
        ]),
        'Database accepted a command key with a retry horizon shorter than 48 hours.',
    );
    api_command_rejects(
        static fn (): bool => $expiredInsert->execute([
            $defaultInstitutionId, $other['id'], ApiCommandHasher::OPERATION, 'command-v1',
            str_repeat('5', 64), str_repeat('6', 64), $application['public_id'], 'pending', $old, $oldExpiry,
        ]),
        'Database accepted a cross-institution command account.',
    );

    echo 'PASS API application transition command contract (' . $driver . ")\n";
} finally {
    Database::reset();
    putenv('CPE_API_KEYRING');
    putenv('CPE_API_ACTIVE_KEY_VERSION');
    putenv('CPE_LOG_PATH');
    api_command_remove_tree($testRoot);
}
