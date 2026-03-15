<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher');

// Enforce plan group limit
$limitResource = 'groups';
$limitRedirect = '/educore/modules/groups/';
include __DIR__ . '/../../includes/limit_gate.php';

$pdo    = getDB();
$cid    = centerId();
$uid    = authId();
$editId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$group  = null;
$isEdit = false;

if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM `groups` WHERE id = ? AND center_id = ?");
    $stmt->execute([$editId, $cid]);
    $group = $stmt->fetch();
    if (!$group) { flash('error', 'Group not found.'); redirect('/educore/modules/groups/'); }
    $isEdit = true;
}

$pageTitle  = $isEdit ? 'Edit Group' : 'New Group';
$activePage = 'groups';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Groups',    'href' => '/educore/modules/groups/'],
    ['label' => $pageTitle],
];

// Load teachers
$tStmt = $pdo->prepare("SELECT id, name FROM users WHERE center_id = ? AND role IN ('owner','teacher') AND is_active = 1 ORDER BY name");
$tStmt->execute([$cid]);
$teachers = $tStmt->fetchAll();

$errors = [];
$data   = $group ?? [];

// Parse existing schedule — new format: [{day, time}] or old format {days:[],time:''}
$scheduleSlots = []; // array of {day, time}
if (!empty($group['schedule'])) {
    $sched = json_decode($group['schedule'], true) ?? [];
    // New format: array of slots
    if (isset($sched[0]) && is_array($sched[0])) {
        $scheduleSlots = $sched;
    } elseif (isset($sched['days']) || isset($sched['time'])) {
        // Old single-time format — migrate to multi-slot
        $oldDays = $sched['days'] ?? [];
        $oldTime = $sched['time'] ?? '';
        foreach ($oldDays as $d) {
            $scheduleSlots[] = ['day' => $d, 'time' => $oldTime];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Collect schedule slots from POST
    $slotDays  = $_POST['slot_day']  ?? [];
    $slotTimes = $_POST['slot_time'] ?? [];
    $scheduleSlots = [];
    foreach ($slotDays as $i => $day) {
        $day  = trim($day);
        $time = trim($slotTimes[$i] ?? '');
        if ($day !== '') {
            $scheduleSlots[] = ['day' => $day, 'time' => $time];
        }
    }

    $data = [
        'name'                     => trim($_POST['name'] ?? ''),
        'subject'                  => trim($_POST['subject'] ?? ''),
        'description'              => trim($_POST['description'] ?? ''),
        'teacher_id'               => (int)($_POST['teacher_id'] ?? 0) ?: null,
        'capacity'                 => max(1, (int)($_POST['capacity'] ?? 30)),
        'monthly_fee'              => (float)($_POST['monthly_fee'] ?? 0),
        'start_date'               => $_POST['start_date'] ?? null,
        'end_date'                 => $_POST['end_date'] ?? null,
        'is_active'                => isset($_POST['is_active']) ? 1 : 0,
        'schedule'                 => json_encode($scheduleSlots),
        'session_duration_minutes' => max(15, min(480, (int)($_POST['session_duration_minutes'] ?? 120))),
    ];

    if (!$data['name']) $errors[] = 'Group name is required.';

    if (!$errors) {
        if ($isEdit) {
            $pdo->prepare("UPDATE `groups` SET name=?, subject=?, description=?, teacher_id=?,
                            capacity=?, monthly_fee=?, start_date=?, end_date=?, is_active=?, schedule=?, session_duration_minutes=?
                            WHERE id=? AND center_id=?")
                ->execute([$data['name'], $data['subject'] ?: null, $data['description'] ?: null,
                           $data['teacher_id'], $data['capacity'], $data['monthly_fee'],
                           $data['start_date'] ?: null, $data['end_date'] ?: null,
                           $data['is_active'], $data['schedule'], $data['session_duration_minutes'],
                           $editId, $cid]);
            auditLog('group.updated', 'group', $editId, ['name' => $data['name']]);
            flash('success', 'Group updated successfully.');
        } else {
            $pdo->prepare("INSERT INTO `groups` (center_id, name, subject, description, teacher_id,
                            capacity, monthly_fee, start_date, end_date, is_active, schedule, session_duration_minutes)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$cid, $data['name'], $data['subject'] ?: null, $data['description'] ?: null,
                           $data['teacher_id'], $data['capacity'], $data['monthly_fee'],
                           $data['start_date'] ?: null, $data['end_date'] ?: null,
                           $data['is_active'], $data['schedule'], $data['session_duration_minutes']]);
            $newId = $pdo->lastInsertId();
            auditLog('group.created', 'group', $newId, ['name' => $data['name']]);
            flash('success', 'Group created successfully.');
            redirect("/educore/modules/groups/enroll.php?id=$newId&new=1");
        }
        redirect('/educore/modules/groups/');
    }
}

$dayOptions = ['Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday','Friday'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= $pageTitle ?></h1>
    <p class="page-subtitle"><?= $isEdit ? 'Update group details' : 'Set up a new student group' ?></p>
  </div>
  <a href="/educore/modules/groups/" class="btn btn-ghost">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    Back
  </a>
</div>

<?php if ($errors): ?>
<div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-md);padding:14px 18px;font-size:0.84rem;color:var(--danger);margin-bottom:20px;">
  <?php foreach ($errors as $e): ?><div>• <?= sanitize($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" action="" id="groupForm">
  <?= csrf_field() ?>

  <div style="display:grid; grid-template-columns:2fr 1fr; gap:20px; align-items:start;">

    <!-- Left -->
    <div>

      <!-- Basic Info -->
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">Group Information</span></div>
        <div class="card-body">

          <div class="form-row cols-2">
            <div class="form-group">
              <label class="form-label required">Group Name</label>
              <input type="text" name="name" class="form-control"
                     placeholder="e.g. Math Grade 10 - A"
                     value="<?= sanitize($data['name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Subject</label>
              <input type="text" name="subject" class="form-control"
                     placeholder="e.g. Mathematics"
                     value="<?= sanitize($data['subject'] ?? '') ?>">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2"
                      placeholder="Optional group description..."><?= sanitize($data['description'] ?? '') ?></textarea>
          </div>

          <div class="form-row cols-2">
            <div class="form-group">
              <label class="form-label">Assigned Teacher</label>
              <select name="teacher_id" class="form-control">
                <option value="">Select teacher...</option>
                <?php foreach ($teachers as $t): ?>
                <option value="<?= $t['id'] ?>" <?= ($data['teacher_id'] ?? null) == $t['id'] ? 'selected':'' ?>>
                  <?= sanitize($t['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Max Capacity</label>
              <input type="number" name="capacity" class="form-control" min="1" max="999"
                     value="<?= (int)($data['capacity'] ?? 30) ?>">
            </div>
          </div>

          <div class="form-row cols-2">
            <div class="form-group">
              <label class="form-label">Start Date</label>
              <input type="date" name="start_date" class="form-control"
                     value="<?= sanitize($data['start_date'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">End Date</label>
              <input type="date" name="end_date" class="form-control"
                     value="<?= sanitize($data['end_date'] ?? '') ?>">
            </div>
          </div>

        </div>
      </div>

      <!-- Schedule — Multi-slot (day + time per row) -->
      <div class="card">
        <div class="card-header">
          <span class="card-title">📅 Weekly Schedule</span>
          <span style="font-size:0.76rem;color:var(--text-muted);">Add one row per class session</span>
        </div>
        <div class="card-body">

          <div id="scheduleSlots">
            <?php if (!empty($scheduleSlots)): ?>
              <?php foreach ($scheduleSlots as $i => $slot): ?>
              <div class="schedule-slot" style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;margin-bottom:10px;">
                <div class="form-group" style="margin:0;">
                  <?php if ($i === 0): ?><label class="form-label">Day</label><?php endif; ?>
                  <select name="slot_day[]" class="form-control">
                    <option value="">Select day…</option>
                    <?php foreach ($dayOptions as $d): ?>
                    <option value="<?= $d ?>" <?= ($slot['day'] ?? '') === $d ? 'selected' : '' ?>><?= $d ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group" style="margin:0;">
                  <?php if ($i === 0): ?><label class="form-label">Time</label><?php endif; ?>
                  <input type="time" name="slot_time[]" class="form-control"
                         value="<?= sanitize($slot['time'] ?? '') ?>">
                </div>
                <div>
                  <?php if ($i === 0): ?><label class="form-label" style="visibility:hidden;">X</label><?php endif; ?>
                  <button type="button" onclick="removeSlot(this)" class="btn btn-ghost btn-icon"
                          style="color:var(--danger);<?= count($scheduleSlots) <= 1 ? 'visibility:hidden;' : '' ?>" title="Remove">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </div>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
              <!-- Default one empty slot -->
              <div class="schedule-slot" style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;margin-bottom:10px;">
                <div class="form-group" style="margin:0;">
                  <label class="form-label">Day</label>
                  <select name="slot_day[]" class="form-control">
                    <option value="">Select day…</option>
                    <?php foreach ($dayOptions as $d): ?>
                    <option value="<?= $d ?>"><?= $d ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group" style="margin:0;">
                  <label class="form-label">Time</label>
                  <input type="time" name="slot_time[]" class="form-control">
                </div>
                <div>
                  <label class="form-label" style="visibility:hidden;">X</label>
                  <button type="button" onclick="removeSlot(this)" class="btn btn-ghost btn-icon"
                          style="color:var(--danger);visibility:hidden;" title="Remove">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <button type="button" onclick="addSlot()" class="btn btn-ghost btn-sm">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Another Session
          </button>

          <p class="form-hint" style="margin-top:10px;">
            Example: Saturday 10:00, Monday 14:00, Wednesday 14:00
          </p>
        </div>
      </div>

    </div>

    <!-- Right sidebar -->
    <div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">Pricing</span></div>
        <div class="card-body">
          <div class="form-group" style="margin:0;">
            <label class="form-label">Monthly Fee</label>
            <div style="position:relative;">
              <input type="number" name="monthly_fee" class="form-control"
                     placeholder="0" min="0" step="0.01"
                     value="<?= (float)($data['monthly_fee'] ?? 0) ?>"
                     style="padding-right:50px;">
              <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:0.75rem;color:var(--text-muted);font-weight:600;">EGP</span>
            </div>
            <span class="form-hint">Set 0 for free groups</span>
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">Status</span></div>
        <div class="card-body">
          <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
            <input type="checkbox" name="is_active" value="1"
                   <?= ($data['is_active'] ?? 1) ? 'checked':'' ?>
                   style="accent-color:var(--accent); width:16px; height:16px;">
            <div>
              <div style="font-size:0.85rem; font-weight:600; color:var(--text-primary);">Active Group</div>
              <div style="font-size:0.72rem; color:var(--text-muted);">Accepts attendance sessions</div>
            </div>
          </label>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">⏱ Session Duration</span></div>
        <div class="card-body">
          <div class="form-group" style="margin:0;">
            <label class="form-label">Auto-close after (minutes)</label>
            <input type="number" name="session_duration_minutes" class="form-control"
                   min="15" max="480" step="15"
                   value="<?= (int)($data['session_duration_minutes'] ?? 120) ?>"
                   placeholder="120">
            <span class="form-hint">Sessions auto-open at scheduled time and close after this many minutes. Default: 120 min (2 hours)</span>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <button type="submit" class="btn btn-primary w-full">
            <?= $isEdit ? 'Save Changes' : 'Create Group' ?>
          </button>
          <a href="/educore/modules/groups/" class="btn btn-ghost w-full" style="margin-top:8px;">Cancel</a>
        </div>
      </div>

    </div>
  </div>
</form>

<?php
$dayOpts = json_encode($dayOptions);
$extraScripts = <<<JS
<script>
const DAY_OPTIONS = {$dayOpts};

function buildSlotRow(isFirst) {
    const row = document.createElement('div');
    row.className = 'schedule-slot';
    row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;margin-bottom:10px;';

    const dayOpts = DAY_OPTIONS.map(d => '<option value="' + d + '">' + d + '</option>').join('');

    row.innerHTML =
        '<div class="form-group" style="margin:0;">' +
            '<select name="slot_day[]" class="form-control">' +
                '<option value="">Select day…</option>' +
                dayOpts +
            '</select>' +
        '</div>' +
        '<div class="form-group" style="margin:0;">' +
            '<input type="time" name="slot_time[]" class="form-control">' +
        '</div>' +
        '<div>' +
            '<button type="button" onclick="removeSlot(this)" class="btn btn-ghost btn-icon" style="color:var(--danger);" title="Remove">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
            '</button>' +
        '</div>';
    return row;
}

function addSlot() {
    const container = document.getElementById('scheduleSlots');
    container.appendChild(buildSlotRow(false));
    updateRemoveButtons();
}

function removeSlot(btn) {
    const slots = document.querySelectorAll('.schedule-slot');
    if (slots.length <= 1) return; // keep at least one
    btn.closest('.schedule-slot').remove();
    updateRemoveButtons();
}

function updateRemoveButtons() {
    const slots = document.querySelectorAll('.schedule-slot');
    slots.forEach(function(slot, i) {
        const btn = slot.querySelector('button');
        if (btn) btn.style.visibility = slots.length <= 1 ? 'hidden' : 'visible';
    });
}

updateRemoveButtons();
</script>
JS;
include __DIR__ . '/../../templates/layout_end.php';
?>
