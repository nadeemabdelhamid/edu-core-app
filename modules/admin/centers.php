<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('super_admin');

$pdo = getDB();

$pageTitle  = 'Centers';
$activePage = 'admin_centers';
$breadcrumbs = [['label'=>'Dashboard','href'=>'/educore/dashboard.php'],['label'=>'Centers']];

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$page   = max(1,(int)($_GET['page']??1));

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $action = $_POST['action']??'';

    if ($action==='toggle_status') {
        $cid    = (int)$_POST['center_id'];
        $newSt  = $_POST['new_status'];
        if (in_array($newSt,['active','suspended','trial'])) {
            $pdo->prepare("UPDATE centers SET status=? WHERE id=?")->execute([$newSt,$cid]);
            auditLog('admin.center_status','center',$cid,['status'=>$newSt]);
            flash('success','Center status updated.');
        }
        redirect('/educore/modules/admin/centers.php');
    }

    if ($action==='assign_plan') {
        $cid    = (int)$_POST['center_id'];
        $planId = (int)$_POST['plan_id'];
        $days   = max(1,(int)($_POST['days']??30));
        // Update or insert subscription
        $existing = $pdo->prepare("SELECT id FROM subscriptions WHERE center_id=? ORDER BY id DESC LIMIT 1");
        $existing->execute([$cid]);
        $sub = $existing->fetch();
        if ($sub) {
            $pdo->prepare("UPDATE subscriptions SET plan_id=?,status='active',expires_at=DATE_ADD(NOW(),INTERVAL ? DAY) WHERE id=?")->execute([$planId,$days,$sub['id']]);
        } else {
            $pdo->prepare("INSERT INTO subscriptions (center_id,plan_id,status,starts_at,expires_at) VALUES (?,?,'active',NOW(),DATE_ADD(NOW(),INTERVAL ? DAY))")->execute([$cid,$planId,$days]);
        }
        // Log history
        $pdo->prepare("INSERT INTO subscription_history (center_id,to_plan,changed_by,reason) VALUES (?,?,?,'Admin assignment')")->execute([$cid,$planId,authId()]);
        auditLog('admin.plan_assigned','center',$cid,['plan_id'=>$planId,'days'=>$days]);
        flash('success','Plan assigned successfully.');
        redirect('/educore/modules/admin/centers.php');
    }
}

$where  = 'WHERE 1=1';
$params = [];
if ($search) { $where .= ' AND (c.name LIKE ? OR c.email LIKE ? OR c.slug LIKE ?)'; $like="%$search%"; $params=[$like,$like,$like]; }
if ($status) { $where .= ' AND c.status=?'; $params[]=$status; }

$query = "SELECT c.*,
    p.name AS plan_name, p.slug AS plan_slug,
    s.status AS sub_status, s.expires_at,
    (SELECT COUNT(*) FROM students st WHERE st.center_id=c.id AND st.is_active=1) AS student_count,
    (SELECT COUNT(*) FROM users u WHERE u.center_id=c.id AND u.role!='student') AS staff_count
  FROM centers c
  LEFT JOIN subscriptions s ON s.center_id=c.id AND s.id=(SELECT MAX(id) FROM subscriptions WHERE center_id=c.id)
  LEFT JOIN plans p ON p.id=s.plan_id
  $where ORDER BY c.created_at DESC";

$result  = paginate($query, $params, $page, 20);
$centers = $result['data'];

// Platform-wide stats
$totalCenters  = $pdo->query("SELECT COUNT(*) FROM centers")->fetchColumn();
$activeCenters = $pdo->query("SELECT COUNT(*) FROM centers WHERE status='active'")->fetchColumn();
$trialCenters  = $pdo->query("SELECT COUNT(*) FROM centers WHERE status='trial'")->fetchColumn();
$totalStudents = $pdo->query("SELECT COUNT(*) FROM students WHERE is_active=1")->fetchColumn();

$plans = $pdo->query("SELECT id,name FROM plans WHERE is_active=1 ORDER BY price")->fetchAll();

$statusColors=['active'=>'badge-success','trial'=>'badge-warning','suspended'=>'badge-danger'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">Centers</h1><p class="page-subtitle">Platform-wide center management</p></div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
  <div class="stat-card" style="--stat-color:#3b82f6;--stat-bg:rgba(59,130,246,0.1)"><div class="stat-value"><?=$totalCenters?></div><div class="stat-label">Total Centers</div></div>
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)"><div class="stat-value"><?=$activeCenters?></div><div class="stat-label">Active</div></div>
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)"><div class="stat-value"><?=$trialCenters?></div><div class="stat-label">Trial</div></div>
  <div class="stat-card" style="--stat-color:#8b5cf6;--stat-bg:rgba(139,92,246,0.1)"><div class="stat-value"><?=number_format($totalStudents)?></div><div class="stat-label">Total Students</div></div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:14px 18px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <input type="text" name="q" class="form-control" placeholder="Search centers…" value="<?=sanitize($search)?>" style="width:220px;">
      <select name="status" class="form-control" style="width:140px;">
        <option value="">All Statuses</option>
        <?php foreach(['active','trial','suspended'] as $s): ?>
        <option value="<?=$s?>" <?=$status===$s?'selected':''?>><?=ucfirst($s)?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <?php if($search||$status): ?><a href="?" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body no-padding">
    <?php if($centers): ?>
    <table class="data-table">
      <thead><tr><th>Center</th><th>Plan</th><th>Students</th><th>Staff</th><th>Status</th><th>Created</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php foreach($centers as $c): ?>
        <tr>
          <td>
            <div class="cell-primary"><?=sanitize($c['name'])?></div>
            <div style="font-size:0.7rem;color:var(--text-muted);"><?=sanitize($c['email'])?></div>
          </td>
          <td>
            <?php if($c['plan_name']): ?>
            <span class="badge badge-accent" style="font-size:0.68rem;"><?=sanitize($c['plan_name'])?></span>
            <?php if($c['expires_at']&&strtotime($c['expires_at'])<time()): ?>
            <div style="font-size:0.68rem;color:var(--danger);margin-top:2px;">Expired</div>
            <?php endif; ?>
            <?php else: ?><span style="color:var(--text-muted);font-size:0.78rem;">—</span><?php endif; ?>
          </td>
          <td><?=$c['student_count']?></td>
          <td><?=$c['staff_count']?></td>
          <td><span class="badge <?=$statusColors[$c['status']]??'badge-muted'?>"><?=ucfirst($c['status'])?></span></td>
          <td style="font-size:0.76rem;color:var(--text-muted);"><?=date('M j, Y',strtotime($c['created_at']))?></td>
          <td style="text-align:right;">
            <div style="display:flex;gap:6px;justify-content:flex-end;">
              <!-- Status toggle -->
              <div class="dropdown" style="position:relative;">
                <button class="btn btn-ghost btn-sm" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='block'?'none':'block'">
                  Status ↓
                </button>
                <div style="display:none;position:absolute;right:0;top:32px;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-sm);min-width:130px;z-index:100;box-shadow:var(--shadow-md);">
                  <?php foreach(['active','trial','suspended'] as $st): ?>
                  <?php if($st!==$c['status']): ?>
                  <form method="POST" style="margin:0;">
                    <?=csrf_field()?><input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="center_id" value="<?=$c['id']?>">
                    <input type="hidden" name="new_status" value="<?=$st?>">
                    <button type="submit" style="width:100%;padding:8px 14px;text-align:left;background:none;border:none;cursor:pointer;font-size:0.8rem;color:var(--text-secondary);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
                      → <?=ucfirst($st)?>
                    </button>
                  </form>
                  <?php endif; ?>
                  <?php endforeach; ?>
                </div>
              </div>
              <!-- Assign plan -->
              <button class="btn btn-ghost btn-sm" onclick="openPlanModal(<?=$c['id']?>, '<?=sanitize($c['name'])?>')">Plan</button>
            </div>
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
        <a href="?page=<?=$i?>&q=<?=urlencode($search)?>&status=<?=$status?>" class="page-btn <?=$i===$result['current_page']?'active':''?>"><?=$i?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon">🏫</div><div class="empty-title">No centers found</div></div>
    <?php endif; ?>
  </div>
</div>

<!-- Assign plan modal -->
<div class="modal-backdrop" id="planModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Assign Plan</span>
      <button class="modal-close" onclick="closeModal('planModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <?=csrf_field()?><input type="hidden" name="action" value="assign_plan">
        <input type="hidden" name="center_id" id="modalCenterId">
        <p style="font-size:0.84rem;color:var(--text-muted);margin-bottom:16px;">Assigning plan to: <strong id="modalCenterName" style="color:var(--text-primary);"></strong></p>
        <div class="form-group">
          <label class="form-label">Plan</label>
          <select name="plan_id" class="form-control">
            <?php foreach($plans as $p): ?><option value="<?=$p['id']?>"><?=sanitize($p['name'])?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Active for (days)</label>
          <input type="number" name="days" class="form-control" value="30" min="1" max="3650">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('planModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Assign Plan</button>
      </div>
    </form>
  </div>
</div>

<script>
function openPlanModal(id, name) {
    document.getElementById('modalCenterId').value = id;
    document.getElementById('modalCenterName').textContent = name;
    openModal('planModal');
}
// Close dropdowns on outside click
document.addEventListener('click', function(e) {
    document.querySelectorAll('.dropdown > div').forEach(d => {
        if (!d.parentElement.contains(e.target)) d.style.display='none';
    });
});
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
