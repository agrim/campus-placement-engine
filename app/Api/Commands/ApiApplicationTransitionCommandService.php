<?php

declare(strict_types=1);

namespace App\Api\Commands;

use App\Api\Http\ApiHttpException;
use App\Api\Http\ApiReadService;
use App\Api\Http\ApiRepresentationEtag;
use App\Api\Http\ApiStorageUnavailable;
use App\Api\Security\ApiKeyring;
use App\Api\Security\ApiPrincipal;
use App\Core\Persistence\WriteTransaction;
use App\Modules\Placement\Application\ApplicationTransitionActor;
use App\Modules\Placement\Application\ApplicationTransitionCommand;
use App\Modules\Placement\Application\ApplicationTransitionService;
use PDO;
use PDOException;

/** Atomic orchestration for the one governed public application command. */
final class ApiApplicationTransitionCommandService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ApiKeyring $keyring,
    ) {
    }

    public function execute(
        ApiPrincipal $principal,
        string $applicationPublicId,
        ApiApplicationTransitionInput $input,
        string $requestId,
    ): ApiApplicationTransitionOutcome {
        try {
            return WriteTransaction::run($this->pdo, function () use (
                $principal,
                $applicationPublicId,
                $input,
                $requestId,
            ): ApiApplicationTransitionOutcome {
                $actor = ApplicationTransitionActor::fromServiceAccount(
                    $principal->serviceAccountId(),
                    $principal->serviceAccountPublicId(),
                    $principal->institutionId(),
                    $principal->institutionPublicId(),
                );
                $boundary = new ApplicationTransitionService($this->pdo);

                // Establish and retain the canonical module -> institution ->
                // account -> scope -> capability locks before idempotency or
                // aggregate locks are acquired.
                $boundary->authorizeServiceAccountWithinTransaction($actor);

                if (preg_match('/\Aapplication_[a-f0-9]{32}\z/D', $applicationPublicId) !== 1) {
                    throw new ApiHttpException(
                        404,
                        'not_found',
                        'The requested API resource was not found.',
                        'RESOURCE_NOT_FOUND',
                    );
                }

                $fingerprint = (new ApiCommandHasher($this->keyring))->fingerprintApplicationTransition(
                    $input->idempotencyKey(),
                    $principal->institutionPublicId(),
                    $principal->serviceAccountPublicId(),
                    $applicationPublicId,
                    $input->fingerprintRequest(),
                );
                $store = new ApiCommandIdempotencyStore($this->pdo, $this->keyring);
                $reservation = $store->resolve($principal->serviceAccountId(), $fingerprint);
                if ($reservation?->isReplay()) {
                    return ApiApplicationTransitionOutcome::fromReservation($reservation, true);
                }

                // A completed replay is resolved before consulting current
                // aggregate state. For a genuinely new key, preserve GET's
                // institution-local 404 before attempting the FK-bound insert.
                $read = new ApiReadService($this->pdo, $this->keyring);
                if ($read->item('applications', $applicationPublicId) === null) {
                    throw new ApiHttpException(
                        404,
                        'not_found',
                        'The requested API resource was not found.',
                        'RESOURCE_NOT_FOUND',
                    );
                }

                $reservation = $store->reserve($principal->serviceAccountId(), $fingerprint);
                if ($reservation->isReplay()) {
                    return ApiApplicationTransitionOutcome::fromReservation($reservation, true);
                }

                $target = $read->applicationForTransition($applicationPublicId);
                if ($target === null) {
                    throw new ApiHttpException(
                        404,
                        'not_found',
                        'The requested API resource was not found.',
                        'RESOURCE_NOT_FOUND',
                    );
                }

                if (!hash_equals($target['etag'], $input->ifMatch())) {
                    throw new ApiHttpException(
                        409,
                        'transition_conflict',
                        'The application changed before the transition could be applied.',
                        'PRECONDITION_STALE',
                    );
                }

                $boundary->executeForServiceAccount(
                    new ApplicationTransitionCommand(
                        $target['internal_id'],
                        $input->targetStatus(),
                        $input->transitionKey(),
                        $input->note(),
                        $target['current_status'],
                        '',
                    ),
                    $actor,
                );

                $updated = $read->applicationForTransition($applicationPublicId);
                if ($updated === null) {
                    throw new ApiStorageUnavailable('The transitioned application projection is unavailable.');
                }
                $payload = [
                    'data' => $updated['item'],
                    'meta' => ['request_id' => $requestId],
                ];
                $completed = $store->complete(
                    $principal->serviceAccountId(),
                    $fingerprint,
                    $reservation,
                    $payload,
                    200,
                    $updated['etag'],
                );
                return ApiApplicationTransitionOutcome::fromReservation($completed, false);
            });
        } catch (PDOException $failure) {
            throw new ApiStorageUnavailable('API command storage is unavailable.', $failure);
        }
    }
}
