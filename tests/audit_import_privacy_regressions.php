<?php

declare(strict_types=1);

namespace App\Controllers {
    function redirect(string $path): never
    {
        throw new \AuditHomeRedirect($path);
    }
}

namespace {
    use App\Controllers\PortalController;
    use App\Core\Modules\ModuleLifecycleService;
    use App\Core\Portal;
    use App\Domain\PlacementService;
    use App\Domain\PrivacyService;
    use App\Import\CsvImporter;
    use App\Install\Installer;
    use App\Modules\Placement\Portability\PlacementPortabilityHandler;
    use App\Support\Auth;
    use App\Support\Database;

    final class AuditHomeRedirect extends RuntimeException
    {
    }

    function audit_import_same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    function audit_import_value(PDO $pdo, string $sql, array $params = []): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }

    function audit_import_csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }

    function audit_import_remove_tree(string $path): void
    {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) ? audit_import_remove_tree($child) : unlink($child);
        }
        rmdir($path);
    }

    $testRoot = sys_get_temp_dir() . '/cpe-audit-import-privacy-' . bin2hex(random_bytes(6));
    mkdir($testRoot, 0700, true);
    putenv('CPE_DB_DRIVER=sqlite');
    putenv('CPE_DATABASE_URL');
    putenv('CPE_DB_PATH=' . $testRoot . '/engine.sqlite');
    putenv('CPE_PRIVACY_SNAPSHOT_DIR=' . $testRoot . '/privacy');
    define('CPE_SKIP_HTTP_BOOTSTRAP', true);
    require __DIR__ . '/../app/bootstrap.php';

    try {
        (new Installer())->install([
            'college_name' => 'Synthetic audit regression college',
            'timezone' => 'UTC',
            'admin_name' => 'Synthetic Administrator',
            'admin_email' => 'audit-regressions@example.test',
            'admin_password' => 'audit-regression-password',
        ]);
        $pdo = Database::connection();
        $placement = new PlacementService($pdo);
        $importer = new CsvImporter($pdo);

        $privateId = $placement->saveCandidate(['external_id' => 'ERASE-001', 'name' => 'Confidential Candidate'], 1);
        $otherId = $placement->saveCandidate(['external_id' => 'KEEP-001', 'name' => 'Unrelated Candidate'], 1);
        $companyId = $placement->saveCompany(['code' => 'ERASECO', 'name' => 'Synthetic privacy company'], 1);
        $placement->saveApplication($privateId, $companyId, 'scheduled', null, 1);
        $applicationId = (int) audit_import_value($pdo, 'SELECT id FROM applications WHERE candidate_id = ?', [$privateId]);
        $placement->transition($applicationId, 'intransit', 1, 'admin', 'CONFIDENTIAL-ONLY movement');
        $placement->returnToIdle($applicationId, 1, 'admin', 'CONFIDENTIAL-ONLY correction reason', 'CONFIDENTIAL-ONLY correction note');
        $importer->candidateUnavailability(audit_import_csv(
            ['candidate_external_id', 'label', 'schedule_day', 'starts_at', 'ends_at', 'notes'],
            [
                ['ERASE-001', 'CONFIDENTIAL-ONLY first label', '1', '09:00', '10:00', 'CONFIDENTIAL-ONLY first note'],
                ['ERASE-001', 'CONFIDENTIAL-ONLY second label', '1', '09:00', '10:00', 'CONFIDENTIAL-ONLY second note'],
                ['KEEP-001', 'KEEP-OTHER-PRIVATE label', '1', '09:00', '10:00', 'KEEP-OTHER-PRIVATE note'],
            ],
        ));
        $firstWindowId = (int) audit_import_value($pdo, 'SELECT MIN(id) FROM candidate_unavailability_windows WHERE candidate_id = ?', [$privateId]);
        $pdo->prepare('UPDATE candidate_unavailability_windows SET label = ? WHERE candidate_id = ? AND label = ?')
            ->execute(['Unavailable period ' . $firstWindowId, $privateId, 'CONFIDENTIAL-ONLY second label']);
        $history = $pdo->query('SELECT id, public_id, transition_key, from_state_key, to_state_key, occurred_at FROM workflow_transition_events ORDER BY id')->fetchAll();
        (new PrivacyService($pdo))->anonymizeCandidate('ERASE-001', 1);
        audit_import_same($history, $pdo->query('SELECT id, public_id, transition_key, from_state_key, to_state_key, occurred_at FROM workflow_transition_events ORDER BY id')->fetchAll(), 'Erasure must preserve transition history');
        audit_import_same(2, (int) audit_import_value($pdo, 'SELECT COUNT(*) FROM candidate_unavailability_windows WHERE candidate_id = ?', [$privateId]), 'Overlapping availability rows must survive without label collisions');
        audit_import_same(0, (int) audit_import_value($pdo, 'SELECT COUNT(*) FROM workflow_transition_events WHERE application_id = ? AND (reason LIKE ? OR note LIKE ?)', [$applicationId, '%CONFIDENTIAL-ONLY%', '%CONFIDENTIAL-ONLY%']), 'Workflow reasons and notes must be redacted');
        audit_import_same('KEEP-OTHER-PRIVATE note', audit_import_value($pdo, 'SELECT notes FROM candidate_unavailability_windows WHERE candidate_id = ?', [$otherId]), 'Erasure must preserve unrelated candidate text');
        $export = json_encode((new PlacementPortabilityHandler($pdo))->export(), JSON_THROW_ON_ERROR);
        foreach (['CONFIDENTIAL-ONLY', 'Confidential Candidate', 'ERASE-001'] as $privateText) {
            audit_import_same(false, str_contains($export, $privateText), 'Portability export retained erased text');
        }

        $rollbackId = $placement->saveCandidate(['external_id' => 'ERASE-ROLLBACK', 'name' => 'Rollback Candidate'], 1);
        $importer->candidateUnavailability("candidate_external_id,label,schedule_day,starts_at,ends_at,notes\nERASE-ROLLBACK,Original label,1,09:00,10:00,Original note\n");
        $pdo->exec("CREATE TRIGGER audit_fail_privacy BEFORE UPDATE OF notes ON candidate_unavailability_windows WHEN OLD.candidate_id = {$rollbackId} BEGIN SELECT RAISE(ABORT, 'Synthetic privacy failure'); END");
        try {
            (new PrivacyService($pdo))->anonymizeCandidate('ERASE-ROLLBACK', 1);
            throw new RuntimeException('Expected linked-text erasure failure.');
        } catch (PDOException) {
        } finally {
            $pdo->exec('DROP TRIGGER audit_fail_privacy');
        }
        audit_import_same('ERASE-ROLLBACK', audit_import_value($pdo, 'SELECT external_id FROM candidates WHERE id = ?', [$rollbackId]), 'A linked-text failure must roll back candidate identity');
        audit_import_same('Original note', audit_import_value($pdo, 'SELECT notes FROM candidate_unavailability_windows WHERE candidate_id = ?', [$rollbackId]), 'A failed erasure must preserve linked text');
        echo "OK linked-text privacy erasure and rollback\n";

        $candidateName = "Doe, \"Jane\"\nCampus";
        $companyName = "+Research, \"Labs\"\nEast";
        $location = "Room \"A\", first floor\nNorth wing";
        $legacyCsv = audit_import_csv(
            ['external_id', 'name', 'program', 'current_location', 'company_code', 'company_name', 'slot', 'process_type', 'room', 'stat4'],
            [['CSV,001', $candidateName, 'MBA, Finance', $location, 'CSV,CO', $companyName, 'Day 1, Slot 2', 'Case, interview', 'Room "A", East', '4']],
        );
        audit_import_same(1, $importer->legacyWide($legacyCsv), 'Quoted legacy import row count');
        audit_import_same($candidateName, audit_import_value($pdo, 'SELECT name FROM candidates WHERE external_id = ?', ['CSV,001']), 'Legacy names must round-trip commas, quotes and newlines');
        audit_import_same('MBA, Finance', audit_import_value($pdo, 'SELECT program FROM candidates WHERE external_id = ?', ['CSV,001']), 'Legacy columns must not shift');
        audit_import_same($location, audit_import_value($pdo, 'SELECT current_location FROM candidates WHERE external_id = ?', ['CSV,001']), 'Legacy locations must round-trip multiline values');
        audit_import_same($companyName, audit_import_value($pdo, 'SELECT name FROM companies WHERE code = ?', ['CSV,CO']), 'Internal serialization must preserve literal formula-prefix text');
        audit_import_same('Room "A", East', audit_import_value($pdo, 'SELECT room FROM companies WHERE code = ?', ['CSV,CO']), 'Legacy company room must round-trip');
        audit_import_same('requested', audit_import_value($pdo, 'SELECT a.current_status FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE c.external_id = ?', ['CSV,001']), 'Legacy status mapping must be preserved');
        $pdo->beginTransaction();
        try {
            $importer->legacyWide("external_id,name,company_code,company_name,stat1\nCSV-ROLLBACK,Rollback candidate,CSVROLLBACK,Rollback company,1\nCSV-INVALID,Invalid candidate,,Invalid company,1\n");
            throw new RuntimeException('Expected invalid legacy company.');
        } catch (RuntimeException $failure) {
            audit_import_same('Company code and name are required.', $failure->getMessage(), 'Legacy failure must come from invalid input');
        } finally {
            $pdo->rollBack();
        }
        audit_import_same(0, (int) audit_import_value($pdo, 'SELECT COUNT(*) FROM candidates WHERE external_id IN (?, ?)', ['CSV-ROLLBACK', 'CSV-INVALID']), 'The controller transaction must roll back every legacy row on failure');
        echo "OK legacy CSV quoted-field serialization\n";

        $winner = $placement->saveCompany(['code' => 'IMPORTWIN', 'name' => 'Synthetic import winner'], 1);
        $competitor = $placement->saveCompany(['code' => 'IMPORTOTHER', 'name' => 'Synthetic import competitor'], 1);
        $candidate = $placement->saveCandidate(['external_id' => 'IMPORTPLACED', 'name' => 'Synthetic import candidate'], 1);
        $placement->saveApplication($candidate, $winner, 'sent', null, 1);
        $placement->saveApplication($candidate, $competitor, 'scheduled', null, 1);
        $shortlist = "candidate_external_id,company_code,status\nIMPORTPLACED,IMPORTWIN,placed\n";
        $offersBefore = (int) audit_import_value($pdo, "SELECT COUNT(*) FROM domain_event_outbox WHERE event_name = 'placement.offer.accepted'");
        $importer->shortlists($shortlist);
        audit_import_same($winner, (int) audit_import_value($pdo, 'SELECT placed_company_id FROM candidates WHERE id = ?', [$candidate]), 'Imported placed status must record the placement');
        audit_import_same('idle', audit_import_value($pdo, 'SELECT current_status FROM applications WHERE candidate_id = ? AND company_id = ?', [$candidate, $competitor]), 'Imported placement must close the competing application');
        audit_import_same(1, (int) audit_import_value($pdo, "SELECT COUNT(*) FROM placement_offers po JOIN placement_cycle_participants p ON p.id = po.participant_id WHERE p.legacy_candidate_id = ? AND po.offer_status = 'accepted'", [$candidate]), 'Imported placement must record one accepted offer');
        audit_import_same($offersBefore + 1, (int) audit_import_value($pdo, "SELECT COUNT(*) FROM domain_event_outbox WHERE event_name = 'placement.offer.accepted'"), 'Imported placement must emit one offer acceptance');
        $evidence = $pdo->query('SELECT id, current_status, aggregate_version FROM applications WHERE candidate_id = ' . $candidate . ' ORDER BY id')->fetchAll();
        $outboxCount = (int) audit_import_value($pdo, 'SELECT COUNT(*) FROM domain_event_outbox');
        $importer->shortlists($shortlist);
        audit_import_same($evidence, $pdo->query('SELECT id, current_status, aggregate_version FROM applications WHERE candidate_id = ' . $candidate . ' ORDER BY id')->fetchAll(), 'Repeating a placement import must preserve aggregate versions');
        audit_import_same($outboxCount, (int) audit_import_value($pdo, 'SELECT COUNT(*) FROM domain_event_outbox'), 'Repeating a placement import must emit no duplicate events');
        foreach ([
            "candidate_external_id,company_code,status\nIMPORTPLACED,IMPORTWIN,placed\nIMPORTPLACED,IMPORTOTHER,scheduled\n",
            "candidate_external_id,company_code,status\nIMPORTPLACED,IMPORTOTHER,scheduled\nIMPORTPLACED,IMPORTWIN,placed\n",
        ] as $mixedShortlist) {
            $importer->shortlists($mixedShortlist);
            $importer->shortlists($mixedShortlist);
            audit_import_same('idle', audit_import_value($pdo, 'SELECT current_status FROM applications WHERE candidate_id = ? AND company_id = ?', [$candidate, $competitor]), 'CSV ordering and repeated imports must not reopen a competing application');
            audit_import_same($offersBefore + 1, (int) audit_import_value($pdo, "SELECT COUNT(*) FROM domain_event_outbox WHERE event_name = 'placement.offer.accepted'"), 'Repeated mixed imports must not duplicate offer acceptance');
        }

        $legacyCandidate = $placement->saveCandidate(['external_id' => 'LEGACYPLACED', 'name' => 'Synthetic legacy candidate'], 1);
        $placement->saveApplication($legacyCandidate, $competitor, 'scheduled', null, 1);
        $legacyPlacement = "external_id,name,company_code,company_name,stat11,stat1\nLEGACYPLACED,Synthetic legacy candidate,IMPORTWIN,Synthetic import winner,11,\nLEGACYPLACED,Synthetic legacy candidate,IMPORTOTHER,Synthetic import competitor,,1\n";
        $legacyOffersBefore = (int) audit_import_value($pdo, "SELECT COUNT(*) FROM domain_event_outbox WHERE event_name = 'placement.offer.accepted'");
        $importer->legacyWide($legacyPlacement);
        audit_import_same('IMPORTWIN', audit_import_value($pdo, 'SELECT current_location FROM candidates WHERE id = ?', [$legacyCandidate]), 'Initial legacy placement must record the accepted company location');
        $importer->legacyWide($legacyPlacement);
        audit_import_same('IMPORTWIN', audit_import_value($pdo, 'SELECT current_location FROM candidates WHERE id = ?', [$legacyCandidate]), 'Repeating a legacy placement import must preserve the accepted company location');
        audit_import_same($legacyOffersBefore + 1, (int) audit_import_value($pdo, "SELECT COUNT(*) FROM domain_event_outbox WHERE event_name = 'placement.offer.accepted'"), 'Restoring an accepted placement location must not publish another offer acceptance');
        audit_import_same($winner, (int) audit_import_value($pdo, 'SELECT placed_company_id FROM candidates WHERE id = ?', [$legacyCandidate]), 'Legacy placed status must use placement acceptance effects');
        audit_import_same('idle', audit_import_value($pdo, 'SELECT current_status FROM applications WHERE candidate_id = ? AND company_id = ?', [$legacyCandidate, $competitor]), 'Legacy placement must close the competing application');

        $failureCandidate = $placement->saveCandidate(['external_id' => 'IMPORTFAIL', 'name' => 'Synthetic rollback candidate'], 1);
        $placement->saveApplication($failureCandidate, $winner, 'sent', null, 1);
        $placement->saveApplication($failureCandidate, $competitor, 'scheduled', null, 1);
        $beforeFailure = $pdo->query('SELECT * FROM applications WHERE candidate_id = ' . $failureCandidate . ' ORDER BY id')->fetchAll();
        $outboxCount = (int) audit_import_value($pdo, 'SELECT COUNT(*) FROM domain_event_outbox');
        $pdo->exec("CREATE TRIGGER audit_fail_offer BEFORE INSERT ON domain_event_outbox WHEN NEW.event_name = 'placement.offer.accepted' BEGIN SELECT RAISE(ABORT, 'Synthetic offer failure'); END");
        try {
            $importer->shortlists("candidate_external_id,company_code,status\nIMPORTFAIL,IMPORTWIN,placed\n");
            throw new RuntimeException('Expected imported offer publication failure.');
        } catch (PDOException) {
        } finally {
            $pdo->exec('DROP TRIGGER audit_fail_offer');
        }
        audit_import_same($beforeFailure, $pdo->query('SELECT * FROM applications WHERE candidate_id = ' . $failureCandidate . ' ORDER BY id')->fetchAll(), 'Offer publication failure must roll back imported statuses and competing cleanup');
        audit_import_same(null, audit_import_value($pdo, 'SELECT placed_company_id FROM candidates WHERE id = ?', [$failureCandidate]), 'Offer publication failure must roll back placement identity');
        audit_import_same($outboxCount, (int) audit_import_value($pdo, 'SELECT COUNT(*) FROM domain_event_outbox'), 'Offer publication failure must roll back transition events');
        echo "OK shortlist and legacy placement effects, duplicate imports and rollback\n";

        (new ModuleLifecycleService($pdo))->enable('advising', 1);
        Portal::reset();
        $advisorId = Auth::createUser('Synthetic Advisor', 'audit-advisor@example.test', 'audit-password123', 'advisor');
        Auth::loginById($advisorId);
        ob_start();
        try {
            (new PortalController())->home();
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        audit_import_same(true, str_contains($html, '<h1>Career Services Portal</h1>'), 'Advisor home must render an accessible portal');
        audit_import_same(true, str_contains($html, '?r=advising'), 'Advisor home must expose Career Advising navigation');
        Auth::loginById(1);
        try {
            (new PortalController())->home();
            throw new RuntimeException('Expected administrator board home.');
        } catch (AuditHomeRedirect $redirect) {
            audit_import_same('/?r=board', $redirect->getMessage(), 'Placement users must keep their board home');
        }
        echo "OK capability-aware advisor home\n";
    } finally {
        Portal::reset();
        Database::reset();
        audit_import_remove_tree($testRoot);
    }
}
