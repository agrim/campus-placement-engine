# Starter Configuration Templates

These portable JSON files are safe examples for common college operating
models. They contain workflow and policy settings only; they do not include
users, candidates, companies, applications, notifications, audit logs, gateway
URLs, or recipient routes.

Each template declares a portable placement cycle name, type, and optional
`YYYY-MM-DD` date range. Edit those values before importing if the cycle name or
dates do not match the institution's live season.

Validate before importing:

```bash
php placement config-validate examples/config-templates/engineering-multi-branch.json
```

Import into an installed app only after reviewing the settings:

```bash
php placement config-import examples/config-templates/engineering-multi-branch.json
```

`configuration_freeze` is off in the starter files so a college can import and
adapt a template before locking setup for live operations.

The four `terminology_*_label` settings let each college render local words
such as Student/Students or Recruiter/Recruiters without editing PHP files.

The text identity settings are deliberately plain text only. `site_name`,
`site_tagline`, `public_placements_title`, and `candidate_status_title` adjust
display copy without adding logos, images, fonts, or a frontend build step.

`calendar_non_operating_weekdays` and `calendar_non_operating_dates` are empty
by default. Set values such as `sat,sun` or `2026-01-26,2026-08-15` when a
college wants readiness warnings for round schedules placed on holidays or
blackout days.

`audit_request_metadata` is `none` by default. Change it only when the
institution explicitly wants audit logs to retain request IP addresses,
user-agent strings, or both.

`export_profile_custom_datasets` starts with aggregate summary datasets. Edit it
if the institution wants a named custom CSV export bundle for internal handoff.

`import_header_aliases_json` is empty by default. Add aliases such as
`{"external_id":["Campus UID"]}` only after validating a synthetic CSV with the
same headers.

Use the matching CSV templates in `examples/csv-templates/` to load synthetic
or institution-owned candidates, companies, rounds, schedules, panelists, and
shortlists.
