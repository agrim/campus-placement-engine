<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Persistence\ConnectionProvider;
use PDO;

final class SqliteConnectionProvider implements ConnectionProvider
{
    private ?PDO $connection = null;

    public function __construct(private readonly string $path)
    {
    }

    public function connection(): PDO
    {
        if ($this->connection === null) {
            $directory = dirname($this->path);
            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            $this->connection = new PDO('sqlite:' . $this->path);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->connection->exec('PRAGMA foreign_keys = ON');
            $this->connection->exec('PRAGMA busy_timeout = 5000');
        }
        return $this->connection;
    }

    public function driver(): string
    {
        return 'sqlite';
    }

    public function identifier(): string
    {
        return $this->path;
    }

    public function disconnect(): void
    {
        $this->connection = null;
    }
}
