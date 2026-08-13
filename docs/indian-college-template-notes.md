# Indian College Template Notes

These notes translate common Indian college placement operating styles into
starter workflows and setup choices. They are intentionally generic and should
be adapted by each institution before live use. Importable starter
configuration JSON files for these workflows live in
`examples/config-templates/`; validate one with `php placement config-validate`
before importing it into a live installation.

## MBA Day-Zero Placement

- Start from `default`.
- Use cycle type `final` and a short date range for day-zero or day-one
  operations.
- Use `scheduled`, `intransit`, `arrived`, `requested`, `sendin`, `inside`,
  `exit`, `requestaway`, `sendaway`, `sent`, and `placed`.
- Configure active caps for companies with limited panel capacity.
- Use preference requests for candidate-choice conflicts.
- Use wanted alerts for missing candidates or floor delays.
- Define rounds for GD/case/interview/panel steps where scheduling matters.

## Engineering Multi-Branch

- Start from `engineering_multi_branch`.
- Use cycle type `final` and a wider date range if companies run tests and
  interviews across several weeks.
- Use branch/program fields in candidate imports.
- Model online test, technical, HR, and offer as statuses or rounds depending
  on whether they need room/time assignment.
- Keep company users scoped by company.
- Use active capacity warnings for companies processing many candidates at once.

## Internship Season

- Start from `internship_season`.
- Use cycle type `internship` and the full application/interview window.
- Treat the process as slower and less room-control heavy than day-zero finals.
- Use `applied`, `shortlisted`, `interview`, `selected`, and `accepted`.
- Keep exports as the audit handoff for faculty and placement-office review.

## Simple Placement Cell

- Start from `simple_placement_cell`.
- Use cycle type `final` or `other`, depending on whether the college treats
  the cycle as formal final placements or ongoing placement-cell work.
- Keep the board compact: shortlisted, interview, offer, placed.
- Use CSV imports for candidates, companies, and shortlists.
- Use aggregate public placement results only if the college wants live
  publication; candidate identities remain private.

## Pooled Campus Drive

- Start from `pooled_campus_drive`.
- Use cycle type `pooled`.
- Use `registered`, `checked_in`, `screening`, `interview`, `selected`, and
  `placed`.
- Treat host/venue information as company process notes and round rooms.
- Create scoped users for each company or venue desk.
- Use floor and mobile roles for check-in and queue movement.
- Use exports after each drive day.

## Virtual Interview Process

- Start from `virtual_interview_process`.
- Use the cycle type that matches the underlying process; only the interview
  logistics are virtual.
- Use `invited`, `link_sent`, `waiting`, `in_interview`, `feedback`, `offer`,
  and `placed`.
- Store meeting links or coordinator notes in process notes until a dedicated
  field is added.
- Use scheduling buffers to avoid overlapping candidate interviews.
- Use company users for interview start/end and placement users for offer
  decisions.

## Walk-In Or Job-Fair Process

- Start from `walk_in_job_fair`.
- Use cycle type `job_fair`.
- Use `registered`, `screened`, `waiting`, `interview`, `offer`, and `placed`.
- Keep candidate import minimal.
- Use active caps and room/time schedules for crowd control.
- Use wanted alerts for candidate recall.

## Template Selection Checklist

- Does the process need live room movement? Use `default`.
- Does it have test/technical/HR stages? Use `engineering_multi_branch`.
- Is it an internship pipeline over days or weeks? Use `internship_season`.
- Is it a small college placement-cell workflow? Use `simple_placement_cell`.
- Is it a pooled host-college event? Use `pooled_campus_drive`.
- Is it mostly online interviews? Use `virtual_interview_process`.
- Is it walk-in or job-fair style? Use `walk_in_job_fair`.
- Does the workflow need a status not listed here? Add it to a new template only
  after writing the transition and permission matrix.
