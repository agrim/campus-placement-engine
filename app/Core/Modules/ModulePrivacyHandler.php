<?php

declare(strict_types=1);

namespace App\Core\Modules;

interface ModulePrivacyHandler
{
    public function reportForPerson(string $personPublicId): array;

    public function erasePerson(string $personPublicId, string $reason): array;
}
