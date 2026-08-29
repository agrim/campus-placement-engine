<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$testRoot = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir())
    . '/cpe-authorization-state-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create authorization-state contract root.');
}
$databasePath = $testRoot . '/engine.sqlite';
$requestedDriver = strtolower(trim((string) (getenv('CPE_DB_DRIVER') ?: 'sqlite')));
$postgres = in_array($requestedDriver, ['pgsql', 'postgresql'], true)
    || trim((string) (getenv('CPE_DATABASE_URL') ?: '')) !== '';
if (!$postgres) {
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $databasePath);
}

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require $projectRoot . '/app/bootstrap.php';

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleManager;
use App\Core\Security\AuthorizationUnavailable;
use App\Core\Security\CapabilityService;
use App\Install\Installer;
use App\Support\Database;

function authorization_state_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function authorization_state_unavailable(callable $operation, string $reason, string $label): void
{
    try {
        $operation();
    } catch (AuthorizationUnavailable $failure) {
        authorization_state_true($failure->reason() === $reason, $label . ' returned the wrong typed reason.');
        authorization_state_true(
            $failure->getMessage() === 'Authorization state is temporarily unavailable.',
            $label . ' did not use the fixed redacted message.',
        );
        authorization_state_true($failure->getPrevious() === null, $label . ' retained its raw database failure.');
        return;
    }
    throw new RuntimeException($label . ' did not raise AuthorizationUnavailable.');
}

function authorization_state_registry(PDO $pdo): ModuleRegistry
{
    return new ModuleRegistry((array) cpe_config('modules', []), $pdo);
}

function authorization_state_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path . '/' . $entry;
        if (is_dir($child)) {
            authorization_state_remove_tree($child);
        } elseif (is_file($child)) {
            unlink($child);
        }
    }
    rmdir($path);
}

try {
    (new Installer())->install([
        'college_name' => 'Authorization State Contract College',
        'timezone' => 'UTC',
        'admin_name' => 'Authorization State Administrator',
        'admin_email' => 'authorization-state@example.test',
        'admin_password' => 'authorization-state-password-123',
    ]);
    $pdo = Database::connection();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $pdo->exec('DELETE FROM role_capabilities');
    $empty = CapabilityService::fromDatabase($pdo, authorization_state_registry($pdo));
    authorization_state_true(
        !$empty->allows(['role' => 'admin', 'active' => 1], 'placement.application.transition'),
        'Readable empty durable grants reconstructed a static administrator grant.',
    );
    $pdo->exec("INSERT INTO role_capabilities (role_key, capability) VALUES ('control', 'placement.application.transition')");
    $granted = CapabilityService::fromDatabase($pdo, authorization_state_registry($pdo));
    authorization_state_true(
        $granted->allows(['role' => 'control', 'active' => 1], 'placement.application.transition'),
        'Legitimate durable grant was not honored.',
    );
    $pdo->exec("DELETE FROM role_capabilities WHERE role_key = 'control'");
    $removed = CapabilityService::fromDatabase($pdo, authorization_state_registry($pdo));
    authorization_state_true(
        !$removed->allows(['role' => 'control', 'active' => 1], 'placement.application.transition'),
        'Removed durable grant was reconstructed.',
    );

    $pdo->exec('ALTER TABLE role_capabilities RENAME TO role_capabilities_valid');
    authorization_state_unavailable(
        static fn (): CapabilityService => CapabilityService::fromDatabase($pdo, authorization_state_registry($pdo)),
        AuthorizationUnavailable::CAPABILITY_STATE,
        'Missing role_capabilities',
    );
    $pdo->exec('ALTER TABLE role_capabilities_valid RENAME TO role_capabilities');
    $pdo->exec("INSERT INTO role_capabilities (role_key, capability) VALUES ('control', 'placement.application.transition')");

    $pdo->exec('ALTER TABLE module_installations RENAME TO module_installations_valid');
    $missingModules = CapabilityService::fromDatabase($pdo, authorization_state_registry($pdo));
    authorization_state_unavailable(
        static fn (): bool => $missingModules->allows(
            ['role' => 'control', 'active' => 1],
            'placement.application.transition',
        ),
        AuthorizationUnavailable::MODULE_STATE,
        'Missing module_installations',
    );
    $pdo->exec('ALTER TABLE module_installations_valid RENAME TO module_installations');

    $constraintRejected = false;
    try {
        $pdo->exec("UPDATE module_installations SET enabled = 2 WHERE module_key = 'placement'");
    } catch (Throwable) {
        $constraintRejected = true;
    }
    authorization_state_true($constraintRejected, 'Module enabled constraint accepted value 2.');
    $constraintRejected = false;
    try {
        $pdo->exec("UPDATE module_installations SET enabled = -1 WHERE module_key = 'placement'");
    } catch (Throwable) {
        $constraintRejected = true;
    }
    authorization_state_true($constraintRejected, 'Module enabled constraint accepted value -1.');

    $pdo->exec('ALTER TABLE module_installations RENAME TO module_installations_valid');
    $pdo->exec(
        'CREATE VIEW module_installations AS
         SELECT module_key, version, 2 AS enabled FROM module_installations_valid',
    );
    $malformedModules = CapabilityService::fromDatabase($pdo, authorization_state_registry($pdo));
    authorization_state_unavailable(
        static fn (): bool => $malformedModules->allows(
            ['role' => 'control', 'active' => 1],
            'placement.application.transition',
        ),
        AuthorizationUnavailable::MODULE_STATE,
        'Malformed module enabled value',
    );
    $pdo->exec('DROP VIEW module_installations');
    $pdo->exec(
        'CREATE VIEW module_installations AS
         SELECT module_key, enabled FROM module_installations_valid',
    );
    $malformedShape = CapabilityService::fromDatabase($pdo, authorization_state_registry($pdo));
    authorization_state_unavailable(
        static fn (): bool => $malformedShape->allows(
            ['role' => 'control', 'active' => 1],
            'placement.application.transition',
        ),
        AuthorizationUnavailable::MODULE_STATE,
        'Malformed module row shape',
    );
    $pdo->exec('DROP VIEW module_installations');
    $pdo->exec('ALTER TABLE module_installations_valid RENAME TO module_installations');

    $pdo->exec("UPDATE module_installations SET version = '9.9.9' WHERE module_key = 'placement'");
    $versionDriftRegistry = authorization_state_registry($pdo);
    $versionDriftCapabilities = CapabilityService::fromDatabase($pdo, $versionDriftRegistry);
    authorization_state_unavailable(
        static fn (): bool => $versionDriftCapabilities->allows(
            ['role' => 'control', 'active' => 1],
            'placement.application.transition',
        ),
        AuthorizationUnavailable::MODULE_STATE,
        'Durable module version drift during authorization',
    );
    authorization_state_unavailable(
        static fn (): array => (new ModuleManager(
            $versionDriftRegistry,
            $versionDriftCapabilities,
        ))->modules(),
        AuthorizationUnavailable::MODULE_STATE,
        'Durable module version drift during module resolution',
    );
    $pdo->exec("UPDATE module_installations SET version = '0.1.0' WHERE module_key = 'placement'");

    $pdo->exec("UPDATE module_installations SET enabled = 0 WHERE module_key = 'placement'");
    $disabled = CapabilityService::fromDatabase($pdo, authorization_state_registry($pdo));
    authorization_state_true(
        !$disabled->allows(['role' => 'control', 'active' => 1], 'placement.application.transition'),
        'Legitimate disabled module granted its capability.',
    );
    $pdo->exec("UPDATE module_installations SET enabled = 1 WHERE module_key = 'placement'");
    $enabled = CapabilityService::fromDatabase($pdo, authorization_state_registry($pdo));
    authorization_state_true(
        $enabled->allows(['role' => 'control', 'active' => 1], 'placement.application.transition'),
        'Legitimate enabled module denied its durable capability.',
    );

    echo 'Authorization state contract passed (' . $driver . ").\n";
} finally {
    Database::reset();
    authorization_state_remove_tree($testRoot);
}
