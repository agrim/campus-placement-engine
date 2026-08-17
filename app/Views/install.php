<?php

use App\Domain\Workflow;
use App\Security\Csrf;

$title = 'Install Campus Placement Engine';
$workflows = Workflow::available();
ob_start();
?>
<div class="setup-layout">
  <section class="panel">
    <h1>First-run setup</h1>
    <p class="muted">This installer creates the application database, local configuration, first administrator, starter workflow, and an optional live dummy placement drive.</p>
    <ol class="setup-steps" aria-label="Setup path">
      <li><strong>1</strong><span>Check system</span></li>
      <li><strong>2</strong><span>Name the placement desk</span></li>
      <li><strong>3</strong><span>Choose cycle and workflow</span></li>
      <li><strong>4</strong><span>Create administrator</span></li>
      <li><strong>5</strong><span>Start live dummy drive</span></li>
    </ol>
    <form method="post" action="/install.php">
      <?= Csrf::input() ?>
      <fieldset class="setup-section">
        <legend><span>1</span> College and site identity</legend>
        <p class="muted">These text labels can be changed later from Admin.</p>

        <label for="college_name">College name</label>
        <input id="college_name" name="college_name" value="Demo College" required>

        <label for="site_name">Site name</label>
        <input id="site_name" name="site_name" value="<?= h(cpe_config('app.name')) ?>" maxlength="80" required>

        <label for="site_tagline">Site tagline</label>
        <input id="site_tagline" name="site_tagline" maxlength="120" placeholder="Placement control room">

        <label for="public_placements_title">Public placements page title</label>
        <input id="public_placements_title" name="public_placements_title" value="<?= h(cpe_config('settings.public_placements_title', 'Public Placements')) ?>" maxlength="80" required>

        <label for="candidate_status_title">Candidate status page title</label>
        <input id="candidate_status_title" name="candidate_status_title" value="<?= h(cpe_config('settings.candidate_status_title', '')) ?>" maxlength="80" placeholder="Candidate Status">
      </fieldset>

      <fieldset class="setup-section">
        <legend><span>2</span> Placement cycle</legend>
        <label for="timezone">Timezone</label>
        <input id="timezone" name="timezone" value="Asia/Kolkata" maxlength="64" required>

        <label for="cycle_name">Placement cycle name</label>
        <input id="cycle_name" name="cycle_name" value="Final Placements 2026" required>

        <label for="cycle_type">Cycle type</label>
        <select id="cycle_type" name="cycle_type">
          <option value="final">Final placement</option>
          <option value="internship">Internship</option>
          <option value="lateral">Lateral placement</option>
          <option value="pooled">Pooled campus drive</option>
          <option value="job_fair">Job fair</option>
          <option value="other">Other</option>
        </select>

        <div class="setup-two-col">
          <div>
            <label for="cycle_start_date">Cycle start date</label>
            <input id="cycle_start_date" type="date" name="cycle_start_date">
          </div>
          <div>
            <label for="cycle_end_date">Cycle end date</label>
            <input id="cycle_end_date" type="date" name="cycle_end_date">
          </div>
        </div>

        <label for="calendar_non_operating_weekdays">Non-operating weekdays</label>
        <input id="calendar_non_operating_weekdays" name="calendar_non_operating_weekdays" placeholder="sat,sun">

        <label for="calendar_non_operating_dates">Non-operating dates</label>
        <textarea class="textarea-compact" id="calendar_non_operating_dates" name="calendar_non_operating_dates" placeholder="2026-01-26,2026-08-15"></textarea>
      </fieldset>

      <fieldset class="setup-section">
        <legend><span>3</span> Local terminology and privacy</legend>
        <label for="terminology_candidate_label">Candidate singular label</label>
        <input id="terminology_candidate_label" name="terminology_candidate_label" maxlength="40" value="Candidate" placeholder="Student">
        <label for="terminology_candidates_label">Candidate plural label</label>
        <input id="terminology_candidates_label" name="terminology_candidates_label" maxlength="40" value="Candidates" placeholder="Students">
        <label for="terminology_company_label">Company singular label</label>
        <input id="terminology_company_label" name="terminology_company_label" maxlength="40" value="Company" placeholder="Recruiter">
        <label for="terminology_companies_label">Company plural label</label>
        <input id="terminology_companies_label" name="terminology_companies_label" maxlength="40" value="Companies" placeholder="Recruiters">

        <label for="audit_request_metadata">Audit request metadata retention</label>
        <select id="audit_request_metadata" name="audit_request_metadata">
          <option value="none">None</option>
          <option value="ip">IP address</option>
          <option value="user_agent">User agent</option>
          <option value="both">IP address and user agent</option>
        </select>
      </fieldset>

      <fieldset class="setup-section">
        <legend><span>4</span> Starter workflow</legend>
        <label for="workflow">Placement workflow</label>
        <select id="workflow" name="workflow">
          <?php foreach ($workflows as $key => $workflow): ?>
            <option value="<?= h($key) ?>"><?= h($workflow['name']) ?> (<?= h((string) count($workflow['statuses'] ?? [])) ?> stages)</option>
          <?php endforeach; ?>
        </select>
      </fieldset>

      <fieldset class="setup-section">
        <legend><span>5</span> First administrator</legend>
        <label for="admin_name">Admin name</label>
        <input id="admin_name" name="admin_name" value="Placement Admin" required>

        <label for="admin_email">Admin email</label>
        <input id="admin_email" type="email" name="admin_email" value="admin@example.test" autocomplete="username" required>

        <label for="admin_password">Admin password</label>
        <input id="admin_password" type="password" name="admin_password" minlength="8" autocomplete="new-password" required>
      </fieldset>

      <fieldset class="setup-section">
        <legend><span>6</span> Live dummy placement drive</legend>
        <label class="checkline">
          <input type="checkbox" name="seed_demo" value="1" checked>
          Start with a fully live dummy database
        </label>
        <p class="muted">
          This loads synthetic candidates, companies, role accounts, rounds,
          schedules, panels, slots, and active board states. Clear it later from
          System before importing actual data.
        </p>
      </fieldset>

      <p class="setup-submit"><button class="primary" type="submit" <?= !empty($requirementsOk) ? '' : 'disabled' ?>>Install and open board</button></p>
      <?php if (empty($requirementsOk)): ?>
        <p class="muted">Fix the system checks before installing.</p>
      <?php endif; ?>
    </form>
  </section>
  <aside class="panel">
    <h2>System checks</h2>
    <p class="muted">Installation stays locked until every required check passes.</p>
    <table class="table">
      <?php foreach (($requirements ?? []) as $check): ?>
        <tr>
          <th><?= h($check['label']) ?></th>
          <td><span class="badge <?= $check['ok'] ? 'ok' : 'fail' ?>"><?= $check['ok'] ? 'OK' : 'ERROR' ?></span></td>
          <td><?= h($check['value']) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr><th>Database</th><td colspan="2"><?= h($databasePath ?? '') ?></td></tr>
      <tr><th>Frontend</th><td colspan="2">No images, no build step</td></tr>
    </table>
    <h2>After install</h2>
    <ol class="compact-list">
      <li>Use the dummy board to learn the live workflow.</li>
      <li>Clear dummy data from System.</li>
      <li>Import actual candidates, companies, rounds, and shortlists.</li>
      <li>Run readiness before the placement day.</li>
    </ol>
  </aside>
</div>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
