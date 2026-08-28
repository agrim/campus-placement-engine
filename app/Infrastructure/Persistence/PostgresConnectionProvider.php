<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Persistence\ConnectionProvider;
use PDO;
use RuntimeException;

final class PostgresConnectionProvider implements ConnectionProvider
{
    private ?PDO $connection = null;
    private ?string $sslRootCert = null;
    private ?int $connectTimeout = null;
    private string $poolMode = 'direct';
    private bool $strictPolicy = false;
    private bool $verifyNegotiatedTls = false;
    private ?bool $negotiatedTlsVerified = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
        private readonly string $sslMode = 'prefer',
    ) {
    }

    public static function fromEnvironment(): self
    {
        return PostgresConnectionPolicy::fromEnvironment();
    }

    public static function fromUrl(string $url, string $label = 'PostgreSQL URL'): self
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['postgres', 'postgresql'], true)) {
            throw new RuntimeException($label . ' must be a postgresql:// URL.');
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $database = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));
        $username = rawurldecode((string) ($parts['user'] ?? ''));
        if ($database === '' || $username === '') {
            throw new RuntimeException($label . ' must include a database and username.');
        }
        $provider = new self(
            (string) ($parts['host'] ?? '127.0.0.1'),
            (int) ($parts['port'] ?? 5432),
            $database,
            $username,
            rawurldecode((string) ($parts['pass'] ?? '')),
            self::sslMode((string) ($query['sslmode'] ?? 'prefer')),
        );
        if (isset($query['sslrootcert']) && is_string($query['sslrootcert']) && $query['sslrootcert'] !== '') {
            $provider->sslRootCert = self::legacyDsnValue($query['sslrootcert'], 'sslrootcert');
        }
        if (isset($query['connect_timeout']) && is_string($query['connect_timeout']) && $query['connect_timeout'] !== '') {
            if (!ctype_digit($query['connect_timeout'])
                || (int) $query['connect_timeout'] < 1
                || (int) $query['connect_timeout'] > 300) {
                throw new RuntimeException('Unsupported PostgreSQL connect_timeout.');
            }
            $provider->connectTimeout = (int) $query['connect_timeout'];
        }
        return $provider;
    }

    /** @internal Strict Engine runtime factory; use PostgresConnectionPolicy. */
    public static function fromStrictPolicy(
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
        string $sslMode,
        ?string $sslRootCert,
        int $connectTimeout,
        string $poolMode,
        bool $allowInsecureLoopback,
    ): self {
        $sslMode = self::sslMode($sslMode);
        if ($port < 1 || $port > 65535
            || preg_match('/[\x00-\x1F\x7F;]/', $host . $database) === 1
            || $host === ''
            || $database === ''
            || $username === ''
            || preg_match('/[\x00-\x1F\x7F]/', $username . $password) === 1) {
            throw new RuntimeException('Strict PostgreSQL provider configuration is invalid.');
        }
        if (!in_array($poolMode, ['direct', 'session'], true)) {
            throw new RuntimeException('Strict PostgreSQL pool mode must be direct or session.');
        }
        if ($connectTimeout < 1 || $connectTimeout > 30) {
            throw new RuntimeException('Strict PostgreSQL connect timeout must be between 1 and 30 seconds.');
        }
        if ($sslMode === 'verify-full') {
            if ($sslRootCert === null
                || preg_match('/[\x00-\x1F\x7F;]/', $sslRootCert) === 1
                || (!str_starts_with($sslRootCert, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $sslRootCert) !== 1)) {
                throw new RuntimeException('Strict PostgreSQL trusted root source is invalid.');
            }
        } elseif ($sslMode !== 'disable' || !$allowInsecureLoopback || !self::isLoopbackHost($host)) {
            throw new RuntimeException('Strict PostgreSQL TLS configuration is invalid.');
        }
        $provider = new self($host, $port, $database, $username, $password, self::sslMode($sslMode));
        $provider->sslRootCert = $sslRootCert;
        $provider->connectTimeout = $connectTimeout;
        $provider->poolMode = $poolMode;
        $provider->strictPolicy = true;
        $provider->verifyNegotiatedTls = $sslMode === 'verify-full';
        return $provider;
    }

    public function connection(): PDO
    {
        if ($this->database === '' || $this->username === '') {
            throw new RuntimeException('PostgreSQL requires CPE_DATABASE_URL or CPE_PG_DATABASE and CPE_PG_USER.');
        }
        if (!extension_loaded('pdo_pgsql')) {
            throw new RuntimeException('The pdo_pgsql PHP extension is required for PostgreSQL.');
        }
        if ($this->connection === null) {
            if ($this->verifyNegotiatedTls
                && ($this->sslRootCert === null || !is_file($this->sslRootCert) || !is_readable($this->sslRootCert))) {
                throw new RuntimeException('PostgreSQL trusted root certificate is unavailable.');
            }
            $dsnParts = [
                'host=' . $this->host,
                'port=' . $this->port,
                'dbname=' . $this->database,
                'sslmode=' . $this->sslMode,
            ];
            if ($this->sslRootCert !== null) {
                $dsnParts[] = 'sslrootcert=' . $this->sslRootCert;
            }
            if ($this->connectTimeout !== null) {
                $dsnParts[] = 'connect_timeout=' . $this->connectTimeout;
            }
            $dsn = 'pgsql:' . implode(';', $dsnParts);
            $this->connection = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ]);
            try {
                $this->connection->exec("SET TIME ZONE 'UTC'");
                if ($this->verifyNegotiatedTls) {
                    $negotiated = $this->connection->query(
                        'SELECT ssl FROM pg_catalog.pg_stat_ssl WHERE pid = pg_backend_pid()'
                    )->fetchColumn();
                    if (!in_array($negotiated, [true, 1, '1', 't', 'true'], true)) {
                        throw new RuntimeException('PostgreSQL negotiated TLS verification failed.');
                    }
                    $this->negotiatedTlsVerified = true;
                } else {
                    $this->negotiatedTlsVerified = false;
                }
            } catch (\Throwable $e) {
                $this->negotiatedTlsVerified = null;
                $this->connection = null;
                if ($e instanceof RuntimeException
                    && $e->getMessage() === 'PostgreSQL negotiated TLS verification failed.') {
                    throw $e;
                }
                if ($this->verifyNegotiatedTls) {
                    throw new RuntimeException('PostgreSQL negotiated TLS verification failed.', 0, $e);
                }
                throw $e;
            }
        }
        return $this->connection;
    }

    public function driver(): string
    {
        return 'pgsql';
    }

    public function identifier(): string
    {
        if ($this->strictPolicy) {
            return sprintf(
                'postgresql://***@%s:%d/%s',
                $this->host,
                $this->port,
                rawurlencode($this->database),
            );
        }
        return sprintf('postgresql://%s@%s:%d/%s', rawurlencode($this->username), $this->host, $this->port, rawurlencode($this->database));
    }

    /** @return array<string, bool|int|string|null> */
    public function diagnostics(): array
    {
        return [
            'driver' => 'pgsql',
            'identity' => $this->identifier(),
            'strict_policy' => $this->strictPolicy,
            'pool_mode' => $this->poolMode,
            'ssl_mode' => $this->sslMode,
            'trusted_root_configured' => $this->sslRootCert !== null,
            'connect_timeout_seconds' => $this->connectTimeout,
            'persistent' => false,
            'negotiated_tls_verified' => $this->negotiatedTlsVerified,
        ];
    }

    public function disconnect(): void
    {
        $this->connection = null;
        $this->negotiatedTlsVerified = null;
    }

    private static function sslMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        $allowed = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
        if (!in_array($mode, $allowed, true)) {
            throw new RuntimeException('Unsupported PostgreSQL sslmode: ' . $mode);
        }
        return $mode;
    }

    private static function legacyDsnValue(string $value, string $name): string
    {
        if (preg_match('/[\x00-\x1F\x7F;]/', $value) === 1) {
            throw new RuntimeException('Unsupported PostgreSQL ' . $name . '.');
        }
        return $value;
    }

    private static function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && str_starts_with($host, '127.');
    }
}
