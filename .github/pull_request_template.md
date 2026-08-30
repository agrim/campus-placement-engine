## Purpose

Describe the user or operator problem and the smallest behavior change that solves it.

## Product and repository boundary

- [ ] Engine remains fully usable without Cloud.
- [ ] This change contains no credentials, runtime data, backups, exports, or real institution records.
- [ ] Cloud-owned tenancy, billing, infrastructure, and support behavior has not been copied into Engine.
- [ ] Candidate and placement behavior changes are implemented here before any Cloud pin update.

## Correctness and compatibility

- [ ] SQLite behavior was considered.
- [ ] PostgreSQL behavior was considered.
- [ ] Transaction, lock, retry, idempotency, and partial-failure behavior are explicit.
- [ ] Existing installations and the documented N-minus-one upgrade path remain supported, or the breaking boundary is documented.
- [ ] Any migration is additive, serialized, rollback-aware, and has a negative-path test.
- [ ] Public API, event, webhook, module, backup, and managed-hosting contracts remain compatible, or are versioned first.

## Security and privacy

- [ ] Authorization is checked server-side and fails closed when durable state is unavailable.
- [ ] Browser mutations retain CSRF protection and output remains contextually escaped.
- [ ] No secret, authorization header, endpoint credential, raw source address, or candidate-level value enters logs or metrics.
- [ ] File, archive, URL, command, and outbound-network inputs are bounded and fail closed.

## Verification

List every command actually run and its result. Do not write “all tests pass” without the commands.

```text
command -> exit status/result
```

- [ ] New material behavior has a deterministic regression test.
- [ ] Documentation and release notes are updated where operator behavior changes.
- [ ] Unexecuted infrastructure, browser, accessibility, capacity, or restore evidence is listed explicitly.

## Rollout and rollback

State the deployment order, preconditions, backup requirement, observable success signal, automatic pause condition, and rollback/forward-recovery boundary.
