<?php

declare(strict_types=1);

namespace App\Core\Modules;

interface ProvidesPortability
{
    public function portabilityHandler(): ModulePortabilityHandler;
}
