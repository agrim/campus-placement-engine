# Public integration compatibility

The machine-readable compatibility declaration is
`contracts/public-integration.v1.json`. Version 1 is exactly:

```json
{
  "schema": 1,
  "event_schemas": {
    "application.status_changed": [1]
  },
  "api_scopes": [
    "opportunities.read",
    "applications.read",
    "applications.transition"
  ],
  "engine_api": [
    "v1"
  ]
}
```

This declares the governed event schema plus Engine API v1 and its three exact
scopes. The API surface is limited to six paths in `contracts/openapi.v1.json`,
including one controlled application transition command; it declares no
candidate or other command/write API.
Internal PHP module APIs, `DomainEvent` payloads, database schemas, and observer
subscriptions remain outside this compatibility promise.

## Version rules

- A connector validates the top-level contract schema before using any other
  declaration and rejects an unknown future contract schema.
- Its event permission set must exactly match the compatible event declarations
  it accepts. An undeclared event or unsupported event schema fails closed.
- `application.status_changed` schema `1` is described by the strict producer
  schemas under `contracts/schemas/`. The example under `contracts/examples/`
  must validate against them. The envelope's data reference is an absolute URN
  resolved from the event schema `$id`; CI and release validation register both
  resources in a pinned Draft 2020-12 implementation.
- The Engine v1 producer emits no undeclared envelope, aggregate, data, or trace
  fields. A new incompatible shape requires a new event schema version and a
  catalog update.
- API v1 uses the strict schemas in `contracts/schemas/api-v1-*.schema.json`.
  The OpenAPI document, producer examples, frozen consumer fixtures, exact
  path/method set, privacy allowlists, command/idempotency contract, and cursor
  behavior are validated by the
  pinned contract gate. Removing or changing a v1 field, method, status,
  authentication rule, or pagination meaning incompatibly requires a new API
  path version and catalog update.
- Consumers should read only the required v1 fields and ignore unknown optional
  fields. The frozen and future-optional fixtures under `contracts/fixtures/`
  prove that defensive consumer behavior; the optional fixture is not an Engine
  v1 producer payload.
- Engine release versions and public integration schema versions are separate.
  A connector must check the compatibility artifact in the pinned, verified
  Engine release rather than infer support from a release number.

The default runtime and downloadable package have no JSON Schema or OpenAPI
library dependency. Portable PHP contracts invoke the pinned test-only
validator and fail closed when it is absent, so lightweight artifact checks are
never reported as proof of standards-compliant reference resolution.

Compatibility is a schema and behavior promise, not an uninterrupted stream
promise. Signed webhook delivery is at least once and ordered only within one
subscription and application aggregate. Webhook validation challenges use the
separate `webhook.validation` type and are not catalog events. Signing-header,
secret-overlap, retry, and network behavior is documented in
`docs/integrations/webhooks.md`; changing it incompatibly requires an explicit
delivery-contract review even when the JSON schema is unchanged. Restores break
stream continuity and require Connector resynchronization as described in
`docs/integrations/events.md`. API cursor recovery and synchronization limits
are documented separately in `docs/api/v1.md`.
