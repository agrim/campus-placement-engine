ALTER TABLE webhook_subscriptions
ADD COLUMN validation_generation INTEGER NOT NULL DEFAULT 0 CHECK (validation_generation >= 0);

-- An interrupted pre-upgrade attempt cannot prove which secret was validated.
UPDATE webhook_subscriptions SET last_validated_at = NULL
WHERE lifecycle_state NOT IN ('active', 'degraded');
