<?php
/**
 * student_history.php
 * Full attendance history for a specific student — viewable by owner/teacher/assistant
 * Route: /educore/modules/attendance/student_history.php?student_id=XX
 */
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pdo = getDB();
$cid = centerId();
$stId = (int)($_GET['student_id'] ?? 0);

if (!$stId) redirect('/educore/modules/attendance/reports.php');

// Load student
$stStmt = $pdo->prepare("SELECT * FROM students WHERE id=? AND center_id=?");
$stStmt->execute([$stId, $cid]);
$student = $stStmt->fetch();
if (!$student) { flash('error', 'Student not found.'); redirect('/educore/modules/attendance/'); }

$pageTitle  = sanitize($student['name']) . ' — Attendance';
$activePage = 'attendance';
$breadcrumbs = [
    ['label' => 'Dashboard',  'href' => '/educore/dashboard.php'],
    ['label' => 'Attendance', 'href' => '/educore/modules/attendance/'],
    ['label' => 'Reports',    'href' => '/educore/modules/attendance/reports.php'],
    ['label' => sanitize($student['name'])],
];

$from = $_GET['from'] ?? date('Y-m-01', strtotime('-2 months'));
$to   = $_GET['to']   ?? date('Y-m-t');
$gid  = (int)($_GET['group'] ?? 0);

// ── Per-group summary ──────────────────────────────────────
$summaryStmt = $pdo->prepare("
    SELECT
        g.id AS group_id, g.name AS group_name, g.subject,
        COUNT(s.id) AS total,
        SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) AS present,
        SUM(CASE WHEN ar.status='late'    THEN 1 ELSE 0 END) AS late,
        SUM(CASE WHEN ar.status='absent'  THEN 1 ELSE 0 END) AS absent,
        SUM(CASE WHEN ar.status='excused' THEN 1 ELSE 0 END) AS excused,
        ROUND(100.0 * SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END)
            / NULLIF(COUNT(s.id), 0), 1) AS rate
    FROM group_students gs
    JOIN `groups` g ON g.id = gs.group_id
    JOIN attendance_sessions s ON s.group_id = g.id AND s.center_id = ? AND s.status = 'closed'
    LEFT JOIN attendance_records ar ON ar.session_id = s.id AND ar.student_id = gs.student_id
    WHERE gs.student_id = ? AND gs.status = 'active'
    GROUP BY g.id ORDER BY g.name");
$summaryStmt->execute([$cid, $stId]);
$groupSummaries = $summaryStmt->fetchAll();

// ── Session-by-session history ─────────────────────────────
$histQ = "
    SELECT ar.status, ar.submitted_at, ar.ip_address, ar.notes,
           s.session_date, s.session_code, s.topic,
           g.name AS group_name, g.id AS group_id
    FROM attendance_records ar
    JOIN attendance_sessions s ON s.id = ar.session_id
    JOIN `groups` g ON g.id = s.group_id
    WHERE ar.student_id = ? AND ar.center_id = ?
        AND s.session_date BETWEEN ? AND ?";
if ($gid) { $histQ .= " AND g.id = $gid"; }
$histQ .= " ORDER BY s.session_date DESC, ar.submitted_at DESC";

$histStmt = $pdo->prepare($histQ);
$histStmt->execute([$stId, $cid, $from, $to]);
$history = $histStmt->fetchAll();

// ── Groups filter list ─────────────────────────────────────
$gStmt = $pdo->prepare("SELECT DISTINCT g.id, g.name FROM groups g JOIN group_students gs ON gs.group_id=g.id WHERE gs.student_id=? AND gs.status='active' AND g.center_id=? ORDER BY g.name");
$gStmt->execute([$stId, $cid]);
$myGroups = $gStmt->fetchAll();

// Overall stats
$totalSessions = array_sum(array_column($groupSummaries, 'total'));
$totalPresent  = array_sum(array_column($groupSummaries, 'present')) + array_sum(array_column($groupSummaries, 'late'));
$overallRate   = $totalSessions > 0 ? round(($totalPresent / $totalSessions) * 100) : 0;

$statusBadge = ['present'=>'badge-success','absent'=>'badge-danger','late'=>'badge-warning','excused'=>'badge-info'];
$statusIcon  = ['present'=>'✓','absent'=>'✗','late'=>'⏱','excused'=>'~'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div style="display:flex; align-items:center; gap:14px;">
    <?php
      $colors = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4'];
      $c = $colors[abs(crc32($student['name'])) % count($colors)];
    ?>
    <div class="avatar" style="width:48px; height:48px; background:linear-gradient(135deg,<?=$c?>,<?=$c?>99); color:#fff; font-size:1.1rem; font-weight:700; border-radius:var(--radius-md); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
      <?= strtoupper(substr($student['name'], 0, 2)) ?>
    </div>
    <div>
      <h1 class="page-title"><?= sanitize($student['name']) ?></h1>
      <p class="page-subtitle">
        <?php if ($student['student_code']): ?><?= sanitize($student['student_code']) ?> · <?php endif; ?>
        Attendance History
      </p>
    </div>
  </div>
  <a href="reports.php" class="btn btn-ghost">← Reports</a>
</div>

<!-- Overall stats -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr); margin-bottom:20px;">
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-value"><?= $totalSessions ?></div>
    <div class="stat-label">Total Sessions</div>
  </div>
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)">
    <div class="stat-value"><?= $totalPresent ?></div>
    <div class="stat-label">Present + Late</div>
  </div>
  <div class="stat-card" style="--stat-color:#ef4444;--stat-bg:rgba(239,68,68,0.1)">
    <div class="stat-value"><?= $totalSessions - $totalPresent ?></div>
    <div class="stat-label">Absent / Excused</div>
  </div>
  <div class="stat-card" style="--stat-color:<?= $overallRate >= 80 ? '#22c55e' : ($overallRate >= 60 ? '#f59e0b' : '#ef4444') ?>;--stat-bg:var(--bg-elevated)">
    <div class="stat-value"><?= $overallRate ?>%</div>
    <div class="stat-label">Overall Rate</div>
  </div>
</div>

<div style="display:grid; grid-template-columns:280px 1fr; gap:20px;">

<!-- ── Left: Per-group summary ───────────────────────────── -->
<div>
  <div class="card">
    <div class="card-header"><span class="card-title">By Group</span></div>
    <div class="card-body no-padding">
      <?php if ($groupSummaries): ?>
      <?php foreach ($groupSummaries as $gs): ?>
      <?php
        $rate  = (float)$gs['rate'];
        $color = $rate >= 80 ? 'var(--success)' : ($rate >= 60 ? 'var(--warning)' : 'var(--danger)');
      ?>
      <a href="?student_id=<?=$stId?>&group=<?=$gs['group_id']?>&from=<?=$from?>&to=<?=$to?>"
         style="display:block; padding:14px 16px; border-bottom:1px solid var(--border); text-decoration:none;
                <?= $gid==$gs['group_id'] ? 'background:var(--accent-subtle); border-left:3px solid var(--accent);' : '' ?>
                transition:background 0.1s;">
        <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
          <span style="font-weight:500; color:var(--text-primary); font-size:0.84rem;"><?= sanitize($gs['group_name']) ?></span>
          <span style="font-weight:700; color:<?= $color ?>; font-size:0.82rem;"><?= $rate ?>%</span>
        </div>
        <div style="height:3px; background:var(--bg-elevated); border-radius:2px; overflow:hidden; margin-bottom:6px;">
          <div style="height:100%; width:<?= $rate ?>%; background:<?= $color ?>; border-radius:2px;"></div>
        </div>
        <div style="display:flex; gap:8px; font-size:0.7rem; color:var(--text-muted);">
          <span style="color:var(--success);">✓ <?= $gs['present'] + $gs['late'] ?></span>
          <span style="color:var(--danger);">✗ <?= $gs['absent'] ?></span>
          <?php if ($gs['late'] > 0): ?>
          <span style="color:var(--warning);">⏱ <?= $gs['late'] ?></span>
          <?php endif; ?>
          <span style="margin-left:auto;"><?= $gs['total'] ?> sessions</span>
        </div>
      </a>
      <?php endforeach; ?>
      <?php if ($gid): ?>
      <div style="padding:10px 14px;">
        <a href="?student_id=<?=$stId?>&from=<?=$from?>&to=<?=$to?>" class="btn btn-ghost btn-sm" style="width:100%;">Show All Groups</a>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div style="padding:20px; text-align:center; color:var(--text-muted); font-size:0.84rem;">No group data found.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Right: Session timeline ───────────────────────────── -->
<div>
  <!-- Filters -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="padding:12px 16px;">
      <form method="GET" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="student_id" value="<?= $stId ?>">
        <?php if ($gid): ?><input type="hidden" name="group" value="<?= $gid ?>"><?php endif; ?>
        <input type="date" name="from" class="form-control" style="width:145px;" value="<?= sanitize($from) ?>" onchange="this.form.submit()">
        <span style="color:var(--text-muted); font-size:0.8rem;">–</span>
        <input type="date" name="to" class="form-control" style="width:145px;" value="<?= sanitize($to) ?>" onchange="this.form.submit()">
        <span style="margin-left:auto; font-size:0.78rem; color:var(--text-muted);"><?= count($history) ?> sessions</span>
      </form>
    </div>
  </div>

  <!-- Timeline -->
  <div class="card">
    <div class="card-header"><span class="card-title">Session History</span></div>
    <div class="card-body no-padding">
      <?php if ($history): ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Group</th>
            <th>Topic</th>
            <th>Time</th>
            <th>Method</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $rec): ?>
          <?php
            $isManual = $rec['ip_address'] === 'manual';
            $isAuto   = $rec['ip_address'] === 'auto';
          ?>
          <tr>
            <td class="cell-primary" style="font-size:0.84rem;"><?= date('M j, Y', strtotime($rec['session_date'])) ?></td>
            <td style="color:var(--text-secondary); font-size:0.82rem;"><?= sanitize($rec['group_name']) ?></td>
            <td style="color:var(--text-muted); font-size:0.78rem;"><?= sanitize($rec['topic'] ?: '—') ?></td>
            <td style="font-size:0.78rem; color:var(--text-muted);">
              <?= $rec['submitted_at'] ? date('H:i', strtotime($rec['submitted_at'])) : '—' ?>
            </td>
            <td>
              <?php if ($isManual): ?>
              <span class="badge badge-warning" style="font-size:0.65rem;">Manual</span>
              <?php elseif ($isAuto): ?>
              <span class="badge badge-muted" style="font-size:0.65rem;">Auto</span>
              <?php else: ?>
              <span class="badge badge-info" style="font-size:0.65rem;">Code</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?= $statusBadge[$rec['status']] ?? 'badge-muted' ?>"><?= ucfirst($rec['status']) ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon">📭</div>
        <div class="empty-title">No attendance records</div>
        <div class="empty-desc">No sessions found in the selected date range.</div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
