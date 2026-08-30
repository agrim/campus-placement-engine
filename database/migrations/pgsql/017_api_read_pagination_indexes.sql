CREATE INDEX IF NOT EXISTS idx_placement_opportunities_api_updated
    ON placement_opportunities(updated_at, public_id);

CREATE INDEX IF NOT EXISTS idx_applications_api_updated
    ON applications(updated_at, public_id);
