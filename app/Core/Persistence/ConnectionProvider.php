<?php

declare(strict_types=1);

namespace App\Core\Persistence;

use PDO;

interface ConnectionProvider
{
    public function connection(): PDO;

    public function driver(): string;

    public function identifier(): string;

    public function disconnect(): void;
}
