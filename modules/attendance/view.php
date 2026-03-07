<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pdo  = getDB();
$cid  = centerId();
$sid  = (int)($_GET['id'] ?? 0);

if (!$sid) redirect('/educore/modules/attendance/');

// Load session
$sStmt = $pdo->prepare("
    SELECT s.*, g.name AS group_name, g.subject, g.id AS group_id, u.name AS teacher_name
    FROM attendance_sessions s
    JOIN `groups` g ON g.id = s.group_id
    JOIN users u ON u.id = s.teacher_id
    WHERE s.id = ? AND s.center_id = ?");
$sStmt->execute([$sid, $cid]);
$session = $sStmt->fetch();
if (!$session) { flash('error', 'Session not found.'); redirect('/educore/modules/attendance/'); }

$pageTitle   = sanitize($session['group_name']) . ' — ' . date('M j, Y', strtotime($session['session_date']));
$activePage  = 'attendance';
$breadcrumbs = [
    ['label' => 'Dashboard',  'href' => '/educore/dashboard.php'],
    ['label' => 'Attendance', 'href' => '/educore/modules/attendance/'],
    ['label' => $pageTitle],
];

// ── Handle POST (manual override / close) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if ($studentId && in_array($newStatus, ['present', 'absent', 'late', 'excused'])) {
            $pdo->prepare("INSERT INTO attendance_records (session_id, student_id, center_id, status, submitted_at, ip_address)
                            VALUES (?,?,?,?,NOW(),'manual')
                            ON DUPLICATE KEY UPDATE status=VALUES(status), submitted_at=VALUES(submitted_at), ip_address='manual'")
                ->execute([$sid, $studentId, $cid, $newStatus]);
            auditLog('attendance.manual_override', 'attendance_record', $sid, [
                'student_id' => $studentId, 'status' => $newStatus
            ]);
            flash('success', 'Attendance updated.');
        }
    }

    if ($action === 'bulk_status') {
        $bulkStatus = $_POST['bulk_status'] ?? '';
        $target     = $_POST['target'] ?? 'all';
        if (in_array($bulkStatus, ['present', 'absent', 'late', 'excused'])) {
            // Get all enrolled students
            $enrolledStmt = $pdo->prepare("SELECT student_id FROM group_students WHERE group_id=? AND status='active'");
            $enrolledStmt->execute([$session['group_id']]);
            $enrolled = $enrolledStmt->fetchAll(PDO::FETCH_COLUMN);

            if ($target === 'unsubmitted') {
                $existStmt = $pdo->prepare("SELECT student_id FROM attendance_records WHERE session_id=?");
                $existStmt->execute([$sid]);
                $submitted = $existStmt->fetchAll(PDO::FETCH_COLUMN);
                $enrolled  = array_diff($enrolled, $submitted);
            }

            foreach ($enrolled as $stId) {
                $pdo->prepare("INSERT INTO attendance_records (session_id, student_id, center_id, status, submitted_at, ip_address)
                                VALUES (?,?,?,?,NOW(),'manual')
                                ON DUPLICATE KEY UPDATE status=VALUES(status), submitted_at=VALUES(submitted_at), ip_address='manual'")
                    ->execute([$sid, $stId, $cid, $bulkStatus]);
            }
            auditLog('attendance.bulk_override', 'attendance_session', $sid, ['status' => $bulkStatus, 'target' => $target, 'count' => count($enrolled)]);
            flash('success', 'Bulk update applied to ' . count($enrolled) . ' students.');
        }
    }

    if ($action === 'close_session') {
        // Auto-mark missing students as absent
        $enrolledStmt = $pdo->prepare("SELECT student_id FROM group_students WHERE group_id=? AND status='active'");
        $enrolledStmt->execute([$session['group_id']]);
        $enrolled = $enrolledStmt->fetchAll(PDO::FETCH_COLUMN);

        $existStmt = $pdo->prepare("SELECT student_id FROM attendance_records WHERE session_id=?");
        $existStmt->execute([$sid]);
        $submitted = $existStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach (array_diff($enrolled, $submitted) as $stId) {
            $pdo->prepare("INSERT IGNORE INTO attendance_records (session_id, student_id, center_id, status, submitted_at, ip_address) VALUES (?,?,?,'absent',NOW(),'auto')")
                ->execute([$sid, $stId, $cid]);
        }
        $pdo->prepare("UPDATE attendance_sessions SET status='closed' WHERE id=? AND center_id=?")->execute([$sid, $cid]);
        auditLog('attendance.session_closed', 'attendance_session', $sid);
        flash('success', 'Session closed.');
    }

    if ($action === 'add_notes') {
        $notes = trim($_POST['session_notes'] ?? '');
        $pdo->prepare("UPDATE attendance_sessions SET notes=? WHERE id=? AND center_id=?")->execute([$notes, $sid, $cid]);
        flash('success', 'Notes saved.');
    }

    redirect("view.php?id=$sid");
}

// ── Load all enrolled students & records ────────────────────
$stuStmt = $pdo->prepare("
    SELECT s.*, gs.enrolled_at
    FROM group_students gs
    JOIN students s ON s.id = gs.student_id
    WHERE gs.group_id = ? AND gs.status = 'active'
    ORDER BY s.name");
$stuStmt->execute([$session['group_id']]);
$allStudents = $stuStmt->fetchAll();

$recStmt = $pdo->prepare("SELECT * FROM attendance_records WHERE session_id=?");
$recStmt->execute([$sid]);
$records = [];
foreach ($recStmt->fetchAll() as $r) {
    $records[$r['student_id']] = $r;
}

// ── Calculate stats ──────────────────────────────────────────
$totalStudents = count($allStudents);
$counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
foreach ($allStudents as $stu) {
    $st = $records[$stu['id']]['status'] ?? 'absent';
    $counts[$st] = ($counts[$st] ?? 0) + 1;
}
$presentTotal = $counts['present'] + $counts['late'];
$rate = $totalStudents > 0 ? round(($presentTotal / $totalStudents) * 100) : 0;

// ── Historical rate for this student/group (for tooltip) ───
$statusBadge = [
    'present' => 'badge-success',
    'absent'  => 'badge-danger',
    'late'    => 'badge-warning',
    'excused' => 'badge-info',
];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">
      <?= sanitize($session['group_name']) ?>
      <span style="font-size:0.85rem; font-weight:400; color:var(--text-muted);"> · <?= date('l, M j, Y', strtotime($session['session_date'])) ?></span>
    </h1>
    <p class="page-subtitle">
      <?php if ($session['subject']): ?><?= sanitize($session['subject']) ?> · <?php endif; ?>
      <?php if ($session['topic']): ?><em><?= sanitize($session['topic']) ?></em> · <?php endif; ?>
      Teacher: <?= sanitize($session['teacher_name']) ?>
    </p>
  </div>
  <div style="display:flex; gap:8px; align-items:center;">
    <?php if ($session['status'] === 'open'): ?>
      <a href="create.php?session=<?= $sid ?>" class="btn btn-success btn-sm">
        <span style="animation:pulse 2s infinite; display:inline-block;">●</span> Live View
      </a>
      <form method="POST" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="close_session">
        <button class="btn btn-danger btn-sm" onclick="return confirm('Close session and auto-mark absent students?')">Close Session</button>
      </form>
    <?php else: ?>
      <span class="badge badge-muted" style="padding:6px 12px;">Closed</span>
    <?php endif; ?>
  </div>
</div>

<!-- ── Stats Row ─────────────────────────────────────────────── -->
<div class="stats-grid" style="grid-template-columns:repeat(5,1fr); margin-bottom:20px;">
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)">
    <div class="stat-value"><?= $counts['present'] ?></div>
    <div class="stat-label">Present</div>
  </div>
  <div class="stat-card" style="--stat-color:#f59e0b;--stat-bg:rgba(245,158,11,0.1)">
    <div class="stat-value"><?= $counts['late'] ?></div>
    <div class="stat-label">Late</div>
  </div>
  <div class="stat-card" style="--stat-color:#ef4444;--stat-bg:rgba(239,68,68,0.1)">
    <div class="stat-value"><?= $counts['absent'] ?></div>
    <div class="stat-label">Absent</div>
  </div>
  <div class="stat-card" style="--stat-color:#3b82f6;--stat-bg:rgba(59,130,246,0.1)">
    <div class="stat-value"><?= $counts['excused'] ?></div>
    <div class="stat-label">Excused</div>
  </div>
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-value"><?= $rate ?>%</div>
    <div class="stat-label">Rate</div>
  </div>
</div>

<div style="display:grid; grid-template-columns:1fr 320px; gap:20px; align-items:start;">

<!-- ── Main attendance table ──────────────────────────────── -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Student Attendance</span>
    <div style="display:flex; gap:8px; align-items:center;">
      <!-- Bulk actions -->
      <form method="POST" id="bulkForm" style="display:flex; gap:6px; align-items:center;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_status">
        <select name="target" class="form-control" style="width:130px; padding:5px 10px; font-size:0.78rem;">
          <option value="unsubmitted">Unsubmitted</option>
          <option value="all">All students</option>
        </select>
        <select name="bulk_status" class="form-control" style="width:110px; padding:5px 10px; font-size:0.78rem;">
          <option value="present">→ Present</option>
          <option value="absent">→ Absent</option>
          <option value="late">→ Late</option>
          <option value="excused">→ Excused</option>
        </select>
        <button type="submit" class="btn btn-ghost btn-sm" onclick="return confirm('Apply bulk update?')">Apply</button>
      </form>
    </div>
  </div>

  <!-- Search filter -->
  <div style="padding:12px 20px; border-bottom:1px solid var(--border);">
    <input type="text" id="studentSearch" placeholder="Filter students…" class="form-control"
           style="max-width:280px; font-size:0.84rem;" oninput="filterStudents(this.value)">
  </div>

  <div class="card-body no-padding">
    <table class="data-table" id="attendanceTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Student</th>
          <th>Submitted</th>
          <th>Method</th>
          <th>Status</th>
          <th style="text-align:right;">Override</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allStudents as $i => $stu): ?>
        <?php
          $rec      = $records[$stu['id']] ?? null;
          $status   = $rec ? $rec['status'] : 'absent';
          $isManual = $rec && in_array($rec['ip_address'], ['manual']);
          $isAuto   = $rec && $rec['ip_address'] === 'auto';
          $colors   = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4'];
          $c        = $colors[abs(crc32($stu['name'])) % count($colors)];
        ?>
        <tr data-name="<?= strtolower(sanitize($stu['name'])) ?>">
          <td style="color:var(--text-muted);"><?= $i + 1 ?></td>
          <td>
            <div style="display:flex; align-items:center; gap:10px;">
              <div class="avatar avatar-sm" style="background:linear-gradient(135deg,<?=$c?>,<?=$c?>99); color:#fff; flex-shrink:0;">
                <?= strtoupper(substr($stu['name'], 0, 2)) ?>
              </div>
              <div>
                <div class="cell-primary"><?= sanitize($stu['name']) ?></div>
                <?php if ($stu['student_code']): ?>
                <div style="font-size:0.7rem; color:var(--text-muted);"><?= sanitize($stu['student_code']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td style="font-size:0.8rem; color:var(--text-muted);">
            <?= $rec && $rec['submitted_at'] ? date('H:i:s', strtotime($rec['submitted_at'])) : '—' ?>
          </td>
          <td>
            <?php if (!$rec): ?>
              <span style="color:var(--text-muted); font-size:0.75rem;">—</span>
            <?php elseif ($isManual): ?>
              <span class="badge badge-warning" style="font-size:0.65rem;">Manual</span>
            <?php elseif ($isAuto): ?>
              <span class="badge badge-muted" style="font-size:0.65rem;">Auto</span>
            <?php else: ?>
              <span class="badge badge-info" style="font-size:0.65rem;">Code</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $statusBadge[$status] ?? 'badge-muted' ?>"><?= ucfirst($status) ?></span>
          </td>
          <td>
            <div style="display:flex; gap:4px; justify-content:flex-end;">
              <?php foreach (['present', 'late', 'absent', 'excused'] as $opt):
                $btnColor = match($opt) {
                  'present' => 'rgba(34,197,94,0.12)',
                  'late'    => 'rgba(245,158,11,0.12)',
                  'absent'  => 'rgba(239,68,68,0.12)',
                  'excused' => 'rgba(59,130,246,0.12)',
                };
                $textColor = match($opt) {
                  'present' => 'var(--success)',
                  'late'    => 'var(--warning)',
                  'absent'  => 'var(--danger)',
                  'excused' => 'var(--info)',
                };
                $isActive = $status === $opt;
              ?>
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="student_id" value="<?= $stu['id'] ?>">
                <input type="hidden" name="new_status" value="<?= $opt ?>">
                <button type="submit" class="btn btn-sm" style="
                  background:<?= $btnColor ?>; color:<?= $textColor ?>; border-color:transparent;
                  padding:3px 8px; font-size:0.68rem; letter-spacing:0.02em;
                  <?= $isActive ? 'box-shadow:0 0 0 1.5px ' . $textColor . ';' : 'opacity:0.4;' ?>">
                  <?= ucfirst($opt) ?>
                </button>
              </form>
              <?php endforeach; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Right sidebar: session info ───────────────────────── -->
<div>

  <!-- Rate donut visualization -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header"><span class="card-title">Attendance Rate</span></div>
    <div class="card-body" style="text-align:center; padding:24px;">
      <div style="position:relative; width:100px; height:100px; margin:0 auto 16px;">
        <svg viewBox="0 0 36 36" style="width:100px; height:100px; transform:rotate(-90deg);">
          <circle cx="18" cy="18" r="15.9" fill="none" stroke="var(--bg-elevated)" stroke-width="3"/>
          <?php
            $circ = 2 * M_PI * 15.9;
            $dash = round(($rate / 100) * $circ, 2);
            $rateRGB = $rate >= 80 ? '34,197,94' : ($rate >= 60 ? '245,158,11' : '239,68,68');
          ?>
          <circle cx="18" cy="18" r="15.9" fill="none"
                  stroke="rgba(<?= $rateRGB ?>,0.9)" stroke-width="3"
                  stroke-dasharray="<?= $dash ?> <?= $circ ?>"
                  stroke-linecap="round"/>
        </svg>
        <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); text-align:center;">
          <div style="font-family:var(--font-display); font-size:1.4rem; font-weight:800; color:var(--text-primary);"><?= $rate ?>%</div>
        </div>
      </div>
      <div style="font-size:0.78rem; color:var(--text-muted);"><?= $presentTotal ?> of <?= $totalStudents ?> students present</div>
    </div>
  </div>

  <!-- Session notes -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header"><span class="card-title">Session Notes</span></div>
    <div class="card-body">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_notes">
        <textarea name="session_notes" class="form-control" rows="4"
                  placeholder="Optional notes about this session…"
                  style="font-size:0.82rem; resize:vertical;"><?= sanitize($session['notes'] ?? '') ?></textarea>
        <button type="submit" class="btn btn-ghost btn-sm" style="margin-top:10px; width:100%;">Save Notes</button>
      </form>
    </div>
  </div>

  <!-- Session info -->
  <div class="card">
    <div class="card-header"><span class="card-title">Session Info</span></div>
    <div class="card-body" style="font-size:0.82rem;">
      <?php
      $infoRows = [
        'Group'   => sanitize($session['group_name']),
        'Subject' => sanitize($session['subject'] ?: '—'),
        'Teacher' => sanitize($session['teacher_name']),
        'Date'    => date('M j, Y', strtotime($session['session_date'])),
        'Code'    => $session['status']==='open' ? '<code style="background:var(--bg-elevated);padding:2px 8px;border-radius:4px;font-size:1rem;font-weight:700;color:var(--accent);">' . sanitize($session['session_code']) . '</code>' : '<span style="color:var(--text-muted);">Expired</span>',
        'Status'  => $session['status']==='open' ? '<span class="badge badge-success">Live</span>' : '<span class="badge badge-muted">Closed</span>',
      ];
      if ($session['topic']) $infoRows['Topic'] = sanitize($session['topic']);
      ?>
      <?php foreach ($infoRows as $label => $val): ?>
      <div style="display:flex; justify-content:space-between; align-items:center; padding:7px 0; border-bottom:1px solid var(--border);">
        <span style="color:var(--text-muted); font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em;"><?= $label ?></span>
        <span style="font-weight:500; color:var(--text-primary);"><?= $val ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>
</div>

<script>
function filterStudents(q) {
  q = q.toLowerCase();
  document.querySelectorAll('#attendanceTable tbody tr').forEach(row => {
    row.style.display = row.dataset.name.includes(q) ? '' : 'none';
  });
}
</script>

<style>
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }
</style>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
