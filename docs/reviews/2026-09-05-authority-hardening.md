# Authority hardening review — 5 September 2026

Baseline: `417e6e4a292d5d7df156076b13d3a5f072afa521` (alpha.5).
The `productionisation` tree was already merged into `main` with identical
contents. This review does not constitute a production certification.

## Corrections

- Company-scoped board/filter reads now fail closed for missing/blank scope and
  normalize valid scope consistently. Previously, an unassigned company actor
  could receive other companies' candidate rows.
- Browser sessions carry a durable account generation. Password resets and
  activation changes invalidate older generations; reactivation cannot revive
  an old session. This covers local-password and SSO sessions.
- Password verification is revalidated under the write transaction before the
  login is audited and a session established. A racing reset cannot authenticate
  an obsolete credential snapshot.
- Ordinary transition actors include the account generation. Privileged board
  corrections now use the same transaction-local actor/capability fence before
  idempotency replay or mutation, rather than trusting a stale request snapshot.
- Bulk activation and password reset audit records commit with their associated
  account changes.

## Compatibility and rollout

Migrations `055_user_session_generations.sql` (SQLite) and
`019_user_session_generations.sql` (PostgreSQL) add a non-null generation with a
positive default. Take and verify a backup, run the normal serialized upgrade,
and deploy a consistent new application version. Existing browser sessions
without a generation must sign in again; this intentional one-time logout is
not silent data migration. Account IDs, passwords, placement history, public
API/event contracts, and the managed-hosting contract are unchanged.

Do not run old binaries against the new schema or bypass the migration
compatibility checks. Follow the existing backup/restore procedure for rollback.
A hosted consumer must pin a newly built, verified immutable Engine release
before consuming these changes; a review branch is not a released artifact.

## Verification

```bash
php tests/review_authority_contract.php
php tests/application_transition_boundary_contract.php
CPE_TEST_SCHEMA_PYTHON=/path/to/schema/python php tests/run.php
```

The authority contract uses a new synthetic SQLite database by default. Setting
`CPE_REVIEW_AUTHORITY_DATABASE_URL` selects a **dedicated disposable** PostgreSQL
database. Never point it at a live institution. Its checks cover valid/missing
scope, normalization, password and SSO revocation, disable/reactivate, legacy
sessions, and stale correction authority. CI runs the SQLite authority contract
on PHP 8.2/8.3/8.4 and the PostgreSQL path in the main contract workflow.

These tests are not proof of physical candidate availability or universal panel
capacity enforcement. Current active-conflict/capacity warnings and slot-planner
constraints must not be described as an authoritative live occupancy lock.
Likewise, imported GD round/panel assignments are not proof that all original
retained-subgroup, repeated-visit and exact-size-group operating cases are fully
implemented. Those are explicit remaining functional qualification items.

## Launch boundary

The evaluation-alpha boundary remains. A production pilot still needs actual
operator acceptance, representative concurrency/capacity evidence, browser and
accessibility evidence, deployment configuration, and a real restore drill.
No historical student records, account hashes, backup source or credentials are
included in this review or its synthetic tests.
