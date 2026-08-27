<?php

declare(strict_types=1);

$temporarySqlite = null;
if (trim((string) (getenv('CPE_DATABASE_URL') ?: '')) === ''
    && !in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true)) {
    $temporarySqlite = sys_get_temp_dir() . '/cpe-database-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    putenv('CPE_DB_PATH=' . $temporarySqlite);
}

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Modules\ModuleLifecycleService;
use App\Core\Events\DomainEvent;
use App\Core\Events\DomainEventOutboxWorker;
use App\Domain\ReadinessService;
use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Modules\Placement\Application\PlacementService;
use App\Modules\Placement\Portability\PlacementPortabilityHandler;
use App\Modules\Advising\Application\AdvisingService;
use App\Modules\Advising\Portability\AdvisingPortabilityHandler;
use App\Security\DatabaseSessionHandler;
use App\Support\Database;

$outboxPath = sys_get_temp_dir() . '/cpe-database-contract-events-' . bin2hex(random_bytes(4)) . '.jsonl';
function contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    contract_assert(!Database::isInstalled(), 'Database contract requires an empty target database.');
    (new SystemRequirements())->assertReady();
    Database::migrate();
    contract_assert(!Database::isInstalled(), 'A migration-only run must not mark the application installed.');
    contract_assert(
        str_starts_with(
            (string) Database::connection()->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn(),
            'unbound_',
        ),
        'A migration-only run must reserve an explicitly unbound institution identity.',
    );
    (new Installer())->install([
        'college_name' => 'Database Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'Contract Administrator',
        'admin_email' => 'contract-admin@example.test',
        'admin_password' => 'contract-password-123',
        'seed_demo' => '1',
    ]);

    $pdo = Database::connection();
    $placement = new PlacementService($pdo);
    contract_assert((int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn() === 5, 'Demo candidate count differs.');
    contract_assert((int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn() === 3, 'Demo company count differs.');
    contract_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn()
            === (int) $pdo->query('SELECT COUNT(*) FROM workflow_instances')->fetchColumn(),
        'Every application must have a workflow instance.'
    );
    contract_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn()
            === (int) $pdo->query('SELECT COUNT(*) FROM people')->fetchColumn(),
        'Every candidate must have a durable person record.'
    );
    contract_assert(count($placement->dashboard(['id' => 1, 'role' => 'admin', 'active' => 1])) > 0, 'Board aggregation returned no groups.');

    $applicationId = (int) $pdo->query("SELECT id FROM applications WHERE current_status = 'idle' ORDER BY id LIMIT 1")->fetchColumn();
    contract_assert($applicationId > 0, 'Demo data needs an idle application for transition testing.');
    $placement->moveNext($applicationId, 1, 'admin');
    contract_assert(
        (string) $pdo->query("SELECT current_status FROM applications WHERE id = {$applicationId}")->fetchColumn() === 'scheduled',
        'Workflow transition did not persist.'
    );
    contract_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM workflow_transition_events WHERE application_id = {$applicationId}")->fetchColumn() === 1,
        'Workflow transition event was not persisted.'
    );
    contract_assert((int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn() >= 1, 'Domain event outbox was not written.');
    putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH=' . $outboxPath);
    $outboxResult = (new DomainEventOutboxWorker($pdo))->work(100);
    contract_assert($outboxResult['delivered'] >= 1 && is_file($outboxPath), 'Domain event outbox delivery differs by database driver.');

    $sessionWriter = new DatabaseSessionHandler($pdo, 3600);
    contract_assert($sessionWriter->write('database-contract-session', 'database-contract-payload'), 'Database session write failed.');
    $sessionWriter->close();
    $sessionReader = new DatabaseSessionHandler($pdo, 3600);
    contract_assert($sessionReader->read('database-contract-session') === 'database-contract-payload', 'Database session read or lock release failed.');
    $sessionReader->destroy('database-contract-session');
    $sessionReader->close();

    $candidateId = (int) $pdo->query("SELECT id FROM candidates WHERE external_id = 'C003'")->fetchColumn();
    $companyIds = array_map('intval', $pdo->query('SELECT id FROM companies ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
    $placement->createPreferenceRequest($candidateId, $companyIds, 1, 'Database contract preference');
    $placement->createWantedAlert($candidateId, 'Database contract wanted alert', 1);
    contract_assert((int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() >= 2, 'Operational notifications were not created.');

    $large = $placement->seedLargeDemo(12, 3);
    contract_assert($large['candidates'] === 12 && $large['companies'] === 3, 'Large demo pattern matching differs by database driver.');
    contract_assert((new ReadinessService($pdo))->snapshot()['checks'] !== [], 'Readiness checks returned no results.');

    $handler = new PlacementPortabilityHandler($pdo);
    $portable = $handler->export();
    $summary = $handler->validate($portable);
    contract_assert($summary['candidates'] === 17, 'Placement portability candidate count differs.');

    $lifecycle = new ModuleLifecycleService($pdo);
    $lifecycle->enable('advising', 1);
    $studentId = (int) $pdo->query('SELECT id FROM student_profiles ORDER BY id LIMIT 1')->fetchColumn();
    $advising = new AdvisingService($pdo);
    $start = (new DateTimeImmutable('+1 day', new DateTimeZone('UTC')))->format('Y-m-d\TH:i');
    $end = (new DateTimeImmutable('+1 day 30 minutes', new DateTimeZone('UTC')))->format('Y-m-d\TH:i');
    $appointmentId = $advising->createAppointment([
        'student_profile_id' => $studentId,
        'adviser_user_id' => 1,
        'appointment_status' => 'scheduled',
        'starts_at' => $start,
        'ends_at' => $end,
        'appointment_mode' => 'video',
        'topic' => 'Database contract advising',
    ], 1);
    $advising->addNote($appointmentId, 'Database contract note.', 1);
    $candidatePublicId = (string) $pdo->query(
        "SELECT c.public_id FROM candidates c JOIN people p ON p.legacy_candidate_id = c.id
         JOIN student_profiles sp ON sp.person_id = p.id WHERE sp.id = {$studentId}"
    )->fetchColumn();
    cpe_context()->events()->dispatch(new DomainEvent(
        'placement.offer.accepted',
        'placement_application',
        'application_' . str_repeat('c', 32),
        'placement',
        ['candidate_public_id' => $candidatePublicId],
        cpe_now(),
    ));
    contract_assert((int) $pdo->query('SELECT COUNT(*) FROM advising_tasks')->fetchColumn() === 1, 'Advising event subscriber differs.');
    $advisingPortable = (new AdvisingPortabilityHandler($pdo))->export();
    contract_assert((new AdvisingPortabilityHandler($pdo))->validate($advisingPortable)['appointments'] === 1, 'Advising portability differs.');

    $applicationCount = (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn();
    $lifecycle->disable('placement', 1);
    $lifecycle->enable('placement', 1);
    contract_assert((int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn() === $applicationCount, 'Module lifecycle deleted data.');

    $legacyError = 'Legacy sentinel.admin@example.test postgresql://root:password@db/private/sentinel SQLSTATE[23505] DETAIL: person=Ada_Lovelace';
    $notificationId = (int) $pdo->query('SELECT id FROM notifications ORDER BY id LIMIT 1')->fetchColumn();
    contract_assert($notificationId > 0, 'Legacy error redaction test requires a notification.');
    $insertDelivery = $pdo->prepare(
        'INSERT INTO notification_deliveries
         (notification_id, channel, target, status, attempt_count, last_error, payload_json, created_at, updated_at,
          available_at, idempotency_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $deliveryCreatedAt = cpe_now();
    $insertDelivery->execute([
        $notificationId,
        'file',
        '[config:notification_file]',
        'failed',
        7,
        $legacyError,
        '{}',
        $deliveryCreatedAt,
        $deliveryCreatedAt,
        $deliveryCreatedAt,
        'ndk_' . bin2hex(random_bytes(16)),
    ]);
    $deliveryId = Database::lastInsertId($pdo);
    $eventId = (int) $pdo->query('SELECT id FROM domain_event_outbox ORDER BY id LIMIT 1')->fetchColumn();
    contract_assert($eventId > 0, 'Legacy error redaction test requires a domain event.');
    $eventUpdate = $pdo->prepare(
        'UPDATE domain_event_outbox SET attempts = ?, last_error = ?, failed_at = ? WHERE id = ?'
    );
    $eventUpdate->execute([8, $legacyError, cpe_now(), $eventId]);

    $migrationName = Database::driver() === 'pgsql'
        ? '007_error_detail_redaction.sql'
        : '043_error_detail_redaction.sql';
    $deleteMigration = $pdo->prepare('DELETE FROM migrations WHERE migration = ?');
    $deleteMigration->execute([$migrationName]);
    contract_assert($deleteMigration->rowCount() === 1, 'Legacy error redaction migration must already be registered.');
    Database::migrate(false);

    $delivery = $pdo->query(
        'SELECT status, attempt_count, last_error FROM notification_deliveries WHERE id = ' . $deliveryId
    )->fetch();
    contract_assert((string) $delivery['status'] === 'failed', 'Legacy notification status changed during redaction.');
    contract_assert((int) $delivery['attempt_count'] === 7, 'Legacy notification retry evidence changed during redaction.');
    contract_assert(
        (string) $delivery['last_error'] === 'CPE_LEGACY_ERROR_REDACTED Reference: inc_unavailable',
        'Legacy notification error detail was not redacted.',
    );
    $event = $pdo->query(
        'SELECT attempts, failed_at, last_error FROM domain_event_outbox WHERE id = ' . $eventId
    )->fetch();
    contract_assert((int) $event['attempts'] === 8, 'Legacy domain-event retry evidence changed during redaction.');
    contract_assert((string) $event['failed_at'] !== '', 'Legacy domain-event dead-letter evidence changed during redaction.');
    contract_assert(
        (string) $event['last_error'] === 'CPE_LEGACY_ERROR_REDACTED Reference: inc_unavailable',
        'Legacy domain-event error detail was not redacted.',
    );

    $auditCreatedAt = cpe_now();
    $auditInsert = $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, subject_type, subject_id, detail, ip_address, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $auditInsert->execute([
        null,
        'candidate.update',
        'candidate',
        8675309,
        $legacyError,
        '203.0.113.25',
        'Sentinel Browser ' . $legacyError,
        $auditCreatedAt,
    ]);
    $auditId = Database::lastInsertId($pdo);
    $auditMigration = Database::driver() === 'pgsql'
        ? '008_audit_detail_redaction.sql'
        : '044_audit_detail_redaction.sql';
    $deleteMigration->execute([$auditMigration]);
    contract_assert($deleteMigration->rowCount() === 1, 'Audit redaction migration must already be registered.');
    Database::migrate(false);
    $audit = $pdo->query(
        'SELECT action, subject_type, subject_id, detail, ip_address, user_agent, created_at FROM audit_logs WHERE id = ' . $auditId
    )->fetch();
    contract_assert((string) $audit['action'] === 'candidate.update', 'Audit action changed during redaction.');
    contract_assert((string) $audit['subject_type'] === 'candidate', 'Audit subject type changed during redaction.');
    contract_assert((int) $audit['subject_id'] === 8675309, 'Audit subject id changed during redaction.');
    contract_assert((string) $audit['created_at'] === $auditCreatedAt, 'Audit timestamp changed during redaction.');
    contract_assert((string) $audit['detail'] === 'Legacy audit detail redacted.', 'Legacy audit detail was not redacted.');
    contract_assert((string) $audit['ip_address'] === '', 'Legacy audit IP was not redacted.');
    contract_assert((string) $audit['user_agent'] === '', 'Legacy audit user agent was not redacted.');

    echo 'PASS database contract (' . Database::driver() . ' ' . Database::serverVersion() . ")\n";
} finally {
    putenv('CPE_DOMAIN_EVENT_OUTBOX_PATH');
    Database::reset();
    if (is_file($outboxPath)) {
        unlink($outboxPath);
    }
    if ($temporarySqlite !== null && is_file($temporarySqlite)) {
        unlink($temporarySqlite);
    }
}
