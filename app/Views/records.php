<?php

use App\Security\Csrf;

$title = 'Records';
$candidateLabel = cpe_term('candidate');
$candidatesLabel = cpe_term('candidates');
$companyLabel = cpe_term('company');
$companiesLabel = cpe_term('companies');
ob_start();
?>
<div class="page-head">
  <div>
    <h1>Records</h1>
    <p class="muted">Maintain <?= h(strtolower($candidatesLabel)) ?>, <?= h(strtolower($companiesLabel)) ?>, and shortlists without a spreadsheet round trip.</p>
  </div>
</div>

<section class="panel">
  <h2><?= h($candidatesLabel) ?></h2>
  <div class="record-grid record-grid-candidates" role="table" aria-label="<?= h($candidatesLabel) ?>">
    <div class="record-head" role="row">
      <div role="columnheader">ID</div>
      <div role="columnheader">Name</div>
      <div role="columnheader">Program</div>
      <div role="columnheader">Tags</div>
      <div role="columnheader">Location</div>
      <div role="columnheader">Accommodation</div>
      <div role="columnheader">Custom JSON</div>
      <div role="columnheader">Opted out</div>
      <div role="columnheader">Save</div>
    </div>
    <?php foreach ($candidates as $candidate): ?>
      <form class="record-row" method="post" action="<?= h(url('records-candidate')) ?>" role="row">
        <?= Csrf::input() ?>
        <input type="hidden" name="id" value="<?= h($candidate['id']) ?>">
        <div role="cell"><input name="external_id" value="<?= h($candidate['external_id']) ?>" required aria-label="<?= h($candidateLabel) ?> ID"></div>
        <div role="cell"><input name="name" value="<?= h($candidate['name']) ?>" required aria-label="<?= h($candidateLabel) ?> name"></div>
        <div role="cell"><input name="program" value="<?= h($candidate['program']) ?>" aria-label="Program"></div>
        <div role="cell"><input name="tags" value="<?= h($candidate['tags'] ?? '') ?>" aria-label="<?= h($candidateLabel) ?> tags"></div>
        <div role="cell"><input name="current_location" value="<?= h($candidate['current_location']) ?>" aria-label="Current location"></div>
        <div role="cell"><input name="accommodation_notes" value="<?= h($candidate['accommodation_notes'] ?? '') ?>" aria-label="Accommodation notes"></div>
        <div role="cell"><input name="custom_fields_json" value="<?= h($candidate['custom_fields_json'] ?? '{}') ?>" aria-label="<?= h($candidateLabel) ?> custom fields JSON"></div>
        <div role="cell"><input type="checkbox" name="opted_out" value="1" style="width:auto" <?= $candidate['opted_out'] ? 'checked' : '' ?> aria-label="Opted out"></div>
        <div role="cell"><button type="submit">Save</button></div>
      </form>
    <?php endforeach; ?>
    <form class="record-row record-new" method="post" action="<?= h(url('records-candidate')) ?>" role="row">
      <?= Csrf::input() ?>
      <div role="cell"><input name="external_id" placeholder="C100" required aria-label="New <?= h(strtolower($candidateLabel)) ?> ID"></div>
      <div role="cell"><input name="name" placeholder="<?= h($candidateLabel) ?> name" required aria-label="New <?= h(strtolower($candidateLabel)) ?> name"></div>
      <div role="cell"><input name="program" placeholder="Program" aria-label="New <?= h(strtolower($candidateLabel)) ?> program"></div>
      <div role="cell"><input name="tags" placeholder="Cohort, category" aria-label="New <?= h(strtolower($candidateLabel)) ?> tags"></div>
      <div role="cell"><input name="current_location" value="CP" aria-label="New <?= h(strtolower($candidateLabel)) ?> current location"></div>
      <div role="cell"><input name="accommodation_notes" placeholder="Room/floor need" aria-label="New <?= h(strtolower($candidateLabel)) ?> accommodation notes"></div>
      <div role="cell"><input name="custom_fields_json" value="{}" aria-label="New <?= h(strtolower($candidateLabel)) ?> custom fields JSON"></div>
      <div role="cell"><input type="checkbox" name="opted_out" value="1" style="width:auto" aria-label="New <?= h(strtolower($candidateLabel)) ?> opted out"></div>
      <div role="cell"><button class="primary" type="submit">Add</button></div>
    </form>
  </div>
</section>

<section class="panel">
  <h2><?= h($companiesLabel) ?></h2>
  <div class="record-grid record-grid-companies" role="table" aria-label="<?= h($companiesLabel) ?>">
    <div class="record-head" role="row">
      <div role="columnheader">Code</div>
      <div role="columnheader">Name</div>
      <div role="columnheader">Slot</div>
      <div role="columnheader">Offer tier</div>
      <div role="columnheader">Process</div>
      <div role="columnheader">Room</div>
      <div role="columnheader">Tracker</div>
      <div role="columnheader">Active cap</div>
      <div role="columnheader">Deadline day</div>
      <div role="columnheader">Deadline at</div>
      <div role="columnheader">Notes</div>
      <div role="columnheader">Tags</div>
      <div role="columnheader">Custom JSON</div>
      <div role="columnheader">Save</div>
    </div>
    <?php foreach ($companies as $company): ?>
      <form class="record-row" method="post" action="<?= h(url('records-company')) ?>" role="row">
        <?= Csrf::input() ?>
        <input type="hidden" name="id" value="<?= h($company['id']) ?>">
        <div role="cell"><input name="code" value="<?= h($company['code']) ?>" required aria-label="<?= h($companyLabel) ?> code"></div>
        <div role="cell"><input name="name" value="<?= h($company['name']) ?>" required aria-label="<?= h($companyLabel) ?> name"></div>
        <div role="cell"><input name="slot" value="<?= h($company['slot']) ?>" aria-label="Slot"></div>
        <div role="cell"><input name="offer_tier" value="<?= h($company['offer_tier']) ?>" aria-label="Offer tier"></div>
        <div role="cell"><input name="process_type" value="<?= h($company['process_type']) ?>" aria-label="Process type"></div>
        <div role="cell"><input name="room" value="<?= h($company['room']) ?>" aria-label="Room"></div>
        <div role="cell"><input name="tracker_name" value="<?= h($company['tracker_name']) ?>" aria-label="Tracker"></div>
        <div role="cell"><input name="max_active" value="<?= h($company['max_active']) ?>" inputmode="numeric" aria-label="Active cap"></div>
        <div role="cell"><input name="deadline_day" value="<?= h($company['deadline_day'] ?? '') ?>" aria-label="Deadline day"></div>
        <div role="cell"><input name="deadline_at" value="<?= h($company['deadline_at'] ?? '') ?>" placeholder="17:30" aria-label="Deadline time"></div>
        <div role="cell"><input name="process_notes" value="<?= h($company['process_notes']) ?>" aria-label="Process notes"></div>
        <div role="cell"><input name="tags" value="<?= h($company['tags'] ?? '') ?>" aria-label="<?= h($companyLabel) ?> tags"></div>
        <div role="cell"><input name="custom_fields_json" value="<?= h($company['custom_fields_json'] ?? '{}') ?>" aria-label="<?= h($companyLabel) ?> custom fields JSON"></div>
        <div role="cell"><button type="submit">Save</button></div>
      </form>
    <?php endforeach; ?>
    <form class="record-row record-new" method="post" action="<?= h(url('records-company')) ?>" role="row">
      <?= Csrf::input() ?>
      <div role="cell"><input name="code" placeholder="ACME" required aria-label="New <?= h(strtolower($companyLabel)) ?> code"></div>
      <div role="cell"><input name="name" placeholder="<?= h($companyLabel) ?> name" required aria-label="New <?= h(strtolower($companyLabel)) ?> name"></div>
      <div role="cell"><input name="slot" placeholder="Day / Slot" aria-label="New <?= h(strtolower($companyLabel)) ?> slot"></div>
      <div role="cell"><input name="offer_tier" placeholder="dream/core/etc" aria-label="New <?= h(strtolower($companyLabel)) ?> offer tier"></div>
      <div role="cell"><input name="process_type" placeholder="Interview / test / PI" aria-label="New process type"></div>
      <div role="cell"><input name="room" placeholder="Room" aria-label="New room"></div>
      <div role="cell"><input name="tracker_name" placeholder="Tracker" aria-label="New tracker"></div>
      <div role="cell"><input name="max_active" value="0" inputmode="numeric" aria-label="New active cap"></div>
      <div role="cell"><input name="deadline_day" placeholder="1" aria-label="New deadline day"></div>
      <div role="cell"><input name="deadline_at" placeholder="17:30" aria-label="New deadline time"></div>
      <div role="cell"><input name="process_notes" placeholder="Notes" aria-label="New process notes"></div>
      <div role="cell"><input name="tags" placeholder="Sector, cohort" aria-label="New <?= h(strtolower($companyLabel)) ?> tags"></div>
      <div role="cell"><input name="custom_fields_json" value="{}" aria-label="New <?= h(strtolower($companyLabel)) ?> custom fields JSON"></div>
      <div role="cell"><button class="primary" type="submit">Add</button></div>
    </form>
  </div>
</section>

<section class="panel">
  <h2><?= h($companyLabel) ?> Rounds</h2>
  <div class="record-grid record-grid-rounds" role="table" aria-label="<?= h($companyLabel) ?> rounds">
    <div class="record-head" role="row">
      <div role="columnheader"><?= h($companyLabel) ?></div>
      <div role="columnheader">Seq</div>
      <div role="columnheader">Label</div>
      <div role="columnheader">Type</div>
      <div role="columnheader">Room</div>
      <div role="columnheader">Minutes</div>
      <div role="columnheader">Instructions</div>
      <div role="columnheader">Save</div>
    </div>
    <?php foreach ($rounds as $round): ?>
      <form class="record-row" method="post" action="<?= h(url('records-round')) ?>" role="row">
        <?= Csrf::input() ?>
        <input type="hidden" name="id" value="<?= h($round['id']) ?>">
        <div role="cell">
          <select name="company_id" required aria-label="Round <?= h(strtolower($companyLabel)) ?>">
            <?php foreach ($companies as $company): ?>
              <option value="<?= h($company['id']) ?>" <?= (int) $round['company_id'] === (int) $company['id'] ? 'selected' : '' ?>><?= h($company['code']) ?> - <?= h($company['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div role="cell"><input name="sequence" value="<?= h($round['sequence']) ?>" inputmode="numeric" required aria-label="Round sequence"></div>
        <div role="cell"><input name="label" value="<?= h($round['label']) ?>" required aria-label="Round label"></div>
        <div role="cell"><input name="round_type" value="<?= h($round['round_type']) ?>" aria-label="Round type"></div>
        <div role="cell"><input name="room" value="<?= h($round['room']) ?>" aria-label="Round room"></div>
        <div role="cell"><input name="duration_minutes" value="<?= h($round['duration_minutes']) ?>" inputmode="numeric" aria-label="Round duration"></div>
        <div role="cell"><input name="instructions" value="<?= h($round['instructions']) ?>" aria-label="Round instructions"></div>
        <div role="cell"><button type="submit">Save</button></div>
      </form>
    <?php endforeach; ?>
    <form class="record-row record-new" method="post" action="<?= h(url('records-round')) ?>" role="row">
      <?= Csrf::input() ?>
      <div role="cell">
        <select name="company_id" required aria-label="New round <?= h(strtolower($companyLabel)) ?>">
          <?php foreach ($companies as $company): ?>
            <option value="<?= h($company['id']) ?>"><?= h($company['code']) ?> - <?= h($company['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div role="cell"><input name="sequence" value="1" inputmode="numeric" required aria-label="New round sequence"></div>
      <div role="cell"><input name="label" placeholder="Round label" required aria-label="New round label"></div>
      <div role="cell"><input name="round_type" placeholder="case / test / PI" aria-label="New round type"></div>
      <div role="cell"><input name="room" placeholder="Room" aria-label="New round room"></div>
      <div role="cell"><input name="duration_minutes" value="0" inputmode="numeric" aria-label="New round duration"></div>
      <div role="cell"><input name="instructions" placeholder="Instructions" aria-label="New round instructions"></div>
      <div role="cell"><button class="primary" type="submit">Add</button></div>
    </form>
  </div>
</section>

<section class="panel">
  <h2>Round Schedule</h2>
  <div class="record-grid record-grid-schedules" role="table" aria-label="Round schedule">
    <div class="record-head" role="row">
      <div role="columnheader">Round</div>
      <div role="columnheader">Seq</div>
      <div role="columnheader">Room</div>
      <div role="columnheader">Day</div>
      <div role="columnheader">Start</div>
      <div role="columnheader">End</div>
      <div role="columnheader">Cap</div>
      <div role="columnheader">Status</div>
      <div role="columnheader">Notes</div>
      <div role="columnheader">Save</div>
    </div>
    <?php foreach ($schedules as $schedule): ?>
      <form class="record-row" method="post" action="<?= h(url('records-schedule')) ?>" role="row">
        <?= Csrf::input() ?>
        <input type="hidden" name="id" value="<?= h($schedule['id']) ?>">
        <div role="cell">
          <select name="round_id" required aria-label="Schedule round">
            <?php foreach ($rounds as $round): ?>
              <option value="<?= h($round['id']) ?>" <?= (int) $schedule['round_id'] === (int) $round['id'] ? 'selected' : '' ?>><?= h($round['company_code']) ?> <?= h($round['sequence']) ?>. <?= h($round['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div role="cell"><input name="sequence" value="<?= h($schedule['sequence']) ?>" inputmode="numeric" required aria-label="Schedule sequence"></div>
        <div role="cell"><input name="room" value="<?= h($schedule['room']) ?>" required aria-label="Schedule room"></div>
        <div role="cell"><input name="schedule_day" value="<?= h($schedule['schedule_day'] ?? '') ?>" aria-label="Schedule day"></div>
        <div role="cell"><input name="starts_at" value="<?= h($schedule['starts_at']) ?>" aria-label="Schedule start"></div>
        <div role="cell"><input name="ends_at" value="<?= h($schedule['ends_at']) ?>" aria-label="Schedule end"></div>
        <div role="cell"><input name="capacity" value="<?= h($schedule['capacity']) ?>" inputmode="numeric" aria-label="Schedule capacity"></div>
        <div role="cell">
          <select name="schedule_status" aria-label="Schedule status">
            <?php foreach (['active' => 'Active', 'paused' => 'Paused', 'break' => 'Break', 'cancelled' => 'Cancelled'] as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= ($schedule['schedule_status'] ?? 'active') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div role="cell"><input name="notes" value="<?= h($schedule['notes']) ?>" aria-label="Schedule notes"></div>
        <div role="cell"><button type="submit">Save</button></div>
      </form>
    <?php endforeach; ?>
    <form class="record-row record-new" method="post" action="<?= h(url('records-schedule')) ?>" role="row">
      <?= Csrf::input() ?>
      <div role="cell">
        <select name="round_id" required aria-label="New schedule round">
          <?php foreach ($rounds as $round): ?>
            <option value="<?= h($round['id']) ?>"><?= h($round['company_code']) ?> <?= h($round['sequence']) ?>. <?= h($round['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div role="cell"><input name="sequence" value="1" inputmode="numeric" required aria-label="New schedule sequence"></div>
      <div role="cell"><input name="room" placeholder="Room" required aria-label="New schedule room"></div>
      <div role="cell"><input name="schedule_day" placeholder="1 or 2026-07-01" aria-label="New schedule day"></div>
      <div role="cell"><input name="starts_at" placeholder="09:00" aria-label="New schedule start"></div>
      <div role="cell"><input name="ends_at" placeholder="09:45" aria-label="New schedule end"></div>
      <div role="cell"><input name="capacity" value="0" inputmode="numeric" aria-label="New schedule capacity"></div>
      <div role="cell">
        <select name="schedule_status" aria-label="New schedule status">
          <option value="active">Active</option>
          <option value="paused">Paused</option>
          <option value="break">Break</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
      <div role="cell"><input name="notes" placeholder="Notes" aria-label="New schedule notes"></div>
      <div role="cell"><button class="primary" type="submit">Add</button></div>
    </form>
  </div>
</section>

<section class="panel">
  <h2>Round Panelists</h2>
  <div class="record-grid record-grid-panelists" role="table" aria-label="Round panelists">
    <div class="record-head" role="row">
      <div role="columnheader">Round</div>
      <div role="columnheader">Seq</div>
      <div role="columnheader">Name</div>
      <div role="columnheader">Role</div>
      <div role="columnheader">Affiliation</div>
      <div role="columnheader">Contact</div>
      <div role="columnheader">Availability</div>
      <div role="columnheader">Notes</div>
      <div role="columnheader">Save</div>
    </div>
    <?php foreach ($panelists as $panelist): ?>
      <form class="record-row" method="post" action="<?= h(url('records-panelist')) ?>" role="row">
        <?= Csrf::input() ?>
        <input type="hidden" name="id" value="<?= h($panelist['id']) ?>">
        <div role="cell">
          <select name="round_id" required aria-label="Panelist round">
            <?php foreach ($rounds as $round): ?>
              <option value="<?= h($round['id']) ?>" <?= (int) $panelist['round_id'] === (int) $round['id'] ? 'selected' : '' ?>><?= h($round['company_code']) ?> <?= h($round['sequence']) ?>. <?= h($round['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div role="cell"><input name="sequence" value="<?= h($panelist['sequence']) ?>" inputmode="numeric" required aria-label="Panelist sequence"></div>
        <div role="cell"><input name="name" value="<?= h($panelist['name']) ?>" required aria-label="Panelist name"></div>
        <div role="cell"><input name="role" value="<?= h($panelist['role']) ?>" aria-label="Panelist role"></div>
        <div role="cell"><input name="affiliation" value="<?= h($panelist['affiliation']) ?>" aria-label="Panelist affiliation"></div>
        <div role="cell"><input name="contact" value="<?= h($panelist['contact']) ?>" aria-label="Panelist contact"></div>
        <div role="cell">
          <?php $availabilityStatus = $panelist['availability_status'] ?? 'active'; ?>
          <select name="availability_status" aria-label="Panelist availability">
            <option value="active" <?= $availabilityStatus === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="break" <?= $availabilityStatus === 'break' ? 'selected' : '' ?>>Break</option>
            <option value="unavailable" <?= $availabilityStatus === 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
          </select>
        </div>
        <div role="cell"><input name="notes" value="<?= h($panelist['notes']) ?>" aria-label="Panelist notes"></div>
        <div role="cell"><button type="submit">Save</button></div>
      </form>
    <?php endforeach; ?>
    <form class="record-row record-new" method="post" action="<?= h(url('records-panelist')) ?>" role="row">
      <?= Csrf::input() ?>
      <div role="cell">
        <select name="round_id" required aria-label="New panelist round">
          <?php foreach ($rounds as $round): ?>
            <option value="<?= h($round['id']) ?>"><?= h($round['company_code']) ?> <?= h($round['sequence']) ?>. <?= h($round['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div role="cell"><input name="sequence" value="1" inputmode="numeric" required aria-label="New panelist sequence"></div>
      <div role="cell"><input name="name" placeholder="Panelist name" required aria-label="New panelist name"></div>
      <div role="cell"><input name="role" placeholder="Lead / observer" aria-label="New panelist role"></div>
      <div role="cell"><input name="affiliation" placeholder="<?= h($companyLabel) ?> / college" aria-label="New panelist affiliation"></div>
      <div role="cell"><input name="contact" placeholder="Optional" aria-label="New panelist contact"></div>
      <div role="cell">
        <select name="availability_status" aria-label="New panelist availability">
          <option value="active">Active</option>
          <option value="break">Break</option>
          <option value="unavailable">Unavailable</option>
        </select>
      </div>
      <div role="cell"><input name="notes" placeholder="Notes" aria-label="New panelist notes"></div>
      <div role="cell"><button class="primary" type="submit">Add</button></div>
    </form>
  </div>
</section>

<section class="panel">
  <h2>Interview Slot Assignments</h2>
  <div class="record-grid record-grid-assignments" role="table" aria-label="Interview slot assignments">
    <div class="record-head" role="row">
      <div role="columnheader">Application</div>
      <div role="columnheader">Schedule</div>
      <div role="columnheader">Seq</div>
      <div role="columnheader">Status</div>
      <div role="columnheader">Notes</div>
      <div role="columnheader">Save</div>
    </div>
    <?php foreach ($assignments as $assignment): ?>
      <form class="record-row" method="post" action="<?= h(url('records-slot-assignment')) ?>" role="row">
        <?= Csrf::input() ?>
        <input type="hidden" name="id" value="<?= h($assignment['id']) ?>">
        <div role="cell">
          <select name="application_id" required aria-label="Assigned application">
            <?php foreach ($applications as $application): ?>
              <option value="<?= h($application['id']) ?>" <?= (int) $assignment['application_id'] === (int) $application['id'] ? 'selected' : '' ?>><?= h($application['candidate_external_id']) ?> - <?= h($application['candidate_name']) ?> / <?= h($application['company_code']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div role="cell">
          <select name="round_schedule_id" required aria-label="Assigned schedule">
            <?php foreach ($schedules as $schedule): ?>
              <option value="<?= h($schedule['id']) ?>" <?= (int) $assignment['round_schedule_id'] === (int) $schedule['id'] ? 'selected' : '' ?>><?= h($schedule['company_code']) ?> <?= h($schedule['round_sequence']) ?>. <?= h($schedule['round_label']) ?> / <?= h($schedule['room']) ?> <?= h($schedule['schedule_day'] ?? '') ?> <?= h($schedule['starts_at']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div role="cell"><input name="sequence" value="<?= h($assignment['sequence']) ?>" inputmode="numeric" required aria-label="Assignment sequence"></div>
        <div role="cell"><input name="assignment_status" value="<?= h($assignment['assignment_status']) ?>" aria-label="Assignment status"></div>
        <div role="cell"><input name="notes" value="<?= h($assignment['notes']) ?>" aria-label="Assignment notes"></div>
        <div role="cell"><button type="submit">Save</button></div>
      </form>
    <?php endforeach; ?>
    <form class="record-row record-new" method="post" action="<?= h(url('records-slot-assignment')) ?>" role="row">
      <?= Csrf::input() ?>
      <div role="cell">
        <select name="application_id" required aria-label="New assigned application">
          <?php foreach ($applications as $application): ?>
            <option value="<?= h($application['id']) ?>"><?= h($application['candidate_external_id']) ?> - <?= h($application['candidate_name']) ?> / <?= h($application['company_code']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div role="cell">
        <select name="round_schedule_id" required aria-label="New assigned schedule">
          <?php foreach ($schedules as $schedule): ?>
            <option value="<?= h($schedule['id']) ?>"><?= h($schedule['company_code']) ?> <?= h($schedule['round_sequence']) ?>. <?= h($schedule['round_label']) ?> / <?= h($schedule['room']) ?> <?= h($schedule['schedule_day'] ?? '') ?> <?= h($schedule['starts_at']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div role="cell"><input name="sequence" value="1" inputmode="numeric" required aria-label="New assignment sequence"></div>
      <div role="cell"><input name="assignment_status" value="assigned" aria-label="New assignment status"></div>
      <div role="cell"><input name="notes" placeholder="Notes" aria-label="New assignment notes"></div>
      <div role="cell"><button class="primary" type="submit">Add</button></div>
    </form>
  </div>
</section>

<section class="panel">
  <h2>Shortlist / Application</h2>
  <form method="post" action="<?= h(url('records-application')) ?>">
    <?= Csrf::input() ?>
    <label><?= h($candidateLabel) ?></label>
    <select name="candidate_id" required>
      <?php foreach ($candidates as $candidate): ?>
        <option value="<?= h($candidate['id']) ?>"><?= h($candidate['external_id']) ?> - <?= h($candidate['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label><?= h($companyLabel) ?></label>
    <select name="company_id" required>
      <?php foreach ($companies as $company): ?>
        <option value="<?= h($company['id']) ?>"><?= h($company['code']) ?> - <?= h($company['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Status</label>
    <select name="status">
      <?php foreach ($workflow->statuses() as $key => $status): ?>
        <option value="<?= h($key) ?>"><?= h($workflow->statusLabel($key)) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Waitlist rank</label>
    <input name="waitlist_rank" inputmode="numeric" placeholder="Optional">
    <p><button class="primary" type="submit">Save shortlist/application</button></p>
  </form>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
