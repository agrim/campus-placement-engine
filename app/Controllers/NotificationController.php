<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\UserVisibleException;
use App\Modules\Placement\Application\PlacementService;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\ControllerFailure;
use App\Support\Flash;

final class NotificationController
{
    public function show(): void
    {
        $user = Auth::requireCapability('placement.notifications.view', 'Your role cannot open placement notifications.');
        $service = new PlacementService();
        view('notifications', [
            'notifications' => $service->notificationsForUser($user),
            'openCount' => $service->notificationCountForUser($user),
            'user' => $user,
        ]);
    }

    public function acknowledge(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.notifications.manage')) {
                throw new UserVisibleException('NOTIFICATION_ACK_FORBIDDEN', 'Auditors cannot acknowledge notifications.');
            }
            (new PlacementService())->acknowledgeNotification((int) ($_POST['notification_id'] ?? 0), $user);
            Flash::add('success', 'Notification acknowledged.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_NOTIFICATION_ACK_FAILURE', 'notification.acknowledge');
        }
        redirect(url('notifications'));
    }
}
