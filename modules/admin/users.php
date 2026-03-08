<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('super_admin');

$pdo = getDB();

$pageTitle  = 'All Users';
$activePage = 'admin_users';
$breadcrumbs = [['label'=>'Dashboard','href'=>'/educore/dashboard.php'],['label'=>'All Users']];

$search = trim($_GET['q'] ?? '');
$role   = $_GET['role'] ?? '';
$page   = max(1,(int)($_GET['page']??1));

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle') {
        $uid = (int)$_POST['user_id'];
        $pdo->prepare("UPDATE users SET is_active=NOT is_active WHERE id=?")->execute([$uid]);
        flash('success','User status toggled.');
        redirect('/educore/modules/admin/users.php');
    }
}

$where  = 'WHERE u.role != "student"';
$params = [];
if ($search) { $where .= ' AND (u.name LIKE ? OR u.email LIKE ?)'; $like="%$search%"; $params=[$like,$like]; }
if ($role)   { $where .= ' AND u.role=?'; $params[]=$role; }

$query = "SELECT u.*, c.name AS center_name
          FROM users u LEFT JOIN centers c ON c.id=u.center_id
          $where ORDER BY u.created_at DESC";

$result = paginate($query, $params, $page, 25);
$users  = $result['data'];

$totalUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE role!='student'")->fetchColumn();
$roleColors = ['owner'=>'#f5a623','teacher'=>'#3b82f6','assistant'=>'#8b5cf6','accountant'=>'#22c55e','super_admin'=>'#ef4444'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">All Users</h1><p class="page-subtitle"><?=number_format($totalUsers)?> staff accounts across all centers</p></div>
</div>

<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:14px 18px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <input type="text" name="q" class="form-control" placeholder="Search by name or email…" value="<?=sanitize($search)?>" style="width:240px;">
      <select name="role" class="form-control" style="width:150px;">
        <option value="">All Roles</option>
        <?php foreach(['owner','teacher','assistant','accountant','super_admin'] as $r): ?>
        <option value="<?=$r?>" <?=$role===$r?'selected':''?>><?=ucfirst(str_replace('_',' ',$r))?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <?php if($search||$role): ?><a href="?" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body no-padding">
    <?php if($users): ?>
    <table class="data-table">
      <thead><tr><th>User</th><th>Role</th><th>Center</th><th>Status</th><th>Last Login</th><th>Joined</th><th style="text-align:right;">Action</th></tr></thead>
      <tbody>
        <?php foreach($users as $u):
          $rc = $roleColors[$u['role']] ?? '#888';
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,<?=$rc?>,<?=$rc?>88);color:#fff;font-size:0.72rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?=strtoupper(substr($u['name'],0,2))?></div>
              <div>
                <div class="cell-primary"><?=sanitize($u['name'])?></div>
                <div style="font-size:0.72rem;color:var(--text-muted);"><?=sanitize($u['email'])?></div>
              </div>
            </div>
          </td>
          <td><span class="badge" style="background:<?=$rc?>22;color:<?=$rc?>;font-size:0.68rem;"><?=ucfirst(str_replace('_',' ',$u['role']))?></span></td>
          <td style="font-size:0.82rem;"><?=sanitize($u['center_name']??'—')?></td>
          <td><?=$u['is_active']?'<span class="badge badge-success">Active</span>':'<span class="badge badge-danger">Inactive</span>'?></td>
          <td style="font-size:0.76rem;color:var(--text-muted);"><?=$u['last_login']?date('M j, g:i a',strtotime($u['last_login'])):'Never'?></td>
          <td style="font-size:0.76rem;color:var(--text-muted);"><?=date('M j, Y',strtotime($u['created_at']))?></td>
          <td style="text-align:right;">
            <?php if($u['role']!=='super_admin'): ?>
            <form method="POST" style="display:inline;">
              <?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?=$u['id']?>">
              <button type="submit" class="btn btn-sm <?=$u['is_active']?'btn-danger':'btn-success'?>" style="font-size:0.72rem;"><?=$u['is_active']?'Suspend':'Restore'?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if($result['last_page']>1): ?>
    <div class="pagination">
      <span>Page <?=$result['current_page']?> of <?=$result['last_page']?> · <?=$result['total']?> users</span>
      <div class="page-btns">
        <?php for($i=max(1,$result['current_page']-2);$i<=min($result['last_page'],$result['current_page']+2);$i++): ?>
        <a href="?page=<?=$i?>&q=<?=urlencode($search)?>&role=<?=$role?>" class="page-btn <?=$i===$result['current_page']?'active':''?>"><?=$i?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon">👤</div><div class="empty-title">No users found</div></div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
