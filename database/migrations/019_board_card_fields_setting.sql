INSERT INTO settings (key, value)
VALUES ('board_card_fields', 'candidate_id,program,company,process,tracker,active_cap,rounds,schedule,slot,panel,route,location,accommodation,waitlist')
ON CONFLICT(key) DO NOTHING;
