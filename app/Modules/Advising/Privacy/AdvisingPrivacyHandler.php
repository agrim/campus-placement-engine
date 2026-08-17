<?php

declare(strict_types=1);

namespace App\Modules\Advising\Privacy;

use App\Core\Modules\ModulePrivacyHandler;
use App\Core\People\PersonReferenceRepository;
use App\Support\Database;
use PDO;
use RuntimeException;

final class AdvisingPrivacyHandler implements ModulePrivacyHandler
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public function reportForPerson(string $personPublicId): array
    {
        $references = (new PersonReferenceRepository($this->pdo))->referencesForPerson($personPublicId);
        if ($references === null || empty($references['student_profile_id'])) {
            return ['module' => 'advising', 'found' => false, 'person_public_id' => $personPublicId];
        }
        $studentId = (int) $references['student_profile_id'];
        return [
            'module' => 'advising',
            'found' => true,
            'person_public_id' => $personPublicId,
            'student_profile_public_id' => (string) $references['student_profile_public_id'],
            'records' => [
                'appointments' => $this->count('SELECT COUNT(*) FROM advising_appointments WHERE student_profile_id = ?', $studentId),
                'staff_notes' => $this->count('SELECT COUNT(*) FROM advising_notes WHERE student_profile_id = ?', $studentId),
                'tasks' => $this->count('SELECT COUNT(*) FROM advising_tasks WHERE student_profile_id = ?', $studentId),
            ],
        ];
    }

    public function erasePerson(string $personPublicId, string $reason): array
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A privacy-erasure reason is required.');
        }
        $references = (new PersonReferenceRepository($this->pdo))->referencesForPerson($personPublicId);
        if ($references === null || empty($references['student_profile_id'])) {
            throw new RuntimeException('Advising student profile not found for person: ' . $personPublicId);
        }
        $studentId = (int) $references['student_profile_id'];
        $candidateReference = (string) $references['placement_candidate_public_id'];
        $now = cpe_now();
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE advising_appointments SET location = ?, topic = ?, student_notes = ?, updated_at = ? WHERE student_profile_id = ?'
            );
            $stmt->execute(['', '[redacted by privacy erasure]', '[redacted by privacy erasure]', $now, $studentId]);
            $appointments = $stmt->rowCount();
            $stmt = $this->pdo->prepare('UPDATE advising_notes SET body = ?, updated_at = ? WHERE student_profile_id = ?');
            $stmt->execute(['[redacted by privacy erasure]', $now, $studentId]);
            $notes = $stmt->rowCount();
            $stmt = $this->pdo->prepare(
                'UPDATE advising_tasks SET detail = ?, subject_reference = ?, updated_at = ?
                 WHERE student_profile_id = ? OR (? <> \'\' AND subject_reference = ?)'
            );
            $stmt->execute(['[redacted by privacy erasure]', '', $now, $studentId, $candidateReference, $candidateReference]);
            $tasks = $stmt->rowCount();
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
            'module' => 'advising',
            'person_public_id' => $personPublicId,
            'reason' => trim($reason),
            'appointments' => $appointments,
            'staff_notes' => $notes,
            'tasks' => $tasks,
        ];
    }

    private function count(string $sql, int $studentId): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$studentId]);
        return (int) $stmt->fetchColumn();
    }
}
