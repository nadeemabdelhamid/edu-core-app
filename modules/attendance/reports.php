<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pdo = getDB();
$cid = centerId();
$uid = authId();

$pageTitle  = 'Attendance Reports';
$activePage = 'attendance';
$breadcrumbs = [
    ['label' => 'Dashboard',  'href' => '/educore/dashboard.php'],
    ['label' => 'Attendance', 'href' => '/educore/modules/attendance/'],
    ['label' => 'Reports'],
];

// ── Filters ─────────────────────────────────────────────────
$view   = $_GET['view']   ?? 'group';    // group | student
$gid    = (int)($_GET['group'] ?? 0);
$sid    = (int)($_GET['student'] ?? 0);
$from   = $_GET['from']   ?? date('Y-m-01');
$to     = $_GET['to']     ?? date('Y-m-t');
$export = $_GET['export'] ?? '';

// ── Groups ───────────────────────────────────────────────────
$groupWhere = isRole('owner', 'assistant') ? "WHERE g.center_id=?" : "WHERE g.center_id=? AND g.teacher_id=?";
$groupP     = isRole('owner', 'assistant') ? [$cid] : [$cid, $uid];
$gStmt = $pdo->prepare("SELECT g.id, g.name, g.subject FROM `groups` g $groupWhere AND g.is_active=1 ORDER BY g.name");
$gStmt->execute($groupP);
$groups = $gStmt->fetchAll();

// ── Students (for filter) ────────────────────────────────────
$stuQ = $gid
    ? "SELECT s.id, s.name FROM students s JOIN group_students gs ON gs.student_id=s.id WHERE gs.group_id=? AND s.center_id=? AND gs.status='active' ORDER BY s.name"
    : "SELECT id, name FROM students WHERE center_id=? AND is_active=1 ORDER BY name LIMIT 200";
$stuP = $gid ? [$gid, $cid] : [$cid];
$stuStmt = $pdo->prepare($stuQ);
$stuStmt->execute($stuP);
$students = $stuStmt->fetchAll();

// ── Build report data ────────────────────────────────────────
$reportRows  = [];
$reportTitle = '';

if ($view === 'group') {
    // Per-group summary: each group × date range
    $reportTitle = 'Group Attendance Summary';
    $q = "
        SELECT
            g.id AS group_id, g.name AS group_name, g.subject,
            COUNT(DISTINCT s.id) AS session_count,
            COUNT(DISTINCT gs.student_id) AS enrolled,
            SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END) AS total_present,
            SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) AS total_absent,
            COUNT(ar.id) AS total_records,
            ROUND(100.0 * SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END)
                / NULLIF(COUNT(DISTINCT gs.student_id) * COUNT(DISTINCT s.id), 0), 1) AS avg_rate
        FROM `groups` g
        JOIN group_students gs ON gs.group_id=g.id AND gs.status='active'
        JOIN attendance_sessions s ON s.group_id=g.id AND s.center_id=? AND s.status='closed'
            AND s.session_date BETWEEN ? AND ?
        LEFT JOIN attendance_records ar ON ar.session_id=s.id AND ar.student_id=gs.student_id
        WHERE g.center_id=?
        " . (!isRole('owner', 'assistant') ? " AND g.teacher_id=$uid" : "") . "
        " . ($gid ? " AND g.id=$gid" : "") . "
        GROUP BY g.id
        ORDER BY avg_rate DESC";
    $stmt = $pdo->prepare($q);
    $stmt->execute([$cid, $from, $to, $cid]);
    $reportRows = $stmt->fetchAll();

} elseif ($view === 'student') {
    // Per-student detail across groups
    $reportTitle = 'Student Attendance Detail';
    $q = "
        SELECT
            st.id AS student_id, st.name AS student_name, st.student_code,
            g.name AS group_name,
            COUNT(DISTINCT s.id) AS total_sessions,
            SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN ar.status='late'    THEN 1 ELSE 0 END) AS late_count,
            SUM(CASE WHEN ar.status='absent'  THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN ar.status='excused' THEN 1 ELSE 0 END) AS excused_count,
            ROUND(100.0 * SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END)
                / NULLIF(COUNT(DISTINCT s.id), 0), 1) AS rate
        FROM students st
        JOIN group_students gs ON gs.student_id=st.id AND gs.status='active'
        JOIN `groups` g ON g.id=gs.group_id
        JOIN attendance_sessions s ON s.group_id=g.id AND s.center_id=? AND s.status='closed'
            AND s.session_date BETWEEN ? AND ?
        LEFT JOIN attendance_records ar ON ar.session_id=s.id AND ar.student_id=st.id
        WHERE st.center_id=?
        " . ($gid ? " AND g.id=$gid" : "") . "
        " . ($sid ? " AND st.id=$sid" : "") . "
        GROUP BY st.id, g.id
        ORDER BY st.name, g.name";
    $stmt = $pdo->prepare($q);
    $stmt->execute([$cid, $from, $to, $cid]);
    $reportRows = $stmt->fetchAll();
}

// ── CSV Export ───────────────────────────────────────────────
if ($export === 'csv' && !empty($reportRows)) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_report_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');

    if ($view === 'group') {
        fputcsv($out, ['Group', 'Subject', 'Sessions', 'Enrolled', 'Total Present', 'Total Absent', 'Avg Rate (%)']);
        foreach ($reportRows as $r) {
            fputcsv($out, [$r['group_name'], $r['subject'], $r['session_count'], $r['enrolled'], $r['total_present'], $r['total_absent'], $r['avg_rate']]);
        }
    } else {
        fputcsv($out, ['Student', 'Code', 'Group', 'Sessions', 'Present', 'Late', 'Absent', 'Excused', 'Rate (%)']);
        foreach ($reportRows as $r) {
            fputcsv($out, [$r['student_name'], $r['student_code'], $r['group_name'], $r['total_sessions'], $r['present_count'], $r['late_count'], $r['absent_count'], $r['excused_count'], $r['rate']]);
        }
    }
    fclose($out);
    exit;
}

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Attendance Reports</h1>
    <p class="page-subtitle">Analyze attendance across groups and students</p>
  </div>
  <a href="index.php" class="btn btn-ghost">← Back</a>
</div>

<!-- ── Filter bar ─────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:16px 20px;">
    <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">

      <!-- View toggle -->
      <div style="display:flex; background:var(--bg-elevated); border-radius:var(--radius-sm); padding:3px; gap:2px;">
        <a href="?view=group&group=<?=$gid?>&from=<?=$from?>&to=<?=$to?>"
           class="btn btn-sm <?= $view==='group' ? 'btn-primary' : 'btn-ghost' ?>" style="border-radius:calc(var(--radius-sm) - 2px);">
           By Group
        </a>
        <a href="?view=student&group=<?=$gid?>&from=<?=$from?>&to=<?=$to?>"
           class="btn btn-sm <?= $view==='student' ? 'btn-primary' : 'btn-ghost' ?>" style="border-radius:calc(var(--radius-sm) - 2px);">
           By Student
        </a>
      </div>

      <input type="hidden" name="view" value="<?= $view ?>">

      <select name="group" class="form-control" style="width:180px;" onchange="this.form.submit()">
        <option value="">All Groups</option>
        <?php foreach ($groups as $g): ?>
        <option value="<?= $g['id'] ?>" <?= $gid==$g['id']?'selected':'' ?>><?= sanitize($g['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <?php if ($view === 'student' && !empty($students)): ?>
      <select name="student" class="form-control" style="width:200px;" onchange="this.form.submit()">
        <option value="">All Students</option>
        <?php foreach ($students as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $sid==$s['id']?'selected':'' ?>><?= sanitize($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>

      <input type="date" name="from" class="form-control" style="width:148px;" value="<?= sanitize($from) ?>" onchange="this.form.submit()">
      <span style="color:var(--text-muted); font-size:0.8rem;">–</span>
      <input type="date" name="to" class="form-control" style="width:148px;" value="<?= sanitize($to) ?>" onchange="this.form.submit()">

      <button type="submit" class="btn btn-ghost btn-sm">Apply</button>

      <?php if (!empty($reportRows)): ?>
      <a href="?view=<?=$view?>&group=<?=$gid?>&student=<?=$sid?>&from=<?=$from?>&to=<?=$to?>&export=csv"
         class="btn btn-ghost btn-sm" style="margin-left:auto; color:var(--success);">
        ↓ Export CSV
      </a>
      <?php endif; ?>

    </form>
  </div>
</div>

<!-- ── Report table ───────────────────────────────────────── -->
<?php if ($view === 'group'): ?>

<div class="card">
  <div class="card-header">
    <span class="card-title"><?= $reportTitle ?></span>
    <span style="font-size:0.75rem; color:var(--text-muted);"><?= date('M j', strtotime($from)) ?> – <?= date('M j, Y', strtotime($to)) ?></span>
  </div>
  <div class="card-body no-padding">
    <?php if ($reportRows): ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Group</th>
          <th>Subject</th>
          <th>Sessions</th>
          <th>Enrolled</th>
          <th>Present</th>
          <th>Absent</th>
          <th>Avg Rate</th>
          <th style="text-align:right;">Detail</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reportRows as $r): ?>
        <?php
          $rate  = (float)$r['avg_rate'];
          $color = $rate >= 80 ? 'var(--success)' : ($rate >= 60 ? 'var(--warning)' : 'var(--danger)');
        ?>
        <tr>
          <td class="cell-primary"><?= sanitize($r['group_name']) ?></td>
          <td style="color:var(--text-muted); font-size:0.82rem;"><?= sanitize($r['subject'] ?: '—') ?></td>
          <td><?= $r['session_count'] ?></td>
          <td><?= $r['enrolled'] ?></td>
          <td style="color:var(--success); font-weight:600;"><?= $r['total_present'] ?></td>
          <td style="color:var(--danger); font-weight:600;"><?= $r['total_absent'] ?></td>
          <td>
            <div style="display:flex; align-items:center; gap:8px;">
              <div style="width:60px; height:5px; background:var(--bg-elevated); border-radius:3px; overflow:hidden;">
                <div style="height:100%; width:<?= $rate ?>%; background:<?= $color ?>; border-radius:3px;"></div>
              </div>
              <span style="font-weight:700; color:<?= $color ?>; font-size:0.84rem;"><?= $rate ?>%</span>
            </div>
          </td>
          <td style="text-align:right;">
            <a href="?view=student&group=<?= $r['group_id'] ?>&from=<?= $from ?>&to=<?= $to ?>"
               class="btn btn-ghost btn-sm btn-icon" title="View students">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">📊</div>
      <div class="empty-title">No data in selected range</div>
      <div class="empty-desc">Try adjusting the date range or group filter.</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php else: // student view ?>

<div class="card">
  <div class="card-header">
    <span class="card-title"><?= $reportTitle ?></span>
    <span style="font-size:0.75rem; color:var(--text-muted);"><?= count($reportRows) ?> records</span>
  </div>
  <div class="card-body no-padding">
    <?php if ($reportRows): ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Student</th>
          <th>Group</th>
          <th>Sessions</th>
          <th>Present</th>
          <th>Late</th>
          <th>Absent</th>
          <th>Excused</th>
          <th>Rate</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reportRows as $r): ?>
        <?php
          $rate  = (float)$r['rate'];
          $color = $rate >= 80 ? 'var(--success)' : ($rate >= 60 ? 'var(--warning)' : 'var(--danger)');
        ?>
        <tr>
          <td>
            <div class="cell-primary"><?= sanitize($r['student_name']) ?></div>
            <?php if ($r['student_code']): ?>
            <div style="font-size:0.7rem; color:var(--text-muted);"><?= sanitize($r['student_code']) ?></div>
            <?php endif; ?>
          </td>
          <td style="color:var(--text-secondary); font-size:0.84rem;"><?= sanitize($r['group_name']) ?></td>
          <td><?= $r['total_sessions'] ?></td>
          <td style="color:var(--success); font-weight:600;"><?= $r['present_count'] ?></td>
          <td style="color:var(--warning); font-weight:600;"><?= $r['late_count'] ?></td>
          <td style="color:<?= $r['absent_count'] > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>; font-weight:600;"><?= $r['absent_count'] ?></td>
          <td style="color:var(--info);"><?= $r['excused_count'] ?></td>
          <td>
            <div style="display:flex; align-items:center; gap:6px;">
              <div style="width:50px; height:4px; background:var(--bg-elevated); border-radius:2px; overflow:hidden;">
                <div style="height:100%; width:<?= $rate ?>%; background:<?= $color ?>; border-radius:2px;"></div>
              </div>
              <span style="font-weight:700; color:<?= $color ?>; font-size:0.82rem;"><?= $rate ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">👥</div>
      <div class="empty-title">No attendance data found</div>
      <div class="empty-desc">Try adjusting the date range or group filter.</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
