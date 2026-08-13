<?php

declare(strict_types=1);

namespace App\Core\Settings;

use PDO;

final class SettingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([$key, $value]);
    }

    public function all(): array
    {
        $settings = [];
        foreach ($this->pdo->query('SELECT key, value FROM settings ORDER BY key')->fetchAll() as $row) {
            $settings[(string) $row['key']] = (string) $row['value'];
        }
        return $settings;
    }
}
