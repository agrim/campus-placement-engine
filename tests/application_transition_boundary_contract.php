<?php

declare(strict_types=1);

$temporarySqlite = null;
$postgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
if (!$postgres) {
    $temporarySqlite = sys_get_temp_dir() . '/cpe-application-transition-boundary-' . bin2hex(random_bytes(4)) . '.sqlite';
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $temporarySqlite);
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/authorized_setup_recovery_fixture.php';

use App\Core\Http\UserVisibleException;
use App\Core\Portal;
use App\Domain\PlacementService as LegacyPlacementService;
use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Modules\Placement\Application\ApplicationTransitionActor;
use App\Modules\Placement\Application\ApplicationTransitionCommand;
use App\Modules\Placement\Application\ApplicationTransitionService;
use App\Support\Database;

function transition_boundary_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function transition_boundary_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')',
        );
    }
}

/** @return array{status: string, aggregate_version: int, events: int, workflow_events: int, outbox: int, audit: int} */
function transition_boundary_evidence(PDO $pdo, int $applicationId): array
{
    $application = $pdo->query(
        "SELECT current_status, aggregate_version FROM applications WHERE id = {$applicationId}",
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($application)) {
        throw new RuntimeException('Transition boundary fixture application is missing.');
    }
    return [
        'status' => (string) $application['current_status'],
        'aggregate_version' => (int) $application['aggregate_version'],
        'events' => (int) $pdo->query(
            "SELECT COUNT(*) FROM events WHERE application_id = {$applicationId}",
        )->fetchColumn(),
        'workflow_events' => (int) $pdo->query(
            "SELECT COUNT(*) FROM workflow_transition_events WHERE application_id = {$applicationId}",
        )->fetchColumn(),
        'outbox' => (int) $pdo->query(
            "SELECT COUNT(*)
             FROM domain_event_outbox event
             JOIN applications application ON application.public_id = event.aggregate_public_id
             WHERE application.id = {$applicationId}
               AND event.event_name = 'placement.application.transitioned'",
        )->fetchColumn(),
        'audit' => (int) $pdo->query(
            "SELECT COUNT(*) FROM audit_logs
             WHERE action = 'transition' AND subject_type = 'application' AND subject_id = {$applicationId}",
        )->fetchColumn(),
    ];
}

/** @return list<array<string, mixed>> */
function transition_boundary_rows(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string, list<array<string, mixed>>> */
function transition_boundary_workflow_sync_state(PDO $pdo): array
{
    return [
        'definitions' => transition_boundary_rows(
            $pdo,
            'SELECT * FROM workflow_definitions ORDER BY id',
        ),
        'versions' => transition_boundary_rows(
            $pdo,
            'SELECT * FROM workflow_versions ORDER BY id',
        ),
        'states' => transition_boundary_rows(
            $pdo,
            'SELECT * FROM workflow_states ORDER BY id',
        ),
        'transitions' => transition_boundary_rows(
            $pdo,
            'SELECT * FROM workflow_transitions ORDER BY id',
        ),
        'cycles' => transition_boundary_rows(
            $pdo,
            'SELECT * FROM placement_cycles ORDER BY id',
        ),
        'instances' => transition_boundary_rows(
            $pdo,
            'SELECT * FROM workflow_instances ORDER BY id',
        ),
        'marker' => transition_boundary_rows(
            $pdo,
            "SELECT key, value FROM settings WHERE key = 'workflow_legacy_mirror_checksum'",
        ),
        'status_overrides' => transition_boundary_rows(
            $pdo,
            'SELECT status_key, label, color FROM workflow_status_overrides ORDER BY status_key',
        ),
        'transition_overrides' => transition_boundary_rows(
            $pdo,
            'SELECT from_status, to_status, roles_csv FROM workflow_transition_overrides ORDER BY from_status, to_status',
        ),
    ];
}

function transition_boundary_legacy_mirror_checksum(PDO $pdo): string
{
    return hash('sha256', json_encode([
        'states' => transition_boundary_rows(
            $pdo,
            'SELECT status_key, label, color FROM workflow_status_overrides ORDER BY status_key',
        ),
        'transitions' => transition_boundary_rows(
            $pdo,
            'SELECT from_status, to_status, roles_csv FROM workflow_transition_overrides ORDER BY from_status, to_status',
        ),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function transition_boundary_command(int $applicationId, string $key, string $note): ApplicationTransitionCommand
{
    return new ApplicationTransitionCommand(
        $applicationId,
        'scheduled',
        '',
        $note,
        'idle',
        $key,
    );
}

function transition_boundary_expect_denied(
    ApplicationTransitionService $service,
    ApplicationTransitionCommand $command,
    ApplicationTransitionActor $actor,
    PDO $pdo,
): void {
    try {
        $service->execute($command, $actor);
    } catch (UserVisibleException $exception) {
        transition_boundary_same(
            ApplicationTransitionService::DENIED_CODE,
            $exception->publicCode(),
            'Transaction-boundary denial returned the wrong public code.',
        );
        transition_boundary_same(
            ApplicationTransitionService::DENIED_MESSAGE,
            $exception->publicMessage(),
            'Transaction-boundary denial changed the browser message.',
        );
        transition_boundary_same(
            [
                'status' => 'idle',
                'aggregate_version' => 1,
                'events' => 0,
                'workflow_events' => 0,
                'outbox' => 0,
                'audit' => 0,
            ],
            transition_boundary_evidence($pdo, $command->applicationId()),
            'Transaction-boundary denial retained transition evidence.',
        );
        $keyCount = $pdo->prepare('SELECT COUNT(*) FROM idempotency_keys WHERE key = ?');
        $keyCount->execute([$command->idempotencyKey()]);
        transition_boundary_same(
            0,
            (int) $keyCount->fetchColumn(),
            'Transaction-boundary denial retained an idempotency reservation.',
        );
        return;
    }
    throw new RuntimeException('Transaction-boundary authorization change did not deny the move.');
}

function transition_boundary_install_outbox_failure(
    PDO $pdo,
    bool $postgres,
    int $workflowVersionCountBefore,
): void
{
    if ($postgres) {
        $pdo->exec(
            "CREATE FUNCTION cpe_application_transition_boundary_outbox_fail()
             RETURNS trigger LANGUAGE plpgsql AS \$body\$
             BEGIN
                 IF (SELECT COUNT(*) FROM workflow_versions) <= {$workflowVersionCountBefore} THEN
                     RAISE EXCEPTION 'workflow synchronization did not precede transition';
                 END IF;
                 RAISE EXCEPTION 'forced transition boundary rollback';
             END;
             \$body\$",
        );
        try {
            $pdo->exec(
                'CREATE TRIGGER fail_application_transition_boundary_outbox
                 BEFORE INSERT ON domain_event_outbox
                 FOR EACH ROW EXECUTE FUNCTION cpe_application_transition_boundary_outbox_fail()',
            );
        } catch (Throwable $failure) {
            $pdo->exec('DROP FUNCTION cpe_application_transition_boundary_outbox_fail()');
            throw $failure;
        }
        return;
    }
    $pdo->exec(
        "CREATE TRIGGER fail_application_transition_boundary_outbox
         BEFORE INSERT ON domain_event_outbox
         WHEN (SELECT COUNT(*) FROM workflow_versions) > {$workflowVersionCountBefore}
         BEGIN SELECT RAISE(ABORT, 'forced transition boundary rollback'); END",
    );
}

function transition_boundary_drop_outbox_failure(PDO $pdo, bool $postgres): void
{
    if ($postgres) {
        $pdo->exec('DROP TRIGGER fail_application_transition_boundary_outbox ON domain_event_outbox');
        $pdo->exec('DROP FUNCTION cpe_application_transition_boundary_outbox_fail()');
        return;
    }
    $pdo->exec('DROP TRIGGER fail_application_transition_boundary_outbox');
}

try {
    (new SystemRequirements())->assertReady();
    Database::migrate();
    (new Installer())->install([
        'college_name' => 'Application Transition Boundary College',
        'timezone' => 'UTC',
        'admin_name' => 'Transition Administrator',
        'admin_email' => 'transition-admin@example.test',
        'admin_password' => 'transition-password-123',
        'seed_demo' => '1',
    ], test_authorized_setup_recovery_authority());
    Portal::reset();
    $pdo = Database::connection();

    $admin = $pdo->query(
        "SELECT id, role, scope_type, scope_value, active
         FROM users WHERE email = 'transition-admin@example.test'",
    )->fetch(PDO::FETCH_ASSOC);
    transition_boundary_assert(is_array($admin), 'Transition boundary requires its installed administrator.');
    $actor = ApplicationTransitionActor::fromAuthenticatedUser($admin);
    $fixtures = new LegacyPlacementService($pdo);
    $companyId = $fixtures->saveCompany([
        'code' => 'BNDRY',
        'name' => 'Boundary Contract Company',
    ], (int) $admin['id']);
    $applicationIds = [];
    for ($index = 1; $index <= 4; $index++) {
        $candidateId = $fixtures->saveCandidate([
            'external_id' => 'BND' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'name' => 'Boundary Candidate ' . $index,
        ], (int) $admin['id']);
        $fixtures->saveApplication($candidateId, $companyId, 'idle', null, (int) $admin['id']);
        $lookup = $pdo->prepare('SELECT id FROM applications WHERE candidate_id = ? AND company_id = ?');
        $lookup->execute([$candidateId, $companyId]);
        $applicationIds[] = (int) $lookup->fetchColumn();
    }
    transition_boundary_assert(
        !in_array(0, $applicationIds, true),
        'Transition boundary could not create its idle fixtures.',
    );

    $boundary = new ApplicationTransitionService($pdo);
    $legacy = new LegacyPlacementService($pdo);
    $boundaryKey = bin2hex(random_bytes(16));
    $boundaryCommand = transition_boundary_command($applicationIds[0], $boundaryKey, 'boundary parity');
    $boundaryFirst = $boundary->execute($boundaryCommand, $actor)->toArray();
    $boundaryEvidence = transition_boundary_evidence($pdo, $applicationIds[0]);
    $boundaryDuplicate = $boundary->execute($boundaryCommand, $actor)->toArray();
    transition_boundary_same(
        $boundaryEvidence,
        transition_boundary_evidence($pdo, $applicationIds[0]),
        'Boundary duplicate repeated status or transition evidence.',
    );

    $legacyKey = bin2hex(random_bytes(16));
    $legacyFirst = $legacy->applyBoardMove(
        $applicationIds[1],
        (int) $admin['id'],
        (string) $admin['role'],
        'scheduled',
        '',
        'legacy parity',
        'idle',
        $legacyKey,
        $admin,
    );
    $legacyEvidence = transition_boundary_evidence($pdo, $applicationIds[1]);
    $legacyDuplicate = $legacy->applyBoardMove(
        $applicationIds[1],
        (int) $admin['id'],
        (string) $admin['role'],
        'scheduled',
        '',
        'legacy parity',
        'idle',
        $legacyKey,
        $admin,
    );

    transition_boundary_same($legacyFirst, $boundaryFirst, 'Shared boundary changed the browser result shape.');
    transition_boundary_same($legacyDuplicate, $boundaryDuplicate, 'Shared boundary changed duplicate behavior.');
    transition_boundary_same($legacyEvidence, $boundaryEvidence, 'Shared boundary changed status/event/outbox behavior.');
    transition_boundary_same(
        [
            'status' => 'scheduled',
            'aggregate_version' => 2,
            'events' => 1,
            'workflow_events' => 1,
            'outbox' => 1,
            'audit' => 1,
        ],
        $boundaryEvidence,
        'Boundary transition evidence differs from the established browser contract.',
    );

    $currentMirrorChecksum = transition_boundary_legacy_mirror_checksum($pdo);
    $mirrorMarker = $pdo->prepare(
        "INSERT INTO settings (key, value) VALUES ('workflow_legacy_mirror_checksum', ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value",
    );
    $mirrorMarker->execute([$currentMirrorChecksum]);
    $staleOverride = $pdo->prepare(
        "INSERT INTO workflow_status_overrides (status_key, label, color)
         VALUES ('idle', 'Boundary Stale Idle', '#123456')
         ON CONFLICT(status_key) DO UPDATE SET label = excluded.label, color = excluded.color",
    );
    $staleOverride->execute();
    transition_boundary_assert(
        !hash_equals($currentMirrorChecksum, transition_boundary_legacy_mirror_checksum($pdo)),
        'Transition boundary could not create a stale legacy workflow mirror.',
    );

    $deniedApplicationId = $applicationIds[2];
    $pdo->prepare('UPDATE users SET active = 0 WHERE id = ?')->execute([(int) $admin['id']]);
    $workflowBeforeDeniedConstructor = transition_boundary_workflow_sync_state($pdo);
    $deniedService = new ApplicationTransitionService($pdo);
    transition_boundary_same(
        $workflowBeforeDeniedConstructor,
        transition_boundary_workflow_sync_state($pdo),
        'ApplicationTransitionService constructor synchronized the stale workflow mirror.',
    );
    transition_boundary_expect_denied(
        $deniedService,
        transition_boundary_command($deniedApplicationId, bin2hex(random_bytes(16)), 'inactive actor'),
        $actor,
        $pdo,
    );
    transition_boundary_same(
        $workflowBeforeDeniedConstructor,
        transition_boundary_workflow_sync_state($pdo),
        'Denied actor changed stale workflow synchronization state.',
    );
    $pdo->prepare('UPDATE users SET active = 1, role = ?, scope_type = ?, scope_value = ? WHERE id = ?')
        ->execute([$admin['role'], $admin['scope_type'], $admin['scope_value'], (int) $admin['id']]);

    $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute(['control', (int) $admin['id']]);
    transition_boundary_expect_denied(
        $deniedService,
        transition_boundary_command($deniedApplicationId, bin2hex(random_bytes(16)), 'changed role'),
        $actor,
        $pdo,
    );
    $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$admin['role'], (int) $admin['id']]);

    $pdo->prepare('UPDATE users SET scope_type = ?, scope_value = ? WHERE id = ?')
        ->execute(['company', 'ATLAS', (int) $admin['id']]);
    transition_boundary_expect_denied(
        $deniedService,
        transition_boundary_command($deniedApplicationId, bin2hex(random_bytes(16)), 'changed scope'),
        $actor,
        $pdo,
    );
    $pdo->prepare('UPDATE users SET scope_type = ?, scope_value = ? WHERE id = ?')
        ->execute([$admin['scope_type'], $admin['scope_value'], (int) $admin['id']]);

    $pdo->prepare("DELETE FROM role_capabilities WHERE role_key = ? AND capability = '*'")
        ->execute([(string) $admin['role']]);
    transition_boundary_expect_denied(
        $deniedService,
        transition_boundary_command($deniedApplicationId, bin2hex(random_bytes(16)), 'revoked capability'),
        $actor,
        $pdo,
    );
    $pdo->prepare('INSERT INTO role_capabilities (role_key, capability) VALUES (?, ?)')
        ->execute([(string) $admin['role'], '*']);

    transition_boundary_same(
        $workflowBeforeDeniedConstructor,
        transition_boundary_workflow_sync_state($pdo),
        'Denied actor role, scope, or capability changes synchronized the stale workflow mirror.',
    );

    $rollbackApplicationId = $applicationIds[3];
    $rollbackKey = bin2hex(random_bytes(16));
    $workflowBeforeRollback = transition_boundary_workflow_sync_state($pdo);
    $workflowVersionCountBefore = count($workflowBeforeRollback['versions']);
    transition_boundary_install_outbox_failure($pdo, $postgres, $workflowVersionCountBefore);
    try {
        $boundary->execute(
            transition_boundary_command($rollbackApplicationId, $rollbackKey, 'forced rollback'),
            $actor,
        );
        throw new RuntimeException('Transition boundary fault injection did not fail.');
    } catch (RuntimeException $exception) {
        transition_boundary_assert(
            str_contains($exception->getMessage(), 'forced transition boundary rollback'),
            'Forced nested-write failure returned an unexpected error.',
        );
    } finally {
        transition_boundary_drop_outbox_failure($pdo, $postgres);
    }
    transition_boundary_same(
        [
            'status' => 'idle',
            'aggregate_version' => 1,
            'events' => 0,
            'workflow_events' => 0,
            'outbox' => 0,
            'audit' => 0,
        ],
        transition_boundary_evidence($pdo, $rollbackApplicationId),
        'Nested transition writes escaped the shared outer transaction.',
    );
    transition_boundary_same(
        $workflowBeforeRollback,
        transition_boundary_workflow_sync_state($pdo),
        'Failed transition retained workflow mirror synchronization changes.',
    );
    $rollbackKeyCount = $pdo->prepare('SELECT COUNT(*) FROM idempotency_keys WHERE key = ?');
    $rollbackKeyCount->execute([$rollbackKey]);
    transition_boundary_same(
        0,
        (int) $rollbackKeyCount->fetchColumn(),
        'Nested transition failure retained its idempotency reservation.',
    );

    echo 'PASS application transition boundary contract (' . Database::driver() . " shared transaction)\n";
} finally {
    Database::reset();
    if ($temporarySqlite !== null) {
        foreach ([$temporarySqlite, $temporarySqlite . '-shm', $temporarySqlite . '-wal'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
