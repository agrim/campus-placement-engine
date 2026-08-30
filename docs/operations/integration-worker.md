# Integration worker operations

Signed webhook Integrations use the existing institution database as their
durable queue. The default Engine still requires no Redis, Kafka, RabbitMQ,
Node.js, container runtime, or Cloud service.

After at least one Integration is ready for activation, schedule this existing
short-running command every minute with the same PHP binary, working directory,
database configuration, and external webhook keyring as the web process:

```bash
php placement work-integrations --limit=100
```

Set `CPE_INTEGRATION_WORKER_CONFIGURED=1` for the web, CLI, and scheduler
processes only after that cron or scheduler entry is installed. The setting is
an operator attestation; it does not pretend a worker ran. The durable heartbeat
separately reports whether a run was observed within the 15-minute readiness
window.

## What to monitor

`php placement doctor`, `php placement readiness`, and **System** report:

- whether an Active or Degraded Integration requires the worker;
- whether scheduler installation has been attested;
- heartbeat status, freshness, and age;
- pending deliveries, oldest pending age, and dead letters;
- the enforced webhook TLS/network policy;
- external encryption-key presence and referenced-version readiness; and
- the current PDO database driver and a live readiness probe.

No Integration requires a worker while all Integrations are Disabled, Setup
required, or Validating. An Active or Degraded Integration without the
scheduler attestation or a recent heartbeat is a readiness warning. A missing
encryption key needed by an Active Integration and an unavailable database
driver are failures.

## Triage order

1. Confirm the scheduler invokes the same release and configuration as the web
   process, then run one bounded worker command manually.
2. Check the heartbeat and oldest pending age. A fresh heartbeat with growing
   backlog points to destination or delivery failures, not scheduler absence.
3. Review the Integration state and opaque support reference on the
   **Integrations** page. Degraded and dead-lettered work needs an administrator
   decision.
4. Restore referenced encryption-key versions before activation or delivery.
5. Replay only the exact reviewed dead-letter with
   `replay-webhook-delivery`; revocation-era deliveries remain terminal.
6. Create `php placement support-report` output when an institution approves
   sharing the bounded metadata described in `support-report.md`.

Never run the worker in an unbounded busy loop. Never put signing keys, endpoint
URLs, delivery bodies, or database credentials in scheduler command arguments
or support tickets.
