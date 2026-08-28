ALTER TABLE idempotency_keys ADD COLUMN request_hash TEXT NULL;
ALTER TABLE idempotency_keys ADD COLUMN result_json TEXT NULL;
