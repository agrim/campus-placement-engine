INSERT INTO settings (key, value)
SELECT 'cycle_name', COALESCE(NULLIF((SELECT value FROM settings WHERE key = 'college_name'), ''), 'Campus') || ' Placement Cycle'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key = 'cycle_name');

INSERT INTO settings (key, value)
SELECT 'cycle_type', 'final'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key = 'cycle_type');

INSERT INTO settings (key, value)
SELECT 'cycle_start_date', ''
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key = 'cycle_start_date');

INSERT INTO settings (key, value)
SELECT 'cycle_end_date', ''
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key = 'cycle_end_date');
