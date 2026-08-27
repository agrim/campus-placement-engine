<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\UserVisibleException;
use App\Domain\DemoDataService;
use App\Domain\ReadinessService;
use App\Domain\Workflow;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\ControllerFailure;
use App\Support\Database;
use App\Support\Flash;

final class SystemController
{
    public function show(): void
    {
        Auth::requireCapability('placement.system.view', 'Your role cannot open System.');
        $pdo = Database::connection();
        view('system', [
            'phpVersion' => PHP_VERSION,
            'databaseDescription' => 'Configured relational database',
            'workflowErrors' => (new Workflow())->validate(),
            'readiness' => (new ReadinessService($pdo))->snapshot(),
            'demoData' => (new DemoDataService($pdo))->counts(),
            'audit' => $pdo->query("SELECT a.id, a.actor_user_id, a.action, a.subject_type, a.subject_id,
                                           'Audit event recorded.' AS detail, '' AS ip_address, '' AS user_agent,
                                           a.created_at, u.name AS actor_name
                                    FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
                                    ORDER BY a.id DESC LIMIT 20")->fetchAll(),
        ]);
    }

    public function clearDemoData(): void
    {
        $user = Auth::requireCapability('placement.demo.clear', 'Only administrators can clear dummy data.');
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (($_POST['confirm'] ?? '') !== 'clear-demo-data') {
                throw new UserVisibleException('DEMO_CLEAR_CONFIRMATION_REQUIRED', 'Confirm the dummy-data cleanup before continuing.');
            }
            $result = (new DemoDataService(Database::connection()))->clear((int) $user['id']);
            $deleted = $result['deleted'];
            Flash::add(
                'success',
                'Dummy data cleared: ' .
                (int) ($deleted['candidates'] ?? 0) . ' candidates, ' .
                (int) ($deleted['companies'] ?? 0) . ' companies, ' .
                (int) ($deleted['applications'] ?? 0) . ' applications, and ' .
                (int) ($deleted['demo_users'] ?? 0) . ' demo users removed.'
            );
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_DEMO_CLEAR_FAILURE', 'system.clear_demo_data');
        }
        redirect(url('system'));
    }
}
