<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','accountant');

$pdo = getDB();
$cid = centerId();

$pageTitle  = 'Student Report';
$activePage = 'reports';
$breadcrumbs = [
    ['label'=>'Dashboard', 'href'=>'/educore/dashboard.php'],
    ['label'=>'Reports',   'href'=>'/educore/modules/reports/'],
    ['label'=>'Students'],
];

$search = trim($_GET['q']      ?? '');
$sortBy = $_GET['sort']        ?? 'name';
$order  = $_GET['order'] === 'asc' ? 'ASC' : 'DESC';
$page   = max(1,(int)($_GET['page']??1));

// ── Overview stats ───────────────────────────────────────
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE center_id=? AND is_active=1");
$totalStmt->execute([$cid]); $totalActive = $totalStmt->fetchColumn();

$inactiveStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE center_id=? AND is_active=0");
$inactiveStmt->execute([$cid]); $totalInactive = $inactiveStmt->fetchColumn();

$withLoginStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE center_id=? AND user_id IS NOT NULL");
$withLoginStmt->execute([$cid]); $withLogin = $withLoginStmt->fetchColumn();

$newThisMonthStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE center_id=? AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())");
$newThisMonthStmt->execute([$cid]); $newThisMonth = $newThisMonthStmt->fetchColumn();

// ── Enrollment by group ───────────────────────────────────
$grpEnrollStmt = $pdo->prepare("SELECT g.name, COUNT(gs.id) AS cnt FROM group_students gs JOIN `groups` g ON g.id=gs.group_id WHERE gs.center_id=? AND gs.status='active' GROUP BY g.id ORDER BY cnt DESC LIMIT 8");
$grpEnrollStmt->execute([$cid]); $groupEnroll = $grpEnrollStmt->fetchAll();

// ── Per-student table ─────────────────────────────────────
$allowedSort = ['name','att_rate','total_paid','created_at'];
$sortCol     = in_array($sortBy, $allowedSort) ? $sortBy : 'name';

$where  = 'WHERE s.center_id = ?';
$params = [$cid];
if ($search) { $where .= ' AND (s.name LIKE ? OR s.student_code LIKE ? OR s.phone LIKE ?)'; $like="%$search%"; $params=array_merge($params,[$like,$like,$like]); }

$sortMap = [
    'name'       => 's.name',
    'att_rate'   => 'att_rate',
    'total_paid' => 'total_paid',
    'created_at' => 's.created_at',
];
$orderClause = ($sortMap[$sortCol]??'s.name').' '.$order;

$query = "SELECT s.id, s.name, s.student_code, s.phone, s.email, s.is_active, s.created_at, s.user_id,
                 (SELECT COUNT(*) FROM group_students gs WHERE gs.student_id=s.id AND gs.status='active') AS group_count,
                 (SELECT ROUND(100.0*SUM(ar.status IN('present','late'))/NULLIF(COUNT(*),0),1)
                  FROM attendance_records ar
                  JOIN attendance_sessions asess ON asess.id=ar.session_id
                  WHERE ar.student_id=s.id AND ar.center_id=s.center_id
                  AND asess.session_date >= DATE_SUB(NOW(),INTERVAL 90 DAY)) AS att_rate,
                 (SELECT COALESCE(SUM(paid_amount),0) FROM payments WHERE student_id=s.id AND center_id=s.center_id) AS total_paid,
                 (SELECT COALESCE(SUM(amount-COALESCE(discount,0)-paid_amount),0) FROM payments WHERE student_id=s.id AND center_id=s.center_id AND status IN('unpaid','partial','overdue')) AS outstanding
          FROM students s $where ORDER BY $orderClause";

$result   = paginate($query, $params, $page, 30);
$students = $result['data'];

function sortLink($col, $label, $currentSort, $currentOrder) {
    $newOrder = ($currentSort===$col && $currentOrder==='DESC') ? 'asc' : 'desc';
    $arrow    = $currentSort===$col ? ($currentOrder==='DESC'?'↓':'↑') : '';
    return "<a href=\"?sort=$col&order=$newOrder&q=".urlencode($_GET['q']??'')."\" style=\"color:inherit;text-decoration:none;\">$label $arrow</a>";
}

$colors = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">Student Report</h1><p class="page-subtitle">Attendance, payments and engagement per student</p></div>
  <div style="display:flex;gap:10px;">
    <a href="export_students.php" class="btn btn-ghost btn-sm">↓ Export CSV</a>
    <a href="/educore/modules/reports/" class="btn btn-ghost btn-sm">← Reports</a>
  </div>
</div>

<!-- KPI -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
  <div class="stat-card" style="--stat-color:#3b82f6;--stat-bg:rgba(59,130,246,0.1)">
    <div class="stat-value"><?=$totalActive?></div><div class="stat-label">Active Students</div>
  </div>
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-value"><?=$newThisMonth?></div><div class="stat-label">New This Month</div>
  </div>
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.08)">
    <div class="stat-value"><?=$withLogin?></div><div class="stat-label">Have Portal Access</div>
  </div>
  <div class="stat-card" style="--stat-color:#ef4444;--stat-bg:rgba(239,68,68,0.08)">
    <div class="stat-value"><?=$totalActive-$withLogin?></div><div class="stat-label">No Login Yet</div>
  </div>
</div>

<!-- Enrollment by group bars -->
<?php if($groupEnroll): ?>
<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">Enrollment by Group</span></div>
  <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;">
    <?php
    $maxEnroll = max(array_column($groupEnroll,'cnt'));
    foreach($groupEnroll as $i=>$g):
      $pct = $maxEnroll>0 ? round($g['cnt']/$maxEnroll*100) : 0;
      $gc = $colors[$i % count($colors)];
    ?>
    <div style="background:var(--bg-elevated);border-radius:var(--radius-md);padding:14px;">
      <div style="font-size:0.8rem;font-weight:600;color:var(--text-primary);margin-bottom:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= sanitize($g['name']) ?></div>
      <div style="height:6px;background:var(--bg-surface);border-radius:3px;margin-bottom:6px;">
        <div style="height:100%;width:<?=$pct?>%;background:<?=$gc?>;border-radius:3px;"></div>
      </div>
      <span style="font-size:0.75rem;color:var(--text-muted);"><?=$g['cnt']?> students</span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Student table -->
<div class="card">
  <div class="card-header">
    <span class="card-title">All Students</span>
    <form method="GET" style="display:flex;gap:8px;">
      <input type="hidden" name="sort" value="<?=$sortBy?>">
      <input type="hidden" name="order" value="<?=strtolower($order)?>">
      <input type="text" name="q" class="form-control" placeholder="Search…" value="<?=sanitize($search)?>" style="width:200px;">
      <button type="submit" class="btn btn-primary btn-sm">Search</button>
    </form>
  </div>
  <div class="card-body no-padding">
    <?php if($students): ?>
    <table class="data-table">
      <thead>
        <tr>
          <th><?= sortLink('name','Student',$sortBy,$order) ?></th>
          <th>Groups</th>
          <th><?= sortLink('att_rate','Attendance (90d)',$sortBy,$order) ?></th>
          <th><?= sortLink('total_paid','Total Paid',$sortBy,$order) ?></th>
          <th>Outstanding</th>
          <th>Login</th>
          <th><?= sortLink('created_at','Joined',$sortBy,$order) ?></th>
          <th style="text-align:right;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($students as $s):
          $sc = $colors[abs(crc32($s['name']))%count($colors)];
          $rate = $s['att_rate'];
          $rc   = $rate>=80?'var(--success)':($rate>=60?'var(--warning)':($rate>0?'var(--danger)':'var(--text-muted)'));
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,<?=$sc?>,<?=$sc?>99);color:#fff;font-size:0.65rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?= strtoupper(substr($s['name'],0,2)) ?></div>
              <div>
                <div class="cell-primary"><?= sanitize($s['name']) ?></div>
                <?php if($s['student_code']): ?><div style="font-size:0.68rem;color:var(--text-muted);">#<?= sanitize($s['student_code']) ?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td><?php if($s['group_count']>0): ?><span class="badge badge-info"><?=$s['group_count']?> group<?=$s['group_count']>1?'s':''?></span><?php else: ?><span style="color:var(--text-muted);font-size:0.78rem;">None</span><?php endif; ?></td>
          <td>
            <?php if($rate !== null): ?>
            <div style="display:flex;align-items:center;gap:6px;">
              <div style="width:50px;height:3px;background:var(--bg-elevated);border-radius:2px;"><div style="height:100%;width:<?=$rate?>%;background:<?=$rc?>;border-radius:2px;"></div></div>
              <span style="font-size:0.8rem;font-weight:600;color:<?=$rc?>;"><?=$rate?>%</span>
            </div>
            <?php else: ?><span style="color:var(--text-muted);font-size:0.75rem;">—</span><?php endif; ?>
          </td>
          <td style="color:var(--success);font-weight:600;font-size:0.84rem;"><?= $s['total_paid']>0 ? number_format($s['total_paid']).' EGP' : '—' ?></td>
          <td style="color:<?=$s['outstanding']>0?'var(--danger)':'var(--text-muted)'?>;font-size:0.84rem;"><?= $s['outstanding']>0 ? number_format($s['outstanding']).' EGP' : '—' ?></td>
          <td>
            <?php if($s['user_id']): ?>
            <span style="font-size:0.75rem;color:var(--success);">✓ Active</span>
            <?php else: ?>
            <span style="font-size:0.75rem;color:var(--danger);">✗ None</span>
            <?php endif; ?>
          </td>
          <td style="font-size:0.78rem;color:var(--text-muted);"><?= date('M j, Y',strtotime($s['created_at'])) ?></td>
          <td style="text-align:right;">
            <a href="/educore/modules/students/view.php?id=<?=$s['id']?>" class="btn btn-ghost btn-sm" style="font-size:0.75rem;">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if($result['last_page']>1): ?>
    <div class="pagination">
      <span>Page <?=$result['current_page']?> of <?=$result['last_page']?></span>
      <div class="page-btns">
        <?php for($i=1;$i<=$result['last_page'];$i++): ?>
        <a href="?page=<?=$i?>&q=<?=urlencode($search)?>&sort=<?=$sortBy?>&order=<?=strtolower($order)?>"
           class="page-btn <?=$i===$result['current_page']?'active':''?>"><?=$i?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon">👥</div><div class="empty-title"><?=$search?'No students match your search':'No students yet'?></div></div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
