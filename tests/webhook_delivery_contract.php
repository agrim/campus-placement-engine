<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/cpe-webhook-delivery-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create webhook contract directory.');
}
$testRoot = realpath($testRoot) ?: $testRoot;
putenv('CPE_LOG_PATH=' . $testRoot . '/structured.log');
$postgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== ''
    || in_array(strtolower((string) (getenv('CPE_DB_DRIVER') ?: '')), ['pgsql', 'postgresql'], true);
if (!$postgres) {
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $testRoot . '/contract.sqlite');
}
$keyMaterial = rtrim(strtr(base64_encode(str_repeat("\x5a", 32)), '+/', '-_'), '=');
putenv('CPE_WEBHOOK_ENCRYPTION_KEYS=contract-v1=' . $keyMaterial);
putenv('CPE_WEBHOOK_ACTIVE_KEY_VERSION=contract-v1');
putenv('CPE_WEBHOOK_MAX_ATTEMPTS=10');
putenv('CPE_WEBHOOK_ENDPOINT_CONCURRENCY=1');
putenv('CPE_WEBHOOK_INSTITUTION_CONCURRENCY=20');

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require $projectRoot . '/app/bootstrap.php';
require $projectRoot . '/tests/authorized_setup_recovery_fixture.php';

use App\Core\Events\DomainEvent;
use App\Core\Events\EventDispatcher;
use App\Core\Events\PublicEventProjection;
use App\Install\Installer;
use App\Install\SystemRequirements;
use App\Integrations\Webhooks\WebhookDeliveryReplayService;
use App\Integrations\Webhooks\WebhookDeliveryWorker;
use App\Integrations\Webhooks\WebhookHealthService;
use App\Integrations\Webhooks\WebhookHttpResult;
use App\Integrations\Webhooks\WebhookHttpTransport;
use App\Integrations\Webhooks\WebhookSecretCipher;
use App\Integrations\Webhooks\WebhookSigner;
use App\Integrations\Webhooks\WebhookSubscriptionService;
use App\Integrations\Webhooks\WebhookTransportException;
use App\Security\OutboundHttpPolicy;
use App\Support\Database;
use App\Support\StructuredLogger;

function webhook_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function webhook_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function webhook_rejects(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $failure) {
        return $failure;
    }
    throw new RuntimeException($message);
}

function webhook_remove_tree(string $path): void
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

final class WebhookContractTransport implements WebhookHttpTransport
{
    /** @var list<WebhookHttpResult|Throwable> */
    public array $outcomes = [];
    /** @var list<array<string, mixed>> */
    public array $calls = [];
    public mixed $beforeSend = null;

    public function send(string $endpointUrl, string $body, array $headers, bool $allowPrivateNetwork): WebhookHttpResult
    {
        $this->calls[] = compact('endpointUrl', 'body', 'headers', 'allowPrivateNetwork');
        if (is_callable($this->beforeSend)) {
            $callback = $this->beforeSend;
            $this->beforeSend = null;
            $callback();
        }
        $outcome = array_shift($this->outcomes) ?? new WebhookHttpResult(204);
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }
        return $outcome;
    }
}

/** @return array{event_id: string, delivery_count: int} */
function webhook_dispatch(PDO $pdo, string $aggregateId, int $version, string $from, string $to): array
{
    $instance = (string) $pdo->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn();
    $eventId = cpe_context()->events()->dispatch(new DomainEvent(
        'placement.application.transitioned',
        'placement_application',
        $aggregateId,
        'placement',
        ['private_application_id' => 999, 'private_marker' => 'must-not-leave-engine'],
        cpe_now(),
        PublicEventProjection::applicationStatusChanged(
            $instance,
            $aggregateId,
            $version,
            $from,
            $to,
            StructuredLogger::requestId(),
        ),
    ));
    $query = $pdo->prepare(
        'SELECT COUNT(*) FROM webhook_deliveries delivery
         JOIN domain_event_outbox event ON event.id = delivery.event_id
         WHERE event.public_id = ?',
    );
    $query->execute([$eventId]);
    return ['event_id' => $eventId, 'delivery_count' => (int) $query->fetchColumn()];
}

/** @return array{public_id: string, secret: string} */
function webhook_active_subscription(PDO $pdo, WebhookContractTransport $transport, string $name): array
{
    $service = new WebhookSubscriptionService($pdo, $transport, WebhookSecretCipher::fromEnvironment());
    $created = $service->create($name, 'https://hooks.example.test/cpe', true, false, 1);
    webhook_assert(is_string($created['signing_secret']), 'Creation did not return the one-time signing secret.');
    $service->validate($created['subscription_id'], 1);
    $service->activate($created['subscription_id'], 1);
    return ['public_id' => $created['subscription_id'], 'secret' => $created['signing_secret']];
}

try {
    putenv('CPE_WEBHOOK_ENCRYPTION_KEYS');
    putenv('CPE_WEBHOOK_ACTIVE_KEY_VERSION');
    (new SystemRequirements())->assertReady();
    webhook_same(false, WebhookSecretCipher::environmentStatus()['present'], 'Missing webhook keyring did not remain an optional setup state.');
    $emptyHealthDatabase = new PDO('sqlite::memory:');
    webhook_same(
        0,
        (new WebhookHealthService($emptyHealthDatabase))->snapshot()['configured'],
        'Absent webhook health tables were not reported as an unconfigured installation.',
    );
    $partialHealthDatabase = new PDO('sqlite::memory:');
    $partialHealthDatabase->exec('CREATE TABLE webhook_deliveries (id INTEGER PRIMARY KEY)');
    webhook_rejects(
        static fn (): array => (new WebhookHealthService($partialHealthDatabase))->snapshot(),
        'Partially installed webhook health storage was silently reported as healthy and unconfigured.',
    );
    $damagedHealthDatabase = new PDO('sqlite::memory:');
    $damagedHealthDatabase->exec('CREATE TABLE webhook_subscriptions (unexpected TEXT NOT NULL)');
    $damagedHealthDatabase->exec('CREATE TABLE webhook_subscription_events (unexpected TEXT NOT NULL)');
    $damagedHealthDatabase->exec('CREATE TABLE webhook_deliveries (unexpected TEXT NOT NULL)');
    $damagedHealthDatabase->exec('CREATE TABLE webhook_worker_heartbeat (unexpected TEXT NOT NULL)');
    webhook_rejects(
        static fn (): array => (new WebhookHealthService($damagedHealthDatabase))->snapshot(),
        'Damaged webhook health storage was silently reported as healthy and unconfigured.',
    );
    putenv('CPE_WEBHOOK_ENCRYPTION_KEYS=contract-v1=' . $keyMaterial . '=');
    putenv('CPE_WEBHOOK_ACTIVE_KEY_VERSION=contract-v1');
    webhook_rejects(
        static fn (): WebhookSecretCipher => WebhookSecretCipher::fromEnvironment(),
        'Non-canonical padded webhook key material was accepted.',
    );
    putenv('CPE_WEBHOOK_ENCRYPTION_KEYS=contract-v1=' . $keyMaterial);
    Database::migrate();
    if ($postgres && in_array(strtolower((string) (getenv('CPE_WEBHOOK_TEST_PRIVILEGED_HEALTH') ?: '')), ['1', 'true', 'yes', 'on'], true)) {
        $pdo = Database::connection();
        $canCreateRole = (bool) $pdo->query(
            'SELECT rolsuper OR rolcreaterole FROM pg_catalog.pg_roles WHERE rolname = current_user',
        )->fetchColumn();
        webhook_assert($canCreateRole, 'Privileged PostgreSQL webhook health proof requires a disposable role-capable CI database user.');
        $healthRole = 'cpe_webhook_health_' . bin2hex(random_bytes(6));
        $quotedHealthRole = '"' . $healthRole . '"';
        $healthRoleCreated = false;
        $healthRoleActive = false;
        $quotedSchema = null;
        try {
            $pdo->exec('CREATE ROLE ' . $quotedHealthRole . ' NOLOGIN');
            $healthRoleCreated = true;
            $schema = (string) $pdo->query('SELECT current_schema()')->fetchColumn();
            webhook_assert(preg_match('/\A[a-z_][a-z0-9_]{0,62}\z/D', $schema) === 1, 'PostgreSQL webhook health schema identifier is unsafe.');
            $quotedSchema = '"' . $schema . '"';
            $pdo->exec('GRANT USAGE ON SCHEMA ' . $quotedSchema . ' TO ' . $quotedHealthRole);
            $pdo->exec(
                'GRANT SELECT ON TABLE '
                . $quotedSchema . '.webhook_subscriptions, '
                . $quotedSchema . '.webhook_deliveries, '
                . $quotedSchema . '.webhook_worker_heartbeat TO ' . $quotedHealthRole,
            );
            $pdo->exec('SET ROLE ' . $quotedHealthRole);
            $healthRoleActive = true;
            $unreadableHealthFailure = webhook_rejects(
                static fn (): array => (new WebhookHealthService($pdo))->snapshot(),
                'Unreadable PostgreSQL webhook storage was silently reported as healthy.',
            );
            webhook_same('42501', (string) $unreadableHealthFailure->getCode(), 'Unreadable PostgreSQL relation did not fail with insufficient privilege.');
        } finally {
            $cleanupFailures = [];
            if ($healthRoleActive) {
                try {
                    $pdo->exec('RESET ROLE');
                } catch (Throwable $failure) {
                    $cleanupFailures[] = $failure;
                }
            }
            if ($healthRoleCreated) {
                $cleanupStatements = [];
                if (is_string($quotedSchema)) {
                    $cleanupStatements[] =
                        'REVOKE ALL PRIVILEGES ON TABLE '
                        . $quotedSchema . '.webhook_subscriptions, '
                        . $quotedSchema . '.webhook_subscription_events, '
                        . $quotedSchema . '.webhook_deliveries, '
                        . $quotedSchema . '.webhook_worker_heartbeat FROM ' . $quotedHealthRole;
                    $cleanupStatements[] =
                        'REVOKE ALL PRIVILEGES ON SCHEMA ' . $quotedSchema . ' FROM ' . $quotedHealthRole;
                }
                $cleanupStatements[] = 'DROP OWNED BY ' . $quotedHealthRole;
                $cleanupStatements[] = 'DROP ROLE ' . $quotedHealthRole;
                foreach ($cleanupStatements as $cleanupStatement) {
                    try {
                        $pdo->exec($cleanupStatement);
                    } catch (Throwable $failure) {
                        $cleanupFailures[] = $failure;
                    }
                }
            }
            if ($cleanupFailures !== []) {
                throw new RuntimeException(
                    'Privileged PostgreSQL webhook health cleanup failed: '
                    . implode('; ', array_map(
                        static fn (Throwable $failure): string => $failure->getMessage(),
                        $cleanupFailures,
                    )),
                    0,
                    $cleanupFailures[0],
                );
            }
        }
    }
    (new Installer())->installHosted([
        'college_name' => 'Webhook Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'Webhook Contract Administrator',
        'admin_email' => 'webhook-contract@example.test',
        'admin_password' => 'webhook-contract-password-123',
        'seed_demo' => '0',
    ], 'tenant_' . str_repeat('b', 32), test_authorized_setup_recovery_authority());
    $pdo = Database::connection();
    $driver = Database::driver();

    $migrations = $driver === 'pgsql'
        ? ['014_signed_webhook_integrations.sql', '015_webhook_claim_cursor.sql']
        : ['050_signed_webhook_integrations.sql', '051_webhook_claim_cursor.sql'];
    $registered = $pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
    foreach ($migrations as $migration) {
        $registered->execute([$migration]);
        webhook_same(1, (int) $registered->fetchColumn(), 'Webhook migration is not registered: ' . $migration);
    }
    foreach (['webhook_subscriptions', 'webhook_subscription_events', 'webhook_deliveries', 'webhook_worker_heartbeat'] as $table) {
        webhook_same(0, (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), 'Fresh install synthesized webhook state.');
    }

    $columns = $driver === 'pgsql'
        ? $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'webhook_subscriptions'")->fetchAll(PDO::FETCH_COLUMN)
        : array_column($pdo->query("PRAGMA table_info('webhook_subscriptions')")->fetchAll(PDO::FETCH_ASSOC), 'name');
    webhook_assert(!in_array('secret', $columns, true) && !in_array('signing_secret', $columns, true), 'Schema contains a plaintext secret column.');
    foreach (['current_secret_ciphertext', 'current_secret_nonce', 'current_secret_tag', 'current_secret_key_version'] as $column) {
        webhook_assert(in_array($column, $columns, true), 'Encrypted secret metadata column is missing: ' . $column);
    }
    $heartbeatColumns = $driver === 'pgsql'
        ? $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'webhook_worker_heartbeat'")->fetchAll(PDO::FETCH_COLUMN)
        : array_column($pdo->query("PRAGMA table_info('webhook_worker_heartbeat')")->fetchAll(PDO::FETCH_ASSOC), 'name');
    webhook_assert(
        in_array('claim_cursor_subscription_id', $heartbeatColumns, true),
        'Webhook worker claim cursor column is missing.',
    );

    $cipher = WebhookSecretCipher::fromEnvironment();
    $bindingSecret = WebhookSecretCipher::generateSigningSecret();
    $encrypted = $cipher->encrypt($bindingSecret, 'tenant_' . str_repeat('b', 32), 'whsub_' . str_repeat('1', 32));
    webhook_same(
        $bindingSecret,
        $cipher->decrypt($encrypted, 'tenant_' . str_repeat('b', 32), 'whsub_' . str_repeat('1', 32)),
        'Authenticated encryption did not round trip.',
    );
    webhook_rejects(
        static fn (): string => $cipher->decrypt($encrypted, 'tenant_' . str_repeat('b', 32), 'whsub_' . str_repeat('2', 32)),
        'Encrypted secret was not bound to its subscription identity.',
    );

    $transport = new WebhookContractTransport();
    $primary = webhook_active_subscription($pdo, $transport, 'Primary receiver');
    webhook_same(1, count($transport->calls), 'Validation did not make exactly one synthetic transport call.');
    $validation = json_decode((string) $transport->calls[0]['body'], true, 8, JSON_THROW_ON_ERROR);
    webhook_same('webhook.validation', $validation['type'] ?? null, 'Validation challenge pretended to be a placement event.');
    webhook_assert(!str_contains((string) $transport->calls[0]['body'], 'application_'), 'Validation challenge leaked an aggregate identity.');
    webhook_assert(!str_contains((string) $transport->calls[0]['body'], 'from_status'), 'Validation challenge contained event example data.');
    $transport->calls = [];

    $alternateKey = rtrim(strtr(base64_encode(str_repeat("\x59", 32)), '+/', '-_'), '=');
    putenv('CPE_WEBHOOK_ENCRYPTION_KEYS=alternate-v1=' . $alternateKey);
    putenv('CPE_WEBHOOK_ACTIVE_KEY_VERSION=alternate-v1');
    $missingReferenceHealth = (new WebhookHealthService($pdo))->snapshot();
    webhook_same('fail', $missingReferenceHealth['status'], 'Active integration with an unavailable referenced key version did not fail readiness.');
    webhook_same(false, $missingReferenceHealth['encryption_key_references_ready'], 'Readiness did not detect an unavailable referenced key version.');
    webhook_same(1, $missingReferenceHealth['missing_encryption_key_versions'], 'Readiness exposed or miscounted missing key versions.');
    putenv('CPE_WEBHOOK_ENCRYPTION_KEYS=contract-v1=' . $keyMaterial);
    putenv('CPE_WEBHOOK_ACTIVE_KEY_VERSION=contract-v1');

    $secretProbe = $pdo->prepare('SELECT * FROM webhook_subscriptions WHERE public_id = ?');
    $secretProbe->execute([$primary['public_id']]);
    $storedSubscription = $secretProbe->fetch(PDO::FETCH_ASSOC);
    webhook_assert(is_array($storedSubscription), 'Stored subscription is missing.');
    webhook_assert(
        !str_contains(json_encode($storedSubscription, JSON_THROW_ON_ERROR), $primary['secret']),
        'Plaintext signing secret entered the database.',
    );
    webhook_assert(
        !str_contains(json_encode((new WebhookSubscriptionService($pdo, $transport, $cipher))->listForAdministrator(), JSON_THROW_ON_ERROR), $primary['secret']),
        'Administrator integration listing re-revealed a signing secret.',
    );
    $activeService = new WebhookSubscriptionService($pdo, $transport, $cipher);
    webhook_rejects(
        static function () use ($activeService, $primary): void {
            $activeService->validate($primary['public_id'], 1);
        },
        'Active integration validation paused future event capture.',
    );
    webhook_same('active', (string) $pdo->query("SELECT lifecycle_state FROM webhook_subscriptions WHERE public_id = " . $pdo->quote($primary['public_id']))->fetchColumn(), 'Rejected active validation changed lifecycle state.');
    webhook_rejects(
        static fn (): bool => $pdo->prepare('UPDATE webhook_subscriptions SET current_secret_nonce = NULL WHERE public_id = ?')->execute([$primary['public_id']]),
        'Database allowed an active integration secret to become incomplete.',
    );
    webhook_rejects(
        static fn (): bool => $pdo->prepare('UPDATE webhook_subscriptions SET endpoint_url = ? WHERE public_id = ?')->execute(['https://changed.example.test/cpe', $primary['public_id']]),
        'Database allowed endpoint policy drift without versioned setup.',
    );
    webhook_rejects(
        static fn (): bool => $pdo->prepare(
            'DELETE FROM webhook_subscription_events WHERE subscription_id = (SELECT id FROM webhook_subscriptions WHERE public_id = ?)',
        )->execute([$primary['public_id']]),
        'Database allowed active event selection to be removed.',
    );

    $aggregate = 'application_' . str_repeat('1', 32);
    $beforeDispatchCalls = count($transport->calls);
    $first = webhook_dispatch($pdo, $aggregate, 2, 'idle', 'scheduled');
    webhook_same(1, $first['delivery_count'], 'Active subscription was not captured atomically with the source event.');
    webhook_same($beforeDispatchCalls, count($transport->calls), 'Event dispatch performed network I/O.');
    $deliveryColumns = $driver === 'pgsql'
        ? $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'webhook_deliveries'")->fetchAll(PDO::FETCH_COLUMN)
        : array_column($pdo->query("PRAGMA table_info('webhook_deliveries')")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['payload_json', 'body', 'request_body', 'aggregate_id'] as $forbiddenColumn) {
        webhook_assert(!in_array($forbiddenColumn, $deliveryColumns, true), 'Delivery duplicated sensitive event content: ' . $forbiddenColumn);
    }

    $worker = new WebhookDeliveryWorker($pdo, $transport, $cipher, null, static fn (): int => 0);
    $deliveryResult = $worker->work(10);
    webhook_same(1, $deliveryResult['succeeded'], 'Captured delivery did not succeed.');
    webhook_same(1, count($transport->calls), 'Worker did not send exactly one event request.');
    $call = $transport->calls[0];
    $headers = [];
    foreach ($call['headers'] as $header) {
        [$name, $value] = explode(': ', $header, 2);
        $headers[$name] = $value;
    }
    webhook_same($first['event_id'], $headers['CPE-Webhook-Id'] ?? null, 'Webhook ID header differs from the immutable event ID.');
    webhook_same('application.status_changed;version=1', $headers['CPE-Webhook-Schema'] ?? null, 'Webhook schema header differs.');
    webhook_assert(
        WebhookSigner::verify(
            (string) $headers['CPE-Webhook-Id'],
            (int) $headers['CPE-Webhook-Timestamp'],
            (string) $call['body'],
            (string) $headers['CPE-Webhook-Signature'],
            $primary['secret'],
            (int) $headers['CPE-Webhook-Timestamp'],
        ),
        'Exact raw webhook body signature did not verify.',
    );
    webhook_assert(!WebhookSigner::verify((string) $headers['CPE-Webhook-Id'], (int) $headers['CPE-Webhook-Timestamp'], (string) $call['body'] . ' ', (string) $headers['CPE-Webhook-Signature'], $primary['secret'], (int) $headers['CPE-Webhook-Timestamp']), 'Altered body verified.');
    webhook_assert(!WebhookSigner::verify((string) $headers['CPE-Webhook-Id'], (int) $headers['CPE-Webhook-Timestamp'], (string) $call['body'], (string) $headers['CPE-Webhook-Signature'], WebhookSecretCipher::generateSigningSecret(), (int) $headers['CPE-Webhook-Timestamp']), 'Wrong secret verified.');
    webhook_assert(!WebhookSigner::verify((string) $headers['CPE-Webhook-Id'], (int) $headers['CPE-Webhook-Timestamp'], (string) $call['body'], (string) $headers['CPE-Webhook-Signature'], $primary['secret'], (int) $headers['CPE-Webhook-Timestamp'] + 301), 'Stale signature verified outside skew.');
    $wire = json_decode((string) $call['body'], true, 16, JSON_THROW_ON_ERROR);
    webhook_assert(!str_contains((string) $call['body'], 'private_marker'), 'Private outbox payload entered the public body.');
    webhook_same($aggregate, $wire['aggregate']['id'] ?? null, 'Public aggregate identity was not reconstructed from immutable projection.');

    // Later versions are ordered only within one subscription and aggregate.
    $transport->calls = [];
    $second = webhook_dispatch($pdo, $aggregate, 3, 'scheduled', 'intransit');
    $other = webhook_dispatch($pdo, 'application_' . str_repeat('2', 32), 2, 'idle', 'scheduled');
    $transport->outcomes = [new WebhookTransportException(WebhookTransportException::TIMEOUT, true)];
    $ordered = $worker->work(10);
    webhook_same(1, $ordered['retrying'], 'Next version for the same aggregate was not selected after predecessor success.');
    $sentIds = [];
    foreach ($transport->calls as $sent) {
        foreach ($sent['headers'] as $header) {
            if (str_starts_with($header, 'CPE-Webhook-Id: ')) {
                $sentIds[] = substr($header, strlen('CPE-Webhook-Id: '));
            }
        }
    }
    webhook_assert(in_array($second['event_id'], $sentIds, true), 'Next aggregate version did not progress after predecessor success.');
    $transport->calls = [];
    $differentAggregate = $worker->work(10);
    webhook_same(1, $differentAggregate['succeeded'], 'Different aggregate was globally blocked by a retrying aggregate.');
    $differentId = '';
    foreach ($transport->calls[0]['headers'] ?? [] as $header) {
        if (str_starts_with($header, 'CPE-Webhook-Id: ')) {
            $differentId = substr($header, strlen('CPE-Webhook-Id: '));
        }
    }
    webhook_same($other['event_id'], $differentId, 'Worker advanced the blocked aggregate instead of the independent aggregate.');

    // One endpoint failure cannot block another endpoint.
    $secondary = webhook_active_subscription($pdo, $transport, 'Healthy receiver');
    $transport->calls = [];
    $isolated = webhook_dispatch($pdo, 'application_' . str_repeat('3', 32), 2, 'idle', 'scheduled');
    webhook_same(2, $isolated['delivery_count'], 'Event was not captured once per active endpoint.');
    $transport->outcomes = [
        new WebhookTransportException(WebhookTransportException::TIMEOUT, true),
        new WebhookHttpResult(204),
    ];
    $isolation = $worker->work(10);
    webhook_same(1, $isolation['retrying'], 'Failing endpoint did not retain bounded retry state.');
    webhook_same(1, $isolation['succeeded'], 'Healthy endpoint was blocked by another endpoint failure.');

    // Secret rotation is one-time reveal and both v1 signatures overlap safely.
    $service = new WebhookSubscriptionService($pdo, $transport, $cipher);
    $newSecret = $service->rotateSecret($primary['public_id'], 1);
    webhook_assert(!hash_equals($newSecret, $primary['secret']), 'Secret rotation repeated the old secret.');
    $secretProbe->execute([$primary['public_id']]);
    $rotatedRow = $secretProbe->fetch(PDO::FETCH_ASSOC);
    webhook_assert(is_array($rotatedRow) && !str_contains(json_encode($rotatedRow, JSON_THROW_ON_ERROR), $newSecret), 'Rotated plaintext secret entered the database.');
    $transport->calls = [];
    webhook_dispatch($pdo, 'application_' . str_repeat('4', 32), 2, 'idle', 'scheduled');
    $worker->work(10);
    $rotatedCall = null;
    $rotationSignature = '';
    $rotationId = '';
    $rotationTimestamp = 0;
    foreach ($transport->calls as $candidateCall) {
        $candidateSignature = '';
        $candidateId = '';
        $candidateTimestamp = 0;
        foreach ($candidateCall['headers'] as $header) {
            if (str_starts_with($header, 'CPE-Webhook-Signature: ')) {
                $candidateSignature = substr($header, strlen('CPE-Webhook-Signature: '));
            } elseif (str_starts_with($header, 'CPE-Webhook-Id: ')) {
                $candidateId = substr($header, strlen('CPE-Webhook-Id: '));
            } elseif (str_starts_with($header, 'CPE-Webhook-Timestamp: ')) {
                $candidateTimestamp = (int) substr($header, strlen('CPE-Webhook-Timestamp: '));
            }
        }
        if (WebhookSigner::verify(
            $candidateId,
            $candidateTimestamp,
            (string) $candidateCall['body'],
            $candidateSignature,
            $newSecret,
            $candidateTimestamp,
        )) {
            $rotatedCall = $candidateCall;
            $rotationSignature = $candidateSignature;
            $rotationId = $candidateId;
            $rotationTimestamp = $candidateTimestamp;
            break;
        }
    }
    webhook_assert(is_array($rotatedCall), 'Rotated integration did not send.');
    webhook_same(2, count(explode(',', $rotationSignature)), 'Rotation overlap did not include exactly two v1 signatures.');
    webhook_assert(WebhookSigner::verify($rotationId, $rotationTimestamp, (string) $rotatedCall['body'], $rotationSignature, $newSecret, $rotationTimestamp), 'New overlap secret did not verify.');
    webhook_assert(WebhookSigner::verify($rotationId, $rotationTimestamp, (string) $rotatedCall['body'], $rotationSignature, $primary['secret'], $rotationTimestamp), 'Previous overlap secret did not verify.');

    // Terminal TLS/410 handling, exact replay attribution, and stale lease reclaim.
    $transport->calls = [];
    $terminal = webhook_dispatch($pdo, 'application_' . str_repeat('5', 32), 2, 'idle', 'scheduled');
    $transport->outcomes = [new WebhookHttpResult(410), new WebhookHttpResult(204)];
    $terminalResult = $worker->work(10);
    webhook_assert($terminalResult['dead_lettered'] >= 1, 'HTTP 410 did not dead-letter and degrade for review.');
    $dead = $pdo->query("SELECT public_id FROM webhook_deliveries WHERE status = 'dead_lettered' ORDER BY id DESC LIMIT 1")->fetchColumn();
    webhook_assert(is_string($dead), 'Dead-letter identity is missing.');
    $replay = (new WebhookDeliveryReplayService($pdo))->replay($dead, 1);
    webhook_same('replayed', $replay['status'], 'Exact dead-letter replay did not requeue.');
    webhook_same('already-replayed', (new WebhookDeliveryReplayService($pdo))->replay($dead, 1)['status'], 'Exact replay was not idempotent.');
    $audit = $pdo->prepare("SELECT actor_user_id, detail FROM audit_logs WHERE action = 'webhook.delivery.replay' ORDER BY id DESC LIMIT 1");
    $audit->execute();
    $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
    webhook_same(1, (int) ($auditRow['actor_user_id'] ?? 0), 'Replay audit omitted administrator attribution.');
    webhook_same('Dead-lettered webhook delivery replayed.', (string) ($auditRow['detail'] ?? ''), 'Replay audit is not fixed and payload-free.');

    $staleId = (int) $pdo->query("SELECT id FROM webhook_deliveries WHERE status IN ('pending', 'retrying') ORDER BY id LIMIT 1")->fetchColumn();
    if ($staleId > 0) {
        $pdo->prepare(
            "UPDATE webhook_deliveries SET status = 'processing', locked_at = ?, lock_token = ?, lease_generation = lease_generation + 1 WHERE id = ?",
        )->execute(['2000-01-01 00:00:00', 'claim_' . str_repeat('a', 32), $staleId]);
        $worker->work(1);
        webhook_assert((string) $pdo->query('SELECT locked_at FROM webhook_deliveries WHERE id = ' . $staleId)->fetchColumn() === '', 'Stale lease was not reclaimed and completed.');
    }

    // Claim loss fences acknowledgement after an administrator-side state change.
    $claimLossEvent = webhook_dispatch($pdo, 'application_' . str_repeat('6', 32), 2, 'idle', 'scheduled');
    $transport->beforeSend = static function () use ($pdo, $claimLossEvent): void {
        $query = $pdo->prepare(
            "UPDATE webhook_deliveries SET lock_token = ?, lease_generation = lease_generation + 1
             WHERE event_id = (SELECT id FROM domain_event_outbox WHERE public_id = ?) AND status = 'processing'",
        );
        $query->execute(['claim_' . str_repeat('f', 32), $claimLossEvent['event_id']]);
    };
    $claimLoss = $worker->work(10);
    webhook_assert($claimLoss['claim_lost'] >= 1, 'Stolen delivery claim was acknowledged.');

    // Database capture faults roll back the source outbox event.
    $outboxBeforeFault = (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn();
    if ($driver === 'pgsql') {
        $pdo->exec("CREATE OR REPLACE FUNCTION cpe_webhook_contract_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'contract delivery fault'; END; $$");
        $pdo->exec('CREATE TRIGGER webhook_contract_fault BEFORE INSERT ON webhook_deliveries FOR EACH ROW EXECUTE FUNCTION cpe_webhook_contract_fault()');
    } else {
        $pdo->exec("CREATE TRIGGER webhook_contract_fault BEFORE INSERT ON webhook_deliveries BEGIN SELECT RAISE(ABORT, 'contract delivery fault'); END");
    }
    webhook_rejects(
        static fn (): array => webhook_dispatch($pdo, 'application_' . str_repeat('7', 32), 2, 'idle', 'scheduled'),
        'Delivery capture fault did not fail source dispatch.',
    );
    webhook_same($outboxBeforeFault, (int) $pdo->query('SELECT COUNT(*) FROM domain_event_outbox')->fetchColumn(), 'Delivery capture fault retained an orphaned source event.');
    if ($driver === 'pgsql') {
        $pdo->exec('DROP TRIGGER webhook_contract_fault ON webhook_deliveries');
        $pdo->exec('DROP FUNCTION cpe_webhook_contract_fault()');
    } else {
        $pdo->exec('DROP TRIGGER webhook_contract_fault');
    }

    // A bounded endpoint circuit pauses repeated failure without blocking other endpoints.
    $service->disable($primary['public_id'], 1);
    $service->disable($secondary['public_id'], 1);
    $breaker = webhook_active_subscription($pdo, $transport, 'Circuit receiver');
    $transport->calls = [];
    $pdo->prepare('UPDATE webhook_subscriptions SET consecutive_failures = 2 WHERE public_id = ?')->execute([$breaker['public_id']]);
    webhook_dispatch($pdo, 'application_' . str_repeat('8', 32), 2, 'idle', 'scheduled');
    $transport->outcomes = [new WebhookTransportException(WebhookTransportException::TIMEOUT, true)];
    $circuitFailure = $worker->work(10);
    webhook_same(1, $circuitFailure['retrying'], 'Circuit fixture did not record its third consecutive failure.');
    $breakerQuery = $pdo->prepare('SELECT lifecycle_state, circuit_open_until FROM webhook_subscriptions WHERE public_id = ?');
    $breakerQuery->execute([$breaker['public_id']]);
    $breakerRow = $breakerQuery->fetch(PDO::FETCH_ASSOC);
    webhook_same('degraded', (string) ($breakerRow['lifecycle_state'] ?? ''), 'Three consecutive failures did not degrade the endpoint.');
    webhook_assert((string) ($breakerRow['circuit_open_until'] ?? '') > cpe_now(), 'Three consecutive failures did not open a bounded endpoint circuit.');
    webhook_dispatch($pdo, 'application_' . str_repeat('9', 32), 2, 'idle', 'scheduled');
    webhook_same(0, $worker->work(10)['claimed'], 'Open endpoint circuit entered a delivery busy loop.');
    $service->disable($breaker['public_id'], 1);

    // Candidate selection is fair across endpoints even when one older
    // endpoint has more eligible rows than the bounded candidate window.
    $fairBacklog = webhook_active_subscription($pdo, $transport, 'Fair backlog receiver');
    $transport->calls = [];
    for ($index = 0; $index < 25; $index++) {
        webhook_dispatch(
            $pdo,
            'application_' . str_pad(dechex(256 + $index), 32, '0', STR_PAD_LEFT),
            2,
            'idle',
            'scheduled',
        );
    }
    $fairLater = webhook_active_subscription($pdo, $transport, 'Fair later receiver');
    $transport->calls = [];
    webhook_dispatch($pdo, 'application_' . str_repeat('e', 32), 2, 'idle', 'scheduled');
    $fairResult = $worker->work(2);
    webhook_same(2, $fairResult['claimed'], 'Deep older endpoint backlog starved an eligible later endpoint.');
    webhook_same(2, $fairResult['succeeded'], 'Fair endpoint claims did not both complete.');
    $service->disable($fairBacklog['public_id'], 1);
    $service->disable($fairLater['public_id'], 1);

    // Public-only policy, explicit self-hosted private opt-in, and rebinding checks.
    $publicTarget = OutboundHttpPolicy::assertWebhookAllowed(
        'https://hooks.example.test/cpe',
        false,
        static fn (): array => ['93.184.216.34'],
        false,
        false,
        [443],
    );
    webhook_same(['93.184.216.34'], $publicTarget['addresses'], 'Public endpoint resolution was not preserved for pinning.');
    webhook_same('https://hooks.example.test:443/cpe', $publicTarget['request_url'], 'Pinned transport target was not normalized deterministically.');
    foreach ([
        '127.0.0.1', '10.0.0.1', '100.64.0.1', '169.254.10.1', '192.0.2.1',
        '198.18.0.1', '203.0.113.1', '224.0.0.1', '240.0.0.1', '0.0.0.0',
        '::1', '64:ff9b::1', '100::1', '2001:db8::1', '3fff::1', 'fc00::1',
        'fe80::1', 'fec0::1', 'ff02::1', '::', '::ffff:127.0.0.1',
    ] as $forbidden) {
        webhook_rejects(
            static fn (): array => OutboundHttpPolicy::assertWebhookAllowed('https://hooks.example.test/cpe', false, static fn (): array => [$forbidden], false, false, [443]),
            'Default webhook policy allowed forbidden address ' . $forbidden,
        );
    }
    webhook_rejects(
        static fn (): array => OutboundHttpPolicy::assertWebhookAllowed('https://hooks.example.test/cpe', false, static fn (): array => ['93.184.216.34', '10.0.0.1'], false, false, [443]),
        'All-address validation allowed a DNS rebinding set containing private space.',
    );
    webhook_same(['10.0.0.1'], OutboundHttpPolicy::assertWebhookAllowed('https://hooks.example.test/cpe', true, static fn (): array => ['10.0.0.1'], false, false, [443])['addresses'], 'Explicit self-hosted private-network policy rejected RFC1918 space.');
    webhook_same(['fc00::1'], OutboundHttpPolicy::assertWebhookAllowed('https://hooks.example.test/cpe', true, static fn (): array => ['fc00::1'], false, false, [443])['addresses'], 'Explicit self-hosted private-network policy rejected ULA space.');
    webhook_same('http://10.0.0.1:80/hook', OutboundHttpPolicy::assertWebhookAllowed('http://10.0.0.1/hook', true, null, false, true, [80])['request_url'], 'Explicit self-hosted HTTP policy did not require and preserve its approved private target.');
    webhook_rejects(static fn (): array => OutboundHttpPolicy::assertWebhookAllowed('http://10.0.0.1/hook', false, null, false, true, [80]), 'HTTP policy allowed delivery without explicit private-network permission.');
    webhook_rejects(static fn (): array => OutboundHttpPolicy::assertWebhookAllowed('https://hooks.example.test/cpe', true, static fn (): array => ['10.0.0.1'], true, false, [443]), 'Managed mode allowed private-network policy.');
    webhook_rejects(static fn (): array => OutboundHttpPolicy::assertWebhookAllowed('https://user@example.test/cpe', false, static fn (): array => ['93.184.216.34'], false, false, [443]), 'Policy allowed URL userinfo.');
    webhook_rejects(static fn (): array => OutboundHttpPolicy::assertWebhookAllowed('https://hooks.example.test/cpe#secret', false, static fn (): array => ['93.184.216.34'], false, false, [443]), 'Policy allowed URL fragment.');
    webhook_rejects(static fn (): array => OutboundHttpPolicy::assertWebhookAllowed('https://hooks.example.test:444/cpe', false, static fn (): array => ['93.184.216.34'], false, false, [443]), 'Policy allowed an unapproved port.');
    webhook_rejects(static fn (): array => OutboundHttpPolicy::assertWebhookAllowed('https://hooks.example.test./cpe', false, static fn (): array => ['93.184.216.34'], false, false, [443]), 'Policy allowed an ambiguous trailing-dot host.');
    webhook_rejects(static fn (): array => OutboundHttpPolicy::assertWebhookAllowed('https://hooks.example.test/cpe', false, static fn (): array => array_map(static fn (int $last): string => '93.184.216.' . $last, range(1, 33)), false, false, [443]), 'Policy allowed an unbounded resolver result.');

    $classifier = new WebhookDeliveryWorker($pdo, $transport, $cipher, null, static fn (): int => 0);
    webhook_same('retrying', $classifier->classify(429, null, 1)['status'], 'HTTP 429 was not retryable.');
    webhook_same('dead_lettered', $classifier->classify(410, null, 1)['status'], 'HTTP 410 was not terminal.');
    webhook_same('dead_lettered', $classifier->classify(null, new WebhookTransportException(WebhookTransportException::TLS, false), 1)['status'], 'Invalid TLS was not terminal.');
    webhook_same('retrying', $classifier->classify(null, new WebhookTransportException(WebhookTransportException::TIMEOUT, true), 1)['status'], 'Timeout was not retryable.');
    webhook_same('dead_lettered', $classifier->classify(null, new WebhookTransportException(WebhookTransportException::RESPONSE_TOO_LARGE, false), 1)['status'], 'Oversized response was not terminal.');
    webhook_same([60, 300, 900, 3600, 14400, 43200, 86400], array_map(static fn (int $attempt): int => $classifier->retryDelaySeconds($attempt), range(1, 7)), 'Retry schedule differs from the bounded policy.');
    webhook_same('dead_lettered', $classifier->classify(302, null, 1)['status'], 'Redirect response was not terminal.');

    $curlSource = (string) file_get_contents($projectRoot . '/app/Integrations/Webhooks/CurlWebhookHttpTransport.php');
    foreach (['CURLOPT_FOLLOWLOCATION => false', 'CURLOPT_RESOLVE', 'CURLOPT_SSL_VERIFYPEER => true', 'CURLOPT_SSL_VERIFYHOST => 2', '1048576', 'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT'] as $guard) {
        webhook_assert(str_contains($curlSource, $guard), 'Curl transport is missing static policy guard: ' . $guard);
    }
    $dispatcherSource = (string) file_get_contents($projectRoot . '/app/Core/Events/EventDispatcher.php');
    webhook_assert(!str_contains($dispatcherSource, 'WebhookHttpTransport') && !str_contains($dispatcherSource, 'CurlWebhook'), 'Placement transaction constructs a webhook transport.');
    $controllerSource = (string) file_get_contents($projectRoot . '/app/Controllers/WebhookController.php');
    webhook_assert(str_contains($controllerSource, 'Csrf::verify') && str_contains($controllerSource, "portal.integrations.manage"), 'Webhook mutation routes are not CSRF and capability protected.');
    $engineSources = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        glob($projectRoot . '/app/Integrations/Webhooks/*.php') ?: [],
    ));
    webhook_assert(!str_contains($engineSources, '../cloud') && !str_contains($engineSources, 'Cloud\\'), 'Engine webhook implementation depends on Cloud.');

    // Revocation removes all encrypted secret metadata and fences unresolved work.
    $service->revoke($secondary['public_id'], 1);
    $revoked = $pdo->prepare('SELECT * FROM webhook_subscriptions WHERE public_id = ?');
    $revoked->execute([$secondary['public_id']]);
    $revokedRow = $revoked->fetch(PDO::FETCH_ASSOC);
    webhook_same('disabled', (string) ($revokedRow['lifecycle_state'] ?? ''), 'Revocation did not disable integration.');
    foreach (['current_secret_ciphertext', 'current_secret_nonce', 'current_secret_tag', 'current_secret_key_version', 'previous_secret_ciphertext', 'previous_secret_nonce', 'previous_secret_tag', 'previous_secret_key_version'] as $field) {
        webhook_same(null, $revokedRow[$field] ?? null, 'Revocation retained secret metadata: ' . $field);
    }
    $structuredLog = is_file($testRoot . '/structured.log') ? (string) file_get_contents($testRoot . '/structured.log') : '';
    foreach ([$primary['secret'], $newSecret, 'https://hooks.example.test/cpe', $aggregate, (string) $call['body']] as $sensitiveValue) {
        webhook_assert(!str_contains($structuredLog, $sensitiveValue), 'Webhook diagnostic log retained sensitive integration content.');
    }

    echo 'PASS signed webhook delivery contract (' . $driver . ")\n";
} finally {
    Database::reset();
    putenv('CPE_WEBHOOK_ENCRYPTION_KEYS');
    putenv('CPE_WEBHOOK_ACTIVE_KEY_VERSION');
    putenv('CPE_WEBHOOK_MAX_ATTEMPTS');
    putenv('CPE_WEBHOOK_ENDPOINT_CONCURRENCY');
    putenv('CPE_WEBHOOK_INSTITUTION_CONCURRENCY');
    putenv('CPE_LOG_PATH');
    webhook_remove_tree($testRoot);
}
