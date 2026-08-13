<?php

declare(strict_types=1);

namespace App\Core\Modules;

interface ProvidesPrivacy
{
    public function privacyHandler(): ModulePrivacyHandler;
}
