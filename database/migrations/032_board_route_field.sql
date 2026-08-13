INSERT OR IGNORE INTO settings (key, value)
VALUES ('board_card_fields', 'candidate_id,program,tags,company,process,tracker,active_cap,rounds,schedule,slot,panel,route,location,accommodation,waitlist');

UPDATE settings
SET value = 'candidate_id,program,tags,company,process,tracker,active_cap,rounds,schedule,slot,panel,route,location,accommodation,waitlist'
WHERE key = 'board_card_fields'
  AND value = 'candidate_id,program,tags,company,process,tracker,active_cap,rounds,schedule,slot,panel,location,accommodation,waitlist';

UPDATE settings
SET value = 'candidate_id,program,company,process,tracker,active_cap,rounds,schedule,slot,panel,route,location,accommodation,waitlist'
WHERE key = 'board_card_fields'
  AND value = 'candidate_id,program,company,process,tracker,active_cap,rounds,schedule,slot,panel,location,accommodation,waitlist';
