<?php

declare(strict_types=1);

namespace App\Core\Privacy;

use App\Core\Backup\DatabaseBackupService;
use App\Core\Modules\Module;
use App\Core\Modules\ProvidesPrivacy;
use App\Support\Auth;
use App\Support\Database;
use PDO;
use RuntimeException;

final class PortalPrivacyService
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public function report(string $personPublicId): array
    {
        $person = $this->person($personPublicId);
        $modules = [];
        foreach ($this->privacyModules() as $key => $module) {
            $modules[$key] = $module->privacyHandler()->reportForPerson($personPublicId);
        }
        return [
            'schema' => 'career_services.privacy_report.v1',
            'created_at' => cpe_now(),
            'person' => [
                'public_id' => (string) $person['person_public_id'],
                'display_name' => (string) $person['display_name'],
                'anonymized_at' => $person['anonymized_at'],
                'student_profile_public_id' => (string) ($person['student_profile_public_id'] ?? ''),
                'external_id' => (string) ($person['external_id'] ?? ''),
            ],
            'modules' => $modules,
        ];
    }

    public function erase(string $personPublicId, string $reason, ?int $actorId = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A portal privacy-erasure reason is required.');
        }
        $person = $this->person($personPublicId);
        if (!empty($person['anonymized_at'])) {
            throw new RuntimeException('This person is already anonymized.');
        }
        $directory = (string) (getenv('CPE_PRIVACY_SNAPSHOT_DIR') ?: cpe_data_path('privacy'));
        $backup = (new DatabaseBackupService($this->pdo))->create('portal-person-erasure', $directory);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $moduleResults = [];
            foreach ($this->privacyModules() as $key => $module) {
                $report = $module->privacyHandler()->reportForPerson($personPublicId);
                if (empty($report['found'])) {
                    $moduleResults[$key] = ['module' => $key, 'found' => false];
                    continue;
                }
                $moduleResults[$key] = $module->privacyHandler()->erasePerson($personPublicId, $reason);
            }
            $now = cpe_now();
            $stmt = $this->pdo->prepare(
                "UPDATE people SET display_name = 'Anonymized Person', anonymized_at = ?, updated_at = ? WHERE public_id = ?"
            );
            $stmt->execute([$now, $now, $personPublicId]);
            if (!empty($person['student_profile_id'])) {
                $externalId = !empty($person['legacy_candidate_id'])
                    ? (string) $this->pdo->query('SELECT external_id FROM candidates WHERE id = ' . (int) $person['legacy_candidate_id'])->fetchColumn()
                    : 'ANON-STUDENT-' . (int) $person['student_profile_id'];
                $stmt = $this->pdo->prepare(
                    "UPDATE student_profiles
                     SET external_id = ?, program = '', tags = '', accommodation_notes = '', custom_fields_json = '{}', updated_at = ?
                     WHERE id = ?"
                );
                $stmt->execute([$externalId, $now, (int) $person['student_profile_id']]);
            }
            Auth::audit($actorId, 'privacy.person_erased', 'person', (int) $person['person_id'], 'Portal-wide privacy erasure completed');
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException($e->getMessage() . ' Restore from safety backup: ' . $backup['path'], 0, $e);
        }
        return [
            'schema' => 'career_services.privacy_erasure.v1',
            'person_public_id' => $personPublicId,
            'anonymized_at' => $now,
            'reason' => $reason,
            'backup_path' => $backup['path'],
            'modules' => $moduleResults,
        ];
    }

    private function person(string $publicId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id AS person_id, p.public_id AS person_public_id, p.display_name,
                    p.anonymized_at, p.legacy_candidate_id,
                    sp.id AS student_profile_id, sp.public_id AS student_profile_public_id, sp.external_id
             FROM people p LEFT JOIN student_profiles sp ON sp.person_id = p.id
             WHERE p.public_id = ?'
        );
        $stmt->execute([$publicId]);
        $person = $stmt->fetch();
        if (!$person) {
            throw new RuntimeException('Person was not found: ' . $publicId);
        }
        return $person;
    }

    /** @return array<string, ProvidesPrivacy&Module> */
    private function privacyModules(): array
    {
        $installed = array_fill_keys(
            $this->pdo->query('SELECT module_key FROM module_installations')->fetchAll(PDO::FETCH_COLUMN),
            true,
        );
        $modules = [];
        foreach (cpe_config('modules', []) as $key => $definition) {
            if (!isset($installed[$key])) {
                continue;
            }
            $class = (string) ($definition['class'] ?? '');
            $module = $class !== '' && class_exists($class) ? new $class() : null;
            if (!$module instanceof Module || !$module instanceof ProvidesPrivacy) {
                throw new RuntimeException('Installed module has no privacy handler: ' . $key);
            }
            $modules[$key] = $module;
        }
        return $modules;
    }
}
