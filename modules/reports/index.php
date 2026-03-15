<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','accountant');

$pdo = getDB();
$cid = centerId();

$pageTitle  = 'Reports & Analytics';
$activePage = 'reports';
$breadcrumbs = [
    ['label'=>'Dashboard', 'href'=>'/educore/dashboard.php'],
    ['label'=>'Reports'],
];

// ── Date range (default: current year) ──────────────────
$year  = (int)($_GET['year']  ?? date('Y'));
$month = $_GET['month'] ?? ''; // optional single month filter

// ── KPI Overview ─────────────────────────────────────────
// Revenue this year vs last year
$rev = $pdo->prepare("SELECT YEAR(paid_date) AS yr, COALESCE(SUM(paid_amount),0) AS total FROM payments WHERE center_id=? AND status IN('paid','partial') AND paid_date IS NOT NULL AND YEAR(paid_date) IN (?,?) GROUP BY yr");
$rev->execute([$cid, $year, $year-1]);
$revByYear = []; foreach($rev->fetchAll() as $r) $revByYear[$r['yr']] = $r['total'];
$revThis  = $revByYear[$year]    ?? 0;
$revLast  = $revByYear[$year-1]  ?? 0;
$revChange = $revLast > 0 ? round((($revThis-$revLast)/$revLast)*100,1) : 0;

// Outstanding
$outStmt = $pdo->prepare("SELECT COALESCE(SUM(amount-COALESCE(discount,0)-paid_amount),0) FROM payments WHERE center_id=? AND status IN('unpaid','partial','overdue')");
$outStmt->execute([$cid]); $outstanding = $outStmt->fetchColumn();

// Active students
$stuStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE center_id=? AND is_active=1");
$stuStmt->execute([$cid]); $activeStudents = $stuStmt->fetchColumn();

// Avg attendance rate this month
$attStmt = $pdo->prepare("
    SELECT ROUND(100.0*SUM(ar.status IN('present','late'))/NULLIF(COUNT(*),0),1)
    FROM attendance_records ar
    JOIN attendance_sessions s ON s.id=ar.session_id
    WHERE ar.center_id=? AND MONTH(s.session_date)=MONTH(NOW()) AND YEAR(s.session_date)=YEAR(NOW())");
$attStmt->execute([$cid]); $attRate = $attStmt->fetchColumn() ?? 0;

// ── Revenue by month (12 months of selected year) ────────
$rmStmt = $pdo->prepare("
    SELECT MONTH(paid_date) AS m, COALESCE(SUM(paid_amount),0) AS total
    FROM payments
    WHERE center_id=? AND status IN('paid','partial') AND YEAR(paid_date)=?
    GROUP BY MONTH(paid_date)");
$rmStmt->execute([$cid, $year]);
$revByMonth = array_fill(1, 12, 0);
foreach ($rmStmt->fetchAll() as $r) $revByMonth[(int)$r['m']] = (float)$r['total'];

// ── Bills by month (billed vs collected) ─────────────────
$billStmt = $pdo->prepare("
    SELECT MONTH(STR_TO_DATE(CONCAT(month_year,'-01'),'%Y-%m-%d')) AS m,
           SUM(amount-COALESCE(discount,0)) AS billed,
           SUM(paid_amount)                 AS collected
    FROM payments WHERE center_id=? AND YEAR(STR_TO_DATE(CONCAT(month_year,'-01'),'%Y-%m-%d'))=?
    AND month_year IS NOT NULL
    GROUP BY m ORDER BY m");
$billStmt->execute([$cid, $year]);
$billedByMonth    = array_fill(1,12,0);
$collectedByMonth = array_fill(1,12,0);
foreach($billStmt->fetchAll() as $r) {
    $billedByMonth[(int)$r['m']]    = (float)$r['billed'];
    $collectedByMonth[(int)$r['m']] = (float)$r['collected'];
}

// ── Payment status breakdown ──────────────────────────────
$statusStmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt, SUM(amount-COALESCE(discount,0)) AS total FROM payments WHERE center_id=? AND YEAR(created_at)=? GROUP BY status");
$statusStmt->execute([$cid, $year]);
$statusBreakdown = []; foreach($statusStmt->fetchAll() as $r) $statusBreakdown[$r['status']] = $r;

// ── Attendance by month ───────────────────────────────────
$attMonStmt = $pdo->prepare("
    SELECT MONTH(s.session_date) AS m,
           COUNT(*) AS total,
           SUM(ar.status IN('present','late')) AS present
    FROM attendance_records ar
    JOIN attendance_sessions s ON s.id=ar.session_id
    WHERE ar.center_id=? AND YEAR(s.session_date)=?
    GROUP BY m ORDER BY m");
$attMonStmt->execute([$cid, $year]);
$attByMonth = array_fill(1,12,['total'=>0,'present'=>0,'rate'=>0]);
foreach($attMonStmt->fetchAll() as $r) {
    $attByMonth[(int)$r['m']] = [
        'total'   => $r['total'],
        'present' => $r['present'],
        'rate'    => $r['total']>0 ? round($r['present']/$r['total']*100,1) : 0,
    ];
}

// ── Top groups by revenue ─────────────────────────────────
$topGrpStmt = $pdo->prepare("
    SELECT g.name, SUM(p.paid_amount) AS revenue, COUNT(DISTINCT p.student_id) AS students
    FROM payments p JOIN `groups` g ON g.id=p.group_id
    WHERE p.center_id=? AND YEAR(p.created_at)=? AND p.status IN('paid','partial')
    GROUP BY g.id ORDER BY revenue DESC LIMIT 8");
$topGrpStmt->execute([$cid, $year]);
$topGroups = $topGrpStmt->fetchAll();

// ── Student attendance leaderboard ───────────────────────
$attLBStmt = $pdo->prepare("
    SELECT s.name, s.student_code,
           COUNT(*) AS total,
           SUM(ar.status IN('present','late')) AS present,
           ROUND(100.0*SUM(ar.status IN('present','late'))/COUNT(*),1) AS rate
    FROM attendance_records ar
    JOIN students s ON s.id=ar.student_id
    JOIN attendance_sessions ss ON ss.id=ar.session_id
    WHERE ar.center_id=? AND YEAR(ss.session_date)=?
    GROUP BY s.id HAVING total >= 3
    ORDER BY rate DESC LIMIT 10");
$attLBStmt->execute([$cid, $year]);
$attLeaderboard = $attLBStmt->fetchAll();

// ── Unpaid students ───────────────────────────────────────
$unpaidStmt = $pdo->prepare("
    SELECT s.name, s.student_code, s.phone,
           COUNT(p.id) AS cnt,
           SUM(p.amount-COALESCE(p.discount,0)-p.paid_amount) AS owed
    FROM payments p JOIN students s ON s.id=p.student_id
    WHERE p.center_id=? AND p.status IN('unpaid','overdue','partial')
    GROUP BY s.id ORDER BY owed DESC LIMIT 10");
$unpaidStmt->execute([$cid]);
$unpaidStudents = $unpaidStmt->fetchAll();

// ── Monthly new enrollments ───────────────────────────────
$enrollStmt = $pdo->prepare("
    SELECT MONTH(enrolled_at) AS m, COUNT(*) AS cnt
    FROM group_students WHERE center_id=? AND YEAR(enrolled_at)=?
    GROUP BY m ORDER BY m");
$enrollStmt->execute([$cid, $year]);
$enrollByMonth = array_fill(1,12,0);
foreach($enrollStmt->fetchAll() as $r) $enrollByMonth[(int)$r['m']] = (int)$r['cnt'];

$monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$availYears = range(date('Y'), max(date('Y')-4, 2020));

include __DIR__ . '/../../templates/layout.php';
?>

<!-- Header -->
<div class="page-header">
  <div>
    <h1 class="page-title">Reports & Analytics</h1>
    <p class="page-subtitle">Financial performance, attendance trends and student insights</p>
  </div>
  <div style="display:flex;gap:10px;align-items:center;">
    <form method="GET" style="display:flex;gap:8px;">
      <select name="year" class="form-control" style="width:110px;" onchange="this.form.submit()">
        <?php foreach($availYears as $y): ?>
        <option value="<?=$y?>" <?=$y===$year?'selected':''?>><?=$y?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <a href="financial.php?year=<?=$year?>" class="btn btn-ghost btn-sm">Financial Report</a>
    <a href="attendance.php?year=<?=$year?>" class="btn btn-ghost btn-sm">Attendance Report</a>
    <a href="students.php" class="btn btn-ghost btn-sm">Student Report</a>
  </div>
</div>

<!-- KPI row -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)">
    <div class="stat-value"><?= number_format($revThis) ?><small style="font-size:0.65rem;opacity:.6"> EGP</small></div>
    <div class="stat-label">Revenue <?=$year?></div>
    <span class="stat-change <?=$revChange>=0?'up':'down'?>"><?=$revChange>=0?'↑':'↓'?> <?=abs($revChange)?>% vs <?=$year-1?></span>
  </div>
  <div class="stat-card" style="--stat-color:#ef4444;--stat-bg:rgba(239,68,68,0.1)">
    <div class="stat-value"><?= number_format($outstanding) ?><small style="font-size:0.65rem;opacity:.6"> EGP</small></div>
    <div class="stat-label">Outstanding Balance</div>
  </div>
  <div class="stat-card" style="--stat-color:#3b82f6;--stat-bg:rgba(59,130,246,0.1)">
    <div class="stat-value"><?= number_format($activeStudents) ?></div>
    <div class="stat-label">Active Students</div>
  </div>
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-value"><?= $attRate ?>%</div>
    <div class="stat-label">Attendance This Month</div>
  </div>
</div>

<!-- Revenue chart + Payment status -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px;">

  <!-- Monthly revenue chart -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Revenue — <?=$year?></span>
      <span style="font-size:0.75rem;color:var(--text-muted);">Billed vs Collected (EGP)</span>
    </div>
    <div class="card-body">
      <canvas id="revenueChart" height="220"></canvas>
    </div>
  </div>

  <!-- Payment status donut -->
  <div class="card">
    <div class="card-header"><span class="card-title">Payment Status</span></div>
    <div class="card-body" style="display:flex;flex-direction:column;align-items:center;gap:16px;">
      <canvas id="statusChart" width="180" height="180"></canvas>
      <div style="width:100%;">
        <?php
        $statusColors=['paid'=>'#22c55e','partial'=>'#f59e0b','unpaid'=>'#ef4444','overdue'=>'#dc2626'];
        $statusLabels=['paid'=>'Paid','partial'=>'Partial','unpaid'=>'Unpaid','overdue'=>'Overdue'];
        foreach($statusBreakdown as $s=>$d):
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
          <div style="display:flex;align-items:center;gap:8px;">
            <div style="width:10px;height:10px;border-radius:50%;background:<?=$statusColors[$s]??'#888'?>;flex-shrink:0;"></div>
            <span style="font-size:0.8rem;color:var(--text-secondary);"><?=$statusLabels[$s]??ucfirst($s)?></span>
          </div>
          <span style="font-size:0.8rem;font-weight:600;color:var(--text-primary);"><?= $d['cnt'] ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- Attendance trend + Enrollments -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

  <!-- Attendance rate by month -->
  <div class="card">
    <div class="card-header"><span class="card-title">Attendance Rate by Month</span></div>
    <div class="card-body">
      <canvas id="attChart" height="200"></canvas>
    </div>
  </div>

  <!-- New enrollments by month -->
  <div class="card">
    <div class="card-header"><span class="card-title">New Enrollments by Month</span></div>
    <div class="card-body">
      <canvas id="enrollChart" height="200"></canvas>
    </div>
  </div>
</div>

<!-- Top groups + Unpaid students -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

  <div class="card">
    <div class="card-header"><span class="card-title">Top Groups by Revenue</span></div>
    <div class="card-body no-padding">
      <?php if($topGroups):
        $maxRev = max(array_column($topGroups,'revenue'));
        foreach($topGroups as $g):
        $pct = $maxRev > 0 ? round($g['revenue']/$maxRev*100) : 0;
      ?>
      <div style="padding:10px 20px;border-bottom:1px solid var(--border);">
        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
          <span style="font-size:0.84rem;font-weight:500;color:var(--text-primary);"><?= sanitize($g['name']) ?></span>
          <span style="font-size:0.84rem;font-weight:700;color:var(--accent);"><?= number_format($g['revenue']) ?> EGP</span>
        </div>
        <div style="height:4px;background:var(--bg-elevated);border-radius:2px;">
          <div style="height:100%;width:<?=$pct?>%;background:var(--accent);border-radius:2px;"></div>
        </div>
        <div style="font-size:0.68rem;color:var(--text-muted);margin-top:3px;"><?=$g['students']?> students</div>
      </div>
      <?php endforeach; else: ?>
      <div class="empty-state" style="padding:30px;"><div class="empty-icon">💰</div><div class="empty-title">No revenue data yet</div></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="card-title">Outstanding Balances</span>
      <a href="/educore/modules/payments/?status=unpaid" class="btn btn-ghost btn-sm">View All</a>
    </div>
    <div class="card-body no-padding">
      <?php if($unpaidStudents): foreach($unpaidStudents as $u): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 20px;border-bottom:1px solid var(--border);">
        <div>
          <div style="font-size:0.84rem;font-weight:500;color:var(--text-primary);"><?= sanitize($u['name']) ?></div>
          <div style="font-size:0.7rem;color:var(--text-muted);"><?= $u['cnt'] ?> unpaid bill<?=$u['cnt']>1?'s':''?></div>
        </div>
        <span style="font-size:0.88rem;font-weight:700;color:var(--danger);"><?= number_format($u['owed']) ?> EGP</span>
      </div>
      <?php endforeach; else: ?>
      <div class="empty-state" style="padding:30px;"><div class="empty-icon">✅</div><div class="empty-title">No outstanding balances</div></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Attendance leaderboard -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Attendance Leaderboard — <?=$year?></span>
    <a href="attendance.php?year=<?=$year?>" class="btn btn-ghost btn-sm">Full Report</a>
  </div>
  <div class="card-body no-padding">
    <?php if($attLeaderboard): ?>
    <table class="data-table">
      <thead><tr><th>#</th><th>Student</th><th>Sessions</th><th>Present</th><th>Rate</th></tr></thead>
      <tbody>
        <?php foreach($attLeaderboard as $i=>$s):
          $col = $s['rate']>=80?'var(--success)':($s['rate']>=60?'var(--warning)':'var(--danger)');
        ?>
        <tr>
          <td style="color:var(--text-muted);font-weight:600;"><?=$i+1?></td>
          <td>
            <div class="cell-primary"><?= sanitize($s['name']) ?></div>
            <?php if($s['student_code']): ?><div style="font-size:0.7rem;color:var(--text-muted);">#<?= sanitize($s['student_code']) ?></div><?php endif; ?>
          </td>
          <td><?= $s['total'] ?></td>
          <td><?= $s['present'] ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;">
              <div style="width:80px;height:5px;background:var(--bg-elevated);border-radius:3px;">
                <div style="height:100%;width:<?=$s['rate']?>%;background:<?=$col?>;border-radius:3px;"></div>
              </div>
              <span style="font-size:0.84rem;font-weight:700;color:<?=$col?>;"><?=$s['rate']?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state" style="padding:30px;"><div class="empty-icon">📊</div><div class="empty-title">No attendance data for <?=$year?></div></div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const MONTHS = <?= json_encode(array_values($monthNames)) ?>;
const accent = '#f5a623';

// Shared chart defaults
Chart.defaults.color = '#8892a4';
Chart.defaults.font.family = "'Nunito', sans-serif";
Chart.defaults.plugins.legend.display = false;

const gridColor = 'rgba(255,255,255,0.05)';

// ── Revenue chart (bar - billed vs collected) ─────────────
new Chart(document.getElementById('revenueChart'), {
    type: 'bar',
    data: {
        labels: MONTHS,
        datasets: [
            {
                label: 'Billed',
                data: <?= json_encode(array_values($billedByMonth)) ?>,
                backgroundColor: 'rgba(245,166,35,0.2)',
                borderColor: accent,
                borderWidth: 1.5,
                borderRadius: 4,
            },
            {
                label: 'Collected',
                data: <?= json_encode(array_values($collectedByMonth)) ?>,
                backgroundColor: 'rgba(34,197,94,0.6)',
                borderColor: '#22c55e',
                borderWidth: 1.5,
                borderRadius: 4,
            }
        ]
    },
    options: {
        plugins: {
            legend: { display: true, labels: { color:'#8892a4', boxWidth:12, padding:16, font:{size:11} } },
            tooltip: { callbacks: { label: ctx => ' ' + ctx.parsed.y.toLocaleString() + ' EGP' } }
        },
        scales: {
            x: { grid:{color:gridColor}, ticks:{font:{size:11}} },
            y: { grid:{color:gridColor}, ticks:{callback: v => v.toLocaleString()} }
        }
    }
});

// ── Payment status donut ──────────────────────────────────
const statusData = <?= json_encode(array_values(array_map(fn($d)=>(float)$d['total'], $statusBreakdown))) ?>;
const statusLabels = <?= json_encode(array_map(fn($s)=>ucfirst($s), array_keys($statusBreakdown))) ?>;
const statusColors = ['#22c55e','#f59e0b','#ef4444','#dc2626','#8b5cf6'];
if (statusData.length > 0) {
    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: statusLabels,
            datasets: [{ data: statusData, backgroundColor: statusColors.slice(0,statusData.length), borderWidth: 0, hoverOffset: 6 }]
        },
        options: {
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ' ' + ctx.label + ': ' + Number(ctx.parsed).toLocaleString() + ' EGP' } }
            },
            cutout: '70%'
        }
    });
}

// ── Attendance rate line ──────────────────────────────────
new Chart(document.getElementById('attChart'), {
    type: 'line',
    data: {
        labels: MONTHS,
        datasets: [{
            label: 'Attendance %',
            data: <?= json_encode(array_map(fn($m)=>$m['rate'], array_values($attByMonth))) ?>,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59,130,246,0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointBackgroundColor: '#3b82f6',
        }]
    },
    options: {
        scales: {
            x: { grid:{color:gridColor}, ticks:{font:{size:11}} },
            y: { min:0, max:100, grid:{color:gridColor}, ticks:{callback: v=>v+'%'} }
        },
        plugins: {
            tooltip: { callbacks: { label: ctx => ' ' + ctx.parsed.y + '%' } }
        }
    }
});

// ── Enrollments bar ───────────────────────────────────────
new Chart(document.getElementById('enrollChart'), {
    type: 'bar',
    data: {
        labels: MONTHS,
        datasets: [{
            label: 'New Enrollments',
            data: <?= json_encode(array_values($enrollByMonth)) ?>,
            backgroundColor: 'rgba(139,92,246,0.7)',
            borderColor: '#8b5cf6',
            borderWidth: 1.5,
            borderRadius: 4,
        }]
    },
    options: {
        scales: {
            x: { grid:{color:gridColor}, ticks:{font:{size:11}} },
            y: { grid:{color:gridColor}, ticks:{stepSize:1} }
        }
    }
});
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
