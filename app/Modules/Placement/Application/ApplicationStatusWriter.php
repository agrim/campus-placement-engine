<?php

declare(strict_types=1);

namespace App\Modules\Placement\Application;

use App\Core\Events\DomainEvent;
use App\Core\Events\PublicEventProjection;
use App\Core\Http\UserVisibleException;
use App\Core\Persistence\WriteTransaction;
use App\Support\Database;
use App\Support\StructuredLogger;
use PDO;
use RuntimeException;

/**
 * Single transactional writer for the application status aggregate.
 *
 * Status, aggregate version, movement history, the private domain event, and
 * its optional public projection either all commit or all roll back.
 */
final class ApplicationStatusWriter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, scalar|null> $privateContext
     * @return array<string, mixed>
     */
    public function changeStatus(
        int $applicationId,
        string $expectedStatus,
        int $expectedVersion,
        string $toStatus,
        ?int $actorId,
        string $actorRole,
        string $note,
        string $occurredAt,
        array $privateContext = [],
    ): array {
        return WriteTransaction::run($this->pdo, function () use (
            $applicationId,
            $expectedStatus,
            $expectedVersion,
            $toStatus,
            $actorId,
            $actorRole,
            $note,
            $occurredAt,
            $privateContext,
        ): array {
            self::assertStatus($expectedStatus);
            self::assertStatus($toStatus);
            if ($expectedStatus === $toStatus) {
                throw new RuntimeException('ApplicationStatusWriter requires an actual status change.');
            }
            if ($applicationId < 1 || $expectedVersion < 1) {
                throw new RuntimeException('Application status aggregate identity is invalid.');
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurredAt) !== 1) {
                throw new RuntimeException('Application status occurrence time is invalid.');
            }

            $application = $this->application($applicationId);
            if ((string) $application['current_status'] !== $expectedStatus
                || (int) $application['aggregate_version'] !== $expectedVersion) {
                throw new UserVisibleException(
                    'PLACEMENT_BOARD_STALE',
                    'This application changed before the update completed. Reload and retry.',
                );
            }
            $nextVersion = $expectedVersion + 1;
            $update = $this->pdo->prepare(
                'UPDATE applications
                 SET current_status = ?, aggregate_version = ?, updated_at = ?
                 WHERE id = ? AND current_status = ? AND aggregate_version = ?',
            );
            $update->execute([
                $toStatus,
                $nextVersion,
                $occurredAt,
                $applicationId,
                $expectedStatus,
                $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new UserVisibleException(
                    'PLACEMENT_BOARD_STALE',
                    'This application changed before the update completed. Reload and retry.',
                );
            }

            $movement = $this->pdo->prepare(
                'INSERT INTO events
                 (application_id, candidate_id, company_id, from_status, to_status,
                  actor_user_id, actor_role, note, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $movement->execute([
                $applicationId,
                (int) $application['candidate_id'],
                (int) $application['company_id'],
                $expectedStatus,
                $toStatus,
                $actorId,
                $actorRole,
                $note,
                $occurredAt,
            ]);

            $applicationPublicId = self::canonicalId(
                (string) ($application['application_public_id'] ?? ''),
                'application',
            );
            $instanceId = self::canonicalInstanceId($this->institutionPublicId());
            $candidatePublicId = self::canonicalId(
                (string) ($application['candidate_public_id'] ?? ''),
                'candidate',
            );
            $companyPublicId = self::canonicalId(
                (string) ($application['company_public_id'] ?? ''),
                'company',
            );
            $basePayload = [
                'application_id' => $applicationId,
                'candidate_public_id' => $candidatePublicId,
                'company_public_id' => $companyPublicId,
                'from_state' => $expectedStatus,
                'to_state' => $toStatus,
                'transition_key' => (string) ($privateContext['transition_key'] ?? ''),
                'actor_role' => $actorRole,
            ];
            cpe_context()->events()->dispatch(new DomainEvent(
                'placement.application.transitioned',
                'placement_application',
                $applicationPublicId,
                'placement',
                $basePayload + $privateContext,
                $occurredAt,
                PublicEventProjection::applicationStatusChanged(
                    $instanceId,
                    $applicationPublicId,
                    $nextVersion,
                    $expectedStatus,
                    $toStatus,
                    StructuredLogger::requestId(),
                ),
            ));

            $application['current_status'] = $toStatus;
            $application['aggregate_version'] = $nextVersion;
            $application['updated_at'] = $occurredAt;
            return $application;
        });
    }

    /**
     * Insert a new aggregate at version 1, or change an existing aggregate
     * through the same CAS/publication path. Same-status edits emit nothing.
     *
     * @return array{id: int, created: bool, changed: bool, aggregate_version: int}
     */
    public function saveStatus(
        int $candidateId,
        int $companyId,
        string $status,
        ?int $waitlistRank,
        ?int $actorId,
        string $actorRole,
        string $note,
        string $occurredAt,
    ): array {
        return WriteTransaction::run($this->pdo, function () use (
            $candidateId,
            $companyId,
            $status,
            $waitlistRank,
            $actorId,
            $actorRole,
            $note,
            $occurredAt,
        ): array {
            self::assertStatus($status);
            $lookup = $this->pdo->prepare(
                'SELECT id, current_status, aggregate_version
                 FROM applications WHERE candidate_id = ? AND company_id = ?',
            );
            $lookup->execute([$candidateId, $companyId]);
            $existing = $lookup->fetch();
            if (!is_array($existing)) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO applications
                     (candidate_id, company_id, current_status, waitlist_rank, public_id,
                      aggregate_version, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
                );
                $insert->execute([
                    $candidateId,
                    $companyId,
                    $status,
                    $waitlistRank,
                    'application_' . bin2hex(random_bytes(16)),
                    $occurredAt,
                    $occurredAt,
                ]);
                return [
                    'id' => Database::lastInsertId($this->pdo),
                    'created' => true,
                    'changed' => false,
                    'aggregate_version' => 1,
                ];
            }

            $applicationId = (int) $existing['id'];
            $fromStatus = (string) $existing['current_status'];
            $version = (int) $existing['aggregate_version'];
            if ($fromStatus === $status) {
                $edit = $this->pdo->prepare(
                    'UPDATE applications
                     SET waitlist_rank = ?, updated_at = ?
                     WHERE id = ? AND current_status = ? AND aggregate_version = ?',
                );
                $edit->execute([$waitlistRank, $occurredAt, $applicationId, $fromStatus, $version]);
                if ($edit->rowCount() !== 1) {
                    throw new UserVisibleException(
                        'PLACEMENT_BOARD_STALE',
                        'This application changed before the update completed. Reload and retry.',
                    );
                }
                return [
                    'id' => $applicationId,
                    'created' => false,
                    'changed' => false,
                    'aggregate_version' => $version,
                ];
            }

            $this->changeStatus(
                $applicationId,
                $fromStatus,
                $version,
                $status,
                $actorId,
                $actorRole,
                $note,
                $occurredAt,
                ['source' => 'application.save'],
            );
            $edit = $this->pdo->prepare(
                'UPDATE applications SET waitlist_rank = ? WHERE id = ? AND aggregate_version = ?',
            );
            $edit->execute([$waitlistRank, $applicationId, $version + 1]);
            if ($edit->rowCount() !== 1) {
                throw new RuntimeException('Application metadata changed after its status publication.');
            }
            return [
                'id' => $applicationId,
                'created' => false,
                'changed' => true,
                'aggregate_version' => $version + 1,
            ];
        });
    }

    /** @return array<string, mixed> */
    private function application(int $applicationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.candidate_id, a.company_id, a.current_status, a.aggregate_version,
                    a.public_id AS application_public_id, a.updated_at,
                    c.public_id AS candidate_public_id, co.public_id AS company_public_id
             FROM applications a
             JOIN candidates c ON c.id = a.candidate_id
             JOIN companies co ON co.id = a.company_id
             WHERE a.id = ?',
        );
        $stmt->execute([$applicationId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new UserVisibleException('PLACEMENT_APPLICATION_NOT_FOUND', 'Application not found.');
        }
        return $row;
    }

    private function institutionPublicId(): string
    {
        $value = $this->pdo->query(
            "SELECT public_id FROM institutions WHERE slug = 'default'",
        )->fetchColumn();
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Application status publication requires an institution identity.');
        }
        return $value;
    }

    private static function canonicalId(string $value, string $prefix): string
    {
        if (preg_match('/^' . preg_quote($prefix, '/') . '_[a-f0-9]{32}$/D', $value) !== 1) {
            throw new RuntimeException('Application status publication requires a canonical ' . $prefix . ' id.');
        }
        return $value;
    }

    private static function canonicalInstanceId(string $value): string
    {
        if (preg_match('/^(?:inst|tenant)_[a-f0-9]{32}$/D', $value) !== 1) {
            throw new RuntimeException('Application status publication requires a canonical institution id.');
        }
        return $value;
    }

    private static function assertStatus(string $status): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $status) !== 1) {
            throw new RuntimeException('Application status token is invalid.');
        }
    }
}
