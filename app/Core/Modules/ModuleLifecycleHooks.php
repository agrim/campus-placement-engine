<?php

declare(strict_types=1);

namespace App\Core\Modules;

interface ModuleLifecycleHooks
{
    public function onInstall(): void;

    public function onEnable(): void;

    public function onDisable(): void;
}
