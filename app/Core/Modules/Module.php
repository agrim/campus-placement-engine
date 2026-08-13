<?php

declare(strict_types=1);

namespace App\Core\Modules;

interface Module
{
    public function key(): string;

    public function manifest(): ModuleManifest;

    public function routes(): array;

    public function navigation(): array;
}
