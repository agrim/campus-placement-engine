<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Http\UserVisibleException;
use Throwable;

/**
 * The single browser-controller failure boundary.
 *
 * Only reviewed UserVisibleException messages may cross into a flash. Every
 * other failure is correlated through an opaque incident reference.
 */
final class ControllerFailure
{
    public static function flash(Throwable $exception, string $diagnosticCode, string $operation): void
    {
        if ($exception instanceof UserVisibleException) {
            Flash::add('error', $exception->publicMessage());
            return;
        }

        $incidentId = IncidentReporter::report(
            $exception,
            $diagnosticCode,
            'controller',
            ['operation' => $operation],
        );
        Flash::add('error', 'Request could not be completed. Reference: ' . $incidentId);
    }
}
