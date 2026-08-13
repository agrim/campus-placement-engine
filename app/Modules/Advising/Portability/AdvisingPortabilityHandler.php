<?php

declare(strict_types=1);

namespace App\Modules\Advising\Portability;

use App\Core\Modules\ModulePortabilityHandler;
use App\Support\Database;
use PDO;
use RuntimeException;

final class AdvisingPortabilityHandler implements ModulePortabilityHandler
{
    public const SCHEMA = 'career_services.module.advising.v1';

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public function export(): array
    {
        return [
            'schema' => self::SCHEMA,
            'module_version' => (string) cpe_config('modules.advising.version', '0.0.0'),
            'appointments' => $this->pdo->query(
                'SELECT aa.public_id, sp.public_id AS student_profile_public_id,
                        aa.appointment_status, aa.starts_at, aa.ends_at, aa.appointment_mode,
                        aa.location, aa.topic, aa.student_notes, aa.created_at, aa.updated_at
                 FROM advising_appointments aa
                 JOIN student_profiles sp ON sp.id = aa.student_profile_id
                 ORDER BY aa.created_at, aa.public_id'
            )->fetchAll(),
            'notes' => $this->pdo->query(
                "SELECT n.public_id, sp.public_id AS student_profile_public_id,
                        COALESCE(a.public_id, '') AS appointment_public_id,
                        n.visibility, n.body, n.created_at, n.updated_at
                 FROM advising_notes n
                 JOIN student_profiles sp ON sp.id = n.student_profile_id
                 LEFT JOIN advising_appointments a ON a.id = n.appointment_id
                 ORDER BY n.created_at, n.public_id"
            )->fetchAll(),
            'tasks' => $this->pdo->query(
                "SELECT t.public_id, COALESCE(sp.public_id, '') AS student_profile_public_id,
                        t.task_type, t.task_status, t.title, t.due_on, t.detail,
                        t.source_event_name, t.source_aggregate_public_id, t.subject_reference,
                        t.completed_at, t.created_at, t.updated_at
                 FROM advising_tasks t
                 LEFT JOIN student_profiles sp ON sp.id = t.student_profile_id
                 ORDER BY t.created_at, t.public_id"
            )->fetchAll(),
            'excluded' => ['users', 'adviser_assignments', 'note_authors', 'password_hashes', 'sessions'],
        ];
    }

    public function validate(array $payload): array
    {
        if (($payload['schema'] ?? '') !== self::SCHEMA) {
            throw new RuntimeException('Unsupported Career Advising portability schema.');
        }
        foreach (['appointments', 'notes', 'tasks'] as $list) {
            if (!is_array($payload[$list] ?? null) || !array_is_list($payload[$list])) {
                throw new RuntimeException('Career Advising portability payload is missing list: ' . $list);
            }
            $this->assertUnique($payload[$list], 'public_id', $list . ' public id');
        }
        $appointments = array_fill_keys(array_map(
            static fn (array $row): string => (string) ($row['public_id'] ?? ''),
            $payload['appointments']
        ), true);
        foreach ($payload['appointments'] as $row) {
            $this->requirePublicId((string) ($row['public_id'] ?? ''), 'advising_appointment_');
            $this->requirePublicId((string) ($row['student_profile_public_id'] ?? ''), 'student_');
        }
        foreach ($payload['notes'] as $row) {
            $this->requirePublicId((string) ($row['public_id'] ?? ''), 'advising_note_');
            $this->requirePublicId((string) ($row['student_profile_public_id'] ?? ''), 'student_');
            $appointment = (string) ($row['appointment_public_id'] ?? '');
            if ($appointment !== '' && !isset($appointments[$appointment])) {
                throw new RuntimeException('Advising note references an appointment outside the payload.');
            }
        }
        foreach ($payload['tasks'] as $row) {
            $this->requirePublicId((string) ($row['public_id'] ?? ''), 'advising_task_');
            $student = (string) ($row['student_profile_public_id'] ?? '');
            if ($student !== '') {
                $this->requirePublicId($student, 'student_');
            }
        }
        foreach (['password_hash', 'api_token', 'client_secret', 'session_id'] as $forbidden) {
            if ($this->hasFieldContaining($payload, $forbidden)) {
                throw new RuntimeException('Career Advising payload contains a forbidden secret field: ' . $forbidden);
            }
        }
        return [
            'appointments' => count($payload['appointments']),
            'notes' => count($payload['notes']),
            'tasks' => count($payload['tasks']),
        ];
    }

    public function import(array $payload): array
    {
        $counts = $this->validate($payload);
        foreach (['advising_appointments', 'advising_notes', 'advising_tasks'] as $table) {
            if ((int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() > 0) {
                throw new RuntimeException('Career Advising import requires an empty module data set.');
            }
        }
        $institutionId = (int) $this->pdo->query("SELECT id FROM institutions WHERE slug = 'default'")->fetchColumn();
        if ($institutionId <= 0) {
            throw new RuntimeException('Career Advising import requires an institution context.');
        }
        $students = $this->studentIds();
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $appointmentIds = [];
            $appointment = $this->pdo->prepare(
                'INSERT INTO advising_appointments
                 (public_id, institution_id, student_profile_id, adviser_user_id, appointment_status,
                  starts_at, ends_at, appointment_mode, location, topic, student_notes,
                  created_by, created_at, updated_at)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)'
            );
            foreach ($payload['appointments'] as $row) {
                $studentId = $students[(string) $row['student_profile_public_id']] ?? 0;
                if ($studentId <= 0) {
                    throw new RuntimeException('Advising appointment references an unavailable student profile.');
                }
                $appointment->execute([
                    $row['public_id'], $institutionId, $studentId, $row['appointment_status'],
                    $row['starts_at'], $row['ends_at'], $row['appointment_mode'], $row['location'],
                    $row['topic'], $row['student_notes'], $row['created_at'], $row['updated_at'],
                ]);
                $appointmentIds[(string) $row['public_id']] = Database::lastInsertId($this->pdo);
            }

            $note = $this->pdo->prepare(
                'INSERT INTO advising_notes
                 (public_id, institution_id, student_profile_id, appointment_id, author_user_id,
                  visibility, body, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?)'
            );
            foreach ($payload['notes'] as $row) {
                $studentId = $students[(string) $row['student_profile_public_id']] ?? 0;
                if ($studentId <= 0) {
                    throw new RuntimeException('Advising note references an unavailable student profile.');
                }
                $appointmentId = (string) $row['appointment_public_id'] !== ''
                    ? ($appointmentIds[(string) $row['appointment_public_id']] ?? 0)
                    : null;
                if ($appointmentId === 0) {
                    throw new RuntimeException('Advising note references an unavailable appointment.');
                }
                $note->execute([
                    $row['public_id'], $institutionId, $studentId, $appointmentId,
                    $row['visibility'], $row['body'], $row['created_at'], $row['updated_at'],
                ]);
            }

            $task = $this->pdo->prepare(
                'INSERT INTO advising_tasks
                 (public_id, institution_id, student_profile_id, task_type, task_status, title, due_on,
                  detail, source_event_name, source_aggregate_public_id, subject_reference,
                  completed_by, completed_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)'
            );
            foreach ($payload['tasks'] as $row) {
                $studentReference = (string) $row['student_profile_public_id'];
                $studentId = $studentReference === '' ? null : ($students[$studentReference] ?? 0);
                if ($studentId === 0) {
                    throw new RuntimeException('Advising task references an unavailable student profile.');
                }
                $task->execute([
                    $row['public_id'], $institutionId, $studentId, $row['task_type'], $row['task_status'],
                    $row['title'], $row['due_on'], $row['detail'], $row['source_event_name'],
                    $row['source_aggregate_public_id'], $row['subject_reference'],
                    $row['completed_at'] ?: null, $row['created_at'], $row['updated_at'],
                ]);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $counts;
    }

    private function studentIds(): array
    {
        $ids = [];
        foreach ($this->pdo->query('SELECT id, public_id FROM student_profiles')->fetchAll() as $row) {
            $ids[(string) $row['public_id']] = (int) $row['id'];
        }
        return $ids;
    }

    private function assertUnique(array $rows, string $field, string $label): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $value = (string) ($row[$field] ?? '');
            if ($value === '' || isset($seen[$value])) {
                throw new RuntimeException('Career Advising payload has an empty or duplicate ' . $label . '.');
            }
            $seen[$value] = true;
        }
    }

    private function requirePublicId(string $value, string $prefix): void
    {
        if (!str_starts_with($value, $prefix) || preg_match('/^[a-z_]+[a-f0-9]{32}$/', $value) !== 1) {
            throw new RuntimeException('Career Advising payload has an invalid public id: ' . $value);
        }
    }

    private function hasFieldContaining(array $payload, string $needle): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && stripos($key, $needle) !== false) {
                return true;
            }
            if (is_array($value) && $this->hasFieldContaining($value, $needle)) {
                return true;
            }
        }
        return false;
    }
}
