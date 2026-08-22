# Notifications

Notifications are in-app by default. This keeps the v1 runtime small: no queue
worker, mail server, SMS provider, or Node service is required.

Institutions that want external delivery can enable optional channels:

- `file` writes JSONL rows to a local file for another tool to pick up.
- `webhook` posts the same JSON payload to `CPE_NOTIFICATION_WEBHOOK_URL`.
- `email` sends plain-text notices through PHP `mail()` or writes to a local
  email outbox when `CPE_NOTIFICATION_EMAIL_OUTBOX_PATH` is set.
- `sms` posts a compact JSON payload to a college-approved SMS gateway.
- `whatsapp` posts the same compact JSON payload to a college-approved
  WhatsApp or messaging gateway.

Configure the non-secret settings in Admin:

- `notification_delivery_channels`: `file`, `webhook`, `email`, `sms`,
  `whatsapp`, or a comma-separated combination
- `notification_file_outbox_path`: optional `.jsonl` path inside `data/`
- `notification_email_to`: one or more comma-separated recipient addresses
- `notification_email_from`: optional sender address
- `notification_message_template`: default text template for message channels
- `notification_email_subject_template`: optional email subject template
- `notification_email_body_template`: optional email body template
- `notification_sms_gateway_url`: SMS gateway endpoint
- `notification_sms_to`: SMS recipient, list, alias, or gateway route
- `notification_sms_message_template`: SMS-specific text template
- `notification_sms_payload_template`: SMS-specific JSON payload template
- `notification_whatsapp_gateway_url`: WhatsApp gateway endpoint
- `notification_whatsapp_to`: WhatsApp recipient, list, alias, or gateway route
- `notification_whatsapp_message_template`: WhatsApp-specific text template
- `notification_whatsapp_payload_template`: WhatsApp-specific JSON payload
  template

Keep webhook URLs and environment-specific email overrides out of the database
when possible:

```bash
export CPE_NOTIFICATION_WEBHOOK_URL='https://example.edu/placement-hook'
export CPE_NOTIFICATION_EMAIL_TO='placement-office@example.edu'
export CPE_NOTIFICATION_EMAIL_FROM='placements@example.edu'
export CPE_NOTIFICATION_EMAIL_SUBJECT_TEMPLATE='{{college_name}} / {{subject}}'
export CPE_NOTIFICATION_EMAIL_BODY_TEMPLATE='{{body}}'
export CPE_NOTIFICATION_FILE_OUTBOX_PATH='/secure/path/notification-outbox.jsonl'
export CPE_NOTIFICATION_SMS_GATEWAY_URL='https://sms.example.edu/send'
export CPE_NOTIFICATION_SMS_AUTHORIZATION='Bearer ...'
export CPE_NOTIFICATION_SMS_TO='+910000000000'
export CPE_NOTIFICATION_SMS_MESSAGE_TEMPLATE='{{subject}} - {{body}}'
export CPE_NOTIFICATION_SMS_PAYLOAD_TEMPLATE='{"to": {{to}}, "message": {{text}}}'
export CPE_NOTIFICATION_WHATSAPP_GATEWAY_URL='https://wa.example.edu/send'
export CPE_NOTIFICATION_WHATSAPP_AUTHORIZATION='Bearer ...'
export CPE_NOTIFICATION_WHATSAPP_TO='+910000000000'
export CPE_NOTIFICATION_WHATSAPP_MESSAGE_TEMPLATE='{{subject}} - {{body}}'
export CPE_NOTIFICATION_WHATSAPP_PAYLOAD_TEMPLATE='{"to": {{to}}, "body": {{text}}}'
```

Database-configured file destinations are deliberately confined to `.jsonl`
files under `data/` and symbolic-link targets are rejected. A deployment
operator may use `CPE_NOTIFICATION_FILE_OUTBOX_PATH` to select a protected path
outside the app; ordinary administrators cannot set that environment value.

Webhook and message-gateway delivery accepts only HTTP(S) URLs without embedded
credentials or fragments, requires HTTPS by default, resolves every destination
away from private/reserved networks, pins the verified address for connection,
disables proxy inheritance, follows no redirects, and treats only 2xx responses
as success. These optional outbound channels require PHP `ext-curl`; the default
in-app and file-outbox operation does not. `CPE_NOTIFICATION_ALLOW_HTTP=1`
permits local HTTP testing. An explicitly reviewed internal gateway additionally needs
`CPE_OUTBOUND_ALLOW_PRIVATE_NETWORK=1`; do not enable that process-wide override
for ordinary internet delivery.

## Message Templates

Templates are plain text with placeholders such as:

- `{{college_name}}`
- `{{timezone}}`
- `{{subject}}`
- `{{body}}`
- `{{recipient_role}}`
- `{{recipient_scope_value}}`
- `{{template_key}}`
- `{{source_type}}`
- `{{source_id}}`
- `{{created_at}}`
- `{{channel}}`
- `{{to}}`
- `{{text}}`

SMS and WhatsApp JSON payload templates are rendered after the text template.
Use unquoted placeholders for JSON string escaping:

```json
{"route": {{to}}, "copy": {{text}}, "kind": {{template_key}}}
```

`{{notification_json}}` expands to the original notification payload when a
gateway needs metadata. Always test custom templates against a JSONL outbox
before connecting a live SMS or WhatsApp gateway.

For local testing without a mail server or messaging provider, write deliveries
to temporary JSONL outboxes:

```bash
export CPE_NOTIFICATION_EMAIL_OUTBOX_PATH="$(mktemp -t cpe-email-outbox).jsonl"
export CPE_NOTIFICATION_MESSAGE_OUTBOX_PATH="$(mktemp -t cpe-message-outbox).jsonl"
```

Delivery is explicit:

```bash
php placement deliver-notifications --dry-run
php placement deliver-notifications --channel=file
php placement deliver-notifications --channel=webhook --limit=50
php placement deliver-notifications --channel=email --dry-run
php placement deliver-notifications --channel=sms --dry-run
php placement deliver-notifications --channel=whatsapp --dry-run
```

Before connecting a live SMS or WhatsApp gateway, run the local certification
preflight:

```bash
export CPE_NOTIFICATION_SMS_TO='placement-test-route'
export CPE_NOTIFICATION_SMS_OUTBOX_PATH="$(mktemp -t cpe-sms-cert).jsonl"
php placement certify-notifications --channel=sms

export CPE_NOTIFICATION_WHATSAPP_TO='placement-test-route'
export CPE_NOTIFICATION_WHATSAPP_OUTBOX_PATH="$(mktemp -t cpe-wa-cert).jsonl"
php placement certify-notifications --channel=whatsapp
```

Use `--require-live` when the live gateway URL should already be configured:

```bash
php placement certify-notifications --channel=sms --require-live
```

This preflight validates the app-side handoff: route/recipient configuration,
gateway URL or local outbox, authorization header syntax, message text
templates, and rendered JSON payloads. It also prints manual checks for
provider approval, recipient consent, and a controlled first live probe. Those
manual checks are intentionally not automated because they depend on the
institution's approved provider, local policy, and legal requirements.

SMS and WhatsApp gateway payloads are JSON:

```json
{
  "channel": "sms",
  "to": "+910000000000",
  "text": "Wanted: C001 - Please locate candidate.",
  "notification": {}
}
```

If a channel-specific JSON payload template is configured, that rendered JSON
object is sent instead of the default `channel`/`to`/`text` shape.

The readiness checks warn when external deliveries are queued or failed. CSV
exports include delivery status and payloads, but not delivery targets, because
targets can contain secret webhook tokens or personal contact details.
When SMS or WhatsApp channels are enabled, readiness also runs the same local
certification preflight and warns if the app-side handoff is incomplete.

Campus display boards should be connected through the JSONL outbox or webhook
gateway rather than built into the core app.
