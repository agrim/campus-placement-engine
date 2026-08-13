# Legacy Inventory

Date: 2026-06-28

This inventory describes the private historical material used to extract product
requirements. The files remain under `.legacy-private/`, which is ignored by
Git. Do not move raw archives, SQL dumps, CSVs, spreadsheets, screenshots, or
documents into public source unless they are first rewritten as synthetic
examples.

## Boundary

- Public repo: rebuilt PHP + SQLite app, synthetic examples, docs, tests, and
  safe configuration templates.
- Private archive: legacy source snapshots, old SQL dumps, dry-run documents,
  screenshots, and operational spreadsheets.
- Public extraction: requirements, glossary, workflow matrices, acceptance
  tests, and generic templates derived from the private archive.

## Top-Level Inventory

| Path | Approx size | Role | Publication risk |
|---|---:|---|---|
| `.legacy-private/Control/` | 41M | Dry-run notes, screenshots, CSV/XLSX artifacts, permission notes | High: likely real operational data and names |
| `.legacy-private/Control 2/` | 41M | Duplicate or alternate control-room material | High: duplicate sensitive operational data |
| `.legacy-private/Control App/` | 46M | Old app revisions, SQL dumps, user CSVs | Critical: source plus database/user data |
| `.legacy-private/place/` | 12M | Legacy runnable app tree and SQL dumps | Critical: source plus candidate/company data |
| `.legacy-private/OrangeRinds/` | 2.1M | Unrelated learning/business-plan material | Medium: unrelated and should stay out of product scope |
| `.legacy-private/*.7z`, `.zip`, `.rar` | 180M+ | Archive snapshots and duplicate bundles | Critical: compressed raw historical data |

## Important Archive Groups

| Group | Examples | Use in modernization |
|---|---|---|
| Legacy app snapshots | `app9.zip`, `app10.zip`, `app27.zip`, `app20.rar`, `place.zip` | Compare behavior, screens, schema choices, and missing modules |
| SQL/database snapshots | `control1-3.sql`, `control1-5Oct.sql`, `db-5-11.sql`, `db-for-install.sql` | Extract schema intent only; never publish rows |
| Dry-run and process files | `DryRunUseCasesList.docx`, `DryRun1_02Oct.xlsx`, `DryRun2_05Oct.xlsx` | Convert lived placement-day edge cases into synthetic tests |
| Permission/process notes | `control_permissions.*`, `process states.xlsx` | Derive generic role and transition matrices |
| Screenshots | `Screen Shot 2016-*` | Recover dense-board and operator-screen layout requirements |
| CSV/XLSX operational data | `student_list.csv`, `company_list.csv`, `entry*.csv`, `Full-Data.xlsx` | Derive import fields and validation behavior only |

## Private Hash Ledger

Archive checksums are recorded in the ignored private file:

```text
.legacy-private/HASH_LEDGER.md
```

That ledger is for local deduplication and chain-of-custody only. It should not
be referenced from release notes or committed to public Git.

## Extraction Rules

- Extract concepts, not raw rows.
- Replace all institution, company, candidate, student, email, phone, and room
  identifiers with synthetic examples.
- Treat SQL dumps and CSV/XLSX files as toxic by default.
- Keep duplicate archives until their checksums and contents have been compared.
- Publish only synthetic CSV templates under `examples/csv-templates/`.
- Record every intentionally deferred legacy behavior in
  `docs/implementation-status.md`.
