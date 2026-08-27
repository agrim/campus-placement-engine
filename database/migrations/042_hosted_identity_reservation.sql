-- An uninstalled database has no institution identity yet. Give its bootstrap
-- row an explicit one-time marker so hosted installation can compare-and-set
-- the final tenant identity without ever rebinding an installed data plane.
UPDATE institutions
SET public_id = 'unbound_' || lower(hex(randomblob(16))),
    updated_at = datetime('now')
WHERE slug = 'default'
  AND substr(public_id, 1, 5) = 'inst_'
  AND NOT EXISTS (
      SELECT 1 FROM settings WHERE key = 'installed_at' AND value <> ''
  );
