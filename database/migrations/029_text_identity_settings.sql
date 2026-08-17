INSERT INTO settings (key, value)
VALUES
    ('site_name', 'Campus Placement Engine'),
    ('site_tagline', ''),
    ('public_placements_title', 'Public Placements'),
    ('candidate_status_title', '')
ON CONFLICT(key) DO NOTHING;
