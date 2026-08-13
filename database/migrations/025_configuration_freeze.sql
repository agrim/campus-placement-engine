INSERT INTO settings (key, value)
VALUES ('configuration_freeze', '0')
ON CONFLICT(key) DO NOTHING;
