<?php

declare(strict_types=1);

namespace App\Modules\Advising\Application;

use App\Core\Events\DomainEvent;
use App\Core\People\PersonReferenceRepository;
use App\Support\Auth;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class AdvisingService
{
    private const STATUSES = ['requested', 'scheduled', 'completed', 'cancelled', 'no_show'];
    private const MODES = ['in_person', 'video', 'phone'];

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public function students(): array
    {
        return $this->pdo->query(
            'SELECT sp.id, sp.public_id, sp.external_id, sp.program, p.display_name
             FROM student_profiles sp JOIN people p ON p.id = sp.person_id
             WHERE p.anonymized_at IS NULL OR p.anonymized_at = \'\'
             ORDER BY p.display_name, sp.external_id'
        )->fetchAll();
    }

    public function advisers(): array
    {
        return $this->pdo->query(
            "SELECT id, name, email, role FROM users
             WHERE active = 1 AND role IN ('admin', 'advisor', 'placement', 'control')
             ORDER BY name"
        )->fetchAll();
    }

    public function appointments(): array
    {
        $rows = $this->pdo->query(
            'SELECT aa.*, sp.external_id, p.display_name AS student_name,
                    COALESCE(u.name, \'Unassigned\') AS adviser_name,
                    (SELECT COUNT(*) FROM advising_notes n WHERE n.appointment_id = aa.id) AS note_count
             FROM advising_appointments aa
             JOIN student_profiles sp ON sp.id = aa.student_profile_id
             JOIN people p ON p.id = sp.person_id
             LEFT JOIN users u ON u.id = aa.adviser_user_id
             ORDER BY aa.starts_at DESC, aa.id DESC'
        )->fetchAll();
        foreach ($rows as &$row) {
            $row['starts_at_display'] = $this->displayTime((string) $row['starts_at']);
            $row['ends_at_display'] = $this->displayTime((string) $row['ends_at']);
        }
        unset($row);
        return $rows;
    }

    public function notes(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->pdo->query(
            "SELECT n.*, p.display_name AS student_name, sp.external_id,
                    COALESCE(u.name, 'Former user') AS author_name
             FROM advising_notes n
             JOIN student_profiles sp ON sp.id = n.student_profile_id
             JOIN people p ON p.id = sp.person_id
             LEFT JOIN users u ON u.id = n.author_user_id
             ORDER BY n.created_at DESC, n.id DESC LIMIT {$limit}"
        )->fetchAll();
    }

    public function tasks(): array
    {
        return $this->pdo->query(
            "SELECT t.*, COALESCE(p.display_name, t.subject_reference) AS subject_name
             FROM advising_tasks t
             LEFT JOIN student_profiles sp ON sp.id = t.student_profile_id
             LEFT JOIN people p ON p.id = sp.person_id
             ORDER BY CASE WHEN t.task_status = 'open' THEN 0 ELSE 1 END, t.due_on, t.created_at DESC"
        )->fetchAll();
    }

    public function stats(): array
    {
        return [
            'upcoming' => $this->count("SELECT COUNT(*) FROM advising_appointments WHERE appointment_status IN ('requested', 'scheduled') AND ends_at >= ?", [cpe_now()]),
            'completed' => $this->count("SELECT COUNT(*) FROM advising_appointments WHERE appointment_status = 'completed'"),
            'open_tasks' => $this->count("SELECT COUNT(*) FROM advising_tasks WHERE task_status = 'open'"),
            'students_seen' => $this->count("SELECT COUNT(DISTINCT student_profile_id) FROM advising_appointments WHERE appointment_status = 'completed'"),
        ];
    }

    public function createAppointment(array $input, int $actorId): int
    {
        $studentId = (int) ($input['student_profile_id'] ?? 0);
        $adviserId = (int) ($input['adviser_user_id'] ?? 0);
        $status = strtolower(trim((string) ($input['appointment_status'] ?? 'requested')));
        $mode = strtolower(trim((string) ($input['appointment_mode'] ?? 'in_person')));
        if (!in_array($status, ['requested', 'scheduled'], true) || !in_array($mode, self::MODES, true)) {
            throw new RuntimeException('Appointment status or mode is invalid.');
        }
        $this->requireStudent($studentId);
        if ($adviserId > 0) {
            $this->requireActiveUser($adviserId);
        }
        $startsAt = $this->utcTime((string) ($input['starts_at'] ?? ''), 'Start time');
        $endsAt = $this->utcTime((string) ($input['ends_at'] ?? ''), 'End time');
        if ($endsAt <= $startsAt) {
            throw new RuntimeException('Appointment end time must be after its start time.');
        }
        $institutionId = $this->institutionId();
        $now = cpe_now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO advising_appointments
             (public_id, institution_id, student_profile_id, adviser_user_id, appointment_status,
              starts_at, ends_at, appointment_mode, location, topic, student_notes, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'advising_appointment_' . bin2hex(random_bytes(16)),
            $institutionId,
            $studentId,
            $adviserId > 0 ? $adviserId : null,
            $status,
            $startsAt,
            $endsAt,
            $mode,
            $this->text((string) ($input['location'] ?? ''), 200, 'Location', true),
            $this->text((string) ($input['topic'] ?? ''), 200, 'Topic'),
            $this->text((string) ($input['student_notes'] ?? ''), 2000, 'Student notes', true),
            $actorId,
            $now,
            $now,
        ]);
        $id = Database::lastInsertId($this->pdo);
        Auth::audit($actorId, 'advising.appointment_created', 'advising_appointment', $id, 'Advising appointment created');
        return $id;
    }

    public function updateAppointmentStatus(int $appointmentId, string $status, int $actorId): void
    {
        $status = strtolower(trim($status));
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Appointment status is invalid.');
        }
        $stmt = $this->pdo->prepare('SELECT appointment_status FROM advising_appointments WHERE id = ?');
        $stmt->execute([$appointmentId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            throw new RuntimeException('Advising appointment was not found.');
        }
        $allowed = [
            'requested' => ['scheduled', 'cancelled'],
            'scheduled' => ['completed', 'cancelled', 'no_show'],
            'completed' => [],
            'cancelled' => [],
            'no_show' => ['scheduled'],
        ];
        if ($status !== $current && !in_array($status, $allowed[(string) $current] ?? [], true)) {
            throw new RuntimeException('This appointment status transition is not allowed.');
        }
        $stmt = $this->pdo->prepare('UPDATE advising_appointments SET appointment_status = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$status, cpe_now(), $appointmentId]);
        Auth::audit($actorId, 'advising.appointment_status', 'advising_appointment', $appointmentId, $current . ' -> ' . $status);
    }

    public function addNote(int $appointmentId, string $body, int $actorId): int
    {
        $stmt = $this->pdo->prepare('SELECT institution_id, student_profile_id FROM advising_appointments WHERE id = ?');
        $stmt->execute([$appointmentId]);
        $appointment = $stmt->fetch();
        if (!$appointment) {
            throw new RuntimeException('Advising appointment was not found.');
        }
        $body = $this->text($body, 4000, 'Advising note');
        $now = cpe_now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO advising_notes
             (public_id, institution_id, student_profile_id, appointment_id, author_user_id, visibility, body, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'advising_note_' . bin2hex(random_bytes(16)),
            (int) $appointment['institution_id'],
            (int) $appointment['student_profile_id'],
            $appointmentId,
            $actorId,
            'staff',
            $body,
            $now,
            $now,
        ]);
        $id = Database::lastInsertId($this->pdo);
        Auth::audit($actorId, 'advising.note_created', 'advising_note', $id, 'Staff-only advising note created');
        return $id;
    }

    public function completeTask(int $taskId, int $actorId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE advising_tasks SET task_status = 'complete', completed_by = ?, completed_at = ?, updated_at = ?
             WHERE id = ? AND task_status = 'open'"
        );
        $now = cpe_now();
        $stmt->execute([$actorId, $now, $now, $taskId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Open advising task was not found.');
        }
        Auth::audit($actorId, 'advising.task_completed', 'advising_task', $taskId, 'Advising task completed');
    }

    public function recordOfferFollowUp(DomainEvent $event): void
    {
        if ($event->name !== 'placement.offer.accepted') {
            return;
        }
        $subject = trim((string) ($event->payload['candidate_public_id'] ?? ''));
        if ($subject === '') {
            return;
        }
        $now = cpe_now();
        $studentProfileId = (new PersonReferenceRepository($this->pdo))
            ->studentProfileIdForPlacementCandidate($subject);
        $stmt = $this->pdo->prepare(
            'INSERT INTO advising_tasks
             (public_id, institution_id, student_profile_id, task_type, task_status, title, due_on,
              detail, source_event_name, source_aggregate_public_id, subject_reference, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(source_event_name, source_aggregate_public_id, task_type) DO NOTHING'
        );
        $stmt->execute([
            'advising_task_' . bin2hex(random_bytes(16)),
            $this->institutionId(),
            $studentProfileId,
            'offer_outcome_followup',
            'open',
            'Offer outcome follow-up',
            substr($event->occurredAt, 0, 10),
            'Review the accepted offer outcome and any next-step career guidance.',
            $event->name,
            $event->aggregatePublicId,
            $subject,
            $now,
            $now,
        ]);
    }

    private function count(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function institutionId(): int
    {
        $id = $this->pdo->query("SELECT id FROM institutions WHERE slug = 'default'")->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('Advising requires an institution context.');
        }
        return (int) $id;
    }

    private function requireStudent(int $studentId): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM student_profiles WHERE id = ? AND institution_id = ?');
        $stmt->execute([$studentId, $this->institutionId()]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Student profile was not found.');
        }
    }

    private function requireActiveUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND active = 1');
        $stmt->execute([$userId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Adviser user was not found or is inactive.');
        }
    }

    private function utcTime(string $value, string $label): string
    {
        $timezoneName = trim(cpe_setting('timezone', 'UTC')) ?: 'UTC';
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (\Throwable) {
            $timezone = new DateTimeZone('UTC');
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', trim($value), $timezone);
        if (!$time || $time->format('Y-m-d\TH:i') !== trim($value)) {
            throw new RuntimeException($label . ' must be a valid local date and time.');
        }
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function displayTime(string $value): string
    {
        try {
            $timezone = new DateTimeZone(trim(cpe_setting('timezone', 'UTC')) ?: 'UTC');
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function text(string $value, int $maxLength, string $label, bool $allowEmpty = false): string
    {
        $value = preg_replace('/\s+/', ' ', trim(strip_tags($value))) ?? '';
        if ((!$allowEmpty && $value === '') || mb_strlen($value) > $maxLength) {
            throw new RuntimeException($label . ' must be ' . ($allowEmpty ? 'at most ' : 'between 1 and ') . $maxLength . ' characters.');
        }
        return $value;
    }
}
