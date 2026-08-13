<?php

declare(strict_types=1);

namespace App\Modules\Placement\Install;

use App\Support\Database;
use PDO;
use RuntimeException;

final class LegacyDomainSynchronizer
{
    public function synchronize(PDO $pdo): void
    {
        if (!$this->hasDurableTables($pdo)) {
            return;
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $institutionId = $this->requiredId($pdo, "SELECT id FROM institutions WHERE slug = 'default'");
            $cycleId = $this->requiredId($pdo, "SELECT id FROM placement_cycles WHERE cycle_key = 'default'");
            $this->synchronizeCandidates($pdo, $institutionId, $cycleId);
            $this->synchronizeCompanies($pdo, $institutionId, $cycleId);
            $this->synchronizeApplications($pdo);
            $this->synchronizePresence($pdo);
            $this->synchronizeOffers($pdo);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function synchronizeCandidates(PDO $pdo, int $institutionId, int $cycleId): void
    {
        $candidatePublicId = $pdo->prepare("UPDATE candidates SET public_id = ? WHERE id = ? AND (public_id IS NULL OR public_id = '')");
        $findPerson = $pdo->prepare('SELECT id, public_id FROM people WHERE legacy_candidate_id = ?');
        $insertPerson = $pdo->prepare(
            'INSERT INTO people (public_id, institution_id, legacy_candidate_id, display_name, anonymized_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $updatePerson = $pdo->prepare('UPDATE people SET display_name = ?, anonymized_at = ?, updated_at = ? WHERE id = ?');
        $findProfile = $pdo->prepare('SELECT id FROM student_profiles WHERE person_id = ?');
        $insertProfile = $pdo->prepare(
            'INSERT INTO student_profiles (public_id, institution_id, person_id, external_id, program, tags, accommodation_notes, custom_fields_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $updateProfile = $pdo->prepare(
            'UPDATE student_profiles SET external_id = ?, program = ?, tags = ?, accommodation_notes = ?, custom_fields_json = ?, updated_at = ? WHERE id = ?'
        );
        $findParticipant = $pdo->prepare('SELECT id FROM placement_cycle_participants WHERE legacy_candidate_id = ?');
        $insertParticipant = $pdo->prepare(
            'INSERT INTO placement_cycle_participants (public_id, cycle_id, student_profile_id, legacy_candidate_id, participation_status, opted_out, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $updateParticipant = $pdo->prepare(
            'UPDATE placement_cycle_participants SET cycle_id = ?, student_profile_id = ?, participation_status = ?, opted_out = ?, updated_at = ? WHERE id = ?'
        );

        foreach ($pdo->query('SELECT * FROM candidates ORDER BY id')->fetchAll() as $candidate) {
            $candidateId = (int) $candidate['id'];
            if ((string) ($candidate['public_id'] ?? '') === '') {
                $candidatePublicId->execute([$this->publicId('candidate'), $candidateId]);
            }
            $findPerson->execute([$candidateId]);
            $person = $findPerson->fetch();
            if (!$person) {
                $personPublicId = $this->publicId('person');
                $insertPerson->execute([
                    $personPublicId,
                    $institutionId,
                    $candidateId,
                    (string) $candidate['name'],
                    $candidate['anonymized_at'] ?: null,
                    (string) $candidate['created_at'],
                    (string) $candidate['updated_at'],
                ]);
                $personId = Database::lastInsertId($pdo);
            } else {
                $personId = (int) $person['id'];
                $updatePerson->execute([(string) $candidate['name'], $candidate['anonymized_at'] ?: null, (string) $candidate['updated_at'], $personId]);
            }

            $findProfile->execute([$personId]);
            $profileId = (int) ($findProfile->fetchColumn() ?: 0);
            $profileValues = [
                (string) $candidate['external_id'],
                (string) $candidate['program'],
                (string) ($candidate['tags'] ?? ''),
                (string) ($candidate['accommodation_notes'] ?? ''),
                (string) ($candidate['custom_fields_json'] ?? '{}'),
                (string) $candidate['updated_at'],
            ];
            if ($profileId === 0) {
                $insertProfile->execute([
                    $this->publicId('student'),
                    $institutionId,
                    $personId,
                    ...array_slice($profileValues, 0, 5),
                    (string) $candidate['created_at'],
                    (string) $candidate['updated_at'],
                ]);
                $profileId = Database::lastInsertId($pdo);
            } else {
                $updateProfile->execute([...$profileValues, $profileId]);
            }

            $findParticipant->execute([$candidateId]);
            $participantId = (int) ($findParticipant->fetchColumn() ?: 0);
            $participantStatus = !empty($candidate['anonymized_at']) ? 'anonymized' : 'active';
            if ($participantId === 0) {
                $insertParticipant->execute([
                    $this->publicId('participant'),
                    $cycleId,
                    $profileId,
                    $candidateId,
                    $participantStatus,
                    (int) ($candidate['opted_out'] ?? 0),
                    (string) $candidate['created_at'],
                    (string) $candidate['updated_at'],
                ]);
            } else {
                $updateParticipant->execute([
                    $cycleId,
                    $profileId,
                    $participantStatus,
                    (int) ($candidate['opted_out'] ?? 0),
                    (string) $candidate['updated_at'],
                    $participantId,
                ]);
            }
        }
    }

    private function synchronizeCompanies(PDO $pdo, int $institutionId, int $cycleId): void
    {
        $companyPublicId = $pdo->prepare("UPDATE companies SET public_id = ? WHERE id = ? AND (public_id IS NULL OR public_id = '')");
        $findOrganization = $pdo->prepare('SELECT id FROM organizations WHERE legacy_company_id = ?');
        $insertOrganization = $pdo->prepare(
            'INSERT INTO organizations (public_id, institution_id, legacy_company_id, code, name, tags, custom_fields_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $updateOrganization = $pdo->prepare('UPDATE organizations SET code = ?, name = ?, tags = ?, custom_fields_json = ?, updated_at = ? WHERE id = ?');
        $findOpportunity = $pdo->prepare('SELECT id FROM placement_opportunities WHERE legacy_company_id = ?');
        $insertOpportunity = $pdo->prepare(
            'INSERT INTO placement_opportunities (
                public_id, cycle_id, organization_id, legacy_company_id, opportunity_key, title,
                slot, offer_tier, process_type, room, tracker_name, max_active,
                deadline_day, deadline_at, process_notes, status, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $updateOpportunity = $pdo->prepare(
            'UPDATE placement_opportunities SET cycle_id = ?, organization_id = ?, opportunity_key = ?, title = ?, slot = ?, offer_tier = ?, process_type = ?, room = ?, tracker_name = ?, max_active = ?, deadline_day = ?, deadline_at = ?, process_notes = ?, updated_at = ? WHERE id = ?'
        );

        foreach ($pdo->query('SELECT * FROM companies ORDER BY id')->fetchAll() as $company) {
            $companyId = (int) $company['id'];
            if ((string) ($company['public_id'] ?? '') === '') {
                $companyPublicId->execute([$this->publicId('company'), $companyId]);
            }
            $findOrganization->execute([$companyId]);
            $organizationId = (int) ($findOrganization->fetchColumn() ?: 0);
            if ($organizationId === 0) {
                $insertOrganization->execute([
                    $this->publicId('organization'),
                    $institutionId,
                    $companyId,
                    (string) $company['code'],
                    (string) $company['name'],
                    (string) ($company['tags'] ?? ''),
                    (string) ($company['custom_fields_json'] ?? '{}'),
                    (string) $company['created_at'],
                    (string) $company['updated_at'],
                ]);
                $organizationId = Database::lastInsertId($pdo);
            } else {
                $updateOrganization->execute([
                    (string) $company['code'],
                    (string) $company['name'],
                    (string) ($company['tags'] ?? ''),
                    (string) ($company['custom_fields_json'] ?? '{}'),
                    (string) $company['updated_at'],
                    $organizationId,
                ]);
            }

            $findOpportunity->execute([$companyId]);
            $opportunityId = (int) ($findOpportunity->fetchColumn() ?: 0);
            $values = [
                $cycleId,
                $organizationId,
                (string) $company['code'],
                (string) $company['name'],
                (string) ($company['slot'] ?? ''),
                (string) ($company['offer_tier'] ?? ''),
                (string) ($company['process_type'] ?? ''),
                (string) ($company['room'] ?? ''),
                (string) ($company['tracker_name'] ?? ''),
                (int) ($company['max_active'] ?? 0),
                (string) ($company['deadline_day'] ?? ''),
                (string) ($company['deadline_at'] ?? ''),
                (string) ($company['process_notes'] ?? ''),
            ];
            if ($opportunityId === 0) {
                $insertOpportunity->execute([
                    $this->publicId('opportunity'),
                    $cycleId,
                    $organizationId,
                    $companyId,
                    ...array_slice($values, 2),
                    'open',
                    (string) $company['created_at'],
                    (string) $company['updated_at'],
                ]);
            } else {
                $updateOpportunity->execute([...$values, (string) $company['updated_at'], $opportunityId]);
            }
        }
    }

    private function synchronizeApplications(PDO $pdo): void
    {
        $publicId = $pdo->prepare("UPDATE applications SET public_id = ? WHERE id = ? AND (public_id IS NULL OR public_id = '')");
        $link = $pdo->prepare(
            'UPDATE applications
             SET participant_id = (SELECT id FROM placement_cycle_participants WHERE legacy_candidate_id = applications.candidate_id),
                 opportunity_id = (SELECT id FROM placement_opportunities WHERE legacy_company_id = applications.company_id)
             WHERE id = ?'
        );
        foreach ($pdo->query('SELECT id, public_id FROM applications ORDER BY id')->fetchAll() as $application) {
            if ((string) ($application['public_id'] ?? '') === '') {
                $publicId->execute([$this->publicId('application'), (int) $application['id']]);
            }
            $link->execute([(int) $application['id']]);
        }
    }

    private function synchronizePresence(PDO $pdo): void
    {
        $upsert = $pdo->prepare(
            'INSERT INTO placement_presence (participant_id, current_location, previous_opportunity_id, next_opportunity_id, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(participant_id) DO UPDATE SET
                 current_location = excluded.current_location,
                 previous_opportunity_id = excluded.previous_opportunity_id,
                 next_opportunity_id = excluded.next_opportunity_id,
                 updated_at = excluded.updated_at'
        );
        $latestApplication = $pdo->prepare(
            'SELECT previous_company_id, next_company_id FROM applications
             WHERE candidate_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1'
        );
        $opportunityForCompany = $pdo->prepare('SELECT id FROM placement_opportunities WHERE legacy_company_id = ?');
        foreach ($pdo->query(
            'SELECT p.id AS participant_id, c.id AS candidate_id, c.current_location, c.updated_at
             FROM placement_cycle_participants p
             JOIN candidates c ON c.id = p.legacy_candidate_id'
        )->fetchAll() as $row) {
            $latestApplication->execute([(int) $row['candidate_id']]);
            $route = $latestApplication->fetch() ?: [];
            $previousId = $this->optionalOpportunityId($opportunityForCompany, $route['previous_company_id'] ?? null);
            $nextId = $this->optionalOpportunityId($opportunityForCompany, $route['next_company_id'] ?? null);
            $upsert->execute([(int) $row['participant_id'], (string) $row['current_location'], $previousId, $nextId, (string) $row['updated_at']]);
        }
    }

    private function synchronizeOffers(PDO $pdo): void
    {
        $supersede = $pdo->prepare("UPDATE placement_offers SET offer_status = 'superseded', updated_at = ? WHERE source = 'legacy_projection'");
        $supersede->execute([cpe_now()]);
        $insert = $pdo->prepare(
            'INSERT INTO placement_offers (public_id, participant_id, opportunity_id, offer_status, offer_tier, source, decided_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(participant_id, opportunity_id, source) DO UPDATE SET
                 offer_status = excluded.offer_status,
                 offer_tier = excluded.offer_tier,
                 decided_at = excluded.decided_at,
                 updated_at = excluded.updated_at'
        );
        foreach ($pdo->query(
            'SELECT p.id AS participant_id, o.id AS opportunity_id, o.offer_tier, c.updated_at
             FROM candidates c
             JOIN placement_cycle_participants p ON p.legacy_candidate_id = c.id
             JOIN placement_opportunities o ON o.legacy_company_id = c.placed_company_id
             WHERE c.placed_company_id IS NOT NULL'
        )->fetchAll() as $row) {
            $insert->execute([
                $this->publicId('offer'),
                (int) $row['participant_id'],
                (int) $row['opportunity_id'],
                'accepted',
                (string) $row['offer_tier'],
                'legacy_projection',
                (string) $row['updated_at'],
                (string) $row['updated_at'],
                (string) $row['updated_at'],
            ]);
        }
    }

    private function optionalOpportunityId(\PDOStatement $stmt, mixed $legacyCompanyId): ?int
    {
        if ($legacyCompanyId === null || (int) $legacyCompanyId <= 0) {
            return null;
        }
        $stmt->execute([(int) $legacyCompanyId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function requiredId(PDO $pdo, string $sql): int
    {
        $id = $pdo->query($sql)->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('Placement domain synchronization is missing its installation context.');
        }
        return (int) $id;
    }

    private function hasDurableTables(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM people LIMIT 1');
            $pdo->query('SELECT 1 FROM placement_opportunities LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function publicId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(16));
    }
}
