<?php

declare(strict_types=1);

$alphaDatabaseFixture = trim((string) (getenv('CPE_ALPHA1_DATABASE_FIXTURE') ?: ''));
$alphaBackupFixture = trim((string) (getenv('CPE_ALPHA1_BACKUP_FIXTURE') ?: ''));
if ($alphaDatabaseFixture === '' || $alphaBackupFixture === '') {
    echo "SKIP exact alpha.1 release acceptance: set CPE_ALPHA1_DATABASE_FIXTURE and CPE_ALPHA1_BACKUP_FIXTURE.\n";
    exit(0);
}
if (is_link($alphaDatabaseFixture)
    || !is_file($alphaDatabaseFixture)
    || is_link($alphaBackupFixture)
    || !is_file($alphaBackupFixture)
    || is_link($alphaBackupFixture . '.sha256')
    || !is_file($alphaBackupFixture . '.sha256')) {
    throw new RuntimeException('Exact alpha.1 acceptance fixtures are missing or unsafe.');
}

$alphaRoot = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir())
    . '/cpe-alpha1-release-' . bin2hex(random_bytes(6));
if (!mkdir($alphaRoot, 0700)) {
    throw new RuntimeException('Could not create exact alpha.1 acceptance directory.');
}
$upgradeDatabase = $alphaRoot . '/upgrade.sqlite';
$upgradeBackups = $alphaRoot . '/upgrade-backups';
$conversionInput = $alphaRoot . '/' . basename($alphaBackupFixture);
$conversionOutput = $alphaRoot . '/converted';
foreach ([$upgradeBackups, $conversionOutput] as $directory) {
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Could not create exact alpha.1 acceptance storage.');
    }
}

function alpha1_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, int|string> */
function alpha1_snapshot(PDO $pdo): array
{
    $snapshot = [
        'identity' => (string) $pdo->query(
            "SELECT public_id FROM institutions WHERE slug = 'default'",
        )->fetchColumn(),
    ];
    $queries = [
        'institution_rows' => 'SELECT id, public_id, slug, name, timezone, created_at FROM institutions ORDER BY id',
        'user_rows' => 'SELECT id, name, email, password_hash, role, active, created_at, scope_type, scope_value FROM users ORDER BY id',
        'candidate_rows' => 'SELECT id, external_id, name, program, current_location, placed_company_id, created_at, opted_out, accommodation_notes, anonymized_at, tags, custom_fields_json, public_id FROM candidates ORDER BY id',
        'company_rows' => 'SELECT id, code, name, slot, created_at, offer_tier, process_type, room, tracker_name, max_active, process_notes, deadline_day, deadline_at, tags, custom_fields_json, public_id FROM companies ORDER BY id',
        'application_rows' => 'SELECT id, candidate_id, company_id, current_status, previous_company_id, next_company_id, created_at, waitlist_rank, public_id, participant_id, opportunity_id, workflow_version_id FROM applications ORDER BY id',
    ];
    foreach ($queries as $key => $sql) {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $snapshot[$key . '_count'] = count($rows);
        $snapshot[$key . '_sha256'] = hash(
            'sha256',
            json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
    return $snapshot;
}

/** @return array{int, string, string} */
function alpha1_cli(array $arguments, array $environment): array
{
    $processEnvironment = getenv();
    if (!is_array($processEnvironment)) {
        $processEnvironment = [];
    }
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../placement', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        array_merge($processEnvironment, $environment),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start exact alpha.1 acceptance CLI.');
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

function alpha1_remove_tree(string $directory): void
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

function alpha1_assert_current_backup_set(string $archive, string $identity): void
{
    $metadataPath = $archive . '.metadata.json';
    $checksumPath = $archive . '.sha256';
    alpha1_assert(is_file($metadataPath) && is_file($checksumPath), 'Current backup sidecars are missing.');
    $metadata = json_decode((string) file_get_contents($metadataPath), true, 16, JSON_THROW_ON_ERROR);
    alpha1_assert(is_array($metadata), 'Current backup metadata is invalid.');
    $archiveHash = hash_file('sha256', $archive);
    $metadataHash = hash_file('sha256', $metadataPath);
    alpha1_assert(
        is_string($archiveHash)
            && is_string($metadataHash)
            && ($metadata['schema'] ?? null) === 'cpe.database-backup.v1'
            && ($metadata['driver'] ?? null) === 'sqlite'
            && ($metadata['owner_kind'] ?? null) === 'engine_institution'
            && ($metadata['owner_contract_version'] ?? null) === 1
            && ($metadata['institution_public_id'] ?? null) === $identity
            && ($metadata['archive_sha256'] ?? null) === $archiveHash,
        'Current backup metadata does not bind the expected archive identity.',
    );
    alpha1_assert(
        file($checksumPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) === [
            $archiveHash . '  ' . basename($archive),
            $metadataHash . '  ' . basename($metadataPath),
        ],
        'Current backup checksum does not bind archive and metadata exactly.',
    );
}

try {
    alpha1_assert(copy($alphaDatabaseFixture, $upgradeDatabase), 'Could not copy exact alpha.1 database fixture.');
    alpha1_assert(copy($alphaBackupFixture, $conversionInput), 'Could not copy exact alpha.1 backup fixture.');
    alpha1_assert(
        copy($alphaBackupFixture . '.sha256', $conversionInput . '.sha256'),
        'Could not copy exact alpha.1 checksum fixture.',
    );
    $original = new PDO('sqlite:' . $upgradeDatabase);
    $before = alpha1_snapshot($original);
    alpha1_assert(
        (int) $original->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'cpe_database_ownership'",
        )->fetchColumn() === 0,
        'Exact alpha.1 fixture unexpectedly contains current ownership evidence.',
    );
    $original = null;

    [$upgradeCode, $upgradeOut, $upgradeErr] = alpha1_cli(['upgrade'], [
        'CPE_DB_DRIVER' => 'sqlite',
        'CPE_DB_PATH' => $upgradeDatabase,
        'CPE_BACKUP_DIR' => $upgradeBackups,
    ]);
    alpha1_assert($upgradeCode === 0, 'Exact alpha.1 upgrade failed: ' . $upgradeErr);
    alpha1_assert(str_contains($upgradeOut, 'Upgrade complete.'), 'Exact alpha.1 upgrade did not complete.');
    $upgraded = new PDO('sqlite:' . $upgradeDatabase);
    alpha1_assert(alpha1_snapshot($upgraded) === $before, 'Exact alpha.1 upgrade changed identity or product rows.');
    alpha1_assert(
        $upgraded->query('SELECT owner_kind FROM cpe_database_ownership')->fetchColumn() === 'engine_institution',
        'Exact alpha.1 upgrade did not adopt permanent Engine ownership.',
    );
    $upgraded = null;
    $upgradeArchives = glob($upgradeBackups . '/upgrade-*.sqlite') ?: [];
    alpha1_assert(count($upgradeArchives) === 1, 'Exact alpha.1 upgrade did not create one backup.');
    alpha1_assert_current_backup_set($upgradeArchives[0], (string) $before['identity']);
    $upgradeArchivePdo = new PDO('sqlite:' . $upgradeArchives[0]);
    alpha1_assert(
        alpha1_snapshot($upgradeArchivePdo) === $before,
        'Exact alpha.1 pre-migration backup changed institution or product rows.',
    );
    $upgradeArchivePdo = null;

    $backupHash = hash_file('sha256', $conversionInput);
    $checksumHash = hash_file('sha256', $conversionInput . '.sha256');
    $conversionSourcePdo = new PDO('sqlite:' . $conversionInput);
    $conversionBefore = alpha1_snapshot($conversionSourcePdo);
    $conversionSourcePdo = null;
    [$conversionCode, $conversionOut, $conversionErr] = alpha1_cli([
        'convert-legacy-backup',
        $conversionInput,
        '--confirm=CONVERT',
        '--target-dir=' . $conversionOutput,
    ], []);
    alpha1_assert($conversionCode === 0, 'Exact alpha.1 backup conversion failed: ' . $conversionErr);
    alpha1_assert(!str_contains($conversionOut, $alphaRoot), 'Exact alpha.1 conversion exposed an absolute path.');
    alpha1_assert(
        hash_file('sha256', $conversionInput) === $backupHash
            && hash_file('sha256', $conversionInput . '.sha256') === $checksumHash,
        'Exact alpha.1 conversion modified an original artifact.',
    );
    $converted = glob($conversionOutput . '/*-converted-*.sqlite') ?: [];
    alpha1_assert(count($converted) === 1, 'Exact alpha.1 conversion did not create one new archive.');
    $convertedPdo = new PDO('sqlite:' . $converted[0]);
    alpha1_assert(
        $convertedPdo->query('SELECT owner_kind FROM cpe_database_ownership')->fetchColumn() === 'engine_institution',
        'Exact alpha.1 converted backup lacks permanent Engine ownership.',
    );
    alpha1_assert(
        alpha1_snapshot($convertedPdo) === $conversionBefore,
        'Exact alpha.1 conversion changed institution or product rows.',
    );
    alpha1_assert_current_backup_set($converted[0], (string) $conversionBefore['identity']);

    echo "PASS exact tagged alpha.1 upgrade and legacy backup conversion acceptance\n";
} finally {
    alpha1_remove_tree($alphaRoot);
}
