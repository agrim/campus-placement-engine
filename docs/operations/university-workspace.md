# University opportunity workspace

The **Candidate opportunities** page gives an authorized university team one place
to protect candidate opportunity coverage, resolve interview and assessment
clashes, and complete attendance and adviser follow-up. It is a read-only view
over existing Placement Operations, schedule, workflow, and optional Career
Advising records. It creates no second workflow, eligibility table, action
state, or notification queue.

Opening the page requires both `placement.reports.view` and
`placement.sensitive.view`. Adviser tasks appear only when Career Advising is
enabled and the current user also has `advising.tasks.manage`. Every queue is
bounded to the first 100 priority rows while retaining its full count.

## What the queues mean

| University outcome | Durable evidence used | Honest limitation |
|---|---|---|
| Candidates needing opportunity coverage | Active, not-opted-out, unplaced candidates with no active application | This is a coverage proxy, not a formal eligibility decision. An application at the initial workflow state is not treated as active coverage. |
| Eligibility evidence to review | Missing program data or no candidate-opportunity link | Engine has no durable eligibility-rule result, so the page never labels a candidate eligible or ineligible. |
| Approaching deadlines | Configured company process finish day/time within seven days, plus malformed date setup | These are process finish cut-offs, not application close dates. |
| Interview and assessment clashes | Two active slot assignments for one candidate whose documented day and `HH:MM` windows overlap | Rescheduling owner, employer contact state, escalation state, and escalation deadline are omitted because no durable fields support them. |
| Attendance and confirmation follow-up | Active slot assignments whose local status is not confirmed, accepted, checked in, attended, completed, or cancelled | Free-form assignment status is the only durable signal. The page does not invent a separate candidate-response state. |
| Repeated no-progress signal | Two or more applications with prior events that are currently back at the workflow's initial state | This is a workflow-history signal, not a judgment about candidate quality or employer intent. |
| Opportunities without recorded coverage | Open opportunities with zero application links | Zero links is the strongest current signal. `max_active` is a safety cap, not an expected-coverage target. |
| Adviser action due | Open Career Advising tasks due within seven days or overdue | Only durable advising tasks are shown; the page does not infer adviser ownership from placement records. |

## Operating rhythm

1. Start with clashes and attendance follow-up because they affect a scheduled
   candidate now.
2. Review candidates needing coverage and correct missing program or opportunity
   links in **Records**.
3. Check configured process cut-offs and opportunities with zero links.
4. Complete due adviser tasks in **Advising** when that Module is enabled.
5. Use the existing candidate trace, records, slot assignment, and advising
   workflows to make changes. Refresh the workspace to see the reconciled
   outcome.

Application-close deadlines, formal eligibility results, candidate response,
rescheduling ownership, employer contact progress, and escalation deadlines are
deferred until a governed source of truth exists. They must not be represented
by casual new columns or free-form state added only for this page.
