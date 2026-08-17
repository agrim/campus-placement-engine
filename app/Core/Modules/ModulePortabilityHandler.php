<?php

declare(strict_types=1);

namespace App\Core\Modules;

interface ModulePortabilityHandler
{
    public function export(): array;

    public function validate(array $payload): array;

    public function import(array $payload): array;
}
