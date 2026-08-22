INSERT INTO settings (key, value)
VALUES ('export_profile_custom_datasets', 'placement_totals,application_status_counts,placements_by_company')
ON CONFLICT(key) DO NOTHING;
