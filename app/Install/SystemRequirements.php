<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Http\UserVisibleException;
use App\Support\Database;

final class SystemRequirements
{
    public const MINIMUM_PHP = '8.2.0';

    public function __construct(
        private string $minimumPhp = self::MINIMUM_PHP,
        private ?string $databasePath = null,
        private ?string $dataPath = null
    ) {
    }

    /**
     * @return array<int, array{key: string, label: string, ok: bool, value: string}>
     */
    public function checks(): array
    {
        if (Database::driver() === 'pgsql') {
            return $this->postgresChecks();
        }
        $databasePath = $this->databasePath ?? Database::path();
        $dataPath = $this->dataPath ?? cpe_data_path();
        $databaseDirectoryWritable = self::databaseDirectoryWritable($databasePath);

        return [
            ...$this->runtimeChecks(),
            [
                'key' => 'pdo_sqlite',
                'label' => 'pdo_sqlite',
                'ok' => extension_loaded('pdo_sqlite'),
                'value' => extension_loaded('pdo_sqlite') ? 'yes' : 'no',
            ],
            [
                'key' => 'sqlite3',
                'label' => 'sqlite3',
                'ok' => extension_loaded('sqlite3'),
                'value' => extension_loaded('sqlite3') ? 'yes' : 'no',
            ],
            [
                'key' => 'data_writable',
                'label' => 'data_writable',
                'ok' => is_writable($dataPath),
                'value' => is_writable($dataPath) ? 'yes' : 'no',
            ],
            [
                'key' => 'database_directory_writable',
                'label' => 'database_directory_writable',
                'ok' => $databaseDirectoryWritable,
                'value' => $databaseDirectoryWritable ? 'yes' : 'no',
            ],
        ];
    }

    private function postgresChecks(): array
    {
        $connectionOk = false;
        $policyOk = false;
        $policyValue = 'unavailable (connection initialization failed)';
        if (extension_loaded('pdo_pgsql')) {
            try {
                $connectionOk = (int) Database::connection()->query('SELECT 1')->fetchColumn() === 1;
            } catch (\Throwable) {
                $connectionOk = false;
            }
            if ($connectionOk) {
                try {
                    $provider = Database::provider();
                    if (method_exists($provider, 'diagnostics')) {
                        $diagnostics = (array) $provider->diagnostics();
                        $policyValue = self::formatPostgresPolicyDiagnostics($diagnostics);
                        $policyOk = self::postgresPolicyDiagnosticsAreAcceptable($diagnostics);
                    } else {
                        $policyValue = 'unavailable (provider diagnostics unsupported)';
                    }
                } catch (\Throwable) {
                    $policyValue = 'unavailable (provider diagnostics failed)';
                }
            }
        }
        return [
            ...$this->runtimeChecks(),
            [
                'key' => 'pdo_pgsql',
                'label' => 'pdo_pgsql',
                'ok' => extension_loaded('pdo_pgsql'),
                'value' => extension_loaded('pdo_pgsql') ? 'yes' : 'no',
            ],
            [
                'key' => 'postgres_connection',
                'label' => 'postgres_connection',
                'ok' => $connectionOk,
                'value' => $connectionOk ? 'reachable' : 'unavailable',
            ],
            [
                'key' => 'postgres_policy',
                'label' => 'postgres_policy',
                'ok' => $policyOk,
                'value' => $policyValue,
            ],
        ];
    }

    /** @param array<string, mixed> $diagnostics */
    public static function postgresPolicyDiagnosticsAreAcceptable(array $diagnostics): bool
    {
        if (($diagnostics['strict_policy'] ?? null) !== true
            || !in_array(($diagnostics['pool_mode'] ?? null), ['direct', 'session'], true)
            || ($diagnostics['persistent'] ?? null) !== false) {
            return false;
        }
        $timeout = $diagnostics['connect_timeout_seconds'] ?? null;
        if (!is_int($timeout) || $timeout < 1 || $timeout > 30) {
            return false;
        }

        $tlsMode = $diagnostics['ssl_mode'] ?? null;
        if ($tlsMode === 'verify-full') {
            return ($diagnostics['trusted_root_configured'] ?? null) === true
                && ($diagnostics['negotiated_tls_verified'] ?? null) === true;
        }
        if ($tlsMode === 'disable') {
            return ($diagnostics['trusted_root_configured'] ?? null) === false
                && in_array(($diagnostics['negotiated_tls_verified'] ?? null), [false, null], true);
        }
        return false;
    }

    /** @param array<string, mixed> $diagnostics */
    public static function formatPostgresPolicyDiagnostics(array $diagnostics): string
    {
        $strict = ($diagnostics['strict_policy'] ?? null) === true;
        $poolMode = in_array(($diagnostics['pool_mode'] ?? null), ['direct', 'session'], true)
            ? (string) $diagnostics['pool_mode']
            : 'unknown';
        $tlsMode = in_array(
            ($diagnostics['ssl_mode'] ?? null),
            ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'],
            true,
        ) ? (string) $diagnostics['ssl_mode'] : 'unknown';
        $trustedRoot = ($diagnostics['trusted_root_configured'] ?? null) === true ? 'yes' : 'no';
        $timeout = $diagnostics['connect_timeout_seconds'] ?? null;
        $timeoutValue = is_int($timeout) && $timeout >= 1 && $timeout <= 30
            ? $timeout . 's'
            : 'unbounded-or-unset';
        $persistent = ($diagnostics['persistent'] ?? null) === false ? 'no' : 'yes-or-unknown';
        $negotiatedTls = $tlsMode === 'disable'
            ? 'not-applicable'
            : (($diagnostics['negotiated_tls_verified'] ?? null) === true ? 'yes' : 'no');
        $policyState = $strict
            ? ($tlsMode === 'disable' ? 'test-loopback-insecure' : 'production-strict')
            : 'legacy-compatibility';

        return implode('; ', [
            'state=' . $policyState,
            'strict=' . ($strict ? 'yes' : 'no'),
            'pool=' . $poolMode,
            'tls=' . $tlsMode,
            'trusted_root=' . $trustedRoot,
            'connect_timeout=' . $timeoutValue,
            'persistent=' . $persistent,
            'negotiated_tls=' . $negotiatedTls,
        ]);
    }

    /**
     * @return array<int, array{key: string, label: string, ok: bool, value: string}>
     */
    public function runtimeChecks(): array
    {
        return [
            [
                'key' => 'PHP',
                'label' => 'PHP',
                'ok' => version_compare(PHP_VERSION, $this->minimumPhp, '>='),
                'value' => PHP_VERSION . ' (requires >= ' . $this->minimumPhp . ')',
            ],
            [
                'key' => 'mbstring',
                'label' => 'mbstring',
                'ok' => extension_loaded('mbstring'),
                'value' => extension_loaded('mbstring') ? 'yes' : 'no',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function failures(): array
    {
        $failures = [];
        foreach ($this->checks() as $check) {
            if (!$check['ok']) {
                $failures[] = $check['key'];
            }
        }
        return $failures;
    }

    public function isReady(): bool
    {
        return $this->failures() === [];
    }

    public function assertReady(): void
    {
        $failures = $this->failures();
        if ($failures !== []) {
            throw new UserVisibleException(
                'SETUP_REQUIREMENTS_NOT_MET',
                'System requirements are not met: ' . implode(', ', $failures) . '.',
            );
        }
    }

    public static function databaseDirectoryWritable(string $databasePath): bool
    {
        $dir = dirname($databasePath);
        while ($dir !== dirname($dir) && !is_dir($dir)) {
            $dir = dirname($dir);
        }
        return is_dir($dir) && is_writable($dir);
    }
}
