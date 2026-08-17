<?php

declare(strict_types=1);

namespace App\Core\Security;

use PDO;

final class CapabilityService
{
    public function __construct(private readonly array $roleCapabilities)
    {
    }

    public function allows(?array $user, string $capability): bool
    {
        if ($user === null || (array_key_exists('active', $user) && empty($user['active']))) {
            return false;
        }
        $role = (string) ($user['role'] ?? '');
        $capabilities = $this->roleCapabilities[$role] ?? [];
        return in_array('*', $capabilities, true) || in_array($capability, $capabilities, true);
    }

    public function forRole(string $role): array
    {
        return array_values(array_unique($this->roleCapabilities[$role] ?? []));
    }

    public static function fromDatabase(PDO $pdo, array $fallback): self
    {
        try {
            $roleCapabilities = [];
            foreach ($pdo->query('SELECT role_key, capability FROM role_capabilities ORDER BY role_key, capability')->fetchAll() as $row) {
                $roleCapabilities[(string) $row['role_key']][] = (string) $row['capability'];
            }
            return new self($roleCapabilities !== [] ? $roleCapabilities : $fallback);
        } catch (\Throwable) {
            return new self($fallback);
        }
    }
}
