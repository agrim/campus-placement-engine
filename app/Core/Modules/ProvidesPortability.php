<?php

declare(strict_types=1);

namespace App\Core\Modules;

/** @internal Engine-shipped module portability orchestration contract. */
interface ProvidesPortability
{
    public function portabilityHandler(): ModulePortabilityHandler;
}
