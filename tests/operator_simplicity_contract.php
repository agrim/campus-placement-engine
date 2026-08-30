<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/cpe-operator-simplicity-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create operator simplicity contract directory.');
}
$testRoot = realpath($testRoot) ?: $testRoot;
$postgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
if (!$postgres) {
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $testRoot . '/contract.sqlite');
}
putenv('CPE_BACKUP_DIR=' . $testRoot . '/backups');
putenv('CPE_LOG_PATH=' . $testRoot . '/structured.log');
putenv('CPE_HOSTED_MODE');
putenv('CPE_WEBHOOK_ALLOW_HTTP');
putenv('CPE_INTEGRATION_WORKER_CONFIGURED=1');
putenv('CPE_TRUST_PROXY_HEADERS=1');
putenv('CPE_ENGINE_ARTIFACT_SHA256=' . str_repeat('a', 64));
$keyMaterial = rtrim(strtr(base64_encode(str_repeat("\x6b", 32)), '+/', '-_'), '=');
putenv('CPE_WEBHOOK_ENCRYPTION_KEYS=operator-v1=' . $keyMaterial);
putenv('CPE_WEBHOOK_ACTIVE_KEY_VERSION=operator-v1');

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require $projectRoot . '/app/bootstrap.php';

use App\Core\Events\DomainEvent;
use App\Core\Events\PublicEventProjection;
use App\Core\Http\AuthorizationException;
use App\Core\Portal;
use App\Domain\ReadinessService;
use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Integrations\IntegrationState;
use App\Integrations\Webhooks\WebhookDeliveryWorker;
use App\Integrations\Webhooks\WebhookHttpResult;
use App\Integrations\Webhooks\WebhookHttpTransport;
use App\Integrations\Webhooks\WebhookSecretCipher;
use App\Integrations\Webhooks\WebhookSubscriptionService;
use App\Modules\Placement\Application\UniversityOperationsWorkspace;
use App\Modules\Placement\Install\LegacyDomainSynchronizer;
use App\Operations\SupportReportService;
use App\Support\Database;
use App\Support\StructuredLogger;
use App\Controllers\ReportsController;

function operator_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function operator_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

/** @param list<mixed> $values */
function operator_insert(PDO $pdo, string $sql, array $values): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($values);
    return Database::lastInsertId($pdo);
}

/** Executes a callback under a database-enforced read-only boundary. */
function operator_read_only(PDO $pdo, callable $callback): mixed
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'pgsql') {
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET TRANSACTION READ ONLY');
            return $callback();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    $pdo->exec('PRAGMA query_only = ON');
    try {
        return $callback();
    } finally {
        $pdo->exec('PRAGMA query_only = OFF');
    }
}

function operator_remove_tree(string $path): void
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

/** @return array{code: int, stdout: string, stderr: string} */
function operator_cli(string $projectRoot): array
{
    $process = proc_open(
        [PHP_BINARY, $projectRoot . '/placement', 'support-report'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $projectRoot,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start support-report CLI contract.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'code' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

final class OperatorContractTransport implements WebhookHttpTransport
{
    public int $calls = 0;

    public function send(string $endpointUrl, string $body, array $headers, bool $allowPrivateNetwork): WebhookHttpResult
    {
        $this->calls++;
        return new WebhookHttpResult(204);
    }
}

try {
    (new SystemRequirements())->assertReady();
    $adminId = (new Installer())->install([
        'college_name' => 'Operator Simplicity Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'Operator Contract Administrator',
        'admin_email' => 'operator-contract@example.test',
        'admin_password' => 'operator-contract-password-123',
        'seed_demo' => '0',
    ]);
    $pdo = Database::connection();
    $now = '2026-08-30 10:00:00';
    $operationRoutes = array_values(array_filter(
        cpe_context()->moduleManager()->routes(),
        static fn (array $route): bool => ($route['name'] ?? '') === 'operations',
    ));
    operator_same(1, count($operationRoutes), 'University operations workspace route is not registered exactly once.');
    operator_same('GET', $operationRoutes[0]['method'] ?? null, 'University operations workspace route must be read-only GET.');
    operator_same(ReportsController::class, $operationRoutes[0]['controller'] ?? null, 'University operations route controller differs.');
    $withheldMigration = '999_operator_private_sentinel.sql';
    $pdo->prepare('INSERT INTO migrations (migration, applied_at) VALUES (?, ?)')
        ->execute([$withheldMigration, $now]);

    $coverageCandidate = operator_insert(
        $pdo,
        'INSERT INTO candidates (external_id, name, program, current_location, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)',
        ['COVER-001', 'Sensitive Candidate Sentinel', '', 'CP', $now, $now],
    );
    $clashCandidate = operator_insert(
        $pdo,
        'INSERT INTO candidates (external_id, name, program, current_location, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)',
        ['CLASH-001', 'Clash Candidate', 'Computer Science', 'CP', $now, $now],
    );
    $repeatCandidate = operator_insert(
        $pdo,
        'INSERT INTO candidates (external_id, name, program, current_location, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)',
        ['REPEAT-001', 'Repeat Candidate', 'Electrical Engineering', 'CP', $now, $now],
    );

    $interviewCompany = operator_insert(
        $pdo,
        'INSERT INTO companies (code, name, process_type, max_active, deadline_day, deadline_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        ['INT', 'Interview Opportunity', 'interview', 4, '2026-08-31', '09:00', $now, $now],
    );
    $assessmentCompany = operator_insert(
        $pdo,
        'INSERT INTO companies (code, name, process_type, max_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)',
        ['ASM', 'Assessment Opportunity', 'assessment', 4, $now, $now],
    );
    operator_insert(
        $pdo,
        'INSERT INTO companies
         (code, name, process_type, max_active, deadline_day, deadline_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        ['ZERO', 'Uncovered Opportunity', 'interview', 10, str_repeat('9', 80), '09:00', $now, $now],
    );

    $clashInterviewApplication = operator_insert(
        $pdo,
        'INSERT INTO applications (candidate_id, company_id, current_status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?)',
        [$clashCandidate, $interviewCompany, 'scheduled', $now, $now],
    );
    $clashAssessmentApplication = operator_insert(
        $pdo,
        'INSERT INTO applications (candidate_id, company_id, current_status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?)',
        [$clashCandidate, $assessmentCompany, 'scheduled', $now, $now],
    );
    $repeatFirst = operator_insert(
        $pdo,
        'INSERT INTO applications (candidate_id, company_id, current_status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?)',
        [$repeatCandidate, $interviewCompany, 'idle', $now, $now],
    );
    $repeatSecond = operator_insert(
        $pdo,
        'INSERT INTO applications (candidate_id, company_id, current_status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?)',
        [$repeatCandidate, $assessmentCompany, 'idle', $now, $now],
    );
    $event = $pdo->prepare(
        'INSERT INTO events (application_id, candidate_id, company_id, from_status, to_status, actor_user_id, actor_role, note, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $event->execute([$repeatFirst, $repeatCandidate, $interviewCompany, 'scheduled', 'idle', $adminId, 'admin', '', $now]);
    $event->execute([$repeatSecond, $repeatCandidate, $assessmentCompany, 'scheduled', 'idle', $adminId, 'admin', '', $now]);

    (new LegacyDomainSynchronizer())->synchronize($pdo);
    $interviewRound = operator_insert(
        $pdo,
        'INSERT INTO company_rounds (company_id, sequence, label, round_type, created_at, updated_at)
         VALUES (?, 1, ?, ?, ?, ?)',
        [$interviewCompany, 'Panel interview', 'interview', $now, $now],
    );
    $assessmentRound = operator_insert(
        $pdo,
        'INSERT INTO company_rounds (company_id, sequence, label, round_type, created_at, updated_at)
         VALUES (?, 1, ?, ?, ?, ?)',
        [$assessmentCompany, 'Case assessment', 'assessment', $now, $now],
    );
    $interviewSchedule = operator_insert(
        $pdo,
        'INSERT INTO round_schedules (round_id, sequence, starts_at, ends_at, schedule_status, schedule_day, created_at, updated_at)
         VALUES (?, 1, ?, ?, ?, ?, ?, ?)',
        [$interviewRound, '10:00', '11:00', 'active', '2026-09-01', $now, $now],
    );
    $assessmentSchedule = operator_insert(
        $pdo,
        'INSERT INTO round_schedules (round_id, sequence, starts_at, ends_at, schedule_status, schedule_day, created_at, updated_at)
         VALUES (?, 1, ?, ?, ?, ?, ?, ?)',
        [$assessmentRound, '10:30', '11:30', 'active', '2026-09-01', $now, $now],
    );
    operator_insert(
        $pdo,
        'INSERT INTO application_slot_assignments (application_id, round_schedule_id, assignment_status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?)',
        [$clashInterviewApplication, $interviewSchedule, 'assigned', $now, $now],
    );
    $assessmentAssignment = operator_insert(
        $pdo,
        'INSERT INTO application_slot_assignments (application_id, round_schedule_id, assignment_status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?)',
        [$clashAssessmentApplication, $assessmentSchedule, 'assigned', $now, $now],
    );

    $pdo->exec("UPDATE module_installations SET enabled = 1 WHERE module_key = 'advising'");
    $institutionId = (int) $pdo->query("SELECT id FROM institutions WHERE slug = 'default'")->fetchColumn();
    $coverageProfile = (int) $pdo->query(
        'SELECT sp.id FROM student_profiles sp JOIN people p ON p.id = sp.person_id WHERE p.legacy_candidate_id = ' . $coverageCandidate,
    )->fetchColumn();
    operator_insert(
        $pdo,
        'INSERT INTO advising_tasks
         (public_id, institution_id, student_profile_id, task_type, task_status, title, due_on,
          detail, source_event_name, source_aggregate_public_id, subject_reference, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['task_' . str_repeat('1', 32), $institutionId, $coverageProfile, 'outreach', 'open',
         'Review candidate coverage', '2026-08-31', '', '', '', 'candidate-support-reference', $now, $now],
    );

    $transport = new OperatorContractTransport();
    $integrationService = new WebhookSubscriptionService($pdo, $transport, WebhookSecretCipher::fromEnvironment());
    $integration = $integrationService->create(
        'Sensitive Integration Name',
        'https://private-support.example.test/cpe',
        true,
        false,
        $adminId,
    );
    operator_assert(is_string($integration['signing_secret']), 'Integration setup did not return the one-time signing secret.');
    $integrationService->validate($integration['subscription_id'], $adminId);
    $integrationService->activate($integration['subscription_id'], $adminId);
    operator_same(1, $transport->calls, 'Integration validation did not use the isolated synthetic request.');
    (new WebhookDeliveryWorker($pdo, $transport, WebhookSecretCipher::fromEnvironment()))->work(1);
    $incidentId = 'inc_' . str_repeat('b', 32);
    $failure = $pdo->prepare('UPDATE webhook_subscriptions SET last_failure_reference = ?, updated_at = ? WHERE public_id = ?');
    $failure->execute(['CPE_WEBHOOK_DELIVERY_FAILED Reference: ' . $incidentId, $now, $integration['subscription_id']]);

    $applicationPublicId = (string) $pdo->query(
        'SELECT public_id FROM applications WHERE id = ' . $clashInterviewApplication,
    )->fetchColumn();
    $instancePublicId = (string) $pdo->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn();
    cpe_context()->events()->dispatch(new DomainEvent(
        'placement.application.transitioned',
        'placement_application',
        $applicationPublicId,
        'placement',
        ['private_marker' => 'private-event-payload-sentinel'],
        cpe_now(),
        PublicEventProjection::applicationStatusChanged(
            $instancePublicId,
            $applicationPublicId,
            2,
            'idle',
            'scheduled',
            StructuredLogger::requestId(),
        ),
    ));

    $workspace = operator_read_only(
        $pdo,
        static fn (): array => (new UniversityOperationsWorkspace(
            $pdo,
            null,
            new DateTimeImmutable('2026-08-30T10:00:00Z', new DateTimeZone('UTC')),
        ))->snapshot(true),
    );
    operator_assert($workspace['queues']['coverage_needed']['count'] >= 2, 'Coverage queue missed candidates with no active application.');
    operator_same(1, $workspace['queues']['schedule_clashes']['count'], 'Overlapping interview/assessment slots were not reconciled.');
    operator_same(2, $workspace['queues']['attendance_follow_up']['count'], 'Non-confirmed attendance rows were not surfaced.');
    operator_same(1, $workspace['queues']['repeated_no_progress']['count'], 'Repeated no-progress evidence was not surfaced.');
    operator_same(1, $workspace['queues']['low_coverage_opportunities']['count'], 'Zero-link opportunity coverage signal differs.');
    operator_same(1, $workspace['queues']['adviser_actions_due']['count'], 'Due adviser action was not reconciled.');
    operator_assert($workspace['queues']['configured_deadlines']['count'] >= 1, 'Approaching process cut-off was not surfaced.');
    operator_assert(
        in_array('Needs date setup', array_column($workspace['queues']['configured_deadlines']['rows'], 'deadline_status'), true),
        'Invalid or unbounded deadline-day input was not contained as setup work.',
    );
    operator_assert(str_contains($workspace['evidence_notes']['coverage'], 'proxy'), 'Coverage approximation is not labelled honestly.');
    $clashKeys = array_keys($workspace['queues']['schedule_clashes']['rows'][0]);
    operator_assert(
        array_intersect($clashKeys, ['owner', 'contact_state', 'escalation_state', 'escalation_deadline']) === [],
        'Clash queue invented owner, contact, or escalation state.',
    );
    $pdo->prepare('UPDATE application_slot_assignments SET assignment_status = ?, updated_at = ? WHERE id = ?')
        ->execute(['no_show', $now, $assessmentAssignment]);
    $afterNoShow = operator_read_only(
        $pdo,
        static fn (): array => (new UniversityOperationsWorkspace(
            $pdo,
            null,
            new DateTimeImmutable('2026-08-30T10:00:00Z', new DateTimeZone('UTC')),
        ))->snapshot(true),
    );
    operator_same(0, $afterNoShow['queues']['schedule_clashes']['count'], 'Terminal attendance state remained a live clash.');
    operator_assert(
        in_array('Attendance follow-up', array_column($afterNoShow['queues']['attendance_follow_up']['rows'], 'follow_up'), true),
        'No-show assignment did not remain available for attendance follow-up.',
    );

    $roleNow = cpe_now();
    $pdo->prepare(
        'INSERT INTO roles (role_key, label, system_role, created_at, updated_at)
         VALUES (?, ?, 0, ?, ?) ON CONFLICT(role_key) DO NOTHING',
    )->execute(['coverage_contract', 'Coverage Contract', $roleNow, $roleNow]);
    $pdo->prepare(
        'INSERT INTO role_capabilities (role_key, capability) VALUES (?, ?)
         ON CONFLICT(role_key, capability) DO NOTHING',
    )->execute(['coverage_contract', 'placement.reports.view']);
    $restrictedUser = operator_insert(
        $pdo,
        'INSERT INTO users (name, email, password_hash, role, active, created_at)
         VALUES (?, ?, ?, ?, 1, ?)',
        ['Coverage Contract User', 'coverage-contract@example.test', password_hash('coverage-contract-password', PASSWORD_DEFAULT), 'coverage_contract', $roleNow],
    );
    $_SESSION = ['user_id' => $restrictedUser];
    Portal::reset();
    $restrictedRoutes = array_column(cpe_context()->moduleManager()->navigation([
        'role' => 'coverage_contract',
        'active' => 1,
    ]), 'route');
    operator_assert(
        !in_array('operations', $restrictedRoutes, true),
        'Reports-only role was shown a candidate-level workspace it cannot open.',
    );
    $denied = false;
    try {
        operator_read_only($pdo, static fn (): mixed => (new ReportsController())->operations());
    } catch (AuthorizationException) {
        $denied = true;
    }
    operator_assert($denied, 'Reports-only role reached candidate-level operations without sensitive-record access.');

    $pdo->prepare(
        'INSERT INTO role_capabilities (role_key, capability) VALUES (?, ?)
         ON CONFLICT(role_key, capability) DO NOTHING',
    )->execute(['coverage_contract', 'placement.sensitive.view']);
    Portal::reset();
    $authorizedRoutes = array_column(cpe_context()->moduleManager()->navigation([
        'role' => 'coverage_contract',
        'active' => 1,
    ]), 'route');
    operator_assert(
        in_array('operations', $authorizedRoutes, true),
        'Reports-plus-sensitive role was not shown its authorized workspace.',
    );
    ob_start();
    operator_read_only($pdo, static fn (): mixed => (new ReportsController())->operations());
    $authorizedHtml = (string) ob_get_clean();
    operator_assert(str_contains($authorizedHtml, 'Maximise candidate opportunities'), 'Authorized operations workspace did not render.');
    operator_assert(str_contains($authorizedHtml, 'Ask an authorized operator'), 'Read-only workspace did not explain unavailable actions.');
    operator_assert(!str_contains($authorizedHtml, '?r=records'), 'Read-only workspace exposed a records mutation action.');

    $readiness = operator_read_only($pdo, static fn (): array => (new ReadinessService($pdo))->snapshot());
    $readinessLabels = array_column($readiness['checks'], 'status', 'label');
    foreach (['Integration worker schedule', 'Integration delivery backlog', 'Webhook TLS policy', 'Integration secret encryption', 'Integration database driver'] as $label) {
        operator_assert(array_key_exists($label, $readinessLabels), 'Readiness omitted ' . $label . '.');
    }
    operator_same('ok', $readinessLabels['Integration worker schedule'], 'Fresh configured Integration worker did not pass readiness.');
    operator_same('ok', $readinessLabels['Integration database driver'], 'Current PDO driver did not pass Integration readiness.');

    $reportService = new SupportReportService($pdo);
    $report = operator_read_only($pdo, static fn (): array => $reportService->snapshot());
    operator_same(
        ['schema_version', 'generated_at', 'engine', 'runtime', 'migrations', 'enabled_module_ids',
         'capability_catalog_revision', 'integrations', 'delivery', 'incident_ids', 'scheduler', 'transport_policy'],
        array_keys($report),
        'Support report top-level allowlist changed.',
    );
    operator_same(str_repeat('a', 64), $report['engine']['artifact_sha256'], 'Engine artifact digest was not normalized.');
    operator_assert(!in_array('operations', $report['enabled_module_ids'], true), 'Workspace must not create a duplicate Module.');
    operator_same(1, $report['delivery']['pending_count'], 'Support report backlog count differs.');
    operator_same([$incidentId], $report['incident_ids'], 'Support report incident allowlist differs.');
    operator_same(1, $report['integrations']['total_count'], 'Support report Integration count differs.');
    operator_same(false, $report['integrations']['truncated'], 'Single Integration should not truncate.');
    operator_same('Active', $report['integrations']['items'][0]['state_label'], 'Institution-visible Integration state label differs.');
    operator_same(1, $report['migrations']['unrecognized_applied_count'], 'Unknown migration should be counted without disclosure.');
    operator_same('fresh', $report['scheduler']['freshness'], 'Support report scheduler freshness differs.');
    operator_same(true, $report['transport_policy']['inbound_proxy_headers_trusted'], 'Redacted proxy policy differs.');
    operator_assert(!array_key_exists('identity', $report['transport_policy']['database_tls']), 'Support report exposed database identity.');
    operator_same(
        ['disabled', 'setup_required', 'validating', 'active', 'degraded'],
        IntegrationState::values(),
        'Integration lifecycle vocabulary differs.',
    );

    $json = $reportService->json();
    foreach ([
        'Sensitive Candidate Sentinel',
        'Sensitive Integration Name',
        'private-support.example.test',
        'private-event-payload-sentinel',
        (string) $integration['signing_secret'],
        $keyMaterial,
        $testRoot,
        'operator-contract@example.test',
        $withheldMigration,
    ] as $forbidden) {
        operator_assert($forbidden === '' || !str_contains($json, $forbidden), 'Support report exposed a forbidden value.');
    }
    $databaseUrl = trim((string) (getenv('CPE_DATABASE_URL') ?: ''));
    operator_assert($databaseUrl === '' || !str_contains($json, $databaseUrl), 'Support report exposed the database URL.');

    $cli = operator_cli($projectRoot);
    operator_same(0, $cli['code'], 'support-report CLI failed: ' . $cli['stderr']);
    $cliReport = json_decode($cli['stdout'], true, 32, JSON_THROW_ON_ERROR);
    operator_same(1, $cliReport['schema_version'] ?? null, 'support-report CLI did not emit the reviewed JSON schema.');
    operator_assert(!str_contains($cli['stdout'], 'private-support.example.test'), 'support-report CLI exposed an endpoint.');
    operator_assert(!str_contains($cli['stdout'], $withheldMigration), 'support-report CLI exposed an unknown migration identifier.');

    echo 'Operator simplicity contract passed (' . ($postgres ? 'pgsql' : 'sqlite') . ").\n";
} finally {
    $_SESSION = [];
    Portal::reset();
    Database::reset();
    operator_remove_tree($testRoot);
}
