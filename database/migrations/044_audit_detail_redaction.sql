-- Historical audit detail and request metadata accepted free-form caller and
-- request text. Preserve the event identity and chronology while removing only
-- fields that could contain private or secret material.
UPDATE audit_logs
SET detail = 'Legacy audit detail redacted.',
    ip_address = '',
    user_agent = '';
