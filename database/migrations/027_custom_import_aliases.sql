INSERT INTO settings (key, value)
VALUES ('import_header_aliases_json', '')
ON CONFLICT(key) DO NOTHING;
