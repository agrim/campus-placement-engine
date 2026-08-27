-- Existing uninstalled PostgreSQL fixtures may already have a bootstrap
-- institution row. Mark it as explicitly unbound before hosted installation.
UPDATE institutions
SET public_id = 'unbound_' || md5(random()::text || clock_timestamp()::text || id::text),
    updated_at = CURRENT_TIMESTAMP::text
WHERE slug = 'default'
  AND LEFT(public_id, 5) = 'inst_'
  AND NOT EXISTS (
      SELECT 1 FROM settings WHERE key = 'installed_at' AND value <> ''
  );
