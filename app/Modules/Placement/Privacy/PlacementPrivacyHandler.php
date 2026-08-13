<?php

declare(strict_types=1);

namespace App\Modules\Placement\Privacy;

use App\Core\Modules\ModulePrivacyHandler;
use App\Domain\PrivacyService;
use App\Support\Database;
use PDO;
use RuntimeException;

final class PlacementPrivacyHandler implements ModulePrivacyHandler
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public function reportForPerson(string $personPublicId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.public_id, p.anonymized_at, c.id AS candidate_id, c.external_id,
                    c.name, c.opted_out,
                    (SELECT COUNT(*) FROM applications a WHERE a.candidate_id = c.id) AS applications,
                    (SELECT COUNT(*) FROM events e WHERE e.candidate_id = c.id) AS movement_events,
                    (SELECT COUNT(*) FROM preference_requests pr WHERE pr.candidate_id = c.id) AS preference_requests,
                    (SELECT COUNT(*) FROM wanted_alerts wa WHERE wa.candidate_id = c.id) AS wanted_alerts
             FROM people p
             JOIN candidates c ON c.id = p.legacy_candidate_id
             WHERE p.public_id = ?'
        );
        $stmt->execute([$personPublicId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['module' => 'placement', 'found' => false, 'person_public_id' => $personPublicId];
        }
        return [
            'module' => 'placement',
            'found' => true,
            'person_public_id' => (string) $row['public_id'],
            'candidate_external_id' => (string) $row['external_id'],
            'candidate_name' => (string) $row['name'],
            'anonymized_at' => $row['anonymized_at'],
            'opted_out' => (bool) $row['opted_out'],
            'records' => [
                'applications' => (int) $row['applications'],
                'movement_events' => (int) $row['movement_events'],
                'preference_requests' => (int) $row['preference_requests'],
                'wanted_alerts' => (int) $row['wanted_alerts'],
            ],
        ];
    }

    public function erasePerson(string $personPublicId, string $reason): array
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A privacy-erasure reason is required.');
        }
        $report = $this->reportForPerson($personPublicId);
        if (empty($report['found'])) {
            throw new RuntimeException('Placement participant not found for person: ' . $personPublicId);
        }
        $result = (new PrivacyService($this->pdo))->anonymizeCandidate((string) $report['candidate_external_id']);
        return [
            'module' => 'placement',
            'person_public_id' => $personPublicId,
            'reason' => trim($reason),
            ...$result,
        ];
    }
}
