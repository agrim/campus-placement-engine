<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Persistence\DatabaseLock;
use App\Core\Persistence\DatabaseOwnership;
use App\Infrastructure\Persistence\PostgresConnectionProvider;

$owner = (string) getenv('CPE_OWNERSHIP_TEST_OWNER');
$mode = (string) (getenv('CPE_OWNERSHIP_TEST_MODE') ?: 'claim');
$sqlitePath = (string) getenv('CPE_OWNERSHIP_TEST_SQLITE_PATH');
$databaseUrl = (string) getenv('CPE_OWNERSHIP_TEST_DATABASE_URL');
$schema = (string) getenv('CPE_OWNERSHIP_TEST_SCHEMA');
$readyPath = (string) getenv('CPE_OWNERSHIP_TEST_READY');
$startPath = (string) getenv('CPE_OWNERSHIP_TEST_START');
$signalPath = (string) getenv('CPE_OWNERSHIP_TEST_SIGNAL');
$releasePath = (string) getenv('CPE_OWNERSHIP_TEST_RELEASE');
$workingDirectory = (string) getenv('CPE_OWNERSHIP_TEST_CWD');
$createProbe = (string) getenv('CPE_OWNERSHIP_TEST_CREATE_PROBE') !== '0';
$timeoutMilliseconds = (int) (getenv('CPE_OWNERSHIP_TEST_LOCK_TIMEOUT_MS') ?: '5000');

/** Wait for a test coordinator file without allowing a child to hang forever. */
function ownership_worker_wait(string $path, string $label, int $timeoutMilliseconds = 10000): void
{
    $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
    while (!is_file($path)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Ownership test ' . $label . ' timed out.');
        }
        usleep(1000);
    }
}

try {
    if ($workingDirectory !== '' && !chdir($workingDirectory)) {
        throw new RuntimeException('Could not enter ownership test working directory.');
    }
    if ($databaseUrl !== '') {
        $pdo = PostgresConnectionProvider::fromUrl($databaseUrl, 'ownership test database URL')->connection();
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $schema) !== 1) {
            throw new RuntimeException('Ownership test schema is invalid.');
        }
        $pdo->exec('SET search_path TO "' . $schema . '"');
    } else {
        if ($sqlitePath === '') {
            throw new RuntimeException('Ownership test SQLite path is missing.');
        }
        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }

    if ($readyPath === '' || file_put_contents($readyPath, "ready\n") === false) {
        throw new RuntimeException('Could not publish ownership test readiness.');
    }
    ownership_worker_wait($startPath, 'start barrier');

    if ($mode === 'hold_lock') {
        DatabaseLock::synchronized(
            $pdo,
            'cpe.database-ownership',
            static function () use ($signalPath, $releasePath): void {
                if ($signalPath === '' || file_put_contents($signalPath, "locked\n") === false) {
                    throw new RuntimeException('Could not publish ownership lock acquisition.');
                }
                ownership_worker_wait($releasePath, 'lock release', 15000);
            },
            $timeoutMilliseconds,
        );
        fwrite(STDOUT, "released\n");
        exit(0);
    }

    if ($mode === 'lock_only') {
        DatabaseLock::synchronized($pdo, 'cpe.database-ownership', static function (): void {
        }, $timeoutMilliseconds);
        fwrite(STDOUT, "acquired\n");
        exit(0);
    }

    DatabaseOwnership::claimOrVerify($pdo, $owner);
    if ($mode === 'crash_after_claim') {
        if ($signalPath === '' || file_put_contents($signalPath, "claimed\n") === false) {
            throw new RuntimeException('Could not publish crash-after-claim completion.');
        }
        fwrite(STDOUT, 'claimed-before-exit ' . $owner . "\n");
        exit(0);
    }

    if ($mode !== 'claim') {
        throw new RuntimeException('Unknown ownership worker mode.');
    }
    if ($createProbe) {
        $probe = $owner === DatabaseOwnership::OWNER_ENGINE_INSTITUTION ? 'migrations' : 'hosted_migrations';
        $qualifiedProbe = $databaseUrl === '' ? 'main.' . $probe : '"' . $schema . '"."' . $probe . '"';
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $qualifiedProbe . ' (id INTEGER NOT NULL)');
    }
    fwrite(STDOUT, 'claimed ' . $owner . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
