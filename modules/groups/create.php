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
$days   = [];
$time   = '';

// Parse existing schedule
if (!empty($group['schedule'])) {
    $sched = json_decode($group['schedule'], true) ?? [];
    $days  = $sched['days'] ?? [];
    $time  = $sched['time'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $days = $_POST['days'] ?? [];
    $time = trim($_POST['time'] ?? '');

    $data = [
        'name'        => trim($_POST['name'] ?? ''),
        'subject'     => trim($_POST['subject'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'teacher_id'  => (int)($_POST['teacher_id'] ?? 0) ?: null,
        'capacity'    => max(1, (int)($_POST['capacity'] ?? 30)),
        'monthly_fee' => (float)($_POST['monthly_fee'] ?? 0),
        'start_date'  => $_POST['start_date'] ?? null,
        'end_date'    => $_POST['end_date'] ?? null,
        'is_active'   => isset($_POST['is_active']) ? 1 : 0,
        'schedule'    => json_encode(['days' => $days, 'time' => $time]),
    ];

    if (!$data['name']) $errors[] = 'Group name is required.';

    if (!$errors) {
        if ($isEdit) {
            $pdo->prepare("UPDATE `groups` SET name=?, subject=?, description=?, teacher_id=?,
                            capacity=?, monthly_fee=?, start_date=?, end_date=?, is_active=?, schedule=?
                            WHERE id=? AND center_id=?")
                ->execute([$data['name'], $data['subject'] ?: null, $data['description'] ?: null,
                           $data['teacher_id'], $data['capacity'], $data['monthly_fee'],
                           $data['start_date'] ?: null, $data['end_date'] ?: null,
                           $data['is_active'], $data['schedule'], $editId, $cid]);
            auditLog('group.updated', 'group', $editId, ['name' => $data['name']]);
            flash('success', 'Group updated successfully.');
        } else {
            $pdo->prepare("INSERT INTO `groups` (center_id, name, subject, description, teacher_id,
                            capacity, monthly_fee, start_date, end_date, is_active, schedule)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$cid, $data['name'], $data['subject'] ?: null, $data['description'] ?: null,
                           $data['teacher_id'], $data['capacity'], $data['monthly_fee'],
                           $data['start_date'] ?: null, $data['end_date'] ?: null,
                           $data['is_active'], $data['schedule']]);
            $newId = $pdo->lastInsertId();
            auditLog('group.created', 'group', $newId, ['name' => $data['name']]);
            flash('success', 'Group created successfully.');

            // Offer to enroll students
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

<form method="POST" action="">
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

      <!-- Schedule -->
      <div class="card">
        <div class="card-header"><span class="card-title">Weekly Schedule</span></div>
        <div class="card-body">
          <div class="form-group">
            <label class="form-label">Class Days</label>
            <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:4px;">
              <?php foreach ($dayOptions as $day): ?>
              <label style="display:flex; align-items:center; gap:6px; padding:7px 12px;
                             background:var(--bg-elevated); border:1px solid var(--border);
                             border-radius:var(--radius-sm); cursor:pointer; font-size:0.82rem;
                             transition:all 0.15s; user-select:none;"
                     id="lbl_<?= $day ?>">
                <input type="checkbox" name="days[]" value="<?= $day ?>"
                       <?= in_array($day, $days) ? 'checked':'' ?>
                       onchange="toggleDay(this, '<?= $day ?>')"
                       style="accent-color:var(--accent); width:14px; height:14px;">
                <?= $day ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="form-group" style="margin-top:16px;">
            <label class="form-label">Class Time</label>
            <input type="time" name="time" class="form-control" style="width:180px;"
                   value="<?= sanitize($time) ?>">
          </div>
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
$extraScripts = <<<JS
<script>
function toggleDay(cb, day) {
  const lbl = document.getElementById('lbl_' + day);
  if (cb.checked) {
    lbl.style.background = 'var(--accent-subtle)';
    lbl.style.borderColor = 'rgba(245,166,35,0.4)';
    lbl.style.color = 'var(--accent)';
  } else {
    lbl.style.background = 'var(--bg-elevated)';
    lbl.style.borderColor = 'var(--border)';
    lbl.style.color = '';
  }
}
// Init checked state
document.querySelectorAll('input[name="days[]"]:checked').forEach(cb => {
  toggleDay(cb, cb.value);
});
</script>
JS;
include __DIR__ . '/../../templates/layout_end.php';
?>
