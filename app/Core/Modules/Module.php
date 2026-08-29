<?php

declare(strict_types=1);

namespace App\Core\Modules;

/**
 * Engine-internal contract for source-bundled product modules.
 *
 * @internal This is not a third-party plugin ABI. Implementations ship in and
 *           are compatibility-tested with the same immutable Engine release.
 *           Every implementation must declare public string constants
 *           CPE_MODULE_KEY and CPE_MODULE_VERSION so integrity can be checked
 *           without constructing optional modules.
 */
interface Module
{
    public function key(): string;

    public function manifest(): ModuleManifest;

    public function routes(): array;

    public function navigation(): array;
}
