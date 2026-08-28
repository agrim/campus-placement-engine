<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Persistence\DatabaseLock;
use App\Infrastructure\Persistence\PostgresConnectionPolicy;
use App\Infrastructure\Persistence\PostgresConnectionProvider;
use App\Infrastructure\Persistence\SqliteConnectionProvider;
use App\Install\Installer;
use App\Support\Database;

function install_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function install_contract_wait_for_files(array $files, string $label, int $timeoutMilliseconds = 30000): void
{
    $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
    do {
        $missing = array_filter($files, static fn (string $file): bool => !is_file($file));
        if ($missing === []) {
            return;
        }
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for ' . $label . '.');
        }
        usleep(1000);
    } while (true);
}

/**
 * @param array<string, mixed> $input
 * @return array{process: resource, pipes: array<int, resource>, participant: string}
 */
function install_contract_spawn(
    string $participant,
    array $input,
    string $readyFile,
    string $startFile,
    ?string $sqlitePath,
    ?string $databaseUrl,
): array {
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    foreach ([
        'CPE_DB_PATH',
        'CPE_DB_DRIVER',
        'CPE_DATABASE_URL',
        'CPE_PG_HOST',
        'CPE_PG_PORT',
        'CPE_PG_DATABASE',
        'CPE_PG_USER',
        'CPE_PG_PASSWORD',
        'CPE_INSTALL_TEST_PARTICIPANT',
        'CPE_INSTALL_TEST_READY',
        'CPE_INSTALL_TEST_START',
        'CPE_INSTALL_TEST_INPUT',
    ] as $key) {
        unset($environment[$key]);
    }
    if ($databaseUrl !== null) {
        $environment['CPE_DB_DRIVER'] = 'pgsql';
        $environment['CPE_DATABASE_URL'] = $databaseUrl;
    } elseif ($sqlitePath !== null) {
        $environment['CPE_DB_DRIVER'] = 'sqlite';
        $environment['CPE_DB_PATH'] = $sqlitePath;
    } else {
        throw new RuntimeException('Install concurrency worker database is missing.');
    }
    $environment['CPE_INSTALL_TEST_PARTICIPANT'] = $participant;
    $environment['CPE_INSTALL_TEST_READY'] = $readyFile;
    $environment['CPE_INSTALL_TEST_START'] = $startFile;
    $environment['CPE_INSTALL_TEST_INPUT'] = base64_encode(json_encode($input, JSON_THROW_ON_ERROR));

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/install_concurrency_worker.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start install concurrency worker ' . $participant . '.');
    }
    fclose($pipes[0]);
    unset($pipes[0]);
    return [
        'process' => $process,
        'pipes' => $pipes,
        'participant' => $participant,
    ];
}

/**
 * @param array<int, array{process: resource, pipes: array<int, resource>, participant: string}> $workers
 * @return array<int, array{code: int, stdout: string, stderr: string, result: array<string, mixed>}>
 */
function install_contract_collect(array &$workers, int $timeoutMilliseconds = 90000): array
{
    $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
    $results = [];
    while ($workers !== []) {
        foreach ($workers as $index => $worker) {
            $status = proc_get_status($worker['process']);
            if (!is_array($status) || ($status['running'] ?? false)) {
                continue;
            }
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $closeCode = proc_close($worker['process']);
            $code = (int) ($status['exitcode'] ?? $closeCode);
            if ($code < 0) {
                $code = $closeCode;
            }
            $decoded = json_decode(trim((string) $stdout), true);
            install_contract_assert(is_array($decoded), 'Worker ' . $worker['participant'] . ' returned invalid JSON: ' . $stdout . ' ' . $stderr);
            $results[] = [
                'code' => $code,
                'stdout' => (string) $stdout,
                'stderr' => (string) $stderr,
                'result' => $decoded,
            ];
            unset($workers[$index]);
        }
        if ($workers === []) {
            break;
        }
        if (hrtime(true) >= $deadline) {
            foreach ($workers as $worker) {
                proc_terminate($worker['process']);
                foreach ($worker['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($worker['process']);
            }
            $workers = [];
            throw new RuntimeException('Install concurrency workers exceeded the bounded deadline.');
        }
        usleep(10_000);
    }
    usort($results, static fn (array $left, array $right): int => strcmp(
        (string) ($left['result']['participant'] ?? ''),
        (string) ($right['result']['participant'] ?? ''),
    ));
    return $results;
}

/** @param array<int, array{process: resource, pipes: array<int, resource>, participant: string}> $workers */
function install_contract_stop(array &$workers): void
{
    foreach ($workers as $worker) {
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
        }
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($worker['process'])) {
            proc_close($worker['process']);
        }
    }
    $workers = [];
}

function install_contract_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
}

/** @return array<string, array<string, mixed>> */
function install_contract_inputs(): array
{
    return [
        'alpha' => [
            'tenant_public_id' => 'tenant_' . str_repeat('a', 32),
            'college_name' => 'Concurrency Alpha College',
            'site_name' => 'Concurrency Alpha Desk',
            'site_tagline' => 'alpha-install-sentinel',
            'timezone' => 'UTC',
            'cycle_name' => 'Concurrency Alpha Cycle',
            'workflow' => 'default',
            'terminology_candidate_label' => 'Alpha Candidate',
            'admin_name' => 'Concurrency Alpha Administrator',
            'admin_email' => 'concurrency-alpha@example.test',
            'admin_password' => 'alpha-contract-password-123',
            'seed_demo' => '1',
        ],
        'beta' => [
            'tenant_public_id' => 'tenant_' . str_repeat('b', 32),
            'college_name' => 'Concurrency Beta College',
            'site_name' => 'Concurrency Beta Desk',
            'site_tagline' => 'beta-install-sentinel',
            'timezone' => 'Asia/Dubai',
            'cycle_name' => 'Concurrency Beta Cycle',
            'workflow' => 'simple_placement_cell',
            'terminology_candidate_label' => 'Beta Candidate',
            'admin_name' => 'Concurrency Beta Administrator',
            'admin_email' => 'concurrency-beta@example.test',
            'admin_password' => 'beta-contract-password-123',
            'seed_demo' => '1',
        ],
    ];
}

function install_contract_observer(?string $sqlitePath, ?string $databaseUrl): PDO
{
    $provider = $databaseUrl !== null
        ? PostgresConnectionProvider::fromUrl($databaseUrl, 'install concurrency database URL')
        : new SqliteConnectionProvider((string) $sqlitePath);
    return $provider->connection();
}

function install_contract_assert_fresh_postgres(PDO $pdo): void
{
    $count = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema()",
    )->fetchColumn();
    install_contract_assert(
        $count === 0,
        'PostgreSQL install concurrency contract requires a fresh dedicated database/schema.',
    );
}

function install_contract_run(string $driver, ?string $sqlitePath, ?string $databaseUrl, string $root): void
{
    $observer = install_contract_observer($sqlitePath, $databaseUrl);
    if ($driver === 'pgsql') {
        install_contract_assert_fresh_postgres($observer);
    }

    $inputs = install_contract_inputs();
    $startFile = $root . '/' . $driver . '-start';
    $readyFiles = [
        'alpha' => $root . '/' . $driver . '-alpha-ready',
        'beta' => $root . '/' . $driver . '-beta-ready',
    ];
    $workers = [];
    try {
        foreach ($inputs as $participant => $input) {
            $workers[] = install_contract_spawn(
                $participant,
                $input,
                $readyFiles[$participant],
                $startFile,
                $sqlitePath,
                $databaseUrl,
            );
        }
        install_contract_wait_for_files(array_values($readyFiles), $driver . ' worker readiness');
        install_contract_assert(file_put_contents($startFile, "start\n") !== false, 'Could not release the install start barrier.');
        $results = install_contract_collect($workers);
    } finally {
        install_contract_stop($workers);
    }

    install_contract_assert(count($results) === 2, 'Install race did not return two worker results.');
    $successes = array_values(array_filter($results, static fn (array $row): bool => ($row['result']['status'] ?? '') === 'success'));
    $failures = array_values(array_filter($results, static fn (array $row): bool => ($row['result']['status'] ?? '') === 'error'));
    install_contract_assert(count($successes) === 1, 'Install race must have exactly one successful worker: ' . json_encode($results));
    install_contract_assert(count($failures) === 1, 'Install race must have exactly one rejected worker: ' . json_encode($results));
    install_contract_assert($successes[0]['code'] === 0, 'Winning installer exited non-zero.');
    install_contract_assert($failures[0]['code'] !== 0, 'Losing installer exited successfully.');
    install_contract_assert(
        str_contains((string) ($failures[0]['result']['error'] ?? ''), Installer::ERROR_ALREADY_INSTALLED),
        'Losing installer did not return the stable already-installed conflict: ' . json_encode($failures[0]),
    );

    $winner = (string) ($successes[0]['result']['participant'] ?? '');
    $loser = (string) ($failures[0]['result']['participant'] ?? '');
    install_contract_assert(isset($inputs[$winner], $inputs[$loser]) && $winner !== $loser, 'Install race participants are inconsistent.');
    $winnerInput = $inputs[$winner];
    $loserInput = $inputs[$loser];

    $settingsStatement = $observer->query(
        "SELECT key, value FROM settings WHERE key IN ('college_name', 'site_name', 'site_tagline', 'timezone', 'cycle_name', 'workflow', 'terminology_candidate_label', 'installed_at')",
    );
    $settings = [];
    foreach ($settingsStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[(string) $row['key']] = (string) $row['value'];
    }
    foreach (['college_name', 'site_name', 'site_tagline', 'timezone', 'cycle_name', 'workflow', 'terminology_candidate_label'] as $key) {
        install_contract_assert(
            ($settings[$key] ?? null) === $winnerInput[$key],
            'Committed setting ' . $key . ' does not belong to the winning installer.',
        );
        install_contract_assert(
            ($settings[$key] ?? null) !== $loserInput[$key],
            'Losing installer payload survived in setting ' . $key . '.',
        );
    }
    install_contract_assert(isset($settings['installed_at']) && $settings['installed_at'] !== '', 'Winner did not commit installed_at.');
    install_contract_assert(
        (int) $observer->query("SELECT COUNT(*) FROM settings WHERE key = 'installed_at'")->fetchColumn() === 1,
        'Database must contain exactly one installed_at marker.',
    );

    $admins = $observer->query("SELECT id, name, email FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
    install_contract_assert(count($admins) === 1, 'Install race must commit exactly one administrator.');
    $admin = $admins[0];
    install_contract_assert((string) $admin['name'] === $winnerInput['admin_name'], 'Administrator name does not match the winner.');
    install_contract_assert((string) $admin['email'] === $winnerInput['admin_email'], 'Administrator email does not match the winner.');
    install_contract_assert(
        (int) $observer->query("SELECT COUNT(*) FROM users WHERE email = " . $observer->quote((string) $loserInput['admin_email']))->fetchColumn() === 0,
        'Losing installer administrator survived.',
    );

    $institution = $observer->query(
        "SELECT public_id, name, timezone FROM institutions WHERE slug = 'default'",
    )->fetch(PDO::FETCH_ASSOC);
    install_contract_assert(is_array($institution), 'Winning installation did not create the default institution.');
    install_contract_assert((string) $institution['name'] === $winnerInput['college_name'], 'Institution name is inconsistent with winner settings.');
    install_contract_assert((string) $institution['timezone'] === $winnerInput['timezone'], 'Institution timezone is inconsistent with winner settings.');
    install_contract_assert(
        (string) $institution['public_id'] === (string) ($successes[0]['result']['institution_public_id'] ?? ''),
        'Institution identity is inconsistent with the winning worker.',
    );
    install_contract_assert(
        (string) $institution['public_id'] === $winnerInput['tenant_public_id'],
        'Hosted institution identity does not belong to the winning tenant.',
    );
    install_contract_assert(
        (string) $institution['public_id'] !== $loserInput['tenant_public_id'],
        'Losing hosted tenant identity survived the race.',
    );

    $installAudits = $observer->query(
        "SELECT actor_user_id, detail FROM audit_logs WHERE action = 'install' ORDER BY id",
    )->fetchAll(PDO::FETCH_ASSOC);
    install_contract_assert(count($installAudits) === 1, 'Install race must commit exactly one installation audit record.');
    install_contract_assert((int) $installAudits[0]['actor_user_id'] === (int) $admin['id'], 'Install audit actor is not the winning administrator.');
    install_contract_assert((string) $installAudits[0]['detail'] === 'Initial installation completed.', 'Install audit detail is inconsistent.');

    install_contract_assert(
        (int) $observer->query('SELECT COUNT(*) FROM candidates')->fetchColumn() === 5,
        'Hosted install race did not commit exactly one complete demo candidate seed.',
    );
    install_contract_assert(
        (int) $observer->query('SELECT COUNT(*) FROM companies')->fetchColumn() === 3,
        'Hosted install race did not commit exactly one complete demo company seed.',
    );

    if ($driver === 'pgsql') {
        $pidBefore = (string) ($successes[0]['result']['backend_pid_before'] ?? '');
        $pidAfter = (string) ($successes[0]['result']['backend_pid_after'] ?? '');
        install_contract_assert(
            preg_match('/^[0-9]+$/', $pidBefore) === 1 && hash_equals($pidBefore, $pidAfter),
            'PostgreSQL winning installer did not retain one backend session.',
        );
    }

    $provider = $databaseUrl !== null
        ? PostgresConnectionPolicy::fromUrl(
            $databaseUrl,
            'direct',
            true,
            'install concurrency database URL',
        )
        : new SqliteConnectionProvider((string) $sqlitePath);
    if ($databaseUrl !== null) {
        $diagnostics = $provider->diagnostics();
        install_contract_assert(
            ($diagnostics['strict_policy'] ?? null) === true
                && ($diagnostics['pool_mode'] ?? null) === 'direct'
                && ($diagnostics['persistent'] ?? null) === false,
            'Later direct installHosted check must use the strict direct PostgreSQL runtime policy.',
        );
    }
    Database::useProvider($provider);
    try {
        (new Installer())->installHosted([
            'college_name' => 'Late Direct Installer',
            'timezone' => 'UTC',
            'admin_name' => 'Late Direct Administrator',
            'admin_email' => 'late-direct@example.test',
            'admin_password' => 'late-direct-password-123',
        ], $loserInput['tenant_public_id']);
        throw new RuntimeException('Later direct installHosted call unexpectedly succeeded.');
    } catch (RuntimeException $e) {
        install_contract_assert(
            str_contains($e->getMessage(), Installer::ERROR_ALREADY_INSTALLED),
            'Later direct installHosted refusal did not use the stable conflict.',
        );
    } finally {
        Database::reset();
    }
    install_contract_assert(
        (int) $observer->query("SELECT COUNT(*) FROM users WHERE email = 'late-direct@example.test'")->fetchColumn() === 0,
        'Later direct Installer refusal mutated users.',
    );
    install_contract_assert(
        (string) $observer->query("SELECT value FROM settings WHERE key = 'college_name'")->fetchColumn() === $winnerInput['college_name'],
        'Later direct Installer refusal mutated winner settings.',
    );

    echo 'PASS install concurrency contract (' . $driver . ') winner=' . $winner . "\n";
}

$installerSource = (string) file_get_contents(__DIR__ . '/../app/Install/Installer.php');
$beginPosition = strpos($installerSource, '$pdo->beginTransaction()');
$firstAffinityPosition = strpos($installerSource, 'DatabaseLock::assertPostgresSession', (int) $beginPosition);
$secondAffinityPosition = $firstAffinityPosition === false
    ? false
    : strpos($installerSource, 'DatabaseLock::assertPostgresSession', $firstAffinityPosition + 1);
$commitPosition = strpos($installerSource, '$pdo->commit()', (int) $secondAffinityPosition);
install_contract_assert(
    $beginPosition !== false
        && $firstAffinityPosition !== false
        && $secondAffinityPosition !== false
        && $commitPosition !== false
        && $beginPosition < $firstAffinityPosition
        && $firstAffinityPosition < $secondAffinityPosition
        && $secondAffinityPosition < $commitPosition,
    'Installer must assert PostgreSQL session affinity immediately after BEGIN and before COMMIT.',
);
$installerReflection = new ReflectionClass(Installer::class);
$timeoutConstant = $installerReflection->getReflectionConstant('LOCK_TIMEOUT_MILLISECONDS');
install_contract_assert(
    $timeoutConstant !== false && $timeoutConstant->getValue() === 60000,
    'Installation lock wait must remain bounded at 60 seconds.',
);
install_contract_assert(Installer::LOCK_NAMESPACE === 'cpe.engine-installation', 'Installation lock namespace changed.');
install_contract_assert(Installer::LOCK_NAMESPACE !== 'cpe.database-ownership', 'Installation and ownership locks must be distinct.');
install_contract_assert(Installer::LOCK_NAMESPACE !== 'cpe.engine-migrations', 'Installation and migration locks must be distinct.');
install_contract_assert(DatabaseLock::CONTRACT_VERSION === 1, 'Installation lock depends on the reviewed database lock contract.');

$root = sys_get_temp_dir() . '/cpe-install-concurrency-' . bin2hex(random_bytes(6));
install_contract_assert(mkdir($root, 0700), 'Could not create install concurrency fixture directory.');
try {
    $sqlitePath = $root . '/concurrent.sqlite';
    install_contract_run('sqlite', $sqlitePath, null, $root);

    $databaseUrl = trim((string) (getenv('CPE_DATABASE_URL') ?: ''));
    if ($databaseUrl !== '') {
        install_contract_run('pgsql', null, $databaseUrl, $root);
    } else {
        echo "SKIP install concurrency contract (pgsql): CPE_DATABASE_URL is not configured.\n";
    }
} finally {
    Database::reset();
    install_contract_remove_tree($root);
}
