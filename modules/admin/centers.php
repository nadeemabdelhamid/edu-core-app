<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('super_admin');

$pdo = getDB();

$pageTitle  = 'Platform Admin';
$activePage = 'admin_centers';
$breadcrumbs = [['label' => 'Platform Admin']];

$view = $_GET['view'] ?? 'centers';

// ── POST handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_center') {
        $cid = (int)$_POST['center_id'];
        $pdo->prepare("UPDATE centers SET status='trial' WHERE id=?")->execute([$cid]);
        $pdo->prepare("UPDATE users SET is_active=1 WHERE center_id=? AND role='owner'")->execute([$cid]);
        $pdo->prepare("UPDATE subscriptions SET status='trial' WHERE center_id=?")->execute([$cid]);
        $ow = $pdo->prepare("SELECT id FROM users WHERE center_id=? AND role='owner' LIMIT 1");
        $ow->execute([$cid]);
        if ($owId = $ow->fetchColumn()) {
            pushNotification($owId,'approval','Your account has been approved!',
                'Welcome to EduCore! Your 14-day free trial has started.','/educore/dashboard.php',$cid);
        }
        auditLog('admin.center_approved','center',$cid);
        flash('success','Center approved — trial started.');
        redirect('/educore/modules/admin/centers.php');
    }

    if ($action === 'reject_center') {
        $cid    = (int)$_POST['center_id'];
        $reason = trim($_POST['reason'] ?? 'Your registration did not meet our requirements.');
        $ow = $pdo->prepare("SELECT id FROM users WHERE center_id=? AND role='owner' LIMIT 1");
        $ow->execute([$cid]);
        if ($owId = $ow->fetchColumn()) {
            pushNotification($owId,'rejection','Registration not approved',$reason,'',$cid);
        }
        $pdo->prepare("UPDATE centers SET status='suspended' WHERE id=?")->execute([$cid]);
        auditLog('admin.center_rejected','center',$cid,['reason'=>$reason]);
        flash('success','Center rejected.');
        redirect('/educore/modules/admin/centers.php');
    }

    if ($action === 'set_status') {
        $cid   = (int)$_POST['center_id'];
        $newSt = $_POST['new_status'] ?? '';
        if (in_array($newSt, ['active','trial','suspended'])) {
            $pdo->prepare("UPDATE centers SET status=? WHERE id=?")->execute([$newSt,$cid]);
            $active = ($newSt === 'suspended') ? 0 : 1;
            $pdo->prepare("UPDATE users SET is_active=? WHERE center_id=? AND role='owner'")->execute([$active,$cid]);
            auditLog('admin.center_status','center',$cid,['status'=>$newSt]);
            flash('success','Status updated to '.ucfirst($newSt).'.');
        }
        redirect('/educore/modules/admin/centers.php');
    }

    if ($action === 'assign_plan') {
        $cid    = (int)$_POST['center_id'];
        $planId = (int)$_POST['plan_id'];
        $days   = max(1,(int)($_POST['days'] ?? 30));
        $existing = $pdo->prepare("SELECT id FROM subscriptions WHERE center_id=? ORDER BY id DESC LIMIT 1");
        $existing->execute([$cid]);
        $sub = $existing->fetch();
        if ($sub) {
            $pdo->prepare("UPDATE subscriptions SET plan_id=?,status='active',expires_at=DATE_ADD(NOW(),INTERVAL ? DAY) WHERE id=?")
                ->execute([$planId,$days,$sub['id']]);
        } else {
            $pdo->prepare("INSERT INTO subscriptions (center_id,plan_id,status,starts_at,expires_at) VALUES (?,?,'active',NOW(),DATE_ADD(NOW(),INTERVAL ? DAY))")
                ->execute([$cid,$planId,$days]);
        }
        $pdo->prepare("UPDATE centers SET status='active' WHERE id=?")->execute([$cid]);
        $pdo->prepare("UPDATE users SET is_active=1 WHERE center_id=? AND role='owner'")->execute([$cid]);
        $pdo->prepare("INSERT INTO subscription_history (center_id,to_plan,changed_by,reason) VALUES (?,?,?,'Admin assignment')")
            ->execute([$cid,$planId,authId()]);
        auditLog('admin.plan_assigned','center',$cid,['plan_id'=>$planId,'days'=>$days]);
        flash('success','Plan assigned and center activated.');
        redirect('/educore/modules/admin/centers.php');
    }

    if ($action === 'revoke_subscription') {
        $cid = (int)$_POST['center_id'];
        $pdo->prepare("UPDATE subscriptions SET status='expired' WHERE center_id=?")->execute([$cid]);
        $pdo->prepare("UPDATE centers SET status='suspended' WHERE id=?")->execute([$cid]);
        $pdo->prepare("UPDATE users SET is_active=0 WHERE center_id=? AND role='owner'")->execute([$cid]);
        auditLog('admin.subscription_revoked','center',$cid);
        flash('success','Subscription revoked and center suspended.');
        redirect('/educore/modules/admin/centers.php');
    }

    if ($action === 'extend_subscription') {
        $cid  = (int)$_POST['center_id'];
        $days = max(1,(int)($_POST['days'] ?? 30));
        $pdo->prepare("UPDATE subscriptions SET expires_at=DATE_ADD(GREATEST(expires_at,NOW()),INTERVAL ? DAY),status='active' WHERE center_id=?")
            ->execute([$days,$cid]);
        $pdo->prepare("UPDATE centers SET status='active' WHERE id=?")->execute([$cid]);
        auditLog('admin.subscription_extended','center',$cid,['days'=>$days]);
        flash('success','Subscription extended by '.$days.' days.');
        redirect('/educore/modules/admin/centers.php');
    }

    // ── Plan CRUD ────────────────────────────────────────────
    if (in_array($action, ['create_plan','update_plan'])) {
        $name  = trim($_POST['name'] ?? '');
        $slug  = trim(strtolower(preg_replace('/[^a-z0-9]+/','-',$_POST['slug'] ?? $name)));
        $price = (float)($_POST['price'] ?? 0);
        $cycle = $_POST['billing_cycle'] ?? 'monthly';
        $maxStu = (int)($_POST['max_students'] ?? 50);
        $maxTch = (int)($_POST['max_teachers'] ?? 3);
        $maxGrp = (int)($_POST['max_groups'] ?? 10);
        $features = [
            'attendance' => isset($_POST['f_attendance']),
            'exams'      => isset($_POST['f_exams']),
            'payments'   => isset($_POST['f_payments']),
            'reports'    => isset($_POST['f_reports']),
            'export'     => isset($_POST['f_export']),
            'videos'     => isset($_POST['f_videos']),
        ];
        if ($name) {
            if ($action === 'create_plan') {
                $pdo->prepare("INSERT INTO plans (name,slug,price,billing_cycle,max_students,max_teachers,max_groups,features) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$name,$slug,$price,$cycle,$maxStu,$maxTch,$maxGrp,json_encode($features)]);
                flash('success','Plan created.');
            } else {
                $pid = (int)$_POST['plan_id'];
                $pdo->prepare("UPDATE plans SET name=?,slug=?,price=?,billing_cycle=?,max_students=?,max_teachers=?,max_groups=?,features=? WHERE id=?")
                    ->execute([$name,$slug,$price,$cycle,$maxStu,$maxTch,$maxGrp,json_encode($features),$pid]);
                flash('success','Plan updated.');
            }
        }
        redirect('/educore/modules/admin/centers.php?view=plans');
    }

    if ($action === 'toggle_plan') {
        $pid = (int)$_POST['plan_id'];
        $pdo->prepare("UPDATE plans SET is_active=NOT is_active WHERE id=?")->execute([$pid]);
        redirect('/educore/modules/admin/centers.php?view=plans');
    }
}

// ── Platform stats ───────────────────────────────────────────
$stats = [
    'total'    => $pdo->query("SELECT COUNT(*) FROM centers")->fetchColumn(),
    'active'   => $pdo->query("SELECT COUNT(*) FROM centers WHERE status='active'")->fetchColumn(),
    'trial'    => $pdo->query("SELECT COUNT(*) FROM centers WHERE status='trial'")->fetchColumn(),
    'pending'  => $pdo->query("SELECT COUNT(*) FROM centers WHERE status='pending'")->fetchColumn(),
    'suspended'=> $pdo->query("SELECT COUNT(*) FROM centers WHERE status='suspended'")->fetchColumn(),
    'mrr'      => $pdo->query("SELECT COALESCE(SUM(p.price),0) FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.status='active'")->fetchColumn(),
    'expiring' => $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 7 DAY)")->fetchColumn(),
];

// ── Centers list ─────────────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$filterSt = $_GET['status'] ?? '';
$page     = max(1,(int)($_GET['page'] ?? 1));
$where    = 'WHERE 1=1';
$params   = [];
if ($search)   { $where .= ' AND (c.name LIKE ? OR c.email LIKE ?)'; $like="%$search%"; $params=[$like,$like]; }
if ($filterSt) { $where .= ' AND c.status=?'; $params[]=$filterSt; }

$query = "SELECT c.id, c.name, c.email, c.phone, c.status, c.created_at,
    p.name  AS plan_name, p.id AS plan_id, p.price AS plan_price,
    s.id    AS sub_id, s.status AS sub_status, s.expires_at, s.starts_at,
    u.name  AS owner_name, u.email AS owner_email
  FROM centers c
  LEFT JOIN subscriptions s ON s.center_id=c.id AND s.id=(SELECT MAX(id) FROM subscriptions WHERE center_id=c.id)
  LEFT JOIN plans p ON p.id=s.plan_id
  LEFT JOIN users u ON u.center_id=c.id AND u.role='owner'
  $where
  ORDER BY FIELD(c.status,'pending','trial','active','suspended'), c.created_at DESC";

$result       = paginate($query, $params, $page, 20);
$centers_list = $result['data'];

// ── Plans ────────────────────────────────────────────────────
$allPlans = $pdo->query("SELECT p.*,
    (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id=p.id AND s.status IN('active','trial')) AS sub_count
    FROM plans p ORDER BY price")->fetchAll();
$activePlans = $pdo->query("SELECT id,name,price FROM plans WHERE is_active=1 ORDER BY price")->fetchAll();
$featureList = ['attendance'=>'Attendance','exams'=>'Exams','payments'=>'Payments','reports'=>'Reports','export'=>'Export','videos'=>'Videos'];

// ── Plan edit ────────────────────────────────────────────────
$editPlan = null;
if ($view === 'plans' && isset($_GET['edit'])) {
    $ep = $pdo->prepare("SELECT * FROM plans WHERE id=?");
    $ep->execute([(int)$_GET['edit']]);
    $editPlan = $ep->fetch();
}

$statusBadge = ['active'=>'badge-success','trial'=>'badge-warning','suspended'=>'badge-danger','pending'=>'badge-info'];

include __DIR__ . '/../../templates/layout.php';
?>

<style>
.pa-tabs { display:flex; gap:2px; border-bottom:1px solid var(--border); margin-bottom:24px; }
.pa-tab  { padding:9px 18px; font-size:0.84rem; font-weight:500; color:var(--text-muted); border-bottom:2px solid transparent; text-decoration:none; white-space:nowrap; transition:color .15s; }
.pa-tab:hover  { color:var(--text-primary); }
.pa-tab.active { color:var(--accent); border-bottom-color:var(--accent); }
.pa-tab .cnt   { display:inline-block; font-size:0.68rem; background:var(--bg-elevated); padding:1px 6px; border-radius:8px; margin-left:5px; }
.c-row { display:grid; grid-template-columns:1fr 220px 180px 160px; align-items:center; gap:16px; padding:14px 20px; border-bottom:1px solid var(--border); transition:background .1s; }
.c-row:last-child { border-bottom:none; }
.c-row:hover { background:var(--bg-hover); }
.c-name  { font-size:0.88rem; font-weight:600; color:var(--text-primary); }
.c-owner { font-size:0.72rem; color:var(--text-muted); margin-top:2px; }
.c-sub   { font-size:0.78rem; }
.sub-name { font-weight:600; color:var(--text-primary); }
.sub-expiry { font-size:0.7rem; color:var(--text-muted); margin-top:2px; }
.sub-expiry.warn { color:#f59e0b; }
.sub-expiry.expired { color:var(--danger); }
.act-btns { display:flex; gap:6px; justify-content:flex-end; flex-wrap:wrap; }
.pending-bar { background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.25); border-radius:var(--radius-md); padding:12px 18px; margin-bottom:20px; display:flex; align-items:center; gap:12px; font-size:0.84rem; }
.expiring-bar { background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.2); border-radius:var(--radius-md); padding:12px 18px; margin-bottom:20px; display:flex; align-items:center; gap:12px; font-size:0.84rem; }
</style>

<!-- Page header -->
<div class="page-header" style="margin-bottom:16px;">
  <div>
    <h1 class="page-title">Platform Admin</h1>
    <p class="page-subtitle">Subscription &amp; center management</p>
  </div>
  <?php if ($view === 'plans'): ?>
  <button class="btn btn-primary" onclick="openModal('planModal')">+ New Plan</button>
  <?php endif; ?>
</div>

<!-- Stats strip -->
<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:var(--border);border:1px solid var(--border);border-radius:var(--radius-md);overflow:hidden;margin-bottom:24px;">
  <?php
  $strip = [
    ['v'=>$stats['total'],    'l'=>'Total',      'c'=>'var(--text-primary)', 'href'=>'?'],
    ['v'=>$stats['active'],   'l'=>'Active',     'c'=>'var(--success)',      'href'=>'?status=active'],
    ['v'=>$stats['trial'],    'l'=>'Trial',      'c'=>'var(--accent)',        'href'=>'?status=trial'],
    ['v'=>$stats['pending'],  'l'=>'Pending',    'c'=>$stats['pending']>0?'#f59e0b':'var(--text-muted)', 'href'=>'?status=pending'],
    ['v'=>$stats['suspended'],'l'=>'Suspended',  'c'=>'var(--danger)',        'href'=>'?status=suspended'],
    ['v'=>$stats['expiring'], 'l'=>'Expiring soon','c'=>$stats['expiring']>0?'#ef4444':'var(--text-muted)', 'href'=>'?status=active'],
    ['v'=>'$'.number_format($stats['mrr'],0),'l'=>'Active MRR','c'=>'var(--success)', 'href'=>'?view=plans'],
  ];
  foreach ($strip as $s): ?>
  <a href="<?= $s['href'] ?>" style="background:var(--bg-card);display:block;text-decoration:none;text-align:center;padding:14px 8px;">
    <div style="font-size:1.4rem;font-weight:700;color:<?= $s['c'] ?>;"><?= $s['v'] ?></div>
    <div style="font-size:0.7rem;color:var(--text-muted);margin-top:3px;"><?= $s['l'] ?></div>
  </a>
  <?php endforeach; ?>
</div>

<!-- Tabs -->
<div class="pa-tabs">
  <a href="?" class="pa-tab <?= $view==='centers'?'active':'' ?>">
    Centers <span class="cnt"><?= $stats['total'] ?></span>
    <?php if ($stats['pending']>0): ?><span class="cnt" style="background:rgba(245,158,11,0.15);color:#f59e0b;"><?= $stats['pending'] ?> pending</span><?php endif; ?>
  </a>
  <a href="?view=plans" class="pa-tab <?= $view==='plans'?'active':'' ?>">
    Plans <span class="cnt"><?= count($allPlans) ?></span>
  </a>
</div>

<?php /* ═══════════════════ CENTERS VIEW ═══════════════════ */ ?>
<?php if ($view === 'centers'): ?>

<?php if ($stats['pending'] > 0): ?>
<div class="pending-bar">
  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <strong><?= $stats['pending'] ?> center<?= $stats['pending']!=1?'s':'' ?> waiting for approval.</strong>
  <a href="?status=pending" class="btn btn-sm btn-warning" style="margin-left:auto;">Review →</a>
</div>
<?php endif; ?>

<?php if ($stats['expiring'] > 0): ?>
<div class="expiring-bar">
  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
  <strong><?= $stats['expiring'] ?> subscription<?= $stats['expiring']!=1?'s':'' ?> expiring within 7 days.</strong>
  <a href="?status=active" class="btn btn-sm btn-danger" style="margin-left:auto;">View →</a>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card" style="margin-bottom:14px;">
  <div class="card-body" style="padding:12px 16px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <input type="text" name="q" class="form-control" placeholder="Search name or email…"
             value="<?= sanitize($search) ?>" style="width:240px;" autocomplete="off">
      <select name="status" class="form-control" style="width:150px;">
        <option value="">All statuses</option>
        <?php foreach(['pending','trial','active','suspended'] as $st): ?>
        <option value="<?=$st?>" <?=$filterSt===$st?'selected':''?>><?=ucfirst($st)?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <?php if ($search||$filterSt): ?><a href="?" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
    </form>
  </div>
</div>

<!-- Centers table -->
<div class="card">
  <!-- Table header -->
  <div style="display:grid;grid-template-columns:1fr 220px 180px 160px;gap:16px;padding:9px 20px;border-bottom:1px solid var(--border);background:var(--bg-elevated);">
    <div style="font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">Center</div>
    <div style="font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">Subscription</div>
    <div style="font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">Status</div>
    <div style="font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);text-align:right;">Actions</div>
  </div>

  <div class="card-body no-padding">
    <?php if (!empty($centers_list)): ?>
    <?php foreach ($centers_list as $c):
      // Subscription expiry color
      $expiryClass = '';
      $expiryText  = '';
      if ($c['expires_at']) {
          $diff = (strtotime($c['expires_at']) - time()) / 86400;
          $expiryText = 'Expires '.date('M j, Y', strtotime($c['expires_at']));
          if ($diff < 0)      { $expiryClass = 'expired'; $expiryText = 'Expired '.date('M j, Y', strtotime($c['expires_at'])); }
          elseif ($diff <= 7) { $expiryClass = 'warn';    $expiryText = 'Expires in '.ceil($diff).' day'.($diff<=1?'':'s'); }
      } elseif ($c['sub_status'] === 'trial') {
          $expiryText = 'Trial — no expiry set';
      }
    ?>
    <div class="c-row">

      <!-- Center info -->
      <div>
        <div class="c-name"><?= sanitize($c['name']) ?></div>
        <div class="c-owner"><?= sanitize($c['owner_name'] ?? '—') ?> · <?= sanitize($c['owner_email'] ?? $c['email']) ?></div>
        <div style="font-size:0.68rem;color:var(--text-muted);margin-top:1px;">Registered <?= date('M j, Y', strtotime($c['created_at'])) ?></div>
      </div>

      <!-- Subscription -->
      <div class="c-sub">
        <?php if ($c['plan_name']): ?>
          <div class="sub-name"><?= sanitize($c['plan_name']) ?> <?= $c['plan_price']>0?'<span style="font-weight:400;color:var(--text-muted);">($'.number_format($c['plan_price'],0).'/mo)</span>':'' ?></div>
          <?php if ($expiryText): ?>
          <div class="sub-expiry <?= $expiryClass ?>"><?= $expiryText ?></div>
          <?php endif; ?>
        <?php else: ?>
          <div style="color:var(--text-muted);font-size:0.78rem;">No plan</div>
        <?php endif; ?>
      </div>

      <!-- Status -->
      <div style="display:flex;flex-direction:column;gap:5px;">
        <span class="badge <?= $statusBadge[$c['status']]??'badge-muted' ?>"><?= ucfirst($c['status']) ?></span>
        <?php if ($c['sub_status'] && $c['sub_status'] !== $c['status']): ?>
        <span style="font-size:0.68rem;color:var(--text-muted);">Sub: <?= ucfirst($c['sub_status']) ?></span>
        <?php endif; ?>
      </div>

      <!-- Actions -->
      <div class="act-btns">
        <?php if ($c['status'] === 'pending'): ?>
          <form method="POST" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_center">
            <input type="hidden" name="center_id" value="<?= $c['id'] ?>">
            <button type="submit" class="btn btn-sm btn-success">✓ Approve</button>
          </form>
          <button class="btn btn-sm btn-danger"
            onclick="openRejectModal(<?=$c['id']?>,'<?=addslashes(sanitize($c['name']))?>')">
            ✕ Reject
          </button>
        <?php else: ?>
          <button class="btn btn-ghost btn-sm"
            onclick="openPlanModal(<?=$c['id']?>,'<?=addslashes(sanitize($c['name']))?>')">
            Subscription
          </button>
          <button class="btn btn-ghost btn-sm"
            onclick="openStatusModal(<?=$c['id']?>,'<?=addslashes(sanitize($c['name']))?>','<?=$c['status']?>')">
            Status
          </button>
        <?php endif; ?>
      </div>

    </div>
    <?php endforeach; ?>
    <?php if (($result['last_page']??1) > 1): ?>
    <div class="pagination">
      <span>Page <?=$result['current_page']?> of <?=$result['last_page']?> · <?=$result['total']?> centers</span>
      <div class="page-btns">
        <?php for($i=1;$i<=$result['last_page'];$i++): ?>
        <a href="?page=<?=$i?>&q=<?=urlencode($search)?>&status=<?=$filterSt?>"
           class="page-btn <?=$i===$result['current_page']?'active':''?>"><?=$i?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon">🏫</div><div class="empty-title">No centers found</div></div>
    <?php endif; ?>
  </div>
</div>

<?php /* ═══════════════════ PLANS VIEW ═══════════════════ */ ?>
<?php elseif ($view === 'plans'): ?>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;">
<?php foreach ($allPlans as $p):
  $pf = json_decode($p['features']??'{}',true)??[];
  $pc = ['starter'=>'#64748b','pro'=>'#3b82f6','enterprise'=>'#f59e0b'][$p['slug']] ?? 'var(--accent)';
?>
<div class="card" style="border-top:3px solid <?=$pc?>;">
  <div class="card-body">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
      <div>
        <div style="font-size:1.1rem;font-weight:700;color:<?=$pc?>;"><?=sanitize($p['name'])?></div>
        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px;"><?=$p['billing_cycle']?></div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:1.4rem;font-weight:800;color:var(--text-primary);"><?=$p['price']>0?'$'.number_format($p['price'],0):'Free'?></div>
        <span class="badge <?=$p['is_active']?'badge-success':'badge-muted'?>"><?=$p['is_active']?'Active':'Inactive'?></span>
      </div>
    </div>

    <div style="font-size:0.78rem;color:var(--text-muted);line-height:1.9;margin-bottom:12px;">
      <div>Max students: <strong style="color:var(--text-primary);"><?=$p['max_students']>=999999?'Unlimited':number_format($p['max_students'])?></strong></div>
      <div>Max teachers: <strong style="color:var(--text-primary);"><?=$p['max_teachers']>=999999?'Unlimited':number_format($p['max_teachers'])?></strong></div>
      <div>Max groups: <strong style="color:var(--text-primary);"><?=$p['max_groups']>=999999?'Unlimited':number_format($p['max_groups'])?></strong></div>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:14px;">
      <?php foreach($featureList as $fk=>$fl): ?>
      <span style="font-size:0.66rem;padding:2px 8px;border-radius:8px;
        background:<?=($pf[$fk]??false)?'var(--success-dim)':'var(--bg-elevated)'?>;
        color:<?=($pf[$fk]??false)?'var(--success)':'var(--text-muted)'?>;">
        <?=($pf[$fk]??false)?'✓':'✗'?> <?=$fl?>
      </span>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border);padding-top:12px;">
      <span style="font-size:0.74rem;color:var(--text-muted);"><?=$p['sub_count']?> active center<?=$p['sub_count']!=1?'s':''?></span>
      <div style="display:flex;gap:6px;">
        <a href="?view=plans&edit=<?=$p['id']?>" class="btn btn-ghost btn-sm">Edit</a>
        <form method="POST" style="display:inline;">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="toggle_plan">
          <input type="hidden" name="plan_id" value="<?=$p['id']?>">
          <button type="submit" class="btn btn-sm <?=$p['is_active']?'btn-danger':'btn-success'?>"><?=$p['is_active']?'Disable':'Enable'?></button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php if (empty($allPlans)): ?>
<div class="card"><div class="card-body" style="text-align:center;color:var(--text-muted);padding:40px;">No plans yet. Create your first plan.</div></div>
<?php endif; ?>
</div>

<?php endif; /* end view switch */ ?>

<!-- ── MODALS ──────────────────────────────────────────────── -->

<!-- Subscription management modal -->
<div class="modal-backdrop" id="planModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Manage Subscription — <span id="pmCenterName"></span></span>
      <button class="modal-close" onclick="closeModal('planModal')">✕</button>
    </div>
    <div class="modal-body">
      <div style="display:flex;gap:10px;margin-bottom:20px;">
        <button type="button" class="btn btn-ghost btn-sm" id="pmTabAssign" onclick="pmShowTab('assign')" style="font-weight:600;">Assign plan</button>
        <button type="button" class="btn btn-ghost btn-sm" id="pmTabExtend" onclick="pmShowTab('extend')">Extend</button>
        <button type="button" class="btn btn-ghost btn-sm" id="pmTabRevoke" onclick="pmShowTab('revoke')">Revoke</button>
      </div>

      <!-- Assign plan form -->
      <form method="POST" id="pmFormAssign">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_plan">
        <input type="hidden" name="center_id" id="pmCenterIdAssign">
        <div class="form-group">
          <label class="form-label required">Plan</label>
          <select name="plan_id" class="form-control" id="pmPlanSelect">
            <?php foreach($activePlans as $p): ?>
            <option value="<?=$p['id']?>"><?=sanitize($p['name'])?><?=$p['price']>0?' — $'.number_format($p['price'],0).'/mo':' — Free'?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label required">Duration (days)</label>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
            <?php foreach([30=>'1 month',90=>'3 months',180=>'6 months',365=>'1 year'] as $d=>$l): ?>
            <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('pmDaysAssign').value=<?=$d?>"><?=$l?></button>
            <?php endforeach; ?>
          </div>
          <input type="number" name="days" id="pmDaysAssign" class="form-control" value="30" min="1" max="3650">
          <span class="form-hint">Center status will be set to Active and owner account unlocked.</span>
        </div>
        <div class="modal-footer" style="padding:20px 0 0;margin:0;">
          <button type="button" class="btn btn-ghost" onclick="closeModal('planModal')">Cancel</button>
          <button type="submit" class="btn btn-primary">Assign &amp; Activate</button>
        </div>
      </form>

      <!-- Extend form -->
      <form method="POST" id="pmFormExtend" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="extend_subscription">
        <input type="hidden" name="center_id" id="pmCenterIdExtend">
        <p style="font-size:0.84rem;color:var(--text-secondary);margin-bottom:16px;">
          Adds days on top of the current expiry date (or from today if already expired).
        </p>
        <div class="form-group">
          <label class="form-label required">Extend by (days)</label>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
            <?php foreach([30=>'1 month',90=>'3 months',180=>'6 months',365=>'1 year'] as $d=>$l): ?>
            <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('pmDaysExtend').value=<?=$d?>"><?=$l?></button>
            <?php endforeach; ?>
          </div>
          <input type="number" name="days" id="pmDaysExtend" class="form-control" value="30" min="1" max="3650">
        </div>
        <div class="modal-footer" style="padding:20px 0 0;margin:0;">
          <button type="button" class="btn btn-ghost" onclick="closeModal('planModal')">Cancel</button>
          <button type="submit" class="btn btn-primary">Extend Subscription</button>
        </div>
      </form>

      <!-- Revoke form -->
      <form method="POST" id="pmFormRevoke" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="revoke_subscription">
        <input type="hidden" name="center_id" id="pmCenterIdRevoke">
        <div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.2);border-radius:var(--radius-sm);padding:14px;font-size:0.84rem;color:var(--danger);margin-bottom:16px;">
          <strong>This will immediately:</strong><br>
          • Set subscription status to Expired<br>
          • Suspend the center (owner loses access)<br>
          • Can be re-activated by assigning a new plan
        </div>
        <div class="modal-footer" style="padding:0;margin:0;">
          <button type="button" class="btn btn-ghost" onclick="closeModal('planModal')">Cancel</button>
          <button type="submit" class="btn btn-danger">Revoke &amp; Suspend</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Status modal -->
<div class="modal-backdrop" id="statusModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Change Status — <span id="smCenterName"></span></span>
      <button class="modal-close" onclick="closeModal('statusModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="set_status">
        <input type="hidden" name="center_id" id="smCenterId">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">New status</label>
          <div style="display:flex;flex-direction:column;gap:8px;">
            <?php foreach(['active'=>['badge-success','Full access — subscription required'],
                           'trial' =>['badge-warning','Trial access — 14-day limit'],
                           'suspended'=>['badge-danger','No access — owner locked out']] as $st=>[$cls,$desc]): ?>
            <label style="display:flex;align-items:center;gap:12px;padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;">
              <input type="radio" name="new_status" value="<?=$st?>" style="accent-color:var(--accent);">
              <div>
                <span class="badge <?=$cls?>"><?=ucfirst($st)?></span>
                <div style="font-size:0.75rem;color:var(--text-muted);margin-top:3px;"><?=$desc?></div>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('statusModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Status</button>
      </div>
    </form>
  </div>
</div>

<!-- Reject modal -->
<div class="modal-backdrop" id="rejectModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Reject Registration</span>
      <button class="modal-close" onclick="closeModal('rejectModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reject_center">
        <input type="hidden" name="center_id" id="rejCenterId">
        <p style="font-size:.84rem;color:var(--text-muted);margin-bottom:14px;">
          Rejecting: <strong id="rejCenterName" style="color:var(--text-primary);"></strong><br>
          The owner will receive a notification with your reason.
        </p>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Reason (sent to applicant)</label>
          <textarea name="reason" class="form-control" rows="3"
                    placeholder="Your registration did not meet our requirements."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('rejectModal')">Cancel</button>
        <button type="submit" class="btn btn-danger">Reject Registration</button>
      </div>
    </form>
  </div>
</div>

<!-- Plan create/edit modal -->
<div class="modal-backdrop" id="planModal" <?=$editPlan?'style="opacity:1;visibility:visible;"':''?>>
  <div class="modal" <?=$editPlan?'style="transform:none;"':''?>>
    <div class="modal-header">
      <span class="modal-title"><?=$editPlan?'Edit Plan':'New Plan'?></span>
      <button class="modal-close" onclick="closeModal('planModal');<?=$editPlan?"window.location='?view=plans'":''?>">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="<?=$editPlan?'update_plan':'create_plan'?>">
        <?php if($editPlan):?><input type="hidden" name="plan_id" value="<?=$editPlan['id']?>"><?php endif;?>
        <?php $ef = $editPlan ? (json_decode($editPlan['features']??'{}',true)??[]) : []; ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group"><label class="form-label required">Name</label><input type="text" name="name" class="form-control" value="<?=sanitize($editPlan['name']??'')?>" required></div>
          <div class="form-group"><label class="form-label">Slug</label><input type="text" name="slug" class="form-control" id="planSlug" value="<?=sanitize($editPlan['slug']??'')?>" placeholder="pro"></div>
          <div class="form-group"><label class="form-label">Price (USD/mo)</label><input type="number" name="price" class="form-control" step="0.01" min="0" value="<?=$editPlan['price']??0?>"></div>
          <div class="form-group"><label class="form-label">Billing cycle</label>
            <select name="billing_cycle" class="form-control">
              <?php foreach(['monthly','yearly','lifetime'] as $cy):?>
              <option value="<?=$cy?>" <?=($editPlan['billing_cycle']??'monthly')===$cy?'selected':''?>><?=ucfirst($cy)?></option>
              <?php endforeach;?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Max students</label><input type="number" name="max_students" class="form-control" value="<?=$editPlan['max_students']??50?>"></div>
          <div class="form-group"><label class="form-label">Max teachers</label><input type="number" name="max_teachers" class="form-control" value="<?=$editPlan['max_teachers']??3?>"></div>
          <div class="form-group" style="grid-column:span 2;"><label class="form-label">Max groups</label><input type="number" name="max_groups" class="form-control" value="<?=$editPlan['max_groups']??10?>"></div>
        </div>
        <div style="margin-top:4px;">
          <div class="form-label" style="margin-bottom:8px;">Features included</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
            <?php foreach($featureList as $fk=>$fl):?>
            <label style="display:flex;align-items:center;gap:8px;font-size:0.82rem;cursor:pointer;padding:8px 10px;border-radius:var(--radius-sm);background:var(--bg-elevated);">
              <input type="checkbox" name="f_<?=$fk?>" <?=($ef[$fk]??false)?'checked':''?> style="accent-color:var(--accent);"><?=$fl?>
            </label>
            <?php endforeach;?>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('planModal');<?=$editPlan?"window.location='?view=plans'":''?>">Cancel</button>
        <button type="submit" class="btn btn-primary"><?=$editPlan?'Save Changes':'Create Plan'?></button>
      </div>
    </form>
  </div>
</div>

<script>
function openPlanModal(id, name) {
    document.getElementById('pmCenterName').textContent = name;
    document.getElementById('pmCenterIdAssign').value  = id;
    document.getElementById('pmCenterIdExtend').value  = id;
    document.getElementById('pmCenterIdRevoke').value  = id;
    pmShowTab('assign');
    openModal('planModal');
}
function openStatusModal(id, name, current) {
    document.getElementById('smCenterId').value = id;
    document.getElementById('smCenterName').textContent = name;
    const radios = document.querySelectorAll('#statusModal input[type=radio]');
    radios.forEach(r => { r.checked = (r.value === current); });
    openModal('statusModal');
}
function openRejectModal(id, name) {
    document.getElementById('rejCenterId').value = id;
    document.getElementById('rejCenterName').textContent = name;
    openModal('rejectModal');
}
function pmShowTab(tab) {
    ['assign','extend','revoke'].forEach(t => {
        document.getElementById('pmForm'+t.charAt(0).toUpperCase()+t.slice(1)).style.display = t===tab?'':'none';
        document.getElementById('pmTab'+t.charAt(0).toUpperCase()+t.slice(1)).style.fontWeight = t===tab?'700':'400';
    });
}
<?php if (!$editPlan && $view==='plans'): ?>
document.querySelector('input[name="name"]')?.addEventListener('input', function() {
    const s = document.getElementById('planSlug');
    if (!s.dataset.manual) s.value = this.value.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
});
document.getElementById('planSlug')?.addEventListener('input', function() { this.dataset.manual=true; });
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
