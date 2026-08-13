<?php

declare(strict_types=1);

namespace App\Core\Modules;

interface ProvidesEventSubscribers
{
    /** @return array<string, list<callable>> */
    public function eventSubscribers(): array;
}
