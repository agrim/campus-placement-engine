INSERT INTO settings (key, value)
VALUES ('board_refresh_seconds', '45')
ON CONFLICT(key) DO NOTHING;
