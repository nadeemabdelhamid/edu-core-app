<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner');

// Only for academy plan
if (!isAcademyPlan()) {
    flash('info', 'Teacher assignment is only available on the Academy plan.');
    redirect('/educore/modules/students/');
}

$pdo = getDB();
$cid = centerId();

$pageTitle  = 'Assign Students to Teachers';
$activePage = 'students';
$breadcrumbs = [
    ['label' => 'Dashboard',  'href' => '/educore/dashboard.php'],
    ['label' => 'Students',   'href' => '/educore/modules/students/'],
    ['label' => 'Assign to Teachers'],
];

$errors = [];

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // Assign one student to one teacher
    if ($action === 'assign') {
        $studentId = (int)$_POST['student_id'];
        $teacherId = (int)$_POST['teacher_id'];

        // Validate both belong to this center
        $sCheck = $pdo->prepare("SELECT id FROM students WHERE id=? AND center_id=?");
        $sCheck->execute([$studentId, $cid]);
        $tCheck = $pdo->prepare("SELECT id FROM users WHERE id=? AND center_id=? AND role='teacher'");
        $tCheck->execute([$teacherId, $cid]);

        if ($sCheck->fetch() && $tCheck->fetch()) {
            $pdo->prepare("INSERT IGNORE INTO student_teachers (center_id, student_id, teacher_id, assigned_by) VALUES (?,?,?,?)")
                ->execute([$cid, $studentId, $teacherId, authId()]);
            auditLog('student.assigned_teacher', 'student', $studentId, ['teacher_id' => $teacherId]);
            flash('success', 'Student assigned to teacher.');
        } else {
            flash('error', 'Invalid student or teacher.');
        }
        redirect('/educore/modules/students/assign_teachers.php');
    }

    // Remove assignment
    if ($action === 'unassign') {
        $studentId = (int)$_POST['student_id'];
        $teacherId = (int)$_POST['teacher_id'];
        $pdo->prepare("DELETE FROM student_teachers WHERE center_id=? AND student_id=? AND teacher_id=?")
            ->execute([$cid, $studentId, $teacherId]);
        auditLog('student.unassigned_teacher', 'student', $studentId, ['teacher_id' => $teacherId]);
        flash('success', 'Assignment removed.');
        redirect('/educore/modules/students/assign_teachers.php');
    }

    // Bulk assign: assign all listed students to a teacher
    if ($action === 'bulk_assign') {
        $teacherId  = (int)$_POST['teacher_id'];
        $studentIds = array_map('intval', $_POST['student_ids'] ?? []);
        $tCheck     = $pdo->prepare("SELECT id FROM users WHERE id=? AND center_id=? AND role='teacher'");
        $tCheck->execute([$teacherId, $cid]);
        if ($tCheck->fetch() && !empty($studentIds)) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO student_teachers (center_id, student_id, teacher_id, assigned_by) VALUES (?,?,?,?)");
            foreach ($studentIds as $sid) {
                $stmt->execute([$cid, $sid, $teacherId, authId()]);
            }
            auditLog('student.bulk_assigned_teacher', 'center', $cid, ['teacher_id' => $teacherId, 'count' => count($studentIds)]);
            flash('success', count($studentIds) . ' student(s) assigned to teacher.');
        }
        redirect('/educore/modules/students/assign_teachers.php');
    }

    // Reassign: move student from one teacher to another
    if ($action === 'reassign') {
        $studentId    = (int)$_POST['student_id'];
        $fromTeacher  = (int)$_POST['from_teacher_id'];
        $toTeacher    = (int)$_POST['to_teacher_id'];
        // Remove old assignment
        $pdo->prepare("DELETE FROM student_teachers WHERE center_id=? AND student_id=? AND teacher_id=?")
            ->execute([$cid, $studentId, $fromTeacher]);
        // Add new assignment
        $pdo->prepare("INSERT IGNORE INTO student_teachers (center_id, student_id, teacher_id, assigned_by) VALUES (?,?,?,?)")
            ->execute([$cid, $studentId, $toTeacher, authId()]);
        auditLog('student.reassigned_teacher', 'student', $studentId, ['from' => $fromTeacher, 'to' => $toTeacher]);
        flash('success', 'Student reassigned successfully.');
        redirect('/educore/modules/students/assign_teachers.php');
    }
}

// ── Load data ─────────────────────────────────────────────────
$teachers = $pdo->prepare("SELECT id, name FROM users WHERE center_id=? AND role='teacher' AND is_active=1 ORDER BY name");
$teachers->execute([$cid]);
$teachers = $teachers->fetchAll();

$filterTeacher = (int)($_GET['teacher'] ?? 0);
$filterUnassigned = isset($_GET['unassigned']);
$search = trim($_GET['q'] ?? '');

// Build student query
$where  = "WHERE s.center_id = ?";
$params = [$cid];

if ($search) {
    $where .= " AND (s.name LIKE ? OR s.email LIKE ? OR s.phone LIKE ?)";
    $like   = "%$search%";
    $params = array_merge($params, [$like, $like, $like]);
}

$students = $pdo->prepare("
    SELECT s.id, s.name, s.email, s.phone, s.student_code,
           GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS assigned_teachers,
           GROUP_CONCAT(DISTINCT st.teacher_id ORDER BY st.teacher_id SEPARATOR ',') AS teacher_ids
    FROM students s
    LEFT JOIN student_teachers st ON st.student_id = s.id AND st.center_id = ?
    LEFT JOIN users u ON u.id = st.teacher_id
    {$where}
    GROUP BY s.id
    ORDER BY s.name
")->execute([$cid] + []) ?: null;

// Re-execute with full params
$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.email, s.phone, s.student_code,
           GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS assigned_teachers,
           GROUP_CONCAT(DISTINCT CAST(st.teacher_id AS CHAR) ORDER BY st.teacher_id SEPARATOR ',') AS teacher_ids
    FROM students s
    LEFT JOIN student_teachers st ON st.student_id = s.id AND st.center_id = s.center_id
    LEFT JOIN users u ON u.id = st.teacher_id AND u.center_id = s.center_id
    {$where}
    GROUP BY s.id
    ORDER BY s.name
");
$stmt->execute($params);
$allStudents = $stmt->fetchAll();

// Apply filters in PHP
if ($filterTeacher) {
    $allStudents = array_filter($allStudents, function($s) use ($filterTeacher) {
        $ids = array_filter(explode(',', $s['teacher_ids'] ?? ''));
        return in_array($filterTeacher, $ids);
    });
}
if ($filterUnassigned) {
    $allStudents = array_filter($allStudents, fn($s) => empty($s['teacher_ids']));
}
$allStudents = array_values($allStudents);

$unassignedCount = count(array_filter($allStudents, fn($s) => empty($s['teacher_ids'])));

include __DIR__ . '/../../templates/layout.php';
?>

<style>
.assign-grid { display:grid; grid-template-columns:280px 1fr; gap:20px; align-items:start; }
.teacher-sidebar { position:sticky; top:20px; }
.teacher-card { border:2px solid transparent; border-radius:var(--radius-md); padding:14px 16px; cursor:pointer; transition:all .15s; background:var(--bg-card); margin-bottom:8px; display:flex;align-items:center;gap:12px; }
.teacher-card:hover { border-color:var(--accent); background:var(--bg-hover); }
.teacher-card.selected { border-color:var(--accent); background:rgba(82,71,230,0.06); }
.teacher-avatar { width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.8rem;font-weight:700;color:#fff;flex-shrink:0; }
.student-row { display:grid; grid-template-columns:32px 1fr 160px 120px; align-items:center; gap:14px; padding:11px 18px; border-bottom:1px solid var(--border); }
.student-row:last-child { border-bottom:none; }
.student-row:hover { background:var(--bg-hover); }
.tag { display:inline-flex;align-items:center;gap:4px;font-size:0.7rem;padding:2px 8px;border-radius:10px;background:rgba(82,71,230,0.1);color:var(--accent);font-weight:500; }
.tag .rm { cursor:pointer;opacity:0.6;font-size:0.8rem;line-height:1; }
.tag .rm:hover { opacity:1;color:var(--danger); }
.unassigned-badge { font-size:0.7rem;color:var(--danger);background:rgba(239,68,68,0.08);padding:2px 7px;border-radius:8px;border:1px solid rgba(239,68,68,0.2); }
@media(max-width:768px){ .assign-grid{grid-template-columns:1fr;} .student-row{grid-template-columns:24px 1fr;} .student-row>*:nth-child(3){display:none;} }
</style>

<div class="page-header">
  <div>
    <h1 class="page-title">Assign Students to Teachers</h1>
    <p class="page-subtitle"><?= count($allStudents) ?> students · <?= $unassignedCount ?> unassigned · <?= count($teachers) ?> teachers</p>
  </div>
  <a href="/educore/modules/students/" class="btn btn-ghost">← Back to Students</a>
</div>

<?php if (empty($teachers)): ?>
<div class="card">
  <div class="card-body" style="text-align:center;padding:48px;">
    <div style="font-size:2.5rem;margin-bottom:12px;">🧑‍🏫</div>
    <div style="font-size:1rem;font-weight:600;margin-bottom:6px;">No teachers yet</div>
    <p style="color:var(--text-muted);font-size:0.84rem;margin-bottom:16px;">Add teachers from Settings → Staff before assigning students.</p>
    <a href="/educore/modules/settings/staff.php" class="btn btn-primary">Manage Staff →</a>
  </div>
</div>
<?php include __DIR__ . '/../../templates/layout_end.php'; exit; ?>
<?php endif; ?>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:12px 16px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <input type="text" name="q" class="form-control" placeholder="Search students…" value="<?= sanitize($search) ?>" style="width:220px;" autocomplete="off">
      <select name="teacher" class="form-control" style="width:180px;" onchange="this.form.submit()">
        <option value="">All teachers</option>
        <?php foreach ($teachers as $t): ?>
        <option value="<?= $t['id'] ?>" <?= $filterTeacher == $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <label style="display:flex;align-items:center;gap:6px;font-size:0.83rem;cursor:pointer;">
        <input type="checkbox" name="unassigned" <?= $filterUnassigned ? 'checked' : '' ?> onchange="this.form.submit()" style="accent-color:var(--accent);">
        Unassigned only
      </label>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <?php if ($search || $filterTeacher || $filterUnassigned): ?>
      <a href="?" class="btn btn-ghost btn-sm">Clear</a>
      <?php endif; ?>
      <span style="margin-left:auto;font-size:0.79rem;color:var(--text-muted);"><?= count($allStudents) ?> shown</span>
    </form>
  </div>
</div>

<div class="assign-grid">

  <!-- Left: Teacher sidebar with bulk assign -->
  <div class="teacher-sidebar">
    <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:10px;">Teachers</div>

    <?php
    $colors = ['#3b82f6','#8b5cf6','#22c55e','#f59e0b','#ec4899','#06b6d4','#ef4444'];
    foreach ($teachers as $i => $t):
        $color = $colors[$i % count($colors)];
        // Count assigned students
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM student_teachers WHERE center_id=? AND teacher_id=?");
        $cnt->execute([$cid, $t['id']]);
        $assignedCount = $cnt->fetchColumn();
    ?>
    <div class="teacher-card" id="tc_<?= $t['id'] ?>" onclick="selectTeacher(<?= $t['id'] ?>, '<?= addslashes(sanitize($t['name'])) ?>', '<?= $color ?>')">
      <div class="teacher-avatar" style="background:<?= $color ?>;"><?= strtoupper(substr($t['name'],0,2)) ?></div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:0.85rem;"><?= sanitize($t['name']) ?></div>
        <div style="font-size:0.72rem;color:var(--text-muted);"><?= $assignedCount ?> student<?= $assignedCount!=1?'s':'' ?> assigned</div>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Bulk assign form -->
    <div class="card" style="margin-top:16px;">
      <div class="card-body" style="padding:14px;">
        <div style="font-size:0.8rem;font-weight:600;margin-bottom:10px;">Bulk Assign</div>
        <form method="POST" id="bulkForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="bulk_assign">
          <input type="hidden" name="teacher_id" id="bulkTeacherId" value="">
          <div id="bulkStudentInputs"></div>
          <div class="form-group" style="margin-bottom:10px;">
            <label class="form-label">Teacher</label>
            <select class="form-control" onchange="document.getElementById('bulkTeacherId').value=this.value;" style="font-size:0.82rem;">
              <option value="">Select teacher…</option>
              <?php foreach ($teachers as $t): ?>
              <option value="<?= $t['id'] ?>"><?= sanitize($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:10px;" id="bulkInfo">Select students using checkboxes, then click assign.</div>
          <button type="submit" class="btn btn-primary btn-sm w-full" id="bulkBtn" disabled>Assign Selected (0)</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Right: Students table -->
  <div>
    <div class="card">
      <div style="display:grid;grid-template-columns:32px 1fr 160px 120px;gap:14px;padding:9px 18px;border-bottom:1px solid var(--border);background:var(--bg-elevated);">
        <div><input type="checkbox" id="checkAll" onchange="toggleAll(this)" style="accent-color:var(--accent);"></div>
        <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">Student</div>
        <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">Assigned Teachers</div>
        <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">Quick Assign</div>
      </div>
      <div class="card-body no-padding">
        <?php if (empty($allStudents)): ?>
        <div class="empty-state"><div class="empty-icon">👥</div><div class="empty-title">No students found</div></div>
        <?php else: ?>
        <?php foreach ($allStudents as $s):
          $assignedTeacherIds = array_filter(explode(',', $s['teacher_ids'] ?? ''));
        ?>
        <div class="student-row">
          <!-- Checkbox -->
          <div>
            <input type="checkbox" class="student-check" value="<?= $s['id'] ?>"
                   onchange="updateBulkCount()" style="accent-color:var(--accent);">
          </div>

          <!-- Student info -->
          <div>
            <div style="font-weight:600;font-size:0.86rem;"><?= sanitize($s['name']) ?></div>
            <div style="font-size:0.73rem;color:var(--text-muted);"><?= sanitize($s['email'] ?: ($s['phone'] ?: '—')) ?></div>
          </div>

          <!-- Assigned teachers with remove -->
          <div style="display:flex;flex-wrap:wrap;gap:4px;">
            <?php if (empty($assignedTeacherIds)): ?>
              <span class="unassigned-badge">Unassigned</span>
            <?php else: ?>
              <?php
              foreach ($teachers as $t):
                if (!in_array($t['id'], $assignedTeacherIds)) continue;
              ?>
              <span class="tag">
                <?= sanitize($t['name']) ?>
                <form method="POST" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="unassign">
                  <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                  <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
                  <button type="submit" class="rm" title="Remove" onclick="return confirm('Remove this assignment?')">✕</button>
                </form>
              </span>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <!-- Quick assign dropdown -->
          <div>
            <form method="POST" style="display:flex;gap:4px;align-items:center;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="assign">
              <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
              <select name="teacher_id" class="form-control" style="font-size:0.75rem;padding:4px 6px;height:auto;" required>
                <option value="">+ Assign</option>
                <?php foreach ($teachers as $t): ?>
                <?php if (!in_array($t['id'], $assignedTeacherIds)): ?>
                <option value="<?= $t['id'] ?>"><?= sanitize($t['name']) ?></option>
                <?php endif; ?>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-primary btn-sm" style="padding:4px 8px;">+</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
function toggleAll(cb) {
    document.querySelectorAll('.student-check').forEach(c => c.checked = cb.checked);
    updateBulkCount();
}

function updateBulkCount() {
    const checked = document.querySelectorAll('.student-check:checked');
    const btn = document.getElementById('bulkBtn');
    const info = document.getElementById('bulkInfo');
    btn.textContent = `Assign Selected (${checked.length})`;
    btn.disabled = checked.length === 0;
    info.textContent = checked.length > 0 ? `${checked.length} student(s) selected.` : 'Select students using checkboxes, then click assign.';

    // Rebuild hidden inputs for bulk form
    const container = document.getElementById('bulkStudentInputs');
    container.innerHTML = '';
    checked.forEach(c => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'student_ids[]'; inp.value = c.value;
        container.appendChild(inp);
    });
}

function selectTeacher(id, name, color) {
    document.querySelectorAll('.teacher-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('tc_' + id)?.classList.add('selected');
}

// Prevent bulk submit if no teacher selected
document.getElementById('bulkForm').addEventListener('submit', function(e) {
    if (!document.getElementById('bulkTeacherId').value) {
        e.preventDefault();
        alert('Please select a teacher first.');
    }
});
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
