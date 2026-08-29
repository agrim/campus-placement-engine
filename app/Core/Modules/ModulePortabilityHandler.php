<?php

declare(strict_types=1);

namespace App\Core\Modules;

/** @internal Engine-shipped module portability handler contract. */
interface ModulePortabilityHandler
{
    public function export(): array;

    public function validate(array $payload): array;

    public function import(array $payload): array;
}
