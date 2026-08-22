<?php

use App\Security\Csrf;
use App\Support\Auth;

$title = 'Admin';
$settingsMap = [];
foreach ($settings as $setting) {
    $settingsMap[$setting['key']] = $setting['value'];
}
$boardCardFieldOptions = $boardCardFieldOptions ?? [];
$boardCardFields = $boardCardFields ?? [];
$workflowTransitions = $workflowTransitions ?? [];
$workflowVersions = $workflowVersions ?? [];
$workflowSemanticCategories = $workflowSemanticCategories ?? [];
$workflowGuards = $workflowGuards ?? [];
$workflowEffects = $workflowEffects ?? [];
$canManageSettings = Auth::hasCapability($user, 'portal.settings.manage');
$canManageUsers = Auth::hasCapability($user, 'portal.users.manage');
$canManageWorkflow = Auth::hasCapability($user, 'placement.workflow.manage');
ob_start();
?>
<div class="split">
  <section class="panel">
    <h1>Admin</h1>
    <form method="post" action="<?= h(url('admin')) ?>">
      <?= Csrf::input() ?>
      <label for="college_name">College name</label>
      <input id="college_name" name="college_name" value="<?= h($settingsMap['college_name'] ?? '') ?>" <?= $canManageSettings ? '' : 'disabled' ?>>
      <fieldset class="setting-fieldset">
        <legend>Text identity</legend>
        <label for="site_name">Site name</label>
        <input id="site_name" name="site_name" maxlength="80" value="<?= h($settingsMap['site_name'] ?? cpe_config('app.name')) ?>" placeholder="<?= h(cpe_config('app.name')) ?>" <?= $canManageSettings ? '' : 'disabled' ?>>
        <label for="site_tagline">Site tagline</label>
        <input id="site_tagline" name="site_tagline" maxlength="120" value="<?= h($settingsMap['site_tagline'] ?? '') ?>" placeholder="Placement control room" <?= $canManageSettings ? '' : 'disabled' ?>>
        <label for="public_placements_title">Public placements page title</label>
        <input id="public_placements_title" name="public_placements_title" maxlength="80" value="<?= h($settingsMap['public_placements_title'] ?? 'Public Placements') ?>" placeholder="Placement Results" <?= $canManageSettings ? '' : 'disabled' ?>>
        <label for="candidate_status_title">Candidate status page title</label>
        <input id="candidate_status_title" name="candidate_status_title" maxlength="80" value="<?= h($settingsMap['candidate_status_title'] ?? '') ?>" placeholder="<?= h(cpe_term('candidate')) ?> Status" <?= $canManageSettings ? '' : 'disabled' ?>>
      </fieldset>
      <label for="timezone">Timezone</label>
      <input id="timezone" name="timezone" value="<?= h($settingsMap['timezone'] ?? '') ?>" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="cycle_name">Placement cycle name</label>
      <input id="cycle_name" name="cycle_name" value="<?= h($settingsMap['cycle_name'] ?? '') ?>" placeholder="Final Placements 2026" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="cycle_type">Cycle type</label>
      <select id="cycle_type" name="cycle_type" <?= $canManageSettings ? '' : 'disabled' ?>>
        <?php foreach (['final' => 'Final placement', 'internship' => 'Internship', 'lateral' => 'Lateral placement', 'pooled' => 'Pooled campus drive', 'job_fair' => 'Job fair', 'other' => 'Other'] as $value => $label): ?>
          <option value="<?= h($value) ?>" <?= ($settingsMap['cycle_type'] ?? 'final') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="cycle_start_date">Cycle start date</label>
      <input id="cycle_start_date" type="date" name="cycle_start_date" value="<?= h($settingsMap['cycle_start_date'] ?? '') ?>" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="cycle_end_date">Cycle end date</label>
      <input id="cycle_end_date" type="date" name="cycle_end_date" value="<?= h($settingsMap['cycle_end_date'] ?? '') ?>" <?= $canManageSettings ? '' : 'disabled' ?>>
      <fieldset class="setting-fieldset">
        <legend>Calendar guardrails</legend>
        <label for="calendar_non_operating_weekdays">Non-operating weekdays</label>
        <input id="calendar_non_operating_weekdays" name="calendar_non_operating_weekdays" value="<?= h($settingsMap['calendar_non_operating_weekdays'] ?? '') ?>" placeholder="sat,sun" <?= $canManageSettings ? '' : 'disabled' ?>>
        <label for="calendar_non_operating_dates">Non-operating dates</label>
        <textarea id="calendar_non_operating_dates" name="calendar_non_operating_dates" placeholder="2026-01-26,2026-08-15" <?= $canManageSettings ? '' : 'disabled' ?>><?= h($settingsMap['calendar_non_operating_dates'] ?? '') ?></textarea>
      </fieldset>
      <fieldset class="setting-fieldset">
        <legend>Audit privacy</legend>
        <label for="audit_request_metadata">Request metadata retention</label>
        <select id="audit_request_metadata" name="audit_request_metadata" <?= $canManageSettings ? '' : 'disabled' ?>>
          <?php foreach (['none' => 'None', 'ip' => 'IP address', 'user_agent' => 'User agent', 'both' => 'IP address and user agent'] as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= ($settingsMap['audit_request_metadata'] ?? 'none') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </fieldset>
      <label>
        <input type="checkbox" name="configuration_freeze" value="1" style="width:auto" <?= ($settingsMap['configuration_freeze'] ?? '0') === '1' ? 'checked' : '' ?> <?= $canManageSettings ? '' : 'disabled' ?>>
        Freeze configuration changes before live operations
      </label>
      <fieldset class="setting-fieldset">
        <legend>Local terminology</legend>
        <label for="terminology_candidate_label">Candidate singular label</label>
        <input id="terminology_candidate_label" name="terminology_candidate_label" maxlength="40" value="<?= h($settingsMap['terminology_candidate_label'] ?? 'Candidate') ?>" placeholder="Student" <?= $canManageSettings ? '' : 'disabled' ?>>
        <label for="terminology_candidates_label">Candidate plural label</label>
        <input id="terminology_candidates_label" name="terminology_candidates_label" maxlength="40" value="<?= h($settingsMap['terminology_candidates_label'] ?? 'Candidates') ?>" placeholder="Students" <?= $canManageSettings ? '' : 'disabled' ?>>
        <label for="terminology_company_label">Company singular label</label>
        <input id="terminology_company_label" name="terminology_company_label" maxlength="40" value="<?= h($settingsMap['terminology_company_label'] ?? 'Company') ?>" placeholder="Recruiter" <?= $canManageSettings ? '' : 'disabled' ?>>
        <label for="terminology_companies_label">Company plural label</label>
        <input id="terminology_companies_label" name="terminology_companies_label" maxlength="40" value="<?= h($settingsMap['terminology_companies_label'] ?? 'Companies') ?>" placeholder="Recruiters" <?= $canManageSettings ? '' : 'disabled' ?>>
      </fieldset>
      <label for="notification_delivery_channels">External notification channels</label>
      <input id="notification_delivery_channels" name="notification_delivery_channels" value="<?= h($settingsMap['notification_delivery_channels'] ?? '') ?>" placeholder="file,webhook,email,sms,whatsapp" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="notification_file_outbox_path">Notification JSONL outbox</label>
      <input id="notification_file_outbox_path" name="notification_file_outbox_path" value="<?= h($settingsMap['notification_file_outbox_path'] ?? '') ?>" placeholder="<?= h(cpe_data_path('notification-outbox.jsonl')) ?>" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="notification_email_to">Notification email recipients</label>
      <input id="notification_email_to" type="email" multiple name="notification_email_to" value="<?= h($settingsMap['notification_email_to'] ?? '') ?>" placeholder="placement-office@example.edu" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="notification_email_from">Notification email sender</label>
      <input id="notification_email_from" type="email" name="notification_email_from" value="<?= h($settingsMap['notification_email_from'] ?? '') ?>" placeholder="placements@example.edu" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="notification_message_template">Default message template</label>
      <textarea id="notification_message_template" name="notification_message_template" placeholder="{{subject}} - {{body}}" <?= $canManageSettings ? '' : 'disabled' ?>><?= h($settingsMap['notification_message_template'] ?? '') ?></textarea>
      <label for="notification_email_subject_template">Email subject template</label>
      <input id="notification_email_subject_template" name="notification_email_subject_template" value="<?= h($settingsMap['notification_email_subject_template'] ?? '') ?>" placeholder="{{subject}}" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="notification_email_body_template">Email body template</label>
      <textarea id="notification_email_body_template" name="notification_email_body_template" placeholder="{{body}}" <?= $canManageSettings ? '' : 'disabled' ?>><?= h($settingsMap['notification_email_body_template'] ?? '') ?></textarea>
      <label for="notification_sms_gateway_url">SMS gateway URL</label>
      <input id="notification_sms_gateway_url" type="url" name="notification_sms_gateway_url" value="<?= h($settingsMap['notification_sms_gateway_url'] ?? '') ?>" placeholder="https://sms.example.edu/send" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="notification_sms_to">SMS recipients</label>
      <input id="notification_sms_to" name="notification_sms_to" value="<?= h($settingsMap['notification_sms_to'] ?? '') ?>" placeholder="+910000000000" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="notification_sms_message_template">SMS message template</label>
      <textarea id="notification_sms_message_template" name="notification_sms_message_template" placeholder="{{subject}} - {{body}}" <?= $canManageSettings ? '' : 'disabled' ?>><?= h($settingsMap['notification_sms_message_template'] ?? '') ?></textarea>
      <label for="notification_sms_payload_template">SMS JSON payload template</label>
      <textarea id="notification_sms_payload_template" name="notification_sms_payload_template" placeholder='{"to": {{to}}, "message": {{text}}}' <?= $canManageSettings ? '' : 'disabled' ?>><?= h($settingsMap['notification_sms_payload_template'] ?? '') ?></textarea>
      <label for="notification_whatsapp_gateway_url">WhatsApp gateway URL</label>
      <input id="notification_whatsapp_gateway_url" type="url" name="notification_whatsapp_gateway_url" value="<?= h($settingsMap['notification_whatsapp_gateway_url'] ?? '') ?>" placeholder="https://wa.example.edu/send" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="notification_whatsapp_to">WhatsApp recipients</label>
      <input id="notification_whatsapp_to" name="notification_whatsapp_to" value="<?= h($settingsMap['notification_whatsapp_to'] ?? '') ?>" placeholder="+910000000000" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="notification_whatsapp_message_template">WhatsApp message template</label>
      <textarea id="notification_whatsapp_message_template" name="notification_whatsapp_message_template" placeholder="{{subject}} - {{body}}" <?= $canManageSettings ? '' : 'disabled' ?>><?= h($settingsMap['notification_whatsapp_message_template'] ?? '') ?></textarea>
      <label for="notification_whatsapp_payload_template">WhatsApp JSON payload template</label>
      <textarea id="notification_whatsapp_payload_template" name="notification_whatsapp_payload_template" placeholder='{"to": {{to}}, "body": {{text}}}' <?= $canManageSettings ? '' : 'disabled' ?>><?= h($settingsMap['notification_whatsapp_payload_template'] ?? '') ?></textarea>
      <label for="scheduling_buffer_minutes">Scheduling buffer minutes</label>
      <input id="scheduling_buffer_minutes" type="number" min="0" max="240" step="1" name="scheduling_buffer_minutes" value="<?= h($settingsMap['scheduling_buffer_minutes'] ?? '0') ?>" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="slot_planner_strategy">Slot planner strategy</label>
      <select id="slot_planner_strategy" name="slot_planner_strategy" <?= $canManageSettings ? '' : 'disabled' ?>>
        <?php foreach (['sequence' => 'Schedule order', 'earliest' => 'Earliest time', 'balanced' => 'Balanced load'] as $value => $label): ?>
          <option value="<?= h($value) ?>" <?= ($settingsMap['slot_planner_strategy'] ?? 'sequence') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="slot_optimizer_exact_limit">Exact optimizer limit</label>
      <input id="slot_optimizer_exact_limit" type="number" min="0" max="12" step="1" name="slot_optimizer_exact_limit" value="<?= h($settingsMap['slot_optimizer_exact_limit'] ?? '10') ?>" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="board_refresh_seconds">Board refresh seconds</label>
      <input id="board_refresh_seconds" type="number" min="0" max="600" step="5" name="board_refresh_seconds" value="<?= h($settingsMap['board_refresh_seconds'] ?? '45') ?>" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="export_profile_custom_datasets">Custom export datasets</label>
      <input id="export_profile_custom_datasets" name="export_profile_custom_datasets" value="<?= h($settingsMap['export_profile_custom_datasets'] ?? 'placement_totals,application_status_counts,placements_by_company') ?>" <?= $canManageSettings ? '' : 'disabled' ?>>
      <label for="import_header_aliases_json">Custom import header aliases JSON</label>
      <textarea id="import_header_aliases_json" name="import_header_aliases_json" placeholder='{"external_id":["Campus UID"],"company_code":["Recruiter Short Code"]}' <?= $canManageSettings ? '' : 'disabled' ?>><?= h($settingsMap['import_header_aliases_json'] ?? '') ?></textarea>
      <fieldset class="setting-fieldset">
        <legend>Board card fields</legend>
        <?php foreach ($boardCardFieldOptions as $fieldKey => $fieldLabel): ?>
          <label class="checkline">
            <input type="checkbox" name="board_card_fields[]" value="<?= h($fieldKey) ?>" <?= !empty($boardCardFields[$fieldKey]) ? 'checked' : '' ?> <?= $canManageSettings ? '' : 'disabled' ?>>
            <?= h($fieldLabel) ?>
          </label>
        <?php endforeach; ?>
      </fieldset>
      <label>
        <input type="checkbox" name="placement_freeze" value="1" style="width:auto" <?= ($settingsMap['placement_freeze'] ?? '0') === '1' ? 'checked' : '' ?> <?= $canManageSettings ? '' : 'disabled' ?>>
        Freeze placement decisions unless admin overrides
      </label>
      <label>
        <input type="checkbox" name="allow_offer_upgrade" value="1" style="width:auto" <?= ($settingsMap['allow_offer_upgrade'] ?? '0') === '1' ? 'checked' : '' ?> <?= $canManageSettings ? '' : 'disabled' ?>>
        Allow offer upgrades after a candidate is already placed
      </label>
      <?php if ($canManageSettings): ?>
        <p><button class="primary" type="submit">Save settings</button></p>
      <?php else: ?>
        <p class="muted">Only administrators can update settings.</p>
      <?php endif; ?>
    </form>
  </section>
  <aside class="panel">
    <h2>Roles</h2>
    <table class="table">
      <?php foreach ($roles as $key => $label): ?>
        <tr><th><?= h($key) ?></th><td><?= h($label) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </aside>
</div>

<section class="panel">
  <h2>Users</h2>
  <form method="post" action="<?= h(url('admin-users')) ?>">
    <?= Csrf::input() ?>
    <table class="table">
      <thead><tr><th>Active</th><th>Name</th><th>Email</th><th>Role</th><th>Scope</th></tr></thead>
      <tbody>
      <?php foreach ($users as $row): ?>
        <tr>
          <td><input type="checkbox" name="active[]" value="<?= h($row['id']) ?>" aria-label="Active user: <?= h($row['name']) ?> (<?= h($row['email']) ?>)" style="width:auto" <?= $row['active'] ? 'checked' : '' ?> <?= $canManageUsers ? '' : 'disabled' ?>></td>
          <td><?= h($row['name']) ?></td>
          <td><?= h($row['email']) ?></td>
          <td><?= h($row['role']) ?></td>
          <td><?= h(trim($row['scope_type'] . ' ' . $row['scope_value'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($canManageUsers): ?>
      <p><button type="submit">Save active users</button></p>
    <?php endif; ?>
  </form>

  <?php if ($canManageUsers): ?>
    <h3>Reset password</h3>
    <form method="post" action="<?= h(url('admin-password')) ?>">
      <?= Csrf::input() ?>
      <label for="reset_user_id">User</label>
      <select id="reset_user_id" name="user_id">
        <?php foreach ($users as $row): ?>
          <option value="<?= h($row['id']) ?>"><?= h($row['email']) ?> / <?= h($row['role']) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="reset_user_password">New password</label>
      <input id="reset_user_password" type="password" name="password" minlength="8" autocomplete="new-password" required>
      <p><button type="submit">Reset password</button></p>
    </form>

    <h3>Create user</h3>
    <form method="post" action="<?= h(url('admin-user')) ?>">
      <?= Csrf::input() ?>
      <label for="new_user_name">Name</label>
      <input id="new_user_name" name="name" required>
      <label for="new_user_email">Email</label>
      <input id="new_user_email" type="email" name="email" autocomplete="username" required>
      <label for="new_user_password">Password</label>
      <input id="new_user_password" type="password" name="password" minlength="8" autocomplete="new-password" required>
      <label for="new_user_role">Role</label>
      <select id="new_user_role" name="role">
        <?php foreach ($roles as $key => $label): ?>
          <option value="<?= h($key) ?>"><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="new_user_scope_type">Scope type</label>
      <select id="new_user_scope_type" name="scope_type">
        <option value="">None</option>
        <option value="company">Company</option>
      </select>
      <label for="new_user_scope_value">Scope value</label>
      <select id="new_user_scope_value" name="scope_value">
        <option value="">None</option>
        <?php foreach ($companies as $company): ?>
          <option value="<?= h($company['code']) ?>"><?= h($company['code']) ?> - <?= h($company['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <p><button class="primary" type="submit">Create user</button></p>
    </form>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Workflow</h2>
  <form method="post" action="<?= h(url('admin-workflow')) ?>">
    <?= Csrf::input() ?>
    <input type="hidden" name="workflow_form" value="full">
    <div class="form-grid">
      <div>
        <label for="workflow_name">Name</label>
        <input id="workflow_name" name="name" maxlength="120" value="<?= h($workflow->name()) ?>" <?= $canManageWorkflow ? '' : 'disabled' ?>>
      </div>
      <div>
        <label for="initial_state_key">Initial state</label>
        <select id="initial_state_key" name="initial_state_key" <?= $canManageWorkflow ? '' : 'disabled' ?>>
          <?php foreach ($workflow->statuses() as $key => $status): ?>
            <option value="<?= h($key) ?>" <?= $workflow->initialStateKey() === $key ? 'selected' : '' ?>><?= h($status['label']) ?> / <?= h($key) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <p class="muted">Published version <?= h($workflow->versionNumber() ?? 'legacy') ?></p>

    <h3>States</h3>
    <table class="table">
      <thead><tr><th>Key</th><th>Label</th><th>Category</th><th>Order</th><th>Color</th><th>Terminal</th><th>Remove</th></tr></thead>
      <tbody>
      <?php foreach ($workflow->statuses() as $key => $status): ?>
        <tr>
          <td><?= h($key) ?></td>
          <td><input name="states[<?= h($key) ?>][label]" aria-label="<?= h($key) ?> state label" maxlength="80" value="<?= h($workflow->statusLabel($key)) ?>" <?= $canManageWorkflow ? '' : 'disabled' ?>></td>
          <td>
            <select name="states[<?= h($key) ?>][semantic_category]" aria-label="<?= h($key) ?> state category" <?= $canManageWorkflow ? '' : 'disabled' ?>>
              <?php foreach ($workflowSemanticCategories as $category): ?>
                <option value="<?= h($category) ?>" <?= ($status['semantic_category'] ?? 'waiting') === $category ? 'selected' : '' ?>><?= h($category) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="number" name="states[<?= h($key) ?>][order]" aria-label="<?= h($key) ?> state order" value="<?= h($status['order']) ?>" <?= $canManageWorkflow ? '' : 'disabled' ?>></td>
          <td><input type="color" name="states[<?= h($key) ?>][color]" aria-label="<?= h($key) ?> state color" value="<?= h($status['color']) ?>" <?= $canManageWorkflow ? '' : 'disabled' ?>></td>
          <td><input type="checkbox" name="states[<?= h($key) ?>][is_terminal]" aria-label="<?= h($key) ?> state is terminal" value="1" <?= !empty($status['is_terminal']) ? 'checked' : '' ?> <?= $canManageWorkflow ? '' : 'disabled' ?>></td>
          <td><input type="checkbox" name="states[<?= h($key) ?>][delete]" aria-label="Remove <?= h($key) ?> state" value="1" <?= $canManageWorkflow ? '' : 'disabled' ?>></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <fieldset class="setting-fieldset">
      <legend>Add state</legend>
      <div class="form-grid">
        <div><label for="new_state_key">Key</label><input id="new_state_key" name="new_state[key]" pattern="[a-z][a-z0-9_]*" <?= $canManageWorkflow ? '' : 'disabled' ?>></div>
        <div><label for="new_state_label">Label</label><input id="new_state_label" name="new_state[label]" maxlength="80" <?= $canManageWorkflow ? '' : 'disabled' ?>></div>
        <div>
          <label for="new_state_category">Category</label>
          <select id="new_state_category" name="new_state[semantic_category]" <?= $canManageWorkflow ? '' : 'disabled' ?>>
            <?php foreach ($workflowSemanticCategories as $category): ?>
              <option value="<?= h($category) ?>"><?= h($category) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label for="new_state_order">Order</label><input id="new_state_order" type="number" name="new_state[order]" value="0" <?= $canManageWorkflow ? '' : 'disabled' ?>></div>
        <div><label for="new_state_color">Color</label><input id="new_state_color" type="color" name="new_state[color]" value="#eef0f2" <?= $canManageWorkflow ? '' : 'disabled' ?>></div>
        <label class="checkline"><input type="checkbox" name="new_state[is_terminal]" value="1" <?= $canManageWorkflow ? '' : 'disabled' ?>> Terminal</label>
      </div>
    </fieldset>

    <h3>Transitions</h3>
    <table class="table">
      <thead><tr><th>Key</th><th>From</th><th>To</th><th>Label</th><th>Roles</th><th>Order</th><th>Correction</th><th>Rules</th><th>Remove</th></tr></thead>
      <tbody>
      <?php foreach ($workflowTransitions as $transition): ?>
        <tr>
          <td><?= h($transition['key']) ?></td>
          <td>
            <select name="transitions[<?= h($transition['key']) ?>][from]" aria-label="<?= h($transition['key']) ?> transition from state" <?= $canManageWorkflow ? '' : 'disabled' ?>>
              <?php foreach ($workflow->statuses() as $stateKey => $status): ?>
                <option value="<?= h($stateKey) ?>" <?= $transition['from'] === $stateKey ? 'selected' : '' ?>><?= h($stateKey) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <select name="transitions[<?= h($transition['key']) ?>][to]" aria-label="<?= h($transition['key']) ?> transition to state" <?= $canManageWorkflow ? '' : 'disabled' ?>>
              <?php foreach ($workflow->statuses() as $stateKey => $status): ?>
                <option value="<?= h($stateKey) ?>" <?= $transition['to'] === $stateKey ? 'selected' : '' ?>><?= h($stateKey) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input name="transitions[<?= h($transition['key']) ?>][label]" aria-label="<?= h($transition['key']) ?> transition label" maxlength="80" value="<?= h($transition['label']) ?>" <?= $canManageWorkflow ? '' : 'disabled' ?>></td>
          <td><input name="transitions[<?= h($transition['key']) ?>][roles]" aria-label="<?= h($transition['key']) ?> transition roles" value="<?= h(implode(',', $transition['roles'])) ?>" <?= $canManageWorkflow ? '' : 'disabled' ?>></td>
          <td><input type="number" name="transitions[<?= h($transition['key']) ?>][order]" aria-label="<?= h($transition['key']) ?> transition order" value="<?= h($transition['order']) ?>" <?= $canManageWorkflow ? '' : 'disabled' ?>></td>
          <td><input type="checkbox" name="transitions[<?= h($transition['key']) ?>][is_correction]" aria-label="<?= h($transition['key']) ?> is a correction transition" value="1" <?= !empty($transition['is_correction']) ? 'checked' : '' ?> <?= $canManageWorkflow ? '' : 'disabled' ?>></td>
          <td>
            <details>
              <summary>Rules</summary>
              <?php foreach ($workflowGuards as $guard): ?>
                <label class="checkline"><input type="checkbox" name="transitions[<?= h($transition['key']) ?>][guards][]" aria-label="<?= h($transition['key']) ?> guard: <?= h($guard) ?>" value="<?= h($guard) ?>" <?= in_array($guard, $transition['guards'], true) ? 'checked' : '' ?> <?= $canManageWorkflow ? '' : 'disabled' ?>> <?= h($guard) ?></label>
              <?php endforeach; ?>
              <?php foreach ($workflowEffects as $effect): ?>
                <label class="checkline"><input type="checkbox" name="transitions[<?= h($transition['key']) ?>][effects][]" aria-label="<?= h($transition['key']) ?> effect: <?= h($effect) ?>" value="<?= h($effect) ?>" <?= in_array($effect, $transition['effects'], true) ? 'checked' : '' ?> <?= $canManageWorkflow ? '' : 'disabled' ?>> <?= h($effect) ?></label>
              <?php endforeach; ?>
            </details>
          </td>
          <td><input type="checkbox" name="transitions[<?= h($transition['key']) ?>][delete]" aria-label="Remove <?= h($transition['key']) ?> transition" value="1" <?= $canManageWorkflow ? '' : 'disabled' ?>></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <fieldset class="setting-fieldset">
      <legend>Add transition</legend>
      <div class="form-grid">
        <div><label for="new_transition_key">Key</label><input id="new_transition_key" name="new_transition[key]" pattern="[a-z][a-z0-9_]*" <?= $canManageWorkflow ? '' : 'disabled' ?>></div>
        <div><label for="new_transition_label">Label</label><input id="new_transition_label" name="new_transition[label]" maxlength="80" <?= $canManageWorkflow ? '' : 'disabled' ?>></div>
        <div><label for="new_transition_from">From state</label><input id="new_transition_from" name="new_transition[from]" pattern="[a-z][a-z0-9_]*" <?= $canManageWorkflow ? '' : 'disabled' ?>></div>
        <div><label for="new_transition_to">To state</label><input id="new_transition_to" name="new_transition[to]" pattern="[a-z][a-z0-9_]*" <?= $canManageWorkflow ? '' : 'disabled' ?>></div>
        <div><label for="new_transition_roles">Roles CSV</label><input id="new_transition_roles" name="new_transition[roles]" <?= $canManageWorkflow ? '' : 'disabled' ?>></div>
        <div><label for="new_transition_order">Order</label><input id="new_transition_order" type="number" name="new_transition[order]" value="0" <?= $canManageWorkflow ? '' : 'disabled' ?>></div>
        <label class="checkline"><input type="checkbox" name="new_transition[is_correction]" value="1" <?= $canManageWorkflow ? '' : 'disabled' ?>> Correction</label>
      </div>
      <details>
        <summary>Rules</summary>
        <?php foreach ($workflowGuards as $guard): ?>
          <label class="checkline"><input type="checkbox" name="new_transition[guards][]" aria-label="New transition guard: <?= h($guard) ?>" value="<?= h($guard) ?>" <?= $canManageWorkflow ? '' : 'disabled' ?>> <?= h($guard) ?></label>
        <?php endforeach; ?>
        <?php foreach ($workflowEffects as $effect): ?>
          <label class="checkline"><input type="checkbox" name="new_transition[effects][]" aria-label="New transition effect: <?= h($effect) ?>" value="<?= h($effect) ?>" <?= $canManageWorkflow ? '' : 'disabled' ?>> <?= h($effect) ?></label>
        <?php endforeach; ?>
      </details>
    </fieldset>
    <?php if ($canManageWorkflow): ?>
      <p><button class="primary" type="submit">Publish workflow version</button></p>
    <?php endif; ?>
  </form>

  <h3>Published versions</h3>
  <table class="table">
    <thead><tr><th>Workflow</th><th>Version</th><th>Source</th><th>Published</th><th>Applications</th><th>Active</th></tr></thead>
    <tbody>
    <?php foreach ($workflowVersions as $version): ?>
      <tr>
        <td><?= h($version['workflow_key']) ?></td>
        <td><?= h($version['version_number']) ?></td>
        <td><?= h($version['source_type']) ?></td>
        <td><?= h($version['published_at']) ?></td>
        <td><?= h($version['application_count']) ?></td>
        <td><?= !empty($version['is_active']) ? 'Yes' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
