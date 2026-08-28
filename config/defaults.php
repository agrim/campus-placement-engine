<?php

return [
    'app' => [
        'name' => 'Campus Placement Engine',
        'version' => '0.1.0-alpha.3',
    ],
    'database' => [
        'path' => __DIR__ . '/../data/app.sqlite',
    ],
    'imports' => [
        'max_bytes' => 5000000,
        'max_rows' => 10000,
    ],
    'security' => [
        'session_name' => 'cpe_session',
        'session_samesite' => 'Lax',
        'session_secure' => 'auto',
    ],
    'settings' => [
        'college_name' => 'Demo College',
        'site_name' => 'Campus Placement Engine',
        'site_tagline' => '',
        'public_placements_title' => 'Public Placements',
        'candidate_status_title' => '',
        'timezone' => 'Asia/Kolkata',
        'cycle_name' => 'Demo Placement Cycle',
        'cycle_type' => 'final',
        'cycle_start_date' => '',
        'cycle_end_date' => '',
        'calendar_non_operating_weekdays' => '',
        'calendar_non_operating_dates' => '',
        'audit_request_metadata' => 'none',
        'configuration_freeze' => '0',
        'terminology_candidate_label' => 'Candidate',
        'terminology_candidates_label' => 'Candidates',
        'terminology_company_label' => 'Company',
        'terminology_companies_label' => 'Companies',
        'board_refresh_seconds' => '45',
        'board_card_fields' => 'candidate_id,program,tags,company,process,tracker,active_cap,rounds,schedule,slot,panel,route,location,accommodation,waitlist',
        'export_profile_custom_datasets' => 'placement_totals,application_status_counts,placements_by_company',
        'import_header_aliases_json' => '',
    ],
    'roles' => [
        'admin' => 'Administrator',
        'control' => 'Control Room',
        'placement' => 'Placement Office',
        'company' => 'Company Tracker',
        'mobile' => 'Mobile Tracker',
        'floor' => 'Floor Coordinator',
        'auditor' => 'Read-only Auditor',
        'advisor' => 'Career Advisor',
    ],
];
