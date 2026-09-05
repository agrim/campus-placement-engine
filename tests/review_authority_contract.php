<?php

declare(strict_types=1);

// Synthetic standalone contract. An optional explicit PostgreSQL test URL is supported.
$fixture = sys_get_temp_dir() . '/cpe-review-authority-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700, true);
$postgresUrl = trim((string) (getenv('CPE_REVIEW_AUTHORITY_DATABASE_URL') ?: ''));
if ($postgresUrl !== '') {
    putenv('CPE_DB_DRIVER=pgsql');
    putenv('CPE_DATABASE_URL=' . $postgresUrl);
    putenv('CPE_POSTGRES_POOL_MODE=direct');
} else {
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $fixture . '/engine.sqlite');
}
putenv('CPE_LOG_PATH=' . $fixture . '/engine.log');
define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Domain\PlacementService;
use App\Install\Installer;
use App\Support\Auth;
use App\Support\Database;

$failures = [];
function authority_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . "\n";
    if (!$condition) {
        $failures[] = $message;
    }
}

try {
    $adminId = (new Installer())->install([
        'college_name' => 'Synthetic Authority College',
        'admin_name' => 'Synthetic Admin',
        'admin_email' => 'admin@authority.example.test',
        'admin_password' => 'Initial-Authority-Password-123',
        'seed_demo' => '1',
    ]);
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $company = (string) $pdo->query('SELECT code FROM companies ORDER BY id LIMIT 1')->fetchColumn();
    $scoped = ['role' => 'company', 'scope_type' => 'company', 'scope_value' => $company];
    $flatten = static fn(array $groups): array => array_merge(...array_map(static fn(array $g): array => $g['applications'], array_values($groups)));
    authority_check(count($flatten($service->dashboard($scoped))) > 0, 'valid company scope retains its own board');
    foreach ([[], ['scope_value' => ''], ['scope_value' => '   ']] as $fields) {
        $actor = array_merge(['role' => 'company', 'scope_type' => 'company'], $fields);
        authority_check($flatten($service->dashboard($actor)) === [], 'missing/blank company scope cannot expose the institution roster');
        authority_check($service->boardFilterOptions($actor)['companies'] === [], 'missing/blank company scope cannot enumerate company filters');
    }
    $otherRole = ['role' => 'control', 'scope_type' => 'company', 'scope_value' => ''];
    authority_check($flatten($service->dashboard($otherRole)) === [], 'a company-scoped control actor also fails closed without a scope');
    $trimmed = $scoped;
    $trimmed['scope_value'] = '  ' . strtolower($company) . '  ';
    authority_check(count($flatten($service->dashboard($trimmed))) > 0, 'valid scope normalization is consistent between reads and writes');

    $email = 'auditor@authority.example.test';
    $userId = Auth::createUser('Synthetic Auditor', $email, 'Original-Password-123', 'auditor');
    authority_check(Auth::attempt($email, 'Original-Password-123'), 'password login succeeds before revocation');
    $oldSession = $_SESSION;
    Auth::setPassword($userId, 'Replacement-Password-456', $adminId);
    $_SESSION = $oldSession;
    authority_check(Auth::user() === null, 'password reset invalidates a previously authenticated session');
    authority_check(Auth::attempt($email, 'Replacement-Password-456'), 'new password establishes a fresh session');
    $beforeDisable = $_SESSION;
    Auth::setActive($userId, false);
    Auth::setActive($userId, true);
    $_SESSION = $beforeDisable;
    authority_check(Auth::user() === null, 'reactivation cannot revive a session predating deactivation');
    Auth::loginById($userId, 'sso:synthetic');
    $ssoSession = $_SESSION;
    Auth::setPassword($userId, 'Another-Replacement-789', $adminId);
    $_SESSION = $ssoSession;
    authority_check(Auth::user() === null, 'administrative credential reset also revokes an older SSO session');
    Auth::loginById($adminId);
    $actorBeforeReset = Auth::user();
    $appId = (int) $pdo->query("SELECT id FROM applications WHERE current_status = 'scheduled' LIMIT 1")->fetchColumn();
    authority_check($appId > 0, 'synthetic correction fixture has a scheduled application');
    Auth::setPassword($adminId, 'Admin-Replacement-Password-456', $adminId);
    $correctionDenied = false;
    try {
        $service->applyBoardReturnToIdle(
            $appId, $adminId, 'admin', 'operator_return', 'Synthetic correction',
            'scheduled', bin2hex(random_bytes(16)), $actorBeforeReset,
        );
    } catch (\App\Core\Http\UserVisibleException) {
        $correctionDenied = true;
    }
    authority_check($correctionDenied, 'correction revalidates actor generation inside its transaction');
    authority_check($pdo->query('SELECT current_status FROM applications WHERE id = ' . $appId)->fetchColumn() === 'scheduled', 'denied correction leaves application state unchanged');
    $_SESSION = ['user_id' => $userId];
    authority_check(Auth::user() === null, 'pre-generation sessions require reauthentication rather than being silently trusted');
} finally {
    $_SESSION = [];
    Database::reset();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($fixture);
}
exit($failures === [] ? 0 : 1);
