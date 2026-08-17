<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\DemoDataService;
use App\Domain\ReadinessService;
use App\Domain\Workflow;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\Database;
use App\Support\Flash;

final class SystemController
{
    public function show(): void
    {
        Auth::requireCapability('placement.system.view', 'Your role cannot open System.');
        $pdo = Database::connection();
        view('system', [
            'dbPath' => Database::path(),
            'phpVersion' => PHP_VERSION,
            'databaseDriver' => Database::driver(),
            'databaseVersion' => Database::serverVersion(),
            'workflowErrors' => (new Workflow())->validate(),
            'readiness' => (new ReadinessService($pdo))->snapshot(),
            'demoData' => (new DemoDataService($pdo))->counts(),
            'audit' => $pdo->query('SELECT a.*, u.name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id ORDER BY a.id DESC LIMIT 20')->fetchAll(),
        ]);
    }

    public function clearDemoData(): void
    {
        $user = Auth::requireCapability('placement.demo.clear', 'Only administrators can clear dummy data.');
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (($_POST['confirm'] ?? '') !== 'clear-demo-data') {
                throw new \RuntimeException('Confirm the dummy-data cleanup before continuing.');
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
            Flash::add('error', $e->getMessage());
        }
        redirect(url('system'));
    }
}
