INSERT INTO settings (key, value)
VALUES
    ('terminology_candidate_label', 'Candidate'),
    ('terminology_candidates_label', 'Candidates'),
    ('terminology_company_label', 'Company'),
    ('terminology_companies_label', 'Companies')
ON CONFLICT(key) DO NOTHING;
