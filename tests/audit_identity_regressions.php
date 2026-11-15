<?php

declare(strict_types=1);

$testRoot = sys_get_temp_dir() . '/cpe-audit-identity-' . bin2hex(random_bytes(6));
mkdir($testRoot, 0700);
putenv('CPE_LOG_PATH=' . $testRoot . '/structured.log');
$postgres = trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== '';
if (!$postgres) {
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DB_PATH=' . $testRoot . '/identity.sqlite');
}
define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Http\UserVisibleException;
use App\Core\Persistence\SqlMigrationRunner;
use App\Install\Installer;
use App\Integrations\Webhooks\WebhookHttpResult;
use App\Integrations\Webhooks\WebhookHttpTransport;
use App\Integrations\Webhooks\WebhookSecretCipher;
use App\Integrations\Webhooks\WebhookSubscriptionService;
use App\Security\ExternalIdentityService;
use App\Support\Auth;
use App\Support\Database;

function identity_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function identity_rejects(callable $action, ?string $code = null): void
{
    try {
        $action();
    } catch (Throwable $failure) {
        if ($code !== null) {
            identity_assert($failure instanceof UserVisibleException && $failure->publicCode() === $code, 'Unexpected rejection: ' . get_class($failure));
        }
        return;
    }
    throw new RuntimeException('Unsafe operation was accepted.');
}

final class IdentityAuditTransport implements WebhookHttpTransport
{
    public mixed $beforeSend = null;
    public int $status = 204;

    public function send(string $endpointUrl, string $body, array $headers, bool $allowPrivateNetwork): WebhookHttpResult
    {
        $callback = $this->beforeSend;
        $this->beforeSend = null;
        if ($callback !== null) {
            $callback();
        }
        return new WebhookHttpResult($this->status);
    }
}

try {
    $input = [
        'college_name' => 'Identity Audit College', 'timezone' => 'UTC',
        'admin_name' => 'Identity Administrator', 'admin_email' => ' Admin@Example.Test ',
        'admin_password' => 'identity-audit-password', 'seed_demo' => '0',
    ];
    identity_rejects(fn () => (new Installer())->install(array_replace($input, ['admin_password' => str_repeat('x', 73)])), 'USER_PASSWORD_INVALID');
    $adminId = (new Installer())->install($input);
    $pdo = Database::connection();
    identity_assert($pdo->query('SELECT email FROM users')->fetchColumn() === 'admin@example.test', 'Installer did not normalize its email.');

    $userId = Auth::createUser('Reader', ' Reader@Example.Test ', str_repeat('x', 72), 'auditor');
    identity_assert(Auth::attempt(' READER@EXAMPLE.TEST ', str_repeat('x', 72)), 'Normalized login or 72-byte password failed.');
    identity_rejects(fn () => Auth::createUser('Duplicate', 'reader@example.test', 'valid-password', 'auditor'), 'USER_EMAIL_EXISTS');
    identity_rejects(fn () => Auth::createAdmin('Duplicate admin', 'ADMIN@example.test', 'valid-password'), 'USER_EMAIL_EXISTS');
    identity_rejects(fn () => Auth::createUser('Too long', 'long@example.test', str_repeat('x', 73), 'auditor'), 'USER_PASSWORD_INVALID');
    identity_rejects(fn () => Auth::createAdmin('Too long', 'long-admin@example.test', str_repeat('x', 73)), 'USER_PASSWORD_INVALID');
    $originalHash = $pdo->query('SELECT password_hash FROM users WHERE id = ' . $userId)->fetchColumn();
    identity_rejects(fn () => Auth::setPassword($userId, str_repeat('x', 73), $adminId), 'USER_PASSWORD_INVALID');
    identity_assert($pdo->query('SELECT password_hash FROM users WHERE id = ' . $userId)->fetchColumn() === $originalHash, 'Rejected reset changed the password.');
    $legacyHash = password_hash(str_repeat('x', 72) . 'old-suffix', PASSWORD_BCRYPT);
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$legacyHash, $userId]);
    identity_assert(!Auth::attempt('reader@example.test', str_repeat('x', 72) . 'different-suffix'), 'Legacy bcrypt accepted a different suffix.');
    identity_assert(!Auth::attempt('reader@example.test', str_repeat('x', 72) . 'old-suffix'), 'Overlength legacy bcrypt credentials must require a reset.');
    Auth::setPassword($userId, 'replacement-password', $adminId);
    identity_assert(Auth::attempt('reader@example.test', 'replacement-password'), 'Reset to a supported password failed.');

    foreach ([['', ''], ['company', ''], ['floor', 'KNOWN'], ['company', 'MISSING']] as [$type, $value]) {
        identity_rejects(fn () => Auth::createUser('Tracker', 'tracker@example.test', 'valid-password', 'company', $type, $value), 'USER_COMPANY_SCOPE_INVALID');
    }
    $pdo->prepare('INSERT INTO companies (code, name, created_at, updated_at) VALUES (?, ?, ?, ?)')->execute(['KNOWN', 'Known company', cpe_now(), cpe_now()]);
    $trackerId = Auth::createUser('Tracker', 'tracker@example.test', 'valid-password', 'company', 'company', ' known ');
    identity_assert($pdo->query('SELECT scope_value FROM users WHERE id = ' . $trackerId)->fetchColumn() === 'KNOWN', 'Company scope was not normalized.');

    $identities = new ExternalIdentityService($pdo);
    $linked = $identities->link('audit_provider', 'reader-subject', ' READER@EXAMPLE.TEST ');
    identity_assert($linked['user_id'] === $userId, 'SSO email linking did not use the normalized identity.');
    $sharedSecret = str_repeat('s', 32);
    putenv('CPE_SSO_ENABLED=1');
    putenv('CPE_SSO_PROVIDER_KEY=audit_provider');
    putenv('CPE_SSO_SHARED_SECRET=' . $sharedSecret);
    putenv('CPE_SSO_AUTO_LINK_EMAIL=1');
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $institution = (string) $pdo->query("SELECT public_id FROM institutions WHERE slug = 'default'")->fetchColumn();
    $_SERVER = array_replace($_SERVER, [
        'HTTP_X_CPE_SSO_SUBJECT' => 'auto-reader-subject', 'HTTP_X_CPE_SSO_EMAIL' => ' READER@EXAMPLE.TEST ',
        'HTTP_X_CPE_SSO_NAME' => 'Reader', 'HTTP_X_CPE_SSO_TIMESTAMP' => $timestamp, 'HTTP_X_CPE_SSO_NONCE' => $nonce,
        'HTTP_X_CPE_SSO_SIGNATURE' => hash_hmac('sha256', implode("\n", ['audit_provider', 'auto-reader-subject', 'reader@example.test', 'Reader', $timestamp, $nonce, $institution]), $sharedSecret),
    ]);
    identity_assert((int) $identities->authenticateRequest()['id'] === $userId, 'SSO auto-link chose a different identity.');

    $transport = new IdentityAuditTransport();
    $cipher = new WebhookSecretCipher(['audit-v1' => str_repeat('k', 32)], 'audit-v1');
    $webhooks = new WebhookSubscriptionService($pdo, $transport, $cipher);
    $subscription = $webhooks->create('Audit receiver', 'https://example.test/receiver', true, false, $adminId)['subscription_id'];
    $webhooks->validate($subscription, $adminId);
    $webhooks->activate($subscription, $adminId);
    $webhooks->revoke($subscription, $adminId);
    $webhooks->generateSecret($subscription, $adminId);
    $transport->beforeSend = function () use ($webhooks, $subscription, $adminId): void {
        identity_rejects(fn () => $webhooks->activate($subscription, $adminId), 'WEBHOOK_ACTIVATION_NOT_READY');
    };
    $transport->status = 500;
    identity_rejects(fn () => $webhooks->validate($subscription, $adminId), 'WEBHOOK_VALIDATION_FAILED');
    identity_assert($pdo->query('SELECT lifecycle_state FROM webhook_subscriptions')->fetchColumn() === 'setup_required', 'Failed new validation left the integration active.');

    $transport->status = 204;
    $transport->beforeSend = fn () => $webhooks->rotateSecret($subscription, $adminId);
    identity_rejects(fn () => $webhooks->validate($subscription, $adminId));
    identity_rejects(fn () => $webhooks->activate($subscription, $adminId), 'WEBHOOK_ACTIVATION_NOT_READY');
    $transport->beforeSend = function () use ($webhooks, $subscription, $adminId, $transport): void {
        $webhooks->validate($subscription, $adminId);
        $transport->status = 500;
    };
    identity_rejects(fn () => $webhooks->validate($subscription, $adminId), 'WEBHOOK_VALIDATION_FAILED');
    $webhooks->activate($subscription, $adminId);
    identity_assert($pdo->query('SELECT lifecycle_state FROM webhook_subscriptions')->fetchColumn() === 'active', 'Stale failed validation erased a newer successful validation.');

    // Exercise the shipped migration against legacy identities in an isolated schema.
    $migrationPdo = $postgres ? $pdo : new PDO('sqlite::memory:');
    $migrationPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schema = 'identity_audit_' . bin2hex(random_bytes(6));
    if ($postgres) {
        $migrationPdo->exec('CREATE SCHEMA ' . $schema);
        $migrationPdo->exec('SET search_path TO ' . $schema);
    }
    try {
        $migrationPdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE)');
        $migrationPdo->exec("INSERT INTO users VALUES (1, 'Legacy@Example.Test'), (2, 'legacy@example.test')");
        $migrationDir = $testRoot . '/email-migration';
        mkdir($migrationDir, 0700);
        $migrationName = $postgres ? '019_user_email_normalization.sql' : '055_user_email_normalization.sql';
        copy(cpe_path('database/migrations/' . ($postgres ? 'pgsql/' : '') . $migrationName), $migrationDir . '/' . $migrationName);
        $runner = new SqlMigrationRunner($migrationPdo, 'audit_migrations', $migrationDir, 'cpe.audit-email');
        identity_rejects(fn () => $runner->run());
        identity_assert($migrationPdo->query('SELECT email FROM users WHERE id = 1')->fetchColumn() === 'Legacy@Example.Test', 'Collision migration partially changed existing identities.');
        $migrationPdo->exec("UPDATE users SET email = ' Other@Example.Test ' WHERE id = 2");
        $runner->run();
        identity_assert($migrationPdo->query('SELECT email FROM users WHERE id = 1')->fetchColumn() === 'legacy@example.test', 'Upgrade did not normalize legacy email.');
        identity_assert($migrationPdo->query('SELECT email FROM users WHERE id = 2')->fetchColumn() === 'other@example.test', 'Upgrade did not trim legacy email.');
        identity_rejects(fn () => $migrationPdo->exec("INSERT INTO users VALUES (3, ' LEGACY@EXAMPLE.TEST ' )"));
    } finally {
        if ($postgres) {
            $migrationPdo->exec('SET search_path TO public');
            $migrationPdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
        }
    }
    echo 'PASS identity audit regressions (' . Database::driver() . ").\n";
} finally {
    Database::reset();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($testRoot);
}
