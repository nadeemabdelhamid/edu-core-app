<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireAuth();

$pdo = getDB();
$uid = authId();
$cid = centerId();

$pageTitle  = 'Notifications';
$activePage = 'settings';
$breadcrumbs = [
    ['label'=>'Dashboard', 'href'=>'/educore/dashboard.php'],
    ['label'=>'Settings',  'href'=>'/educore/modules/settings/'],
    ['label'=>'Notifications'],
];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    if (($_POST['action']??'')==='mark_all') {
        markNotificationsRead($uid);
        flash('success','All notifications marked as read.');
    }
    redirect('/educore/modules/settings/notifications.php');
}

// All notifications (not just unread)
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$uid]);
$allNotifs = $stmt->fetchAll();

$typeIcons = ['payment'=>'💳','attendance'=>'📋','exam'=>'📝','system'=>'⚙️','subscription'=>'💎','security'=>'🔐','info'=>'ℹ️','announcement'=>'📢','warning'=>'⚠️','success'=>'✅','billing'=>'💳','maintenance'=>'🔧','approval'=>'✅','rejection'=>'❌','password_reset'=>'🔐'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">Notifications</h1><p class="page-subtitle">Your recent activity and alerts</p></div>
  <?php if(array_filter($allNotifs,fn($n)=>!$n['is_read'])): ?>
  <form method="POST"><?=csrf_field()?><input type="hidden" name="action" value="mark_all">
    <button type="submit" class="btn btn-ghost btn-sm">Mark all read</button>
  </form>
  <?php endif; ?>
</div>

<!-- Sub-nav (owner only shows full nav) -->
<?php if(isRole('owner')): ?>
<div style="display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);">
  <?php foreach([['./','🏫','Profile'],['subscription.php','💳','Subscription'],['staff.php','👥','Staff'],['notifications.php','🔔','Notifications'],['security.php','🔐','Security']] as [$t,$i,$l]): ?>
  <a href="<?=$t?>" style="display:flex;align-items:center;gap:6px;padding:10px 16px;font-size:0.84rem;font-weight:600;text-decoration:none;border-bottom:2px solid <?=str_contains($t,'notif')?'var(--accent)':'transparent'?>;color:<?=str_contains($t,'notif')?'var(--accent)':'var(--text-muted)'?>;margin-bottom:-1px;"><?=$i?> <?=$l?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-body no-padding">
    <?php if($allNotifs): foreach($allNotifs as $n):
      $ico = $typeIcons[$n['type']] ?? '🔔';
    ?>
    <div style="display:flex;gap:14px;padding:14px 20px;border-bottom:1px solid var(--border);background:<?=$n['is_read']?'transparent':'rgba(245,166,35,0.03)'?>;">
      <div style="width:36px;height:36px;border-radius:50%;background:var(--bg-elevated);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1rem;"><?=$ico?></div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:0.86rem;font-weight:<?=$n['is_read']?'400':'600'?>;color:var(--text-primary);"><?=sanitize($n['title'])?></div>
        <?php if($n['body']): ?><div style="font-size:0.78rem;color:var(--text-secondary);margin-top:2px;"><?=sanitize($n['body'])?></div><?php endif; ?>
        <div style="font-size:0.7rem;color:var(--text-muted);margin-top:4px;"><?=date('M j, Y g:i a',strtotime($n['created_at']))?></div>
      </div>
      <?php if(!$n['is_read']): ?>
      <div style="width:8px;height:8px;background:var(--accent);border-radius:50%;margin-top:6px;flex-shrink:0;"></div>
      <?php endif; ?>
    </div>
    <?php endforeach; else: ?>
    <div class="empty-state" style="padding:60px;">
      <div class="empty-icon">🔔</div>
      <div class="empty-title">No notifications yet</div>
      <div class="empty-desc">Activity and alerts will appear here</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
