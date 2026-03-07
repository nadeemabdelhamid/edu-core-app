<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','assistant','accountant');

$pdo = getDB();
$cid = centerId();

$pageTitle  = 'Payments';
$activePage = 'payments';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Payments'],
];

// ── Filters ─────────────────────────────────────────────────
$search  = trim($_GET['q']      ?? '');
$status  = $_GET['status']      ?? '';
$group   = (int)($_GET['group'] ?? 0);
$month   = $_GET['month']       ?? '';          // e.g. 2024-09
$page    = max(1,(int)($_GET['page'] ?? 1));

$where  = 'WHERE p.center_id = ?';
$params = [$cid];

if ($search) {
    $where .= ' AND (s.name LIKE ? OR p.receipt_number LIKE ? OR p.invoice_number LIKE ?)';
    $like   = "%$search%";
    $params = array_merge($params, [$like,$like,$like]);
}
if ($status) { $where .= ' AND p.status = ?'; $params[] = $status; }
if ($group)  { $where .= ' AND p.group_id = ?'; $params[] = $group; }
if ($month)  { $where .= ' AND p.month_year = ?'; $params[] = $month; }

// ── Stats (unfiltered for the current month) ─────────────
$cur = date('Y-m');
$sm  = $pdo->prepare("SELECT
        COALESCE(SUM(paid_amount),0)                                        AS collected,
        COALESCE(SUM(amount - COALESCE(discount,0)),0)                      AS billed,
        COALESCE(SUM(CASE WHEN status IN('unpaid','overdue') THEN (amount - COALESCE(discount,0) - paid_amount) ELSE 0 END),0) AS outstanding,
        SUM(status='paid')                                                  AS cnt_paid,
        SUM(status='partial')                                               AS cnt_partial,
        SUM(status IN('unpaid','overdue'))                                  AS cnt_unpaid
    FROM payments WHERE center_id=? AND month_year=?");
$sm->execute([$cid, $cur]);
$stats = $sm->fetch();

// ── Main query ───────────────────────────────────────────
$query = "SELECT p.*, s.name AS student_name, s.student_code,
                 g.name AS group_name
          FROM payments p
          JOIN students s ON s.id = p.student_id
          LEFT JOIN `groups` g ON g.id = p.group_id
          $where
          ORDER BY p.created_at DESC";

$result   = paginate($query, $params, $page, 25);
$payments = $result['data'];

// Groups for filter dropdown
$groups = $pdo->prepare("SELECT id,name FROM `groups` WHERE center_id=? AND is_active=1 ORDER BY name");
$groups->execute([$cid]);
$groupList = $groups->fetchAll();

// Distinct months for filter
$mStmt = $pdo->prepare("SELECT DISTINCT month_year FROM payments WHERE center_id=? AND month_year IS NOT NULL ORDER BY month_year DESC LIMIT 24");
$mStmt->execute([$cid]);
$months = array_column($mStmt->fetchAll(), 'month_year');

$badgeMap = ['paid'=>'badge-success','partial'=>'badge-warning','unpaid'=>'badge-danger','overdue'=>'badge-danger'];
$methodLabel = ['cash'=>'Cash','bank_transfer'=>'Bank','card'=>'Card','other'=>'Other'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Payments</h1>
    <p class="page-subtitle">Track fees, collections and outstanding balances</p>
  </div>
  <div style="display:flex;gap:10px;">
    <a href="record.php" class="btn btn-primary">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Record Payment
    </a>
    <a href="generate.php" class="btn btn-ghost">Generate Monthly Bills</a>
  </div>
</div>

<!-- Stats row -->
<div class="stats-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:24px;">
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)">
    <div class="stat-value"><?= number_format($stats['collected']) ?><small style="font-size:0.7rem;opacity:.6"> EGP</small></div>
    <div class="stat-label">Collected This Month</div>
  </div>
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-value"><?= number_format($stats['billed']) ?><small style="font-size:0.7rem;opacity:.6"> EGP</small></div>
    <div class="stat-label">Billed This Month</div>
  </div>
  <div class="stat-card" style="--stat-color:#ef4444;--stat-bg:rgba(239,68,68,0.1)">
    <div class="stat-value"><?= number_format($stats['outstanding']) ?><small style="font-size:0.7rem;opacity:.6"> EGP</small></div>
    <div class="stat-label">Outstanding</div>
  </div>
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.08)">
    <div class="stat-value"><?= (int)$stats['cnt_paid'] ?></div>
    <div class="stat-label">Fully Paid</div>
  </div>
  <div class="stat-card" style="--stat-color:#ef4444;--stat-bg:rgba(239,68,68,0.08)">
    <div class="stat-value"><?= (int)$stats['cnt_unpaid'] + (int)$stats['cnt_partial'] ?></div>
    <div class="stat-label">Unpaid / Partial</div>
  </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:16px 20px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <input type="text" name="q" class="form-control" placeholder="Search student or receipt…"
             value="<?= sanitize($search) ?>" style="width:220px;">

      <select name="status" class="form-control" style="width:150px;">
        <option value="">All Statuses</option>
        <?php foreach(['paid'=>'Paid','partial'=>'Partial','unpaid'=>'Unpaid','overdue'=>'Overdue'] as $v=>$l): ?>
        <option value="<?=$v?>" <?= $status===$v?'selected':''?>><?=$l?></option>
        <?php endforeach; ?>
      </select>

      <select name="group" class="form-control" style="width:180px;">
        <option value="">All Groups</option>
        <?php foreach($groupList as $g): ?>
        <option value="<?=$g['id']?>" <?= $group==(int)$g['id']?'selected':''?>><?= sanitize($g['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="month" class="form-control" style="width:150px;">
        <option value="">All Months</option>
        <?php foreach($months as $m): ?>
        <option value="<?=$m?>" <?= $month===$m?'selected':''?>><?= date('M Y',strtotime($m.'-01')) ?></option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <?php if($search||$status||$group||$month): ?>
      <a href="?" class="btn btn-ghost btn-sm">Clear</a>
      <?php endif; ?>

      <div style="margin-left:auto;">
        <a href="export.php?<?= http_build_query(['q'=>$search,'status'=>$status,'group'=>$group,'month'=>$month]) ?>"
           class="btn btn-ghost btn-sm">↓ Export CSV</a>
      </div>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-body no-padding">
    <?php if($payments): ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Student</th>
          <th>Group</th>
          <th>Month</th>
          <th>Amount</th>
          <th>Paid</th>
          <th>Method</th>
          <th>Due Date</th>
          <th>Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($payments as $p): ?>
        <tr>
          <td>
            <div class="cell-primary"><?= sanitize($p['student_name']) ?></div>
            <?php if($p['student_code']): ?>
            <div style="font-size:0.7rem;color:var(--text-muted);">#<?= sanitize($p['student_code']) ?></div>
            <?php endif; ?>
          </td>
          <td style="font-size:0.83rem;"><?= sanitize($p['group_name'] ?: '—') ?></td>
          <td style="font-size:0.83rem;color:var(--text-muted);">
            <?= $p['month_year'] ? date('M Y', strtotime($p['month_year'].'-01')) : '—' ?>
          </td>
          <td style="font-weight:600;"><?= number_format($p['amount'] - ($p['discount']??0)) ?></td>
          <td style="color:var(--success);font-weight:600;"><?= number_format($p['paid_amount']) ?></td>
          <td style="font-size:0.8rem;color:var(--text-muted);"><?= $methodLabel[$p['payment_method']]??'—' ?></td>
          <td style="font-size:0.8rem;color:<?= $p['due_date']&&$p['due_date']<date('Y-m-d')&&$p['status']!='paid'?'var(--danger)':'var(--text-muted)' ?>;">
            <?= $p['due_date'] ? date('M j',strtotime($p['due_date'])) : '—' ?>
          </td>
          <td><span class="badge <?= $badgeMap[$p['status']] ?>"><?= ucfirst($p['status']) ?></span></td>
          <td style="text-align:right;">
            <div style="display:flex;gap:6px;justify-content:flex-end;">
              <a href="view.php?id=<?= $p['id'] ?>" class="btn btn-ghost btn-icon" title="View">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </a>
              <a href="record.php?id=<?= $p['id'] ?>" class="btn btn-ghost btn-icon" title="Edit">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if($result['last_page']>1): ?>
    <div class="pagination">
      <span>Page <?= $result['current_page'] ?> of <?= $result['last_page'] ?></span>
      <div class="page-btns">
        <?php for($i=1;$i<=$result['last_page'];$i++): ?>
        <a href="?page=<?=$i?>&q=<?=urlencode($search)?>&status=<?=$status?>&group=<?=$group?>&month=<?=urlencode($month)?>"
           class="page-btn <?=$i===$result['current_page']?'active':''?>"><?=$i?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">💳</div>
      <div class="empty-title"><?= $search||$status||$group||$month ? 'No payments match your filters' : 'No payments yet' ?></div>
      <div class="empty-desc">
        <a href="record.php" class="btn btn-primary btn-sm" style="margin-top:12px;">Record First Payment</a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
