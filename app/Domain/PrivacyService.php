<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Backup\DatabaseBackupService;
use App\Modules\Placement\Install\LegacyDomainSynchronizer;
use App\Support\Auth;
use App\Support\Database;
use PDO;
use RuntimeException;

final class PrivacyService
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public function report(): array
    {
        return [
            'total_candidates' => $this->count('SELECT COUNT(*) FROM candidates'),
            'anonymized_candidates' => $this->count("SELECT COUNT(*) FROM candidates WHERE anonymized_at IS NOT NULL AND anonymized_at != ''"),
            'identifiable_candidates' => $this->count("SELECT COUNT(*) FROM candidates WHERE anonymized_at IS NULL OR anonymized_at = ''"),
            'placed_identifiable_candidates' => $this->count("SELECT COUNT(*) FROM candidates WHERE placed_company_id IS NOT NULL AND (anonymized_at IS NULL OR anonymized_at = '')"),
            'open_wanted_alerts' => $this->count("SELECT COUNT(*) FROM wanted_alerts WHERE status = 'open'"),
            'open_preference_requests' => $this->count("SELECT COUNT(*) FROM preference_requests WHERE status = 'open'"),
        ];
    }

    public function anonymizeCandidate(string $externalId, ?int $actorId = null): array
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            throw new RuntimeException('Candidate external ID is required.');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM candidates WHERE external_id = ?');
        $stmt->execute([$externalId]);
        $candidate = $stmt->fetch();
        if (!$candidate) {
            throw new RuntimeException('Candidate not found: ' . $externalId);
        }
        if (!empty($candidate['anonymized_at'])) {
            throw new RuntimeException('Candidate is already anonymized.');
        }

        $candidateId = (int) $candidate['id'];
        $anonymousExternalId = 'ANON-' . $candidateId;
        $now = cpe_now();
        $ownsTransaction = !$this->pdo->inTransaction();
        $safetyPath = $ownsTransaction ? $this->safetyCopy() : '';

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $preferenceIds = $this->idsFor('SELECT id FROM preference_requests WHERE candidate_id = ?', $candidateId);
            $wantedIds = $this->idsFor('SELECT id FROM wanted_alerts WHERE candidate_id = ?', $candidateId);
            $applicationIds = $this->idsFor('SELECT id FROM applications WHERE candidate_id = ?', $candidateId);
            $notificationIds = [
                ...$this->notificationIdsForSource('preference_request', $preferenceIds),
                ...$this->notificationIdsForSource('wanted_alert', $wantedIds),
            ];

            $candidateUpdate = $this->pdo->prepare(
                'UPDATE candidates
                 SET external_id = ?, name = ?, program = ?, tags = ?, current_location = ?, accommodation_notes = ?, custom_fields_json = ?, opted_out = ?, anonymized_at = ?, updated_at = ?
                 WHERE id = ?'
            );
            $candidateUpdate->execute([$anonymousExternalId, 'Anonymized Candidate', '', '', 'Anonymized', '', '{}', 1, $now, $now, $candidateId]);

            $this->pdo->prepare('UPDATE events SET note = ? WHERE candidate_id = ?')
                ->execute(['[redacted by candidate anonymization]', $candidateId]);
            $this->pdo->prepare('UPDATE preference_requests SET status = ?, note = ?, resolved_at = COALESCE(resolved_at, ?) WHERE candidate_id = ?')
                ->execute(['resolved', '[redacted by candidate anonymization]', $now, $candidateId]);
            $this->pdo->prepare('UPDATE wanted_alerts SET status = ?, reason = ?, resolved_at = COALESCE(resolved_at, ?) WHERE candidate_id = ?')
                ->execute(['resolved', '[redacted by candidate anonymization]', $now, $candidateId]);

            if ($applicationIds !== []) {
                $this->executeForIds(
                    'UPDATE application_slot_assignments SET notes = ?, updated_at = ? WHERE application_id IN (%s)',
                    $applicationIds,
                    ['[redacted by candidate anonymization]', $now]
                );
            }
            if ($notificationIds !== []) {
                $this->executeForIds(
                    'UPDATE notifications SET subject = ?, body = ?, status = ?, acknowledged_at = COALESCE(acknowledged_at, ?) WHERE id IN (%s)',
                    $notificationIds,
                    ['Candidate anonymized', '[redacted by candidate anonymization]', 'acknowledged', $now]
                );
                $this->executeForIds(
                    'UPDATE notification_deliveries SET payload_json = ?, last_error = ?, updated_at = ? WHERE notification_id IN (%s)',
                    $notificationIds,
                    ['{}', '', $now]
                );
            }
            if ($preferenceIds !== []) {
                $this->executeForIds(
                    'UPDATE audit_logs SET detail = ? WHERE subject_type = ? AND subject_id IN (%s)',
                    $preferenceIds,
                    ['[redacted by candidate anonymization]', 'preference_request']
                );
            }
            if ($wantedIds !== []) {
                $this->executeForIds(
                    'UPDATE audit_logs SET detail = ? WHERE subject_type = ? AND subject_id IN (%s)',
                    $wantedIds,
                    ['[redacted by candidate anonymization]', 'wanted_alert']
                );
            }
            $this->pdo->prepare('UPDATE audit_logs SET detail = ? WHERE subject_type = ? AND subject_id = ?')
                ->execute(['[redacted by candidate anonymization]', 'candidate', $candidateId]);
            $this->redactAuditText((string) $candidate['external_id'], $anonymousExternalId);
            $this->redactAuditText((string) $candidate['name'], 'Anonymized Candidate');

            Auth::audit($actorId, 'candidate.anonymize', 'candidate', $candidateId, 'Candidate anonymized');
            (new LegacyDomainSynchronizer())->synchronize($this->pdo);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'candidate_id' => $candidateId,
            'external_id' => $anonymousExternalId,
            'anonymized_at' => $now,
            'safety_path' => $safetyPath,
            'applications' => count($applicationIds),
            'preference_requests' => count($preferenceIds),
            'wanted_alerts' => count($wantedIds),
            'notifications' => count(array_unique($notificationIds)),
        ];
    }

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    /** @return array<int, int> */
    private function idsFor(string $sql, int $candidateId): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$candidateId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

    /** @param array<int, int> $sourceIds @return array<int, int> */
    private function notificationIdsForSource(string $sourceType, array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
        $stmt = $this->pdo->prepare("SELECT id FROM notifications WHERE source_type = ? AND source_id IN ({$placeholders})");
        $stmt->execute([$sourceType, ...$sourceIds]);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

    /** @param array<int, int> $ids @param array<int, mixed> $params */
    private function executeForIds(string $sqlTemplate, array $ids, array $params): void
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(sprintf($sqlTemplate, $placeholders));
        $stmt->execute([...$params, ...$ids]);
    }

    private function redactAuditText(string $from, string $to): void
    {
        if ($from === '') {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE audit_logs SET detail = REPLACE(detail, ?, ?)');
        $stmt->execute([$from, $to]);
    }

    private function safetyCopy(): string
    {
        $dir = getenv('CPE_PRIVACY_SNAPSHOT_DIR') ?: cpe_data_path('privacy');
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Could not create privacy data directory.');
        }
        return (string) (new DatabaseBackupService($this->pdo))->create('candidate-anonymize-safety', $dir)['path'];
    }
}
