# CSV Templates

These CSV files are synthetic starter templates. They are safe to copy into a
throwaway local install and edit for testing.

The importer accepts these canonical headers plus common college spreadsheet
aliases. For example:

- `Student ID`, `Roll No`, or `Registration Number` map to candidate IDs.
- `Company Code`, `Recruiter Code`, or `Employer Code` map to company codes.
- `Branch`, `Department`, or `Programme` map to program.
- `Cohort`, `Category`, `Segment`, or `Labels` map to lightweight tags.
- `Custom Fields`, `Fields JSON`, or `Local Fields` map to
  `custom_fields_json` for simple institution-specific candidate/company
  columns.
- `Round No`, `Round Name`, `Venue`, `Start Time`, and `End Time` map to round
  and schedule fields.
- `Active Cap`, `Finish By`, and `Waitlist Rank` map to operational controls.

`custom_fields_json` must be a JSON object. Inside a CSV cell, quote it like:

```csv
"{""branch"":""Finance"",""cgpa"":9.1}"
```

Keep values scalar: strings, numbers, booleans, or null.

Use `candidate_unavailability_windows.csv` when a candidate must not be
scheduled during a known window, such as an exam, accommodation break, travel
handoff, or parallel institutional commitment. The slot planner treats those
windows as conflicts before suggesting or assigning interview slots.

Preview every file before importing. If two headers normalize to the same app
field, the preview fails instead of guessing which column to use.
