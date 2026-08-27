-- Historical delivery failures may contain raw exception text from versions
-- before opaque incident references. Preserve rows and retry state while
-- replacing only that unreviewed detail.
UPDATE notification_deliveries
SET last_error = 'CPE_LEGACY_ERROR_REDACTED Reference: inc_unavailable'
WHERE last_error <> '';

UPDATE domain_event_outbox
SET last_error = 'CPE_LEGACY_ERROR_REDACTED Reference: inc_unavailable'
WHERE last_error <> '';
