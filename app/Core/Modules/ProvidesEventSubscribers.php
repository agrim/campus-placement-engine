<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Events\InternalEventSubscription;

/**
 * Declares post-commit observers owned by source-bundled Engine modules.
 *
 * @internal External consumers use the versioned public event contract.
 */
interface ProvidesEventSubscribers
{
    /** @return list<InternalEventSubscription> */
    public function eventSubscribers(): array;
}
