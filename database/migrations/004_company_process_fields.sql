ALTER TABLE companies ADD COLUMN process_type TEXT NOT NULL DEFAULT '';
ALTER TABLE companies ADD COLUMN room TEXT NOT NULL DEFAULT '';
ALTER TABLE companies ADD COLUMN tracker_name TEXT NOT NULL DEFAULT '';
ALTER TABLE companies ADD COLUMN max_active INTEGER NOT NULL DEFAULT 0;
ALTER TABLE companies ADD COLUMN process_notes TEXT NOT NULL DEFAULT '';

CREATE INDEX IF NOT EXISTS idx_companies_process_type ON companies(process_type);
