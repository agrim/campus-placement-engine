<?php

declare(strict_types=1);

namespace App\Core\People;

use PDO;

final class PersonReferenceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function studentProfileIdForPlacementCandidate(string $candidatePublicId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT sp.id
             FROM student_profiles sp
             JOIN people p ON p.id = sp.person_id
             JOIN candidates c ON c.id = p.legacy_candidate_id
             WHERE c.public_id = ?'
        );
        $stmt->execute([$candidatePublicId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function referencesForPerson(string $personPublicId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id AS person_id, p.public_id AS person_public_id,
                    sp.id AS student_profile_id, sp.public_id AS student_profile_public_id,
                    COALESCE(c.public_id, \'\') AS placement_candidate_public_id
             FROM people p
             LEFT JOIN student_profiles sp ON sp.person_id = p.id
             LEFT JOIN candidates c ON c.id = p.legacy_candidate_id
             WHERE p.public_id = ?'
        );
        $stmt->execute([$personPublicId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
