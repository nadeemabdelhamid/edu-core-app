<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','accountant');

$pdo = getDB();
$cid = centerId();

$pageTitle  = 'Financial Report';
$activePage = 'reports';
$breadcrumbs = [
    ['label'=>'Dashboard', 'href'=>'/educore/dashboard.php'],
    ['label'=>'Reports',   'href'=>'/educore/modules/reports/'],
    ['label'=>'Financial'],
];

$year  = (int)($_GET['year']  ?? date('Y'));
$month = $_GET['month'] ?? '';  // filter to single month if set
$availYears = range(date('Y'), max(date('Y')-4, 2020));
$monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
$shortMonths = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

// ── Build WHERE clause ───────────────────────────────────
$where  = 'AND p.center_id = ?';
$params = [$cid];
if ($month) { $where .= ' AND p.month_year = ?'; $params[] = $month; }
else         { $where .= ' AND YEAR(STR_TO_DATE(CONCAT(COALESCE(p.month_year,DATE_FORMAT(p.created_at,"%Y-%m")),"-01"),"%Y-%m-%d"))=?'; $params[] = $year; }

// ── Summary totals ───────────────────────────────────────
$sumStmt = $pdo->prepare("SELECT
        COUNT(*)                                                          AS total_bills,
        COALESCE(SUM(amount-COALESCE(discount,0)),0)                     AS net_billed,
        COALESCE(SUM(paid_amount),0)                                     AS collected,
        COALESCE(SUM(CASE WHEN status IN('unpaid','overdue','partial') THEN amount-COALESCE(discount,0)-paid_amount ELSE 0 END),0) AS outstanding,
        COALESCE(SUM(discount),0)                                        AS discounts,
        SUM(status='paid')                                               AS cnt_paid,
        SUM(status='partial')                                            AS cnt_partial,
        SUM(status IN('unpaid','overdue'))                               AS cnt_unpaid
    FROM payments p WHERE 1=1 $where");
$sumStmt->execute($params);
$summary = $sumStmt->fetch();
$collectionRate = $summary['net_billed'] > 0 ? round($summary['collected'] / $summary['net_billed'] * 100, 1) : 0;

// ── By group ─────────────────────────────────────────────
$grpStmt = $pdo->prepare("SELECT g.name AS group_name,
        COUNT(p.id) AS cnt,
        SUM(p.amount-COALESCE(p.discount,0))    AS net_billed,
        SUM(p.paid_amount)                       AS collected,
        SUM(p.discount)                          AS discounts,
        SUM(p.status='paid')                     AS cnt_paid,
        SUM(p.status IN('unpaid','overdue','partial')) AS cnt_unpaid,
        ROUND(100.0*SUM(p.paid_amount)/NULLIF(SUM(p.amount-COALESCE(p.discount,0)),0),1) AS coll_rate
    FROM payments p LEFT JOIN `groups` g ON g.id=p.group_id
    WHERE 1=1 $where GROUP BY g.id ORDER BY collected DESC");
$grpStmt->execute($params);
$byGroup = $grpStmt->fetchAll();

// ── By month (only when showing full year) ────────────────
$byMonthData = [];
if (!$month) {
    $mStmt = $pdo->prepare("SELECT
            MONTH(STR_TO_DATE(CONCAT(COALESCE(month_year,DATE_FORMAT(created_at,'%Y-%m')),'-01'),'%Y-%m-%d')) AS m,
            SUM(amount-COALESCE(discount,0))    AS net_billed,
            SUM(paid_amount)                    AS collected,
            COUNT(*)                            AS cnt,
            SUM(status='paid')                  AS cnt_paid,
            SUM(status IN('unpaid','overdue'))   AS cnt_unpaid
        FROM payments p WHERE center_id=? AND YEAR(STR_TO_DATE(CONCAT(COALESCE(month_year,DATE_FORMAT(created_at,'%Y-%m')),'-01'),'%Y-%m-%d'))=?
        GROUP BY m ORDER BY m");
    $mStmt->execute([$cid, $year]);
    foreach($mStmt->fetchAll() as $r) $byMonthData[(int)$r['m']] = $r;
}

// ── Recent transactions ───────────────────────────────────
$txStmt = $pdo->prepare("SELECT p.*,s.name AS student_name,g.name AS group_name FROM payments p JOIN students s ON s.id=p.student_id LEFT JOIN `groups` g ON g.id=p.group_id WHERE 1=1 $where ORDER BY p.created_at DESC LIMIT 30");
$txStmt->execute($params);
$transactions = $txStmt->fetchAll();

$badgeMap = ['paid'=>'badge-success','partial'=>'badge-warning','unpaid'=>'badge-danger','overdue'=>'badge-danger'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Financial Report</h1>
    <p class="page-subtitle"><?= $month ? date('F Y',strtotime($month.'-01')) : "Full Year $year" ?></p>
  </div>
  <div style="display:flex;gap:10px;align-items:center;">
    <form method="GET" style="display:flex;gap:8px;">
      <select name="year" class="form-control" style="width:110px;" onchange="this.form.submit()">
        <?php foreach($availYears as $y): ?>
        <option value="<?=$y?>" <?=$y===$year?'selected':''?>><?=$y?></option>
        <?php endforeach; ?>
      </select>
      <select name="month" class="form-control" style="width:150px;" onchange="this.form.submit()">
        <option value="">Full Year</option>
        <?php foreach($monthNames as $i=>$mn): ?>
        <option value="<?=$year.'-'.str_pad($i+1,2,'0',STR_PAD_LEFT)?>"
                <?=$month===($year.'-'.str_pad($i+1,2,'0',STR_PAD_LEFT))?'selected':''?>><?=$mn?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <a href="export_financial.php?year=<?=$year?>&month=<?=urlencode($month)?>" class="btn btn-ghost btn-sm">↓ CSV</a>
    <a href="/educore/modules/reports/" class="btn btn-ghost btn-sm">← Reports</a>
  </div>
</div>

<!-- KPI -->
<div class="stats-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:24px;">
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)">
    <div class="stat-value"><?= number_format($summary['collected']) ?><small style="font-size:0.6rem;opacity:.6"> EGP</small></div>
    <div class="stat-label">Collected</div>
  </div>
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-value"><?= number_format($summary['net_billed']) ?><small style="font-size:0.6rem;opacity:.6"> EGP</small></div>
    <div class="stat-label">Net Billed</div>
  </div>
  <div class="stat-card" style="--stat-color:#ef4444;--stat-bg:rgba(239,68,68,0.1)">
    <div class="stat-value"><?= number_format($summary['outstanding']) ?><small style="font-size:0.6rem;opacity:.6"> EGP</small></div>
    <div class="stat-label">Outstanding</div>
  </div>
  <?php $rateColor=$collectionRate>=80?'#22c55e':($collectionRate>=60?'#f59e0b':'#ef4444'); ?>
  <div class="stat-card" style="--stat-color:<?=$rateColor?>;--stat-bg:rgba(34,197,94,0.08)">
    <div class="stat-value"><?= $collectionRate ?>%</div>
    <div class="stat-label">Collection Rate</div>
  </div>
  <div class="stat-card" style="--stat-color:#8b5cf6;--stat-bg:rgba(139,92,246,0.1)">
    <div class="stat-value"><?= number_format($summary['discounts']) ?><small style="font-size:0.6rem;opacity:.6"> EGP</small></div>
    <div class="stat-label">Discounts Given</div>
  </div>
</div>

<!-- By month table (full year only) -->
<?php if(!$month && $byMonthData): ?>
<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">Monthly Breakdown — <?=$year?></span></div>
  <div class="card-body no-padding">
    <table class="data-table">
      <thead><tr><th>Month</th><th>Bills</th><th>Net Billed</th><th>Collected</th><th>Outstanding</th><th>Collection Rate</th><th>Status</th></tr></thead>
      <tbody>
        <?php for($m=1;$m<=12;$m++):
          $r = $byMonthData[$m] ?? ['net_billed'=>0,'collected'=>0,'cnt'=>0,'cnt_paid'=>0,'cnt_unpaid'=>0];
          $outstanding = (float)$r['net_billed'] - (float)$r['collected'];
          $rate = (float)$r['net_billed']>0 ? round((float)$r['collected']/(float)$r['net_billed']*100,1) : 0;
          $rateCol = $rate>=80?'var(--success)':($rate>=60?'var(--warning)':'var(--danger)');
          $isCurrentMonth = $m==(int)date('m') && $year==(int)date('Y');
        ?>
        <tr <?=$isCurrentMonth?'style="background:rgba(245,166,35,0.04);"':''?>>
          <td class="cell-primary">
            <?= $shortMonths[$m-1] ?>
            <?php if($isCurrentMonth): ?><span class="badge badge-accent" style="font-size:0.65rem;margin-left:4px;">Current</span><?php endif; ?>
          </td>
          <td><?= (int)$r['cnt'] ?></td>
          <td style="font-weight:600;"><?= number_format($r['net_billed']) ?></td>
          <td style="color:var(--success);font-weight:600;"><?= number_format($r['collected']) ?></td>
          <td style="color:<?= $outstanding>0?'var(--danger)':'var(--text-muted)'?>;"><?= number_format(max(0,$outstanding)) ?></td>
          <td>
            <?php if($r['cnt']>0): ?>
            <div style="display:flex;align-items:center;gap:8px;">
              <div style="width:60px;height:4px;background:var(--bg-elevated);border-radius:2px;"><div style="height:100%;width:<?=$rate?>%;background:<?=$rateCol?>;border-radius:2px;"></div></div>
              <span style="font-size:0.82rem;font-weight:600;color:<?=$rateCol?>;"><?=$rate?>%</span>
            </div>
            <?php else: ?><span style="color:var(--text-muted);font-size:0.78rem;">—</span><?php endif; ?>
          </td>
          <td>
            <?php if($r['cnt']>0): ?>
            <span style="font-size:0.75rem;color:var(--success);"><?=(int)$r['cnt_paid']?> paid</span>
            <?php if($r['cnt_unpaid']>0): ?>&nbsp;<span style="font-size:0.75rem;color:var(--danger);"><?=(int)$r['cnt_unpaid']?> unpaid</span><?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- By group -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">By Group</span></div>
  <div class="card-body no-padding">
    <?php if($byGroup): ?>
    <table class="data-table">
      <thead><tr><th>Group</th><th>Bills</th><th>Net Billed</th><th>Collected</th><th>Outstanding</th><th>Collection Rate</th></tr></thead>
      <tbody>
        <?php foreach($byGroup as $g):
          $outstanding = (float)$g['net_billed'] - (float)$g['collected'];
          $rc = (float)$g['coll_rate']>=80?'var(--success)':((float)$g['coll_rate']>=60?'var(--warning)':'var(--danger)');
        ?>
        <tr>
          <td class="cell-primary"><?= sanitize($g['group_name'] ?: 'No Group') ?></td>
          <td><?=$g['cnt']?></td>
          <td style="font-weight:600;"><?= number_format($g['net_billed']) ?></td>
          <td style="color:var(--success);font-weight:600;"><?= number_format($g['collected']) ?></td>
          <td style="color:<?=$outstanding>0?'var(--danger)':'var(--text-muted)'?>;"><?= number_format(max(0,$outstanding)) ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;">
              <div style="width:60px;height:4px;background:var(--bg-elevated);border-radius:2px;"><div style="height:100%;width:<?=$g['coll_rate']??0?>%;background:<?=$rc?>;border-radius:2px;"></div></div>
              <span style="font-size:0.82rem;font-weight:600;color:<?=$rc?>;"><?=$g['coll_rate']??0?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state" style="padding:30px;"><div class="empty-icon">📊</div><div class="empty-title">No financial data for this period</div></div>
    <?php endif; ?>
  </div>
</div>

<!-- Transactions -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Transactions</span>
    <span style="font-size:0.75rem;color:var(--text-muted);"><?= count($transactions) ?> shown</span>
  </div>
  <div class="card-body no-padding">
    <?php if($transactions): ?>
    <table class="data-table">
      <thead><tr><th>Receipt</th><th>Student</th><th>Group</th><th>Paid</th><th>Method</th><th>Date</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach($transactions as $t): ?>
        <tr>
          <td style="font-family:monospace;font-size:0.78rem;color:var(--accent);"><?= sanitize($t['receipt_number']??'—') ?></td>
          <td class="cell-primary"><?= sanitize($t['student_name']) ?></td>
          <td style="font-size:0.82rem;color:var(--text-muted);"><?= sanitize($t['group_name']??'—') ?></td>
          <td style="font-weight:600;color:var(--success);"><?= number_format($t['paid_amount']) ?></td>
          <td style="font-size:0.78rem;color:var(--text-muted);"><?= ucfirst(str_replace('_',' ',$t['payment_method'])) ?></td>
          <td style="font-size:0.78rem;color:var(--text-muted);"><?= $t['paid_date']?date('M j',strtotime($t['paid_date'])):date('M j',strtotime($t['created_at'])) ?></td>
          <td><span class="badge <?=$badgeMap[$t['status']]?>"><?= ucfirst($t['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state" style="padding:30px;"><div class="empty-icon">💳</div><div class="empty-title">No transactions for this period</div></div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
