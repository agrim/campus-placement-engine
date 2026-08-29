ALTER TABLE module_installations
    ADD CONSTRAINT module_installations_enabled_check
    CHECK (enabled IN (0, 1)) NOT VALID;
