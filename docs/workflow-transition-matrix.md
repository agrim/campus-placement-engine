# Workflow And Permission Matrix

The application reads workflow templates from `config/workflows.php`. Admins can
override labels, colors, and transition roles from the Admin screen.

## Roles

| Role | Label | General permissions |
|---|---|---|
| `admin` | Administrator | Full setup, configuration, user management, and all transitions |
| `control` | Control Room | Central movement and exception handling |
| `placement` | Placement Office | Preference, offer, send-in/send-away, and placement actions |
| `company` | Company Tracker | Scoped company-board movement only |
| `mobile` | Mobile Tracker | Candidate movement from schedule toward venue |
| `floor` | Floor Coordinator | Arrival and floor confirmation |
| `auditor` | Read-only Auditor | View-only access; no mutation |

## Page Access

| Surface | Roles |
|---|---|
| Board, candidate trace, notifications, public links | Any signed-in role |
| Records, reports, preferences, system/readiness | `admin`, `control`, `placement`, `auditor` |
| Import and rollback console | `admin`, `control`, `placement` |
| Wanted alerts | `admin`, `control`, `placement`, `mobile`, `floor`, `auditor` |
| Admin settings, workflow overrides, users | `admin` |

## Default Placement Day

| From | To | Roles |
|---|---|---|
| `idle` | `scheduled` | `control`, `admin` |
| `scheduled` | `intransit` | `company`, `mobile`, `control`, `admin` |
| `intransit` | `arrived` | `company`, `floor`, `control`, `admin` |
| `arrived` | `requested` | `company`, `control`, `admin` |
| `requested` | `sendin` | `control`, `placement`, `admin` |
| `sendin` | `inside` | `company`, `control`, `admin` |
| `inside` | `exit` | `company`, `control`, `admin` |
| `exit` | `requestaway` | `company`, `control`, `admin` |
| `requestaway` | `sendaway` | `control`, `placement`, `admin` |
| `sendaway` | `sent` | `company`, `control`, `admin` |
| `sent` | `placed` | `control`, `placement`, `admin` |

## Engineering Multi-Branch

| From | To | Roles |
|---|---|---|
| `idle` | `eligible` | `control`, `placement`, `admin` |
| `eligible` | `test` | `control`, `company`, `admin` |
| `test` | `technical` | `company`, `control`, `admin` |
| `technical` | `hr` | `company`, `control`, `admin` |
| `hr` | `offer` | `company`, `placement`, `admin` |
| `offer` | `placed` | `control`, `placement`, `admin` |

## Internship Season

| From | To | Roles |
|---|---|---|
| `idle` | `applied` | `control`, `placement`, `admin` |
| `applied` | `shortlisted` | `company`, `control`, `admin` |
| `shortlisted` | `interview` | `company`, `control`, `admin` |
| `interview` | `selected` | `company`, `placement`, `admin` |
| `selected` | `accepted` | `control`, `placement`, `admin` |

## Simple Placement Cell

| From | To | Roles |
|---|---|---|
| `idle` | `shortlisted` | `control`, `placement`, `admin` |
| `shortlisted` | `interview` | `company`, `control`, `admin` |
| `interview` | `offer` | `company`, `placement`, `admin` |
| `offer` | `placed` | `control`, `placement`, `admin` |

## Pooled Campus Drive

| From | To | Roles |
|---|---|---|
| `idle` | `registered` | `control`, `placement`, `admin` |
| `registered` | `checked_in` | `floor`, `mobile`, `control`, `admin` |
| `checked_in` | `screening` | `company`, `control`, `admin` |
| `screening` | `interview` | `company`, `control`, `admin` |
| `interview` | `selected` | `company`, `placement`, `admin` |
| `selected` | `placed` | `control`, `placement`, `admin` |

## Virtual Interview Process

| From | To | Roles |
|---|---|---|
| `idle` | `invited` | `control`, `placement`, `admin` |
| `invited` | `link_sent` | `control`, `placement`, `admin` |
| `link_sent` | `waiting` | `mobile`, `control`, `admin` |
| `waiting` | `in_interview` | `company`, `control`, `admin` |
| `in_interview` | `feedback` | `company`, `control`, `admin` |
| `feedback` | `offer` | `company`, `placement`, `admin` |
| `offer` | `placed` | `control`, `placement`, `admin` |

## Walk-In Or Job-Fair Process

| From | To | Roles |
|---|---|---|
| `idle` | `registered` | `control`, `placement`, `admin` |
| `registered` | `screened` | `floor`, `control`, `admin` |
| `screened` | `waiting` | `floor`, `mobile`, `control`, `admin` |
| `waiting` | `interview` | `company`, `control`, `admin` |
| `interview` | `offer` | `company`, `placement`, `admin` |
| `offer` | `placed` | `control`, `placement`, `admin` |

## Cross-Cutting Guards

- Company users are limited to their configured company scope.
- Auditors cannot mutate.
- Stale board submissions are rejected before transition.
- Opted-out candidates cannot move forward.
- Placement freeze blocks non-admin placement decisions.
- Offer upgrades are blocked unless explicitly enabled.
- Final placement clears competing active applications unless upgrades are
  enabled.
- Sending a candidate away to `sent` records the next scheduled company when
  one exists and auto-starts that next application as `intransit` with an event
  trail.
