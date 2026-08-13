<?php

declare(strict_types=1);

namespace App\Core\Institution;

final class InstitutionContext
{
    public function __construct(
        private readonly int $id,
        private readonly string $publicId,
        private readonly string $slug,
        private readonly string $name,
        private readonly string $timezone,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function publicId(): string
    {
        return $this->publicId;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }
}
