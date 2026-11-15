<?php

declare(strict_types=1);

$databasePath = sys_get_temp_dir() . '/cpe-placement-regressions-' . bin2hex(random_bytes(6)) . '.sqlite';
putenv('CPE_DB_DRIVER=sqlite');
putenv('CPE_DATABASE_URL');
putenv('CPE_DB_PATH=' . $databasePath);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Http\UserVisibleException;
use App\Domain\PlacementService;
use App\Import\CsvImporter;
use App\Install\Installer;
use App\Modules\Placement\Workflow\WorkflowPublisher;
use App\Modules\Placement\Workflow\WorkflowRepository;
use App\Support\Database;

function placement_regression_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function placement_regression_rows(array $groups): array
{
    return array_merge([], ...array_column(array_values($groups), 'applications'));
}

try {
    (new Installer())->install([
        'college_name' => 'Placement regression fixtures',
        'admin_name' => 'Fixture Admin',
        'admin_email' => 'fixture@example.test',
        'admin_password' => 'fixture-password-123',
        'seed_demo' => '0',
    ]);
    $pdo = Database::connection();
    $service = new PlacementService($pdo);
    $firstCompany = $service->saveCompany(['code' => 'FIRST', 'name' => 'First company'], 1);
    $secondCompany = $service->saveCompany(['code' => 'SECOND', 'name' => 'Second company'], 1);
    $candidate = $service->saveCandidate(['external_id' => 'SCOPE', 'name' => 'Scope fixture'], 1);
    $service->saveApplication($candidate, $firstCompany, 'scheduled', null, 1);
    $service->saveApplication($candidate, $secondCompany, 'scheduled', null, 1);

    foreach (['', '   ', 'MISSING'] as $scope) {
        $user = ['id' => 1, 'role' => 'company', 'scope_type' => 'company', 'scope_value' => $scope, 'active' => 1];
        placement_regression_same([], placement_regression_rows($service->dashboard($user)), 'Missing or invalid company scope must expose no applications');
        placement_regression_same([], $service->boardFilterOptions($user)['companies'], 'Missing company scope must expose no company filter records');
    }
    $scopedUser = ['id' => 1, 'role' => 'company', 'scope_type' => 'company', 'scope_value' => ' first ', 'active' => 1];
    placement_regression_same(['FIRST'], array_column(placement_regression_rows($service->dashboard($scopedUser)), 'company_code'), 'Valid normalized scope should expose only its company');
    $unscopedRole = ['id' => 1, 'role' => 'control', 'scope_type' => 'company', 'scope_value' => '', 'active' => 1];
    placement_regression_same([], placement_regression_rows($service->dashboard($unscopedRole)), 'Scope restriction must also cover non-company roles with company scope');
    echo "PASS company board scope fails closed\n";

    $placedCandidate = $service->saveCandidate(['external_id' => 'PLACED', 'name' => 'Placed fixture'], 1);
    $service->saveApplication($placedCandidate, $secondCompany, 'intransit', null, 1);
    $service->saveApplication($placedCandidate, $firstCompany, 'placed', null, 1);
    placement_regression_same($firstCompany, (int) $pdo->query("SELECT placed_company_id FROM candidates WHERE id = {$placedCandidate}")->fetchColumn(), 'Maintenance must record the accepted company');
    placement_regression_same('FIRST', $pdo->query("SELECT current_location FROM candidates WHERE id = {$placedCandidate}")->fetchColumn(), 'Maintenance must update accepted-offer location');
    placement_regression_same('idle', $pdo->query("SELECT current_status FROM applications WHERE candidate_id = {$placedCandidate} AND company_id = {$secondCompany}")->fetchColumn(), 'Maintenance must clear competing active applications');
    placement_regression_same(1, $service->placementSummary()['totals']['placed_candidates'], 'Summary must count maintenance placement');
    placement_regression_same(1, (int) $service->publicPlacements()[0]['placed_count'], 'Public totals must include maintenance placement');
    $offerEvents = (int) $pdo->query("SELECT COUNT(*) FROM domain_event_outbox WHERE event_name = 'placement.offer.accepted'")->fetchColumn();
    $service->saveApplication($placedCandidate, $firstCompany, 'placed', null, 1);
    placement_regression_same($offerEvents, (int) $pdo->query("SELECT COUNT(*) FROM domain_event_outbox WHERE event_name = 'placement.offer.accepted'")->fetchColumn(), 'Repeated placed maintenance must not emit another accepted offer');
    try {
        $service->saveApplication($placedCandidate, $secondCompany, 'placed', null, 1);
        throw new RuntimeException('Disabled offer upgrade unexpectedly succeeded');
    } catch (UserVisibleException $failure) {
        placement_regression_same('idle', $pdo->query("SELECT current_status FROM applications WHERE candidate_id = {$placedCandidate} AND company_id = {$secondCompany}")->fetchColumn(), 'Rejected placement upgrade must roll back its status change');
    }
    $importedCandidate = $service->saveCandidate(['external_id' => 'IMPORTED', 'name' => 'Imported placement fixture'], 1);
    $service->saveApplication($importedCandidate, $secondCompany, 'scheduled', null, 1);
    $placedImport = "candidate_external_id,company_code,status\nIMPORTED,FIRST,placed\nIMPORTED,SECOND,scheduled\n";
    (new CsvImporter($pdo))->shortlists($placedImport);
    placement_regression_same($firstCompany, (int) $pdo->query("SELECT placed_company_id FROM candidates WHERE id = {$importedCandidate}")->fetchColumn(), 'Shortlist import must use the same accepted-offer effects');
    placement_regression_same('idle', $pdo->query("SELECT current_status FROM applications WHERE candidate_id = {$importedCandidate} AND company_id = {$secondCompany}")->fetchColumn(), 'Shortlist placement must clear competing applications');
    $importedOfferEvents = (int) $pdo->query("SELECT COUNT(*) FROM domain_event_outbox WHERE event_name = 'placement.offer.accepted'")->fetchColumn();
    (new CsvImporter($pdo))->shortlists($placedImport);
    placement_regression_same('idle', $pdo->query("SELECT current_status FROM applications WHERE candidate_id = {$importedCandidate} AND company_id = {$secondCompany}")->fetchColumn(), 'Repeated import must clear later competing rows even when the offer was already accepted');
    placement_regression_same($importedOfferEvents, (int) $pdo->query("SELECT COUNT(*) FROM domain_event_outbox WHERE event_name = 'placement.offer.accepted'")->fetchColumn(), 'Repeated import cleanup must not emit duplicate offer acceptance');
    echo "PASS maintenance and import share placement effects\n";

    $planningCompany = $service->saveCompany(['code' => 'PLAN', 'name' => 'Planning fixtures'], 1);
    $round = $service->saveCompanyRound(['company_id' => $planningCompany, 'label' => 'Interview'], 1);
    $service->saveRoundSchedule(['round_id' => $round, 'room' => 'Planning room', 'starts_at' => '10:00', 'ends_at' => '11:00', 'capacity' => 1], 1);
    $eligible = $service->saveCandidate(['external_id' => 'PLAN-ELIGIBLE', 'name' => 'Eligible planning fixture'], 1);
    $optedOut = $service->saveCandidate(['external_id' => 'PLAN-OUT', 'name' => 'Opted out planning fixture', 'opted_out' => '1'], 1);
    $anonymized = $service->saveCandidate(['external_id' => 'PLAN-ANON', 'name' => 'Anonymized planning fixture'], 1);
    $pdo->exec("UPDATE candidates SET anonymized_at = '2026-01-01 00:00:00' WHERE id = {$anonymized}");
    foreach ([$optedOut, $anonymized, $eligible] as $planningCandidate) {
        $service->saveApplication($planningCandidate, $planningCompany, 'scheduled', null, 1);
    }
    foreach ([$service->slotAssignmentSuggestions('PLAN'), $service->optimizedSlotAssignmentSuggestions('PLAN')] as $suggestions) {
        placement_regression_same([$eligible], array_column($suggestions, 'candidate_id'), 'Both planners must reserve capacity for eligible candidates only');
    }
    $assigned = $service->applySlotAssignmentSuggestions('PLAN');
    placement_regression_same([$eligible], array_column($assigned['assigned'], 'candidate_id'), 'Applying suggestions must not assign excluded candidates');
    echo "PASS opted-out and anonymized candidates do not consume planned capacity\n";

    $dateCompany = $service->saveCompany(['code' => 'DATES', 'name' => 'Date fixtures'], 1);
    $dateCandidate = $service->saveCandidate(['external_id' => 'DATES', 'name' => 'Mixed day fixture'], 1);
    $service->saveApplication($dateCandidate, $dateCompany, 'scheduled', null, 1);
    $dateRound = $service->saveCompanyRound(['company_id' => $dateCompany, 'label' => 'Interview'], 1);
    $dateSchedule = $service->saveRoundSchedule(['round_id' => $dateRound, 'room' => 'Date room', 'schedule_day' => '2026-07-01', 'starts_at' => '10:00', 'ends_at' => '11:00', 'capacity' => 1], 1);
    $pdo->exec("UPDATE settings SET value = '2026-07-01' WHERE key = 'cycle_start_date'");
    (new CsvImporter($pdo))->candidateUnavailability("candidate_external_id,schedule_day,starts_at,ends_at,label\nDATES,1,10:00,11:00,Unavailable\n");
    foreach ([$service->slotAssignmentSuggestions('DATES'), $service->optimizedSlotAssignmentSuggestions('DATES')] as $suggestions) {
        placement_regression_same(null, $suggestions[0]['round_schedule_id'], 'Numeric unavailability must block the equivalent ISO day');
    }
    $pdo->exec("UPDATE round_schedules SET schedule_day = '1' WHERE id = {$dateSchedule}");
    $pdo->exec("UPDATE candidate_unavailability_windows SET schedule_day = '2026-07-01' WHERE candidate_id = {$dateCandidate}");
    placement_regression_same(null, $service->slotAssignmentSuggestions('DATES')[0]['round_schedule_id'], 'ISO unavailability must also block the equivalent numeric day');
    $pdo->exec("UPDATE round_schedules SET schedule_day = '2' WHERE id = {$dateSchedule}");
    placement_regression_same($dateSchedule, $service->slotAssignmentSuggestions('DATES')[0]['round_schedule_id'], 'Numeric day two must be the following date rather than day one');
    $pdo->exec("UPDATE candidate_unavailability_windows SET schedule_day = '2026-07-02' WHERE candidate_id = {$dateCandidate}");
    placement_regression_same(null, $service->slotAssignmentSuggestions('DATES')[0]['round_schedule_id'], 'Numeric day two must match the second cycle date');
    echo "PASS numeric and ISO days share conflict coordinates\n";

    $pinnedCandidate = $service->saveCandidate(['external_id' => 'PINNED', 'name' => 'Pinned workflow fixture'], 1);
    $service->saveApplication($pinnedCandidate, $firstCompany, 'intransit', null, 1);
    $pinnedApplication = $pdo->query("SELECT id, workflow_version_id FROM applications WHERE candidate_id = {$pinnedCandidate}")->fetch();
    $repository = new WorkflowRepository($pdo);
    $original = $repository->workflowForCurrentCycle();
    $newVersion = (new WorkflowPublisher($pdo))->publish('default', [
        'name' => 'Reduced workflow',
        'initial_state_key' => 'scheduled',
        'states' => ['scheduled' => $original['states']['scheduled'], 'placed' => $original['states']['placed']],
        'transitions' => [[
            'key' => 'place', 'label' => 'Place', 'from' => 'scheduled', 'to' => 'placed',
            'roles' => ['admin'], 'guards' => [], 'effects' => [], 'is_correction' => false,
        ]],
    ], 'admin', 1, true);
    $currentService = new PlacementService($pdo);
    $groups = $currentService->dashboard(null, ['status' => 'intransit']);
    placement_regression_same([(int) $pinnedApplication['id']], array_column(placement_regression_rows($groups), 'id'), 'Pinned in-use state must remain visible and filterable');
    placement_regression_same($original['states']['intransit']['label'], $groups['intransit']['status']['label'], 'Retained board column must use published pinned state metadata');
    placement_regression_same(true, isset($currentService->boardFilterOptions()['statuses']['intransit']), 'Filters must include retained pinned state');
    placement_regression_same((int) $pinnedApplication['workflow_version_id'], (int) $pdo->query('SELECT workflow_version_id FROM applications WHERE id = ' . (int) $pinnedApplication['id'])->fetchColumn(), 'Board read must not migrate a pinned application');
    $companyRows = placement_regression_rows($currentService->dashboard($scopedUser, ['status' => 'intransit']));
    placement_regression_same(['FIRST'], array_unique(array_column($companyRows, 'company_code')), 'Retained pinned states must still respect company scope');
    echo "PASS published workflow changes preserve pinned board applications\n";
} finally {
    Database::reset();
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
