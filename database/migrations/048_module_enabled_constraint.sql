CREATE TRIGGER IF NOT EXISTS module_installations_enabled_insert
BEFORE INSERT ON module_installations
WHEN typeof(NEW.enabled) <> 'integer' OR NEW.enabled NOT IN (0, 1)
BEGIN
    SELECT RAISE(ABORT, 'module_installations.enabled must be 0 or 1');
END;

CREATE TRIGGER IF NOT EXISTS module_installations_enabled_update
BEFORE UPDATE OF enabled ON module_installations
WHEN typeof(NEW.enabled) <> 'integer' OR NEW.enabled NOT IN (0, 1)
BEGIN
    SELECT RAISE(ABORT, 'module_installations.enabled must be 0 or 1');
END;
