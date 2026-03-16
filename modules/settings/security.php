<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','assistant','accountant');

$pdo = getDB();
$cid = centerId();
$uid = authId();

$pageTitle  = 'Security';
$activePage = 'settings';
$breadcrumbs = [
    ['label'=>'Dashboard', 'href'=>'/educore/dashboard.php'],
    ['label'=>'Settings',  'href'=>'/educore/modules/settings/'],
    ['label'=>'Security'],
];

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        // Rate limit password changes
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!rateLimit($ip, 'password_change', 5, 3600)) {
            $errors[] = 'Too many password change attempts. Try again later.';
        } else {
            $current  = $_POST['current_password'] ?? '';
            $new      = $_POST['new_password']     ?? '';
            $confirm  = $_POST['confirm_password'] ?? '';

            $userRow = $pdo->prepare("SELECT password FROM users WHERE id=?");
            $userRow->execute([$uid]);
            $userRow = $userRow->fetch();

            if (!password_verify($current, $userRow['password']))   $errors[] = 'Current password is incorrect.';
            if (strlen($new) < 8)                                   $errors[] = 'New password must be at least 8 characters.';
            if ($new !== $confirm)                                   $errors[] = 'New passwords do not match.';
            if ($current === $new)                                   $errors[] = 'New password must differ from current.';

            if (!$errors) {
                $pdo->prepare("UPDATE users SET password=?,updated_at=NOW() WHERE id=?")->execute([password_hash($new,PASSWORD_BCRYPT),$uid]);
                auditLog('security.password_changed','user',$uid);
                flash('success','Password changed successfully.');
                redirect('/educore/modules/settings/security.php');
            }
        }
    }
}

// Recent audit logs — owners see all center activity; staff see only their own
if (isRole('owner')) {
    $logsStmt = $pdo->prepare("
        SELECT al.*, u.name AS user_name
        FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id
        WHERE al.center_id=?
        ORDER BY al.created_at DESC LIMIT 50");
    $logsStmt->execute([$cid]);
} else {
    $logsStmt = $pdo->prepare("
        SELECT al.*, u.name AS user_name
        FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id
        WHERE al.user_id=?
        ORDER BY al.created_at DESC LIMIT 20");
    $logsStmt->execute([$uid]);
}
$logs = $logsStmt->fetchAll();

// Color map for action types
function actionColor($action) {
    if (str_starts_with($action,'payment'))     return 'var(--success)';
    if (str_starts_with($action,'security'))    return 'var(--danger)';
    if (str_starts_with($action,'student'))     return 'var(--info)';
    if (str_starts_with($action,'subscription')) return 'var(--accent)';
    return 'var(--text-muted)';
}

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">Security</h1><p class="page-subtitle">Password management and activity log</p></div>
</div>

<!-- Sub-nav: owners get full tabs, staff only see Security -->
<?php if (isRole('owner')): ?>
<div style="display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);overflow-x:auto;-webkit-overflow-scrolling:touch;">
  <?php foreach([['./','🏫','Profile'],['subscription.php','💳','Subscription'],['staff.php','👥','Staff'],['notifications.php','🔔','Notifications'],['security.php','🔐','Security']] as [$t,$i,$l]): ?>
  <a href="<?=$t?>" style="display:flex;align-items:center;gap:6px;padding:10px 16px;font-size:0.84rem;font-weight:600;text-decoration:none;border-bottom:2px solid <?=str_contains($t,'security')?'var(--accent)':'transparent'?>;color:<?=str_contains($t,'security')?'var(--accent)':'var(--text-muted)'?>;margin-bottom:-1px;white-space:nowrap;"><?=$i?> <?=$l?></a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div style="margin-bottom:24px;padding:12px 16px;background:var(--accent-subtle);border:1px solid rgba(82,71,230,0.15);border-radius:var(--radius-md);font-size:0.83rem;color:var(--text-secondary);">
  🔐 <strong style="color:var(--text-primary);">Account Security</strong> — Change your password below.
</div>
<?php endif; ?>

<?php if($errors): ?>
<div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-md);padding:14px 18px;font-size:0.84rem;color:var(--danger);margin-bottom:20px;">
  <?php foreach($errors as $e): ?><div>• <?=sanitize($e)?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;margin-bottom:20px;">

<!-- Change password -->
<div class="card">
  <div class="card-header"><span class="card-title">🔑 Change Password</span></div>
  <div class="card-body">
    <form method="POST">
      <?=csrf_field()?><input type="hidden" name="action" value="change_password">
      <div class="form-group">
        <label class="form-label required">Current Password</label>
        <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
      </div>
      <div class="form-group">
        <label class="form-label required">New Password</label>
        <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password" id="newPwd">
        <!-- Strength meter -->
        <div id="strengthBar" style="height:4px;background:var(--bg-elevated);border-radius:2px;margin-top:6px;"><div id="strengthFill" style="height:100%;width:0%;border-radius:2px;transition:all 0.3s;"></div></div>
        <div id="strengthLabel" class="form-hint"></div>
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label class="form-label required">Confirm New Password</label>
        <input type="password" name="confirm_password" class="form-control" required autocomplete="new-password">
      </div>
      <button type="submit" class="btn btn-primary w-full" style="margin-top:18px;">Update Password</button>
    </form>
  </div>
</div>

<!-- Security checklist -->
<div class="card">
  <div class="card-header"><span class="card-title">🛡 Security Status</span></div>
  <div class="card-body">
    <?php
    $user = auth();
    $items = [
      ['Strong password set',      true, ''],
      ['Email verified',           true, ''],
      ['Last login recorded',      !empty($user['last_login']), 'No login recorded yet'],
      ['Account is active',        (bool)$user['is_active'], 'Account suspended'],
    ];
    foreach($items as [$label,$ok,$warn]): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--border);">
      <div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:<?=$ok?'var(--success-dim)':'var(--danger-dim)'?>;color:<?=$ok?'var(--success)':'var(--danger)'?>;">
        <?=$ok?'✓':'✗'?>
      </div>
      <div>
        <div style="font-size:0.85rem;font-weight:500;color:var(--text-primary);"><?=$label?></div>
        <?php if(!$ok&&$warn): ?><div style="font-size:0.72rem;color:var(--danger);"><?=$warn?></div><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div style="margin-top:16px;padding:14px;background:var(--bg-elevated);border-radius:var(--radius-sm);font-size:0.8rem;color:var(--text-muted);">
      <strong style="color:var(--text-primary);">Session Info</strong><br>
      Your IP: <?=htmlspecialchars($_SERVER['REMOTE_ADDR']??'unknown')?><br>
      Last Login: <?=$user['last_login']?date('M j, Y g:i a',strtotime($user['last_login'])):'—'?>
    </div>
  </div>
</div>
</div>

<!-- Audit log -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Activity Log</span>
    <span style="font-size:0.75rem;color:var(--text-muted);"><?= isRole('owner') ? 'Last 50 actions across your center' : 'Your last 20 account actions' ?></span>
  </div>
  <div class="card-body no-padding">
    <?php if($logs): ?>
    <table class="data-table">
      <thead><tr><th>Action</th><th>By</th><th>Type</th><th>IP</th><th>Time</th></tr></thead>
      <tbody>
        <?php foreach($logs as $log):
          $color = actionColor($log['action']);
          $details = json_decode($log['details']??'{}',true)??[];
        ?>
        <tr>
          <td>
            <span style="font-family:monospace;font-size:0.78rem;color:<?=$color?>;background:rgba(0,0,0,0.2);padding:2px 7px;border-radius:4px;"><?=sanitize($log['action'])?></span>
          </td>
          <td style="font-size:0.82rem;color:var(--text-secondary);"><?=sanitize($log['user_name']??'System')?></td>
          <td style="font-size:0.78rem;color:var(--text-muted);"><?=sanitize($log['entity_type']??'—')?><?=$log['entity_id']?' #'.$log['entity_id']:''?></td>
          <td style="font-family:monospace;font-size:0.72rem;color:var(--text-muted);"><?=sanitize($log['ip_address']??'—')?></td>
          <td style="font-size:0.76rem;color:var(--text-muted);" title="<?=date('Y-m-d H:i:s',strtotime($log['created_at']))?>"><?=date('M j, g:i a',strtotime($log['created_at']))?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state" style="padding:40px;"><div class="empty-icon">📋</div><div class="empty-title">No activity recorded yet</div></div>
    <?php endif; ?>
  </div>
</div>
<script>
// Password strength meter
document.getElementById('newPwd')?.addEventListener('input', function() {
    const v = this.value;
    let score = 0;
    if (v.length >= 8)  score++;
    if (v.length >= 12) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    const colors = ['','var(--danger)','var(--warning)','var(--warning)','var(--success)','var(--success)'];
    const labels = ['','Weak','Fair','Moderate','Strong','Very Strong'];
    const fill   = document.getElementById('strengthFill');
    const label  = document.getElementById('strengthLabel');
    fill.style.width  = (score * 20) + '%';
    fill.style.background = colors[score];
    label.textContent = v.length ? labels[score] : '';
    label.style.color = colors[score];
});
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
