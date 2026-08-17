<?php

declare(strict_types=1);

namespace App\Modules\Placement\Workflow;

use PDO;
use RuntimeException;

final class WorkflowGuardEvaluator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assertAllowed(array $transition, int $applicationId, string $actorRole, string $reason = ''): void
    {
        $application = $this->applicationContext($applicationId);
        foreach ($transition['guards'] ?? [] as $guard) {
            match ($guard) {
                'candidate.not_opted_out' => $this->assertCandidateActive($application),
                'placement.not_frozen_or_admin' => $this->assertPlacementMutable($actorRole),
                'offer.upgrade_allowed' => $this->assertOfferUpgradeAllowed($application),
                'correction.reason_required' => $this->assertReason($reason),
                default => throw new RuntimeException('Unknown workflow guard: ' . $guard),
            };
        }
    }

    public function allows(array $transition, int $applicationId, string $actorRole, string $reason = ''): bool
    {
        try {
            $this->assertAllowed($transition, $applicationId, $actorRole, $reason);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function applicationContext(int $applicationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.company_id, c.opted_out, c.placed_company_id
             FROM applications a
             JOIN candidates c ON c.id = a.candidate_id
             WHERE a.id = ?'
        );
        $stmt->execute([$applicationId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Application not found.');
        }
        return $row;
    }

    private function assertCandidateActive(array $application): void
    {
        if ((int) ($application['opted_out'] ?? 0) === 1) {
            throw new RuntimeException('Candidate has opted out of placement movement.');
        }
    }

    private function assertPlacementMutable(string $actorRole): void
    {
        if ($this->setting('placement_freeze', '0') === '1' && $actorRole !== 'admin') {
            throw new RuntimeException('Placement decisions are frozen. Admin override is required.');
        }
    }

    private function assertOfferUpgradeAllowed(array $application): void
    {
        $placedCompanyId = (int) ($application['placed_company_id'] ?? 0);
        if ($placedCompanyId > 0
            && $placedCompanyId !== (int) $application['company_id']
            && $this->setting('allow_offer_upgrade', '0') !== '1') {
            throw new RuntimeException('Candidate already has a placement. Offer upgrades are disabled.');
        }
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required for workflow corrections.');
        }
    }

    private function setting(string $key, string $default): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    }
}
