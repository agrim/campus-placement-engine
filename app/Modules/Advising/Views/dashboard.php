<?php

use App\Security\Csrf;
use App\Support\Auth;

$title = 'Career Advising';
$canManageAppointments = Auth::hasCapability($user, 'advising.appointments.manage');
$canManageNotes = Auth::hasCapability($user, 'advising.notes.manage');
$canManageTasks = Auth::hasCapability($user, 'advising.tasks.manage');
$statusTransitions = [
    'requested' => ['scheduled' => 'Schedule', 'cancelled' => 'Cancel'],
    'scheduled' => ['completed' => 'Complete', 'cancelled' => 'Cancel', 'no_show' => 'No-show'],
    'no_show' => ['scheduled' => 'Reschedule'],
];
ob_start();
?>
<div class="page-head">
  <div>
    <h1>Career Advising</h1>
    <div class="muted"><?= h(cpe_setting('timezone', 'UTC')) ?> local time</div>
  </div>
  <div class="grid-stats">
    <div class="stat"><strong><?= (int) $stats['upcoming'] ?></strong>Upcoming</div>
    <div class="stat"><strong><?= (int) $stats['completed'] ?></strong>Completed</div>
    <div class="stat"><strong><?= (int) $stats['open_tasks'] ?></strong>Open tasks</div>
    <div class="stat"><strong><?= (int) $stats['students_seen'] ?></strong>Students seen</div>
  </div>
</div>

<?php if ($canManageAppointments): ?>
<section class="panel">
  <h2>New appointment</h2>
  <form method="post" action="<?= h(url('advising-appointment')) ?>">
    <?= Csrf::input() ?>
    <div class="form-grid">
      <label>Student
        <select name="student_profile_id" required>
          <option value="">Select student</option>
          <?php foreach ($students as $student): ?>
            <option value="<?= (int) $student['id'] ?>"><?= h($student['display_name']) ?> / <?= h($student['external_id']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Advisor
        <select name="adviser_user_id">
          <option value="">Unassigned</option>
          <?php foreach ($advisers as $adviser): ?>
            <option value="<?= (int) $adviser['id'] ?>"><?= h($adviser['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Start
        <input type="datetime-local" name="starts_at" required>
      </label>
      <label>End
        <input type="datetime-local" name="ends_at" required>
      </label>
      <label>Mode
        <select name="appointment_mode">
          <option value="in_person">In person</option>
          <option value="video">Video</option>
          <option value="phone">Phone</option>
        </select>
      </label>
      <label>Status
        <select name="appointment_status">
          <option value="scheduled">Scheduled</option>
          <option value="requested">Requested</option>
        </select>
      </label>
      <label>Location
        <input name="location" maxlength="200">
      </label>
      <label>Topic
        <input name="topic" maxlength="200" required>
      </label>
    </div>
    <label>Student notes
      <textarea class="textarea-compact" name="student_notes" maxlength="2000"></textarea>
    </label>
    <button class="primary" type="submit">Create appointment</button>
  </form>
</section>
<?php endif; ?>

<section class="panel">
  <h2>Appointments</h2>
  <?php if ($appointments === []): ?>
    <div class="empty">No advising appointments.</div>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Time</th><th>Student</th><th>Advisor</th><th>Topic</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($appointments as $appointment): ?>
        <tr>
          <td><?= h($appointment['starts_at_display']) ?><br><span class="muted">to <?= h($appointment['ends_at_display']) ?></span></td>
          <td><strong><?= h($appointment['student_name']) ?></strong><br><span class="muted"><?= h($appointment['external_id']) ?></span></td>
          <td><?= h($appointment['adviser_name']) ?><br><span class="muted"><?= h(str_replace('_', ' ', $appointment['appointment_mode'])) ?></span></td>
          <td><?= h($appointment['topic']) ?><?= $appointment['location'] !== '' ? '<br><span class="muted">' . h($appointment['location']) . '</span>' : '' ?></td>
          <td><span class="badge"><?= h(str_replace('_', ' ', $appointment['appointment_status'])) ?></span><br><span class="muted"><?= (int) $appointment['note_count'] ?> notes</span></td>
          <td>
            <?php if ($canManageAppointments && isset($statusTransitions[$appointment['appointment_status']])): ?>
              <form method="post" action="<?= h(url('advising-status')) ?>">
                <?= Csrf::input() ?>
                <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                <select name="appointment_status">
                  <?php foreach ($statusTransitions[$appointment['appointment_status']] as $value => $label): ?>
                    <option value="<?= h($value) ?>"><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit">Update</button>
              </form>
            <?php endif; ?>
            <?php if ($canManageNotes): ?>
              <details>
                <summary>Add staff note</summary>
                <form method="post" action="<?= h(url('advising-note')) ?>">
                  <?= Csrf::input() ?>
                  <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                  <textarea class="textarea-compact" name="body" maxlength="4000" required></textarea>
                  <button type="submit">Add note</button>
                </form>
              </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<div class="split">
  <section class="panel">
    <h2>Follow-up tasks</h2>
    <?php if ($tasks === []): ?>
      <div class="empty">No advising tasks.</div>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>Subject</th><th>Task</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tasks as $task): ?>
          <tr>
            <td><?= h($task['subject_name']) ?></td>
            <td><strong><?= h($task['title']) ?></strong><br><span class="muted"><?= h($task['detail']) ?></span></td>
            <td><?= h($task['task_status']) ?><?= $task['due_on'] !== '' ? '<br><span class="muted">' . h($task['due_on']) . '</span>' : '' ?></td>
            <td>
              <?php if ($canManageTasks && $task['task_status'] === 'open'): ?>
                <form method="post" action="<?= h(url('advising-task')) ?>">
                  <?= Csrf::input() ?>
                  <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                  <button type="submit">Complete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2>Recent staff notes</h2>
    <?php if ($notes === []): ?>
      <div class="empty">No staff notes.</div>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>Student</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($notes as $note): ?>
          <tr>
            <td><strong><?= h($note['student_name']) ?></strong><br><span class="muted"><?= h($note['author_name']) ?> / <?= h($note['created_at']) ?></span></td>
            <td><?= h($note['body']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</div>
<?php
$content = ob_get_clean();
require cpe_path('app/Views/layout.php');
