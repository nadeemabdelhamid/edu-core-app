<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pdo  = getDB();
$cid  = centerId();
$sid  = (int)($_GET['id'] ?? 0);

if (!$sid) redirect('/educore/modules/attendance/');

$sStmt = $pdo->prepare("SELECT s.*, g.name AS group_name, u.name AS teacher_name FROM attendance_sessions s JOIN `groups` g ON g.id=s.group_id JOIN users u ON u.id=s.teacher_id WHERE s.id=? AND s.center_id=?");
$sStmt->execute([$sid, $cid]);
$session = $sStmt->fetch();
if (!$session) { flash('error','Session not found.'); redirect('/educore/modules/attendance/'); }

$pageTitle  = 'Session — ' . date('M j, Y', strtotime($session['session_date']));
$activePage = 'attendance';
$breadcrumbs = [
    ['label' => 'Dashboard',  'href' => '/educore/dashboard.php'],
    ['label' => 'Attendance', 'href' => '/educore/modules/attendance/'],
    ['label' => $pageTitle],
];

// Handle status update (teacher manual override)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action    = $_POST['action'] ?? '';
    $studentId = (int)($_POST['student_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? 'present';

    if ($action === 'update_status' && $studentId && in_array($newStatus, ['present','absent','late','excused'])) {
        // Upsert attendance record
        $pdo->prepare("INSERT INTO attendance_records (session_id, student_id, center_id, status, submitted_at, ip_address)
                        VALUES (?,?,?,?,NOW(),'manual')
                        ON DUPLICATE KEY UPDATE status=?, submitted_at=NOW()")
            ->execute([$sid, $studentId, $cid, $newStatus, $newStatus]);
        auditLog('attendance.manual_override', 'attendance_record', $sid, ['student_id'=>$studentId, 'status'=>$newStatus]);
        flash('success', 'Attendance updated.');
    }

    if ($action === 'close_session') {
        $pdo->prepare("UPDATE attendance_sessions SET status='closed' WHERE id=? AND center_id=?")
            ->execute([$sid, $cid]);
        flash('success', 'Session closed.');
    }

    redirect("view.php?id=$sid");
}

// All students in the group
$stuStmt = $pdo->prepare("SELECT s.* FROM group_students gs JOIN students s ON s.id=gs.student_id WHERE gs.group_id=? AND gs.status='active' ORDER BY s.name");
$stuStmt->execute([$session['group_id']]);
$allStudents = $stuStmt->fetchAll();

// Attendance records for this session
$recStmt = $pdo->prepare("SELECT * FROM attendance_records WHERE session_id=?");
$recStmt->execute([$sid]);
$records = [];
foreach ($recStmt->fetchAll() as $r) {
    $records[$r['student_id']] = $r;
}

$totalStudents = count($allStudents);
$presentCount  = count(array_filter($records, fn($r) => $r['status']==='present'));
$absentCount   = $totalStudents - count($records);
$lateCount     = count(array_filter($records, fn($r) => $r['status']==='late'));
$rate          = $totalStudents > 0 ? round(($presentCount / $totalStudents) * 100) : 0;

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">
      <?= sanitize($session['group_name']) ?>
      <span style="font-size:0.9rem; font-weight:400; color:var(--text-muted);">· <?= date('l, M j, Y', strtotime($session['session_date'])) ?></span>
    </h1>
    <p class="page-subtitle">
      <?php if ($session['topic']): ?><?= sanitize($session['topic']) ?> · <?php endif; ?>
      Teacher: <?= sanitize($session['teacher_name']) ?>
    </p>
  </div>
  <div style="display:flex; gap:10px; align-items:center;">
    <?php if ($session['status']==='open'): ?>
      <span class="badge badge-success" style="padding:6px 12px;">● Live Session</span>
      <a href="create.php?session=<?= $sid ?>" class="btn btn-primary btn-sm">View Live →</a>
      <form method="POST" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="close_session">
        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Close this session?')">Close Session</button>
      </form>
    <?php else: ?>
      <span class="badge badge-muted">Closed</span>
    <?php endif; ?>
  </div>
</div>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(5,1fr); margin-bottom:20px;">
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)">
    <div class="stat-value"><?= $presentCount ?></div>
    <div class="stat-label">Present</div>
  </div>
  <div class="stat-card" style="--stat-color:#ef4444;--stat-bg:rgba(239,68,68,0.1)">
    <div class="stat-value"><?= $absentCount ?></div>
    <div class="stat-label">Absent</div>
  </div>
  <div class="stat-card" style="--stat-color:#f59e0b;--stat-bg:rgba(245,158,11,0.1)">
    <div class="stat-value"><?= $lateCount ?></div>
    <div class="stat-label">Late</div>
  </div>
  <div class="stat-card" style="--stat-color:#3b82f6;--stat-bg:rgba(59,130,246,0.1)">
    <div class="stat-value"><?= $totalStudents ?></div>
    <div class="stat-label">Total</div>
  </div>
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-value"><?= $rate ?>%</div>
    <div class="stat-label">Rate</div>
  </div>
</div>

<!-- Attendance table -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Student Attendance</span>
    <span style="font-size:0.78rem; color:var(--text-muted);">Click a status button to override manually</span>
  </div>
  <div class="card-body no-padding">
    <table class="data-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Student</th>
          <th>Submitted At</th>
          <th>Method</th>
          <th>Status</th>
          <th style="text-align:right;">Override</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allStudents as $i => $stu): ?>
        <?php
          $rec    = $records[$stu['id']] ?? null;
          $status = $rec ? $rec['status'] : 'absent';
          $isManual = $rec && $rec['ip_address'] === 'manual';
          $colors = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4'];
          $c = $colors[crc32($stu['name']) % count($colors)];
          $statusBadge = ['present'=>'badge-success','absent'=>'badge-danger','late'=>'badge-warning','excused'=>'badge-info'];
        ?>
        <tr>
          <td style="color:var(--text-muted);"><?= $i+1 ?></td>
          <td>
            <div style="display:flex; align-items:center; gap:10px;">
              <div class="avatar avatar-sm" style="background:linear-gradient(135deg,<?=$c?>,<?=$c?>99);color:#fff;">
                <?= strtoupper(substr($stu['name'],0,2)) ?>
              </div>
              <span class="cell-primary"><?= sanitize($stu['name']) ?></span>
            </div>
          </td>
          <td style="font-size:0.8rem; color:var(--text-muted);">
            <?= $rec ? date('H:i:s', strtotime($rec['submitted_at'])) : '—' ?>
          </td>
          <td>
            <?php if (!$rec): ?>
              <span style="color:var(--text-muted); font-size:0.75rem;">—</span>
            <?php elseif ($isManual): ?>
              <span class="badge badge-warning" style="font-size:0.65rem;">Manual</span>
            <?php else: ?>
              <span class="badge badge-info" style="font-size:0.65rem;">Code</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $statusBadge[$status] ?? 'badge-muted' ?>"><?= ucfirst($status) ?></span>
          </td>
          <td>
            <div style="display:flex; gap:5px; justify-content:flex-end;">
              <?php foreach (['present','late','absent','excused'] as $opt): ?>
              <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="student_id" value="<?= $stu['id'] ?>">
                <input type="hidden" name="new_status" value="<?= $opt ?>">
                <?php
                  $btnStyle = match($opt) {
                    'present' => 'background:rgba(34,197,94,0.12); color:var(--success); border-color:transparent;',
                    'late'    => 'background:rgba(245,158,11,0.12); color:var(--warning); border-color:transparent;',
                    'absent'  => 'background:rgba(239,68,68,0.12); color:var(--danger); border-color:transparent;',
                    'excused' => 'background:rgba(59,130,246,0.12); color:var(--info); border-color:transparent;',
                  };
                  $isActive = $status === $opt;
                ?>
                <button type="submit" class="btn btn-sm"
                        style="<?= $btnStyle ?> <?= $isActive ? 'box-shadow:0 0 0 2px currentColor;' : 'opacity:0.4;' ?> padding:4px 8px; font-size:0.68rem;">
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

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
