# Institution-local API identity foundation

Status: Phase 3A control foundation; no public API resource exists yet

This release prepares service-account identity, exact scope grants,
verifier-only tokens, rate-limit buckets, and redacted request audit for a
future versioned Engine API. It does **not** register `/api/v1` routes, publish
an OpenAPI document, or add API scopes to
`contracts/public-integration.v1.json`. The public integration contract remains
event-only until a later release governs and implements the first resource
endpoint.

## Disabled by default

`api_enabled` is an institution-local setting created as `0`. It is deliberately
excluded from portable configuration, so an import, clone, or restore cannot
enable API access by configuration transfer. Enabling the foundation only makes
credential authentication state ready; it does not create a public HTTP API.

The administrator **API Access** page and CLI management commands require an
active local user with `portal.integrations.manage`. Browser mutations also
require CSRF validation. A service account receives only its stored exact scope
rows; user roles, the administrator wildcard, browser cookies, PHP sessions,
setup tokens, metrics tokens, and webhook secrets are never API credentials.

The currently reserved exact scopes are:

- `opportunities.read`
- `applications.read`

Both fail closed unless Placement is enabled and the durable
`placement.records.view` capability exists. This internal reservation is not a
public compatibility declaration and grants no broad PlacementService read.

## External keyring

Configure the keyring only in the web and CLI process secret environment:

```text
CPE_API_KEYRING=version-id=<canonical-unpadded-base64url-32-byte-key>[;next-version=...]
CPE_API_ACTIVE_KEY_VERSION=version-id
```

The keyring accepts one to eight distinct versions. Each root key must decode to
exactly 32 bytes, and the active version must name one entry. Generate keys with
an approved cryptographic random source and keep them outside Git, the database,
backups, portable configuration, command arguments, and application logs.

Engine derives separate token-verifier, future-cursor, and source-fingerprint
keys with HKDF-SHA256 domain separation. A token verifier is a 32-byte HMAC bound
to the institution public ID, clear lookup ID, and key version. The database
stores only that verifier and its version; it never stores the random token
secret or a reversible ciphertext. Do not reuse `CPE_WEBHOOK_ENCRYPTION_KEYS`:
webhook encryption and API identity have separate formats and cryptographic
domains.

An absent keyring is valid while the foundation is disabled and has no usable
token references. If any usable token references a missing version, readiness
fails. Keep every referenced version available through rotation grace, then
confirm `php placement api-status` before removing it.

## Token lifecycle

Tokens have the fixed shape
`cpe_live_apitok_<32 lowercase hex lookup characters>.<43 canonical base64url characters>`.
The suffix encodes 32 random bytes. Creation and rotation reveal the complete
token exactly once in the immediate browser response or CLI stdout. Copy it
directly to the consumer's secret manager; it cannot be retrieved later.

Expiry is mandatory: 90 days by default and at most 365 days. Rotation creates
one new current token and gives the previous current token at most 24 hours of
grace, capped by its original expiry. At most two unrevoked tokens may exist for
one account; another rotation revokes older material. Token revoke is immediate.
Disabling an account preserves metadata but denies use. Account revoke is
irreversible and revokes all its tokens.

Malformed and unknown lookup IDs use the same generic credential rejection.
Authentication performs a dummy HMAC for unknown IDs. Successful `last_used_at`
writes are throttled to once per 15 minutes.

## Operator commands

Use a numeric active local user ID whose capability includes integration
management:

```bash
php placement api-status
php placement api-service-account-create \
  --name='Institution warehouse' \
  --scopes=opportunities.read,applications.read \
  --actor-user-id=USER_ID \
  --expiry-days=90
php placement api-service-account-list --actor-user-id=USER_ID
php placement api-token-rotate --service-account=apisa_ID --actor-user-id=USER_ID
php placement api-token-revoke --token-id=LOOKUP_ID --actor-user-id=USER_ID
php placement api-service-account-disable --service-account=apisa_ID --actor-user-id=USER_ID
php placement api-service-account-enable --service-account=apisa_ID --actor-user-id=USER_ID
php placement api-service-account-revoke --service-account=apisa_ID --actor-user-id=USER_ID
php placement api-enable --actor-user-id=USER_ID
php placement api-disable --actor-user-id=USER_ID
php placement api-prune --actor-user-id=USER_ID --limit=1000
```

Prefer browser or an isolated trusted terminal for one-time reveal commands.
Never redirect their output into ordinary logs, shell history annotations,
support tickets, or CI artifacts. List, status, doctor, readiness, health, and
metrics return aggregate or metadata-only information and never return a token
or verifier.

## Rate limit and audit storage

The future request boundary can consume transactional per-institution,
per-token, and keyed-source buckets. Raw source addresses and tokens are not
stored. Request audit accepts only reviewed route classes, exact scope names,
fixed outcome/detail codes, numeric status, and a keyed source fingerprint; it
stores no request body, raw URL, query string, authorization header, token,
candidate/employer identity, or raw source address.

Expired rate-limit buckets and request-audit rows are pruned in bounded batches
with `api-prune`. Request-audit retention defaults to 90 days. Institution policy
must also cover copied databases, backups, exports, and downstream consumer
logs.

## Readiness and recovery

`api-status`, doctor, readiness, health, and metrics expose only counts and
state classes: enabled state, account lifecycle totals, usable-token count,
missing-key-version count, scope readiness, bucket count, and recent denied
count. They never expose account names or IDs, token lookup IDs, key version
names, scopes per account, source fingerprints, or request identity.

A database restore can rewind token revoke and audit state. Keep the foundation
disabled during a restore drill, reconcile external consumer secrets and
keyring versions, revoke uncertain tokens, rotate replacement tokens, verify
aggregate readiness, and enable only after the institution approves the new
boundary.
