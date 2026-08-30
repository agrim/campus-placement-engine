<?php

declare(strict_types=1);

namespace App\Core\Modules;

/** @internal Engine-shipped module privacy orchestration contract. */
interface ProvidesPrivacy
{
    public function privacyHandler(): ModulePrivacyHandler;
}
