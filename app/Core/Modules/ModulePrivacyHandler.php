<?php

declare(strict_types=1);

namespace App\Core\Modules;

/** @internal Engine-shipped module privacy handler contract. */
interface ModulePrivacyHandler
{
    public function reportForPerson(string $personPublicId): array;

    public function erasePerson(string $personPublicId, string $reason): array;
}
