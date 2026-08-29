<?php

declare(strict_types=1);

namespace App\Core\Modules;

/** @internal Engine-shipped module lifecycle only; not a public plugin hook. */
interface ModuleLifecycleHooks
{
    public function onInstall(): void;

    public function onEnable(): void;

    public function onDisable(): void;
}
