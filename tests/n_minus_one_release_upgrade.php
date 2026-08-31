<?php

declare(strict_types=1);

$archivePath = trim((string) (getenv('CPE_N_MINUS_ONE_ARCHIVE') ?: ''));
$expectedVersion = trim((string) (getenv('CPE_N_MINUS_ONE_EXPECTED_VERSION') ?: ''));
$expectedSha256 = strtolower(trim((string) (getenv('CPE_N_MINUS_ONE_EXPECTED_SHA256') ?: '')));
if ($archivePath === '' || $expectedVersion === '' || $expectedSha256 === '') {
    echo "SKIP exact N-minus-one package upgrade: configure archive, version, and SHA-256.\n";
    exit(0);
}
if (is_link($archivePath) || !is_file($archivePath)) {
    throw new RuntimeException('The N-minus-one release archive is missing or unsafe.');
}
$archivePath = (string) realpath($archivePath);
if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1
    || !hash_equals($expectedSha256, (string) hash_file('sha256', $archivePath))) {
    throw new RuntimeException('The N-minus-one release archive checksum does not match the reviewed digest.');
}

$currentRoot = dirname(__DIR__);
$testRoot = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir())
    . '/cpe-n-minus-one-' . bin2hex(random_bytes(6));
$extractRoot = $testRoot . '/release';
$database = $testRoot . '/installed.sqlite';
$backups = $testRoot . '/backups';
foreach ([$testRoot, $extractRoot, $backups] as $directory) {
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Could not create isolated N-minus-one test storage.');
    }
}
$verifiedArchive = $testRoot . '/' . basename($archivePath);
if (!copy($archivePath, $verifiedArchive)
    || file_put_contents(
        $verifiedArchive . '.sha256',
        $expectedSha256 . '  ' . basename($verifiedArchive) . "\n",
        LOCK_EX,
    ) === false) {
    throw new RuntimeException('Could not prepare the isolated N-minus-one package fixture.');
}

function n1_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{int, string, string} */
function n1_cli(string $root, array $arguments, array $environment = []): array
{
    $processEnvironment = getenv();
    if (!is_array($processEnvironment)) {
        $processEnvironment = [];
    }
    $process = proc_open(
        [PHP_BINARY, $root . '/placement', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        array_merge($processEnvironment, $environment),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start an N-minus-one release command.');
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

/** @return array<string, int|string> */
function n1_product_snapshot(PDO $pdo): array
{
    $snapshot = [
        'identity' => (string) $pdo->query(
            "SELECT public_id FROM institutions WHERE slug = 'default'",
        )->fetchColumn(),
    ];
    $queries = [
        'institutions' => 'SELECT id, public_id, slug, name, timezone, created_at FROM institutions ORDER BY id',
        'users' => 'SELECT id, name, email, password_hash, role, active, created_at, scope_type, scope_value FROM users ORDER BY id',
        'candidates' => 'SELECT id, external_id, name, program, current_location, placed_company_id, created_at, opted_out, accommodation_notes, anonymized_at, tags, custom_fields_json, public_id FROM candidates ORDER BY id',
        'companies' => 'SELECT id, code, name, slot, created_at, offer_tier, process_type, room, tracker_name, max_active, process_notes, deadline_day, deadline_at, tags, custom_fields_json, public_id FROM companies ORDER BY id',
        'applications' => 'SELECT id, candidate_id, company_id, current_status, previous_company_id, next_company_id, created_at, waitlist_rank, public_id, participant_id, opportunity_id, workflow_version_id FROM applications ORDER BY id',
    ];
    foreach ($queries as $key => $query) {
        $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
        $snapshot[$key . '_count'] = count($rows);
        $snapshot[$key . '_sha256'] = hash(
            'sha256',
            json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
    return $snapshot;
}

function n1_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

function n1_inspect_archive(string $archivePath): string
{
    if (!class_exists(PharData::class)) {
        throw new RuntimeException('PharData is required to inspect the N-minus-one package.');
    }
    $archive = new PharData($archivePath);
    $archivePrefix = 'phar://' . (realpath($archivePath) ?: $archivePath) . '/';
    $rootName = '';
    $entryCount = 0;
    $uncompressedBytes = 0;
    foreach (new RecursiveIteratorIterator($archive) as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $pathname = $file->getPathname();
        n1_assert(
            str_starts_with($pathname, $archivePrefix),
            'N-minus-one package contains an unreadable archive entry.',
        );
        $entry = substr($pathname, strlen($archivePrefix));
        $segments = explode('/', $entry);
        $candidateRoot = (string) ($segments[0] ?? '');
        n1_assert(
            $entry !== ''
                && !str_contains($entry, "\0")
                && !str_contains($entry, '\\')
                && !in_array('', $segments, true)
                && !in_array('.', $segments, true)
                && !in_array('..', $segments, true)
                && preg_match('/^campus-placement-engine-[A-Za-z0-9._-]+$/', $candidateRoot) === 1
                && !$file->isLink(),
            'N-minus-one package contains an unsafe archive entry: ' . $entry,
        );
        if ($rootName === '') {
            $rootName = $candidateRoot;
        }
        n1_assert($rootName === $candidateRoot, 'N-minus-one package must contain one application root.');
        $entryCount++;
        $size = max(0, (int) $file->getSize());
        $uncompressedBytes += $size;
        n1_assert(
            $entryCount <= 5000 && $size <= 20 * 1024 * 1024 && $uncompressedBytes <= 100 * 1024 * 1024,
            'N-minus-one package exceeds safe inspection limits.',
        );
    }
    n1_assert($rootName !== '' && $entryCount > 0, 'N-minus-one package contains no application files.');
    return $rootName;
}

try {
    $archiveRoot = n1_inspect_archive($verifiedArchive);
    (new PharData($verifiedArchive))->extractTo($extractRoot);
    $entries = array_values(array_filter(glob($extractRoot . '/*') ?: [], 'is_dir'));
    n1_assert(count($entries) === 1, 'N-minus-one archive did not contain one release root.');
    $previousRoot = $entries[0];
    n1_assert(basename($previousRoot) === $archiveRoot, 'N-minus-one extracted root does not match inspection.');
    n1_assert(is_file($previousRoot . '/placement'), 'N-minus-one archive lacks the placement CLI.');

    [$verifyCode, $verifyOut, $verifyErr] = n1_cli($previousRoot, ['verify-package', $verifiedArchive]);
    n1_assert(
        $verifyCode === 0,
        'N-minus-one package failed its immutable release policy: ' . $verifyOut . $verifyErr,
    );

    $previousConfig = require $previousRoot . '/config/defaults.php';
    $currentConfig = require $currentRoot . '/config/defaults.php';
    $previousVersion = (string) ($previousConfig['app']['version'] ?? '');
    $currentVersion = (string) ($currentConfig['app']['version'] ?? '');
    n1_assert($previousVersion === $expectedVersion, 'N-minus-one package version is not the reviewed version.');
    n1_assert(
        version_compare($currentVersion, $previousVersion, '>'),
        'Current Engine version must be newer than the N-minus-one package.',
    );

    [$installCode, $installOut, $installErr] = n1_cli($previousRoot, ['install-demo'], [
        'CPE_DB_DRIVER' => 'sqlite',
        'CPE_DB_PATH' => $database,
    ]);
    n1_assert($installCode === 0, 'N-minus-one package could not install its synthetic fixture: ' . $installOut . $installErr);

    $beforePdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $before = n1_product_snapshot($beforePdo);
    $beforePdo = null;

    [$upgradeCode, $upgradeOut, $upgradeErr] = n1_cli($currentRoot, ['upgrade'], [
        'CPE_DB_DRIVER' => 'sqlite',
        'CPE_DB_PATH' => $database,
        'CPE_BACKUP_DIR' => $backups,
    ]);
    n1_assert($upgradeCode === 0, 'Current Engine could not upgrade N-minus-one: ' . $upgradeOut . $upgradeErr);
    n1_assert(str_contains($upgradeOut, 'Upgrade complete.'), 'N-minus-one upgrade did not report completion.');

    $upgradedPdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    n1_assert(
        n1_product_snapshot($upgradedPdo) === $before,
        'N-minus-one upgrade changed institution, user, candidate, company, or application rows.',
    );
    $migrationFiles = array_map('basename', glob($currentRoot . '/database/migrations/*.sql') ?: []);
    sort($migrationFiles);
    $appliedMigrations = array_map(
        'strval',
        $upgradedPdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN),
    );
    sort($appliedMigrations);
    n1_assert($appliedMigrations === $migrationFiles, 'N-minus-one upgrade did not converge to exact migration history.');
    n1_assert(
        $upgradedPdo->query('SELECT owner_kind FROM cpe_database_ownership')->fetchColumn() === 'engine_institution',
        'N-minus-one upgrade lost permanent Engine database ownership.',
    );
    $upgradedPdo = null;

    $archives = glob($backups . '/upgrade-*.sqlite') ?: [];
    n1_assert(count($archives) === 1, 'N-minus-one upgrade did not produce exactly one pre-migration backup.');
    n1_assert(
        is_file($archives[0] . '.metadata.json') && is_file($archives[0] . '.sha256'),
        'N-minus-one upgrade backup is missing identity or checksum evidence.',
    );
    $backupPdo = new PDO('sqlite:' . $archives[0], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    n1_assert(n1_product_snapshot($backupPdo) === $before, 'N-minus-one backup changed pre-upgrade product rows.');
    $backupPdo = null;

    foreach ([['doctor'], ['readiness']] as $command) {
        [$code, $stdout, $stderr] = n1_cli($currentRoot, $command, [
            'CPE_DB_DRIVER' => 'sqlite',
            'CPE_DB_PATH' => $database,
        ]);
        n1_assert($code === 0, implode(' ', $command) . ' failed after N-minus-one upgrade: ' . $stdout . $stderr);
    }

    echo "PASS exact {$previousVersion} release package upgrade to {$currentVersion}\n";
} finally {
    n1_remove_tree($testRoot);
}
