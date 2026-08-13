# Dry-Run Acceptance Tests

Historical placement dry runs are treated as executable product requirements.
The modern app keeps the implementation small, but the test suite now covers
the pressure cases that shaped the original engine.

Run them with:

```bash
php tests/run.php
```

## Covered Scenarios

- Full default workflow from scheduled to placed.
- Placement cleanup removes a placed candidate from competing active queues
  unless offer upgrades are enabled.
- Active-company conflicts appear on the board and in readiness checks.
- Company requests a candidate who was not already shortlisted.
- Company rejects or refuses a candidate and the application returns to idle
  with an event and audit trail.
- Candidate is unavailable or a stale board card attempts an exception action;
  stale submissions are rejected before mutation.
- Candidate chooses one company over another through a preference decision and
  the losing active application returns to idle.
- Candidate sent away from one company automatically moves to the next
  scheduled company, with previous/next company links and an audit event.
- Send-away handoffs project a server-rendered movement route on the board and
  candidate trace, so operators see the previous-to-next company path.
- Extended-shortlist priority is respected when scarce interview capacity means
  list-one candidates must be served before later-list candidates.
- Last-panel bottlenecks surface through slot suggestions when all schedule
  rows are at capacity.
- GD or panel capacity changes can unblock safe slot suggestions.
- Company pauses panels or marks a schedule row as a break; inactive schedule
  rows are skipped by slot suggestions and active-capacity readiness warnings
  clear when the extra candidate is returned to idle.
- Company hard-stop/departure deadlines prevent slot suggestions that would
  finish after the configured cutoff.
- Panelist-specific breaks block slot suggestions when every configured
  panelist for a round is on break or unavailable.
- Candidate accommodation notes stay visible on the board and candidate trace
  so operators can route room or floor constraints during live movement.
- Day spillover can route later rounds to a later schedule day and avoid
  treating same clock times on different days as conflicts.
- Legacy GD round/panel columns import into synthetic modern rounds, schedules,
  and slot assignments without publishing historical rows.

## Remaining Scenario Mining

- Additional institution-specific dry-run cases from private historical notes,
  once they have been converted into synthetic examples.

Do not add real dry-run data, names, room plans, screenshots, or spreadsheets to
the public repository. Convert each case into synthetic candidates, companies,
rooms, and notes before adding tests.
