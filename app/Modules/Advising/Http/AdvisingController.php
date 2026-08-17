<?php

declare(strict_types=1);

namespace App\Modules\Advising\Http;

use App\Modules\Advising\Application\AdvisingService;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\Flash;

final class AdvisingController
{
    public function show(): void
    {
        $user = Auth::requireCapability('advising.appointments.view', 'Your role cannot open Career Advising.');
        $service = new AdvisingService();
        $this->render('dashboard', [
            'user' => $user,
            'stats' => $service->stats(),
            'students' => $service->students(),
            'advisers' => $service->advisers(),
            'appointments' => $service->appointments(),
            'notes' => $service->notes(),
            'tasks' => $service->tasks(),
        ]);
    }

    public function createAppointment(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'advising.appointments.manage')) {
                throw new \RuntimeException('Your role cannot create advising appointments.');
            }
            (new AdvisingService())->createAppointment($_POST, (int) $user['id']);
            Flash::add('success', 'Advising appointment created.');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
        }
        redirect(url('advising'));
    }

    public function updateStatus(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'advising.appointments.manage')) {
                throw new \RuntimeException('Your role cannot update advising appointments.');
            }
            (new AdvisingService())->updateAppointmentStatus(
                (int) ($_POST['appointment_id'] ?? 0),
                (string) ($_POST['appointment_status'] ?? ''),
                (int) $user['id'],
            );
            Flash::add('success', 'Appointment status updated.');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
        }
        redirect(url('advising'));
    }

    public function addNote(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'advising.notes.manage')) {
                throw new \RuntimeException('Your role cannot add advising notes.');
            }
            (new AdvisingService())->addNote(
                (int) ($_POST['appointment_id'] ?? 0),
                (string) ($_POST['body'] ?? ''),
                (int) $user['id'],
            );
            Flash::add('success', 'Staff note added.');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
        }
        redirect(url('advising'));
    }

    public function completeTask(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'advising.tasks.manage')) {
                throw new \RuntimeException('Your role cannot complete advising tasks.');
            }
            (new AdvisingService())->completeTask((int) ($_POST['task_id'] ?? 0), (int) $user['id']);
            Flash::add('success', 'Advising task completed.');
        } catch (\Throwable $e) {
            Flash::add('error', $e->getMessage());
        }
        redirect(url('advising'));
    }

    private function render(string $template, array $data): void
    {
        extract($data, EXTR_SKIP);
        require cpe_path('app/Modules/Advising/Views/' . $template . '.php');
    }
}
