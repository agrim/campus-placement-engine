<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Persistence\ConnectionProvider;
use PDO;
use RuntimeException;

final class PostgresConnectionProvider implements ConnectionProvider
{
    private ?PDO $connection = null;

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
        $url = trim((string) (getenv('CPE_DATABASE_URL') ?: ''));
        if ($url !== '') {
            return self::fromUrl($url, 'CPE_DATABASE_URL');
        }
        return new self(
            (string) (getenv('CPE_PG_HOST') ?: '127.0.0.1'),
            (int) (getenv('CPE_PG_PORT') ?: 5432),
            (string) (getenv('CPE_PG_DATABASE') ?: ''),
            (string) (getenv('CPE_PG_USER') ?: ''),
            (string) (getenv('CPE_PG_PASSWORD') ?: ''),
            self::sslMode((string) (getenv('CPE_PG_SSLMODE') ?: 'prefer')),
        );
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
        return new self(
            (string) ($parts['host'] ?? '127.0.0.1'),
            (int) ($parts['port'] ?? 5432),
            $database,
            $username,
            rawurldecode((string) ($parts['pass'] ?? '')),
            self::sslMode((string) ($query['sslmode'] ?? 'prefer')),
        );
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
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                $this->host,
                $this->port,
                $this->database,
                $this->sslMode,
            );
            $this->connection = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->connection->exec("SET TIME ZONE 'UTC'");
        }
        return $this->connection;
    }

    public function driver(): string
    {
        return 'pgsql';
    }

    public function identifier(): string
    {
        return sprintf('postgresql://%s@%s:%d/%s', rawurlencode($this->username), $this->host, $this->port, rawurlencode($this->database));
    }

    public function disconnect(): void
    {
        $this->connection = null;
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
}
