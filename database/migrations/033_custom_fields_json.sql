ALTER TABLE candidates ADD COLUMN custom_fields_json TEXT NOT NULL DEFAULT '{}';
ALTER TABLE companies ADD COLUMN custom_fields_json TEXT NOT NULL DEFAULT '{}';
