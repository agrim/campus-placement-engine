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
        if (extension_loaded('pdo_pgsql')) {
            try {
                $connectionOk = (int) Database::connection()->query('SELECT 1')->fetchColumn() === 1;
            } catch (\Throwable) {
                $connectionOk = false;
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
        ];
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
