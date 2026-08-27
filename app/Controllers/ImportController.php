<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\UserVisibleException;
use App\Domain\Workflow;
use App\Import\CsvImporter;
use App\Import\ImportRollbackService;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\ControllerFailure;
use App\Support\Database;
use App\Support\Flash;

final class ImportController
{
    public function show(): void
    {
        Auth::requireCapability('placement.import.manage', 'Your role cannot open Import.');
        view('import', [
            'recentImports' => (new ImportRollbackService())->recent(),
        ]);
    }

    public function run(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.import.manage')) {
                throw new UserVisibleException('IMPORT_FORBIDDEN', 'Your role cannot import data.');
            }
            $type = (string) ($_POST['type'] ?? '');
            $csv = trim((string) ($_POST['csv'] ?? ''));
            if ($csv === '') {
                throw new UserVisibleException('IMPORT_CSV_REQUIRED', 'Paste CSV before importing.');
            }
            $action = (string) ($_POST['action'] ?? 'import');
            $pdo = Database::connection();
            $importer = new CsvImporter($pdo);
            $workflow = new Workflow();
            $statuses = array_keys($workflow->statuses());
            if ($action === 'preview') {
                $report = $importer->preview($type, $csv, $statuses);
                view('import', [
                    'report' => $report,
                    'selectedType' => $type,
                    'csv' => $csv,
                    'recentImports' => (new ImportRollbackService())->recent(),
                ]);
                return;
            }

            $report = $importer->preview($type, $csv, $statuses);
            if (!$report['valid']) {
                view('import', [
                    'report' => $report,
                    'selectedType' => $type,
                    'csv' => $csv,
                    'recentImports' => (new ImportRollbackService())->recent(),
                ]);
                return;
            }
            $rollback = (new ImportRollbackService())->createSnapshot($type, (int) $user['id'], $report);
            $pdo->beginTransaction();
            try {
                $count = match ($type) {
                    'candidates' => $importer->candidates($csv),
                    'companies' => $importer->companies($csv),
                    'rounds' => $importer->companyRounds($csv),
                    'schedules' => $importer->roundSchedules($csv),
                    'panelists' => $importer->roundPanelists($csv),
                    'assignments' => $importer->slotAssignments($csv),
                    'unavailability' => $importer->candidateUnavailability($csv),
                    'shortlists' => $importer->shortlists($csv, $statuses),
                    'legacy' => $importer->legacyWide($csv, $statuses),
                    default => throw new UserVisibleException('IMPORT_TYPE_INVALID', 'Unknown import type.'),
                };
                Auth::audit((int) $user['id'], 'import', $type, null, "Imported {$count} rows; rollback snapshot {$rollback['id']}");
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            Flash::add('success', "Imported {$count} {$type} rows. Rollback snapshot: {$rollback['id']}.");
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_IMPORT_RUN_FAILURE', 'import.run');
        }
        redirect(url('import'));
    }

    public function rollback(): void
    {
        $user = Auth::requireUser();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            if (!Auth::hasCapability($user, 'placement.import.rollback')) {
                throw new UserVisibleException('IMPORT_ROLLBACK_FORBIDDEN', 'Your role cannot restore an import rollback snapshot.');
            }
            $id = (string) ($_POST['id'] ?? '');
            $manifest = (new ImportRollbackService())->restore($id);
            Auth::audit((int) $user['id'], 'import.rollback', 'import', null, 'Restored import rollback snapshot ' . $manifest['id']);
            Flash::add('success', 'Restored import rollback snapshot ' . $manifest['id'] . '. A safety backup was created.');
        } catch (\Throwable $e) {
            ControllerFailure::flash($e, 'CPE_IMPORT_ROLLBACK_FAILURE', 'import.rollback');
        }
        redirect(url('import'));
    }
}
