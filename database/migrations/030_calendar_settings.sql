INSERT INTO settings (key, value)
VALUES
    ('calendar_non_operating_weekdays', ''),
    ('calendar_non_operating_dates', '')
ON CONFLICT(key) DO NOTHING;
