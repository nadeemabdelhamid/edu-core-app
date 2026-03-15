<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','assistant','accountant');

$pdo = getDB();
$cid = centerId();

$pageTitle  = 'Attendance Report';
$activePage = 'reports';
$breadcrumbs = [
    ['label'=>'Dashboard', 'href'=>'/educore/dashboard.php'],
    ['label'=>'Reports',   'href'=>'/educore/modules/reports/'],
    ['label'=>'Attendance'],
];

$year      = (int)($_GET['year']  ?? date('Y'));
$groupId   = (int)($_GET['group'] ?? 0);
$availYears = range(date('Y'), max(date('Y')-4, 2020));
$shortMonths = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

// Groups for filter — academy teachers see only their own groups
if (isRole('teacher') && isAcademyPlan()) {
    $grpList = $pdo->prepare("SELECT id,name FROM `groups` WHERE center_id=? AND teacher_id=? AND is_active=1 ORDER BY name");
    $grpList->execute([$cid, authId()]);
} else {
    $grpList = $pdo->prepare("SELECT id,name FROM `groups` WHERE center_id=? AND is_active=1 ORDER BY name");
    $grpList->execute([$cid]);
}
$groupList = $grpList->fetchAll();

// For academy teachers, restrict all queries to their groups
$teacherGroupIds = [];
if (isRole('teacher') && isAcademyPlan()) {
    $teacherGroupIds = array_column($groupList, 'id');
    // If teacher has no groups, nothing to show
}

// ── Center-wide overview ─────────────────────────────────
// Academy teachers are scoped to their groups
$atWhere = '';
$atExtraParams = [];
if (isRole('teacher') && isAcademyPlan() && !empty($teacherGroupIds)) {
    $placeholders = implode(',', array_fill(0, count($teacherGroupIds), '?'));
    $atWhere = " AND s.group_id IN ($placeholders)";
    $atExtraParams = $teacherGroupIds;
} elseif (isRole('teacher') && isAcademyPlan() && empty($teacherGroupIds)) {
    // Teacher has no groups — return empty results
    $atWhere = " AND 1=0";
}

$overallStmt = $pdo->prepare("
    SELECT COUNT(*) AS total, SUM(ar.status IN('present','late')) AS present,
           SUM(ar.status='absent') AS absent, SUM(ar.status='late') AS late, SUM(ar.status='excused') AS excused
    FROM attendance_records ar
    JOIN attendance_sessions s ON s.id=ar.session_id
    WHERE ar.center_id=? AND YEAR(s.session_date)=?
    ".($groupId ? " AND s.group_id=?" : "").$atWhere);
$overallParams = array_merge(
    $groupId ? [$cid,$year,$groupId] : [$cid,$year],
    $atExtraParams
);
$overallStmt->execute($overallParams);
$overall = $overallStmt->fetch();
$totalRec  = (int)$overall['total'];
$overallRate = $totalRec > 0 ? round(($overall['present']/$totalRec)*100,1) : 0;

// ── By month ─────────────────────────────────────────────
$mStmt = $pdo->prepare("
    SELECT MONTH(s.session_date) AS m,
           COUNT(*) AS total,
           SUM(ar.status IN('present','late')) AS present,
           SUM(ar.status='absent') AS absent,
           COUNT(DISTINCT s.id) AS sessions
    FROM attendance_records ar
    JOIN attendance_sessions s ON s.id=ar.session_id
    WHERE ar.center_id=? AND YEAR(s.session_date)=?
    ".($groupId ? " AND s.group_id=?" : "").$atWhere."
    GROUP BY m ORDER BY m");
$mStmt->execute($overallParams);
$byMonth = array_fill(1,12,['total'=>0,'present'=>0,'absent'=>0,'sessions'=>0,'rate'=>0]);
foreach($mStmt->fetchAll() as $r) {
    $rate = $r['total']>0 ? round($r['present']/$r['total']*100,1) : 0;
    $byMonth[(int)$r['m']] = array_merge($r, ['rate'=>$rate]);
}

// ── Per-group summary ─────────────────────────────────────
$grpStmt = $pdo->prepare("
    SELECT g.id, g.name AS group_name, u.name AS teacher_name,
           COUNT(ar.id) AS total_records,
           SUM(ar.status IN('present','late')) AS present,
           SUM(ar.status='absent') AS absent,
           COUNT(DISTINCT s.id) AS sessions_count,
           COUNT(DISTINCT ar.student_id) AS student_count,
           ROUND(100.0*SUM(ar.status IN('present','late'))/NULLIF(COUNT(ar.id),0),1) AS att_rate
    FROM attendance_sessions s
    JOIN `groups` g ON g.id=s.group_id
    LEFT JOIN users u ON u.id=g.teacher_id
    LEFT JOIN attendance_records ar ON ar.session_id=s.id
    WHERE s.center_id=? AND YEAR(s.session_date)=?
    ".($groupId ? " AND s.group_id=?" : "")."
    GROUP BY g.id ORDER BY att_rate DESC");
$grpStmt->execute($overallParams);
$byGroup = $grpStmt->fetchAll();

// ── Per-student (top & bottom) ────────────────────────────
$stuWhere = $groupId ? "AND s.group_id=?" : "";
$stuStmt  = $pdo->prepare("
    SELECT st.id, st.name, st.student_code,
           COUNT(ar.id) AS total,
           SUM(ar.status IN('present','late')) AS present,
           SUM(ar.status='absent') AS absent,
           ROUND(100.0*SUM(ar.status IN('present','late'))/NULLIF(COUNT(ar.id),0),1) AS rate
    FROM attendance_records ar
    JOIN students st ON st.id=ar.student_id
    JOIN attendance_sessions s ON s.id=ar.session_id
    WHERE ar.center_id=? AND YEAR(s.session_date)=? $stuWhere
    GROUP BY st.id HAVING total >= 3
    ORDER BY rate DESC");
$stuStmt->execute($overallParams);
$byStudent = $stuStmt->fetchAll();

// Split into top 10 and bottom 10 (with issues)
$topStudents    = array_slice($byStudent, 0, 10);
$bottomStudents = array_filter($byStudent, fn($s) => (float)$s['rate'] < 75);
usort($bottomStudents, fn($a,$b) => $a['rate'] <=> $b['rate']);
$bottomStudents = array_slice($bottomStudents, 0, 10);

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Attendance Report</h1>
    <p class="page-subtitle"><?= $year ?> · <?= $groupId ? sanitize($groupList[array_search($groupId,array_column($groupList,'id'))]['name']??'') : 'All Groups' ?></p>
  </div>
  <div style="display:flex;gap:10px;align-items:center;">
    <form method="GET" style="display:flex;gap:8px;">
      <select name="year" class="form-control" style="width:110px;" onchange="this.form.submit()">
        <?php foreach($availYears as $y): ?>
        <option value="<?=$y?>" <?=$y===$year?'selected':''?>><?=$y?></option>
        <?php endforeach; ?>
      </select>
      <select name="group" class="form-control" style="width:180px;" onchange="this.form.submit()">
        <option value="">All Groups</option>
        <?php foreach($groupList as $g): ?>
        <option value="<?=$g['id']?>" <?=$groupId===$g['id']?'selected':''?>><?= sanitize($g['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <a href="export_attendance.php?year=<?=$year?>&group=<?=$groupId?>" class="btn btn-ghost btn-sm">↓ CSV</a>
    <a href="/educore/modules/reports/" class="btn btn-ghost btn-sm">← Reports</a>
  </div>
</div>

<!-- Overview KPI -->
<div class="stats-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:24px;">
  <?php $rateCol=$overallRate>=80?'#22c55e':($overallRate>=60?'#f59e0b':'#ef4444'); ?>
  <div class="stat-card" style="--stat-color:<?=$rateCol?>;--stat-bg:rgba(34,197,94,0.1)">
    <div class="stat-value"><?=$overallRate?>%</div>
    <div class="stat-label">Overall Rate</div>
  </div>
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.08)">
    <div class="stat-value"><?= number_format($overall['present']) ?></div>
    <div class="stat-label">Present</div>
  </div>
  <div class="stat-card" style="--stat-color:#ef4444;--stat-bg:rgba(239,68,68,0.08)">
    <div class="stat-value"><?= number_format($overall['absent']) ?></div>
    <div class="stat-label">Absent</div>
  </div>
  <div class="stat-card" style="--stat-color:#f59e0b;--stat-bg:rgba(245,158,11,0.08)">
    <div class="stat-value"><?= number_format($overall['late']) ?></div>
    <div class="stat-label">Late</div>
  </div>
  <div class="stat-card" style="--stat-color:#06b6d4;--stat-bg:rgba(6,182,212,0.08)">
    <div class="stat-value"><?= number_format($overall['excused']) ?></div>
    <div class="stat-label">Excused</div>
  </div>
</div>

<!-- Monthly table + chart -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
  <div class="card">
    <div class="card-header"><span class="card-title">Monthly Breakdown</span></div>
    <div class="card-body no-padding">
      <table class="data-table">
        <thead><tr><th>Month</th><th>Sessions</th><th>Records</th><th>Present</th><th>Absent</th><th>Rate</th></tr></thead>
        <tbody>
          <?php for($m=1;$m<=12;$m++):
            $r = $byMonth[$m];
            $rc = $r['rate']>=80?'var(--success)':($r['rate']>=60?'var(--warning)':'var(--danger)');
            $isCur = $m==(int)date('m') && $year==(int)date('Y');
          ?>
          <tr <?=$isCur?'style="background:rgba(245,166,35,0.04);"':''?>>
            <td style="font-weight:<?=$isCur?'700':'400'?>;color:<?=$isCur?'var(--accent)':'var(--text-primary)';?>"><?=$shortMonths[$m-1]?></td>
            <td><?=$r['sessions']?:0?></td>
            <td><?=$r['total']?:0?></td>
            <td style="color:var(--success);"><?=$r['present']?:0?></td>
            <td style="color:var(--danger);"><?=$r['absent']?:0?></td>
            <td>
              <?php if($r['total']>0): ?>
              <div style="display:flex;align-items:center;gap:6px;">
                <div style="width:50px;height:3px;background:var(--bg-elevated);border-radius:2px;"><div style="height:100%;width:<?=$r['rate']?>%;background:<?=$rc?>;border-radius:2px;"></div></div>
                <span style="font-size:0.8rem;font-weight:600;color:<?=$rc?>;"><?=$r['rate']?>%</span>
              </div>
              <?php else: ?><span style="color:var(--text-muted);font-size:0.75rem;">—</span><?php endif; ?>
            </td>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Per-group -->
  <div class="card">
    <div class="card-header"><span class="card-title">By Group</span></div>
    <div class="card-body no-padding">
      <?php if($byGroup): foreach($byGroup as $g):
        $rc=$g['att_rate']>=80?'var(--success)':($g['att_rate']>=60?'var(--warning)':'var(--danger)');
      ?>
      <div style="padding:11px 20px;border-bottom:1px solid var(--border);">
        <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
          <div>
            <div style="font-size:0.86rem;font-weight:600;color:var(--text-primary);"><?= sanitize($g['group_name']) ?></div>
            <div style="font-size:0.7rem;color:var(--text-muted);"><?=$g['sessions_count']?> sessions · <?=$g['student_count']?> students · <?= sanitize($g['teacher_name']??'') ?></div>
          </div>
          <span style="font-size:0.95rem;font-weight:700;color:<?=$rc?>;"><?=$g['att_rate']>0?$g['att_rate'].'%':'—'?></span>
        </div>
        <?php if($g['att_rate']>0): ?>
        <div style="height:4px;background:var(--bg-elevated);border-radius:2px;"><div style="height:100%;width:<?=$g['att_rate']?>%;background:<?=$rc?>;border-radius:2px;"></div></div>
        <?php endif; ?>
      </div>
      <?php endforeach; else: ?>
      <div class="empty-state" style="padding:30px;"><div class="empty-icon">📋</div><div class="empty-title">No data for this period</div></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Student tables -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

  <div class="card">
    <div class="card-header"><span class="card-title">🏆 Top Attendance</span></div>
    <div class="card-body no-padding">
      <?php if($topStudents): ?>
      <table class="data-table">
        <thead><tr><th>#</th><th>Student</th><th>Present</th><th>Rate</th></tr></thead>
        <tbody>
          <?php foreach($topStudents as $i=>$s):
            $rc=$s['rate']>=80?'var(--success)':($s['rate']>=60?'var(--warning)':'var(--danger)');
          ?>
          <tr>
            <td style="font-weight:700;color:var(--text-muted);"><?=$i+1?></td>
            <td>
              <div class="cell-primary"><?= sanitize($s['name']) ?></div>
              <?php if($s['student_code']): ?><div style="font-size:0.68rem;color:var(--text-muted);">#<?= sanitize($s['student_code']) ?></div><?php endif; ?>
            </td>
            <td style="font-size:0.83rem;"><?=$s['present']?> / <?=$s['total']?></td>
            <td>
              <div style="display:flex;align-items:center;gap:6px;">
                <div style="width:50px;height:3px;background:var(--bg-elevated);border-radius:2px;"><div style="height:100%;width:<?=$s['rate']?>%;background:<?=$rc?>;border-radius:2px;"></div></div>
                <span style="font-size:0.82rem;font-weight:700;color:<?=$rc?>;"><?=$s['rate']?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state" style="padding:24px;"><div class="empty-title" style="font-size:0.85rem;">No data available</div></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">⚠ At Risk (&lt;75%)</span></div>
    <div class="card-body no-padding">
      <?php if($bottomStudents): ?>
      <table class="data-table">
        <thead><tr><th>Student</th><th>Present</th><th>Rate</th></tr></thead>
        <tbody>
          <?php foreach($bottomStudents as $s):
            $rc=$s['rate']>=60?'var(--warning)':'var(--danger)';
          ?>
          <tr>
            <td>
              <div class="cell-primary"><?= sanitize($s['name']) ?></div>
              <?php if($s['student_code']): ?><div style="font-size:0.68rem;color:var(--text-muted);">#<?= sanitize($s['student_code']) ?></div><?php endif; ?>
            </td>
            <td style="font-size:0.83rem;"><?=$s['present']?> / <?=$s['total']?></td>
            <td>
              <span style="font-size:0.9rem;font-weight:700;color:<?=$rc?>;"><?=$s['rate']?>%</span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state" style="padding:24px;"><div class="empty-icon">✅</div><div class="empty-title" style="font-size:0.85rem;">No at-risk students</div></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
