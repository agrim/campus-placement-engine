<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$testRoot = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir())
    . '/cpe-admin-settings-transaction-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Could not create admin-settings transaction contract root.');
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

use App\Controllers\AdminController;
use App\Install\Installer;
use App\Support\Database;

function admin_settings_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function admin_settings_contract_setting(PDO $pdo, string $key): string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? '' : (string) $value;
}

function admin_settings_contract_install_failure_trigger(PDO $pdo, string $driver): void
{
    if ($driver === 'pgsql') {
        $pdo->exec(<<<'SQL'
CREATE OR REPLACE FUNCTION cpe_admin_settings_contract_fail() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.key = 'board_refresh_seconds' THEN
        RAISE EXCEPTION 'admin settings contract injected failure';
    END IF;
    RETURN NEW;
END
$$
SQL);
        $pdo->exec(
            'CREATE TRIGGER cpe_admin_settings_contract_fail '
            . 'BEFORE INSERT OR UPDATE ON settings '
            . 'FOR EACH ROW EXECUTE FUNCTION cpe_admin_settings_contract_fail()',
        );
        return;
    }
    $pdo->exec(<<<'SQL'
CREATE TRIGGER cpe_admin_settings_contract_fail_insert
BEFORE INSERT ON settings
WHEN NEW.key = 'board_refresh_seconds'
BEGIN
    SELECT RAISE(ABORT, 'admin settings contract injected failure');
END
SQL);
    $pdo->exec(<<<'SQL'
CREATE TRIGGER cpe_admin_settings_contract_fail_update
BEFORE UPDATE OF value ON settings
WHEN NEW.key = 'board_refresh_seconds'
BEGIN
    SELECT RAISE(ABORT, 'admin settings contract injected failure');
END
SQL);
}

function admin_settings_contract_remove_failure_trigger(PDO $pdo, string $driver): void
{
    if ($driver === 'pgsql') {
        $pdo->exec('DROP TRIGGER IF EXISTS cpe_admin_settings_contract_fail ON settings');
        $pdo->exec('DROP FUNCTION IF EXISTS cpe_admin_settings_contract_fail()');
        return;
    }
    $pdo->exec('DROP TRIGGER IF EXISTS cpe_admin_settings_contract_fail_insert');
    $pdo->exec('DROP TRIGGER IF EXISTS cpe_admin_settings_contract_fail_update');
}

function admin_settings_contract_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path . '/' . $entry;
        if (is_dir($child) && !is_link($child)) {
            admin_settings_contract_remove_tree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}

try {
    (new Installer())->install([
        'college_name' => 'Atomic Settings Contract College',
        'timezone' => 'UTC',
        'cycle_name' => 'Original Cycle',
        'admin_name' => 'Atomic Settings Administrator',
        'admin_email' => 'atomic-settings@example.test',
        'admin_password' => 'atomic-settings-password-123',
    ]);
    $pdo = Database::connection();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $actorId = (int) $pdo->query("SELECT id FROM users WHERE email = 'atomic-settings@example.test'")->fetchColumn();
    admin_settings_contract_assert($actorId > 0, 'Installed administrator was not found.');

    $settings = [
        'college_name' => 'Changed College',
        'timezone' => 'Asia/Dubai',
        'cycle_name' => 'Changed Cycle',
        'cycle_type' => 'final',
        'cycle_start_date' => '2026-09-01',
        'cycle_end_date' => '2026-09-30',
        'configuration_freeze' => '0',
        'board_refresh_seconds' => '99',
    ];

    $method = new ReflectionMethod(AdminController::class, 'persistSettings');
    $controller = new AdminController();
    $beforeCollege = admin_settings_contract_setting($pdo, 'college_name');
    $beforeCycle = admin_settings_contract_setting($pdo, 'cycle_name');
    $beforeAudits = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.update'")->fetchColumn();

    admin_settings_contract_install_failure_trigger($pdo, $driver);
    $failed = false;
    try {
        $method->invoke($controller, $pdo, $settings, $actorId);
    } catch (Throwable) {
        $failed = true;
    }
    admin_settings_contract_assert($failed, 'Injected late settings failure did not abort the update.');
    admin_settings_contract_assert(
        admin_settings_contract_setting($pdo, 'college_name') === $beforeCollege,
        'College setting remained partially updated after rollback.',
    );
    admin_settings_contract_assert(
        admin_settings_contract_setting($pdo, 'cycle_name') === $beforeCycle,
        'Cycle setting remained partially updated after rollback.',
    );
    admin_settings_contract_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.update'")->fetchColumn()
            === $beforeAudits,
        'Failed settings update retained a success audit row.',
    );

    admin_settings_contract_remove_failure_trigger($pdo, $driver);
    $unfrozeOnly = $method->invoke($controller, $pdo, $settings, $actorId);
    admin_settings_contract_assert($unfrozeOnly === false, 'Ordinary update was classified as unfreeze-only.');
    admin_settings_contract_assert(
        admin_settings_contract_setting($pdo, 'college_name') === 'Changed College',
        'Successful settings transaction did not persist the college setting.',
    );
    admin_settings_contract_assert(
        admin_settings_contract_setting($pdo, 'board_refresh_seconds') === '99',
        'Successful settings transaction did not persist the late setting.',
    );
    admin_settings_contract_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.update'")->fetchColumn()
            === $beforeAudits + 1,
        'Successful settings update did not record exactly one audit row.',
    );

    echo 'Admin settings transaction contract passed (' . $driver . ").\n";
} finally {
    try {
        if (isset($pdo, $driver)) {
            admin_settings_contract_remove_failure_trigger($pdo, $driver);
        }
    } catch (Throwable) {
    }
    Database::reset();
    admin_settings_contract_remove_tree($testRoot);
}
