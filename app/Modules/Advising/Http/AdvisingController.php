<?php

declare(strict_types=1);

namespace App\Modules\Advising\Http;

use App\Core\Http\UserVisibleException;
use App\Modules\Advising\Application\AdvisingService;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\ControllerFailure;
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
                throw new UserVisibleException('ADVISING_CREATE_FORBIDDEN', 'Your role cannot create advising appointments.');
            }
            (new AdvisingService())->createAppointment($_POST, (int) $user['id']);
            Flash::add('success', 'Advising appointment created.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_ADVISING_CREATE_FAILURE', 'advising.create');
        }
        redirect(url('advising'));
    }

    public function updateStatus(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'advising.appointments.manage')) {
                throw new UserVisibleException('ADVISING_UPDATE_FORBIDDEN', 'Your role cannot update advising appointments.');
            }
            (new AdvisingService())->updateAppointmentStatus(
                (int) ($_POST['appointment_id'] ?? 0),
                (string) ($_POST['appointment_status'] ?? ''),
                (int) $user['id'],
            );
            Flash::add('success', 'Appointment status updated.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_ADVISING_UPDATE_FAILURE', 'advising.update');
        }
        redirect(url('advising'));
    }

    public function addNote(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'advising.notes.manage')) {
                throw new UserVisibleException('ADVISING_NOTE_FORBIDDEN', 'Your role cannot add advising notes.');
            }
            (new AdvisingService())->addNote(
                (int) ($_POST['appointment_id'] ?? 0),
                (string) ($_POST['body'] ?? ''),
                (int) $user['id'],
            );
            Flash::add('success', 'Staff note added.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_ADVISING_NOTE_FAILURE', 'advising.note');
        }
        redirect(url('advising'));
    }

    public function completeTask(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'advising.tasks.manage')) {
                throw new UserVisibleException('ADVISING_TASK_FORBIDDEN', 'Your role cannot complete advising tasks.');
            }
            (new AdvisingService())->completeTask((int) ($_POST['task_id'] ?? 0), (int) $user['id']);
            Flash::add('success', 'Advising task completed.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_ADVISING_TASK_FAILURE', 'advising.task');
        }
        redirect(url('advising'));
    }

    private function render(string $template, array $data): void
    {
        extract($data, EXTR_SKIP);
        require cpe_path('app/Modules/Advising/Views/' . $template . '.php');
    }
}
