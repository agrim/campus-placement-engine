# Privacy-safe support report

`php placement support-report` prints one bounded JSON document for an
institution-approved support exchange. It is read-only and requires an
installed, fully migrated Engine database.

```bash
php placement support-report
```

To retain a local copy, choose institution-controlled storage and restrictive
permissions before redirecting stdout:

```bash
umask 077
php placement support-report > cpe-support-report.json
```

## Reviewed allowlist

Schema version 1 contains only:

- Engine version and an optional operator-supplied release artifact SHA-256;
- PHP version, PDO driver, and a live driver-readiness result;
- applied and pending migration identifiers, plus a count of unrecognized
  applied identifiers whose text is deliberately withheld;
- enabled Module IDs and a SHA-256 revision of the capability catalog;
- up to 200 Integration public IDs and the exact Disabled, Setup required,
  Validating, Active, or Degraded state, with total and truncation metadata;
- aggregate pending/dead-letter counts and oldest pending age;
- at most 20 syntactically valid opaque incident IDs from bounded Integration
  failure-reference rows;
- worker required/configured state, status, freshness, and heartbeat age; and
- redacted inbound-proxy, outbound-proxy, webhook TLS, hosted-mode, and database
  TLS policy fields.

`CPE_ENGINE_ARTIFACT_SHA256` may contain a 64-character SHA-256 digest, with or
without a `sha256:` prefix. Use the checksum of the deployed release archive
when known. If it is absent or malformed, `artifact_sha256` is `null`; Engine
does not fabricate artifact provenance from a mutable checkout.

## Always excluded

The report never selects or emits candidate, employer, application, interview,
assessment, advising, offer, or placement records. It also excludes names,
email addresses, free-form notes, event payloads, credentials, signing material,
tokens, endpoint URLs or origins, database URLs, usernames, filesystem paths,
certificate paths, raw errors, response bodies, and logs. Incident IDs remain
opaque and bounded; diagnostic text is not copied.

Review the JSON before sharing it and use the institution's approved support
channel. The report describes local configuration and aggregate health only. It
does not prove a Connector vendor received an event, a production TLS endpoint
is reachable, or an external service met its obligations.
