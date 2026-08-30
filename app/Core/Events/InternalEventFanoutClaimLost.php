<?php

declare(strict_types=1);

namespace App\Core\Events;

use RuntimeException;

/** @internal Lease-fencing signal used to roll back a stale fanout expansion. */
final class InternalEventFanoutClaimLost extends RuntimeException
{
}
