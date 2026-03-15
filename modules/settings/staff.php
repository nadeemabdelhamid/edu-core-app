<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner');

$pdo = getDB();
$cid = centerId();

$pageTitle  = 'Staff Management';
$activePage = 'settings';
$breadcrumbs = [
    ['label'=>'Dashboard', 'href'=>'/educore/dashboard.php'],
    ['label'=>'Settings',  'href'=>'/educore/modules/settings/'],
    ['label'=>'Staff'],
];

$errors = [];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'invite') {
        $email = trim(strtolower($_POST['email'] ?? ''));
        $role  = $_POST['role'] ?? 'assistant';

        // Individual plan: only assistant/accountant allowed — owner IS the only teacher
        if (isIndividualPlan()) {
            $allowed = ['assistant','accountant'];
            if ($role === 'teacher') {
                $errors[] = 'On the Individual Teacher plan, you are the only teacher. You can add assistants or accountants instead.';
            }
        } else {
            $allowed = ['teacher','assistant','accountant'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
        if (!in_array($role,$allowed)) $errors[] = 'Invalid role.';

        // Check teacher limit (only applies on Academy plan)
        if (!isIndividualPlan()) {
            $limit = checkLimit('teachers');
            if (!$limit['ok']) $errors[] = "Teacher limit reached ({$limit['used']}/{$limit['max']}) on {$limit['plan']} plan. Upgrade to add more.";
        }

        if (!$errors) {
            // Check if already a user or pending invite
            $existing = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $existing->execute([$email]);
            if ($existing->fetch()) {
                $errors[] = 'This email already has an account.';
            } else {
                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));
                // Remove old pending invite for same email+center
                $pdo->prepare("DELETE FROM staff_invitations WHERE center_id=? AND email=? AND status='pending'")->execute([$cid,$email]);
                $pdo->prepare("INSERT INTO staff_invitations (center_id,invited_by,email,role,token,expires_at) VALUES (?,?,?,?,?,?)")
                    ->execute([$cid, authId(), $email, $role, $token, $expires]);
                auditLog('staff.invited','staff_invitation',null,['email'=>$email,'role'=>$role]);
                flash('success',"Invitation sent to $email (role: $role). Invite link shown below.");
                redirect('/educore/modules/settings/staff.php?show_token='.$token);
            }
        }
    }

    if ($action === 'toggle_active') {
        $uid = (int)$_POST['user_id'];
        $pdo->prepare("UPDATE users SET is_active=NOT is_active WHERE id=? AND center_id=? AND role!='owner'")->execute([$uid,$cid]);
        flash('success','Staff status updated.');
        redirect('/educore/modules/settings/staff.php');
    }

    if ($action === 'change_role') {
        $uid     = (int)$_POST['user_id'];
        $newRole = $_POST['new_role'] ?? '';
        // On Individual plan, can't assign teacher role (owner is the only teacher)
        $allowedRoles = isIndividualPlan() ? ['assistant','accountant'] : ['teacher','assistant','accountant'];
        if (in_array($newRole, $allowedRoles)) {
            $pdo->prepare("UPDATE users SET role=? WHERE id=? AND center_id=? AND role!='owner'")->execute([$newRole,$uid,$cid]);
            flash('success','Role updated.');
        }
        redirect('/educore/modules/settings/staff.php');
    }

    if ($action === 'cancel_invite') {
        $invId = (int)$_POST['invite_id'];
        $pdo->prepare("UPDATE staff_invitations SET status='expired' WHERE id=? AND center_id=?")->execute([$invId,$cid]);
        flash('success','Invitation cancelled.');
        redirect('/educore/modules/settings/staff.php');
    }
}

// Load staff
$staff = $pdo->prepare("SELECT * FROM users WHERE center_id=? AND role!='student' ORDER BY FIELD(role,'owner','teacher','assistant','accountant'), name");
$staff->execute([$cid]);
$staffList = $staff->fetchAll();

// Load pending invites
$invStmt = $pdo->prepare("SELECT * FROM staff_invitations WHERE center_id=? AND status='pending' AND expires_at > NOW() ORDER BY created_at DESC");
$invStmt->execute([$cid]);
$invites = $invStmt->fetchAll();

// Show token if just created
$showToken = $_GET['show_token'] ?? '';

$roleColors = ['owner'=>'#f5a623','teacher'=>'#3b82f6','assistant'=>'#8b5cf6','accountant'=>'#22c55e'];
$limit = checkLimit('teachers');

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">Staff Management</h1><p class="page-subtitle">Manage teachers, assistants, and accountants</p></div>
</div>

<!-- Sub-nav -->
<div style="display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);">
  <?php foreach([['./','🏫','Profile'],['subscription.php','💳','Subscription'],['staff.php','👥','Staff'],['notifications.php','🔔','Notifications'],['security.php','🔐','Security']] as [$t,$i,$l]): ?>
  <a href="<?=$t?>" style="display:flex;align-items:center;gap:6px;padding:10px 16px;font-size:0.84rem;font-weight:600;text-decoration:none;border-bottom:2px solid <?=str_contains($t,'staff')?'var(--accent)':'transparent'?>;color:<?=str_contains($t,'staff')?'var(--accent)':'var(--text-muted)'?>;margin-bottom:-1px;"><?=$i?> <?=$l?></a>
  <?php endforeach; ?>
</div>

<?php if($errors): ?>
<div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-md);padding:14px 18px;font-size:0.84rem;color:var(--danger);margin-bottom:20px;">
  <?php foreach($errors as $e): ?><div>• <?=sanitize($e)?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Invite token display -->
<?php if($showToken): ?>
<?php $inv = $pdo->prepare("SELECT * FROM staff_invitations WHERE token=? AND center_id=?"); $inv->execute([$showToken,$cid]); $inv=$inv->fetch(); ?>
<?php if($inv): ?>
<div style="background:rgba(34,197,94,0.07);border:1px solid rgba(34,197,94,0.3);border-radius:var(--radius-md);padding:20px 24px;margin-bottom:20px;">
  <div style="font-weight:700;color:var(--success);margin-bottom:8px;">✅ Invitation created for <?=sanitize($inv['email'])?> (<?=$inv['role']?>)</div>
  <div style="font-size:0.82rem;color:var(--text-secondary);margin-bottom:10px;">Share this invite link with the staff member:</div>
  <div style="display:flex;gap:8px;align-items:center;">
    <input type="text" id="inviteLink" readonly class="form-control" style="font-family:monospace;font-size:0.78rem;"
           value="<?=sanitize('http://'.$_SERVER['HTTP_HOST'].'/educore/accept_invite.php?token='.$showToken)?>">
    <button onclick="navigator.clipboard.writeText(document.getElementById('inviteLink').value);this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',2000);" class="btn btn-success btn-sm" style="white-space:nowrap;">Copy</button>
  </div>
  <div style="font-size:0.72rem;color:var(--text-muted);margin-top:8px;">Expires in 48 hours</div>
</div>
<?php endif; ?>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start;">
<div>

<!-- Staff table -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <span class="card-title">Current Staff (<?=count($staffList)?>)</span>
    <span style="font-size:0.75rem;color:var(--text-muted);"><?=$limit['used']?> / <?=$limit['max']>=PHP_INT_MAX?'∞':$limit['max']?> teacher slots used</span>
  </div>
  <div class="card-body no-padding">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php foreach($staffList as $s):
          $rc = $roleColors[$s['role']] ?? '#888';
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,<?=$rc?>,<?=$rc?>88);color:#fff;font-size:0.7rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?=strtoupper(substr($s['name'],0,2))?></div>
              <span class="cell-primary"><?=sanitize($s['name'])?></span>
            </div>
          </td>
          <td style="font-size:0.8rem;color:var(--text-muted);"><?=sanitize($s['email'])?></td>
          <td>
            <?php if($s['role']==='owner'): ?>
            <span class="badge badge-accent">Owner</span>
            <?php else: ?>
            <form method="POST" style="display:inline;">
              <?=csrf_field()?><input type="hidden" name="action" value="change_role"><input type="hidden" name="user_id" value="<?=$s['id']?>">
              <select name="new_role" class="form-control" style="padding:4px 28px 4px 8px;font-size:0.76rem;width:auto;height:auto;" onchange="this.form.submit()">
                <?php
                // Individual plan: can't promote to teacher role (owner is the only teacher)
                $changeableRoles = isIndividualPlan() ? ['assistant','accountant'] : ['teacher','assistant','accountant'];
                foreach($changeableRoles as $r): ?>
                <option value="<?=$r?>" <?=$s['role']===$r?'selected':''?>><?=ucfirst($r)?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <?php endif; ?>
          </td>
          <td><?php if($s['is_active']): ?><span class="badge badge-success">Active</span><?php else: ?><span class="badge badge-danger">Inactive</span><?php endif; ?></td>
          <td style="font-size:0.77rem;color:var(--text-muted);"><?=$s['last_login']?date('M j, g:i a',strtotime($s['last_login'])):'Never'?></td>
          <td style="text-align:right;">
            <?php if($s['role']!=='owner'): ?>
            <form method="POST" style="display:inline;">
              <?=csrf_field()?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="user_id" value="<?=$s['id']?>">
              <button type="submit" class="btn btn-sm <?=$s['is_active']?'btn-danger':'btn-success'?>" style="font-size:0.72rem;">
                <?=$s['is_active']?'Suspend':'Activate'?>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Pending invites -->
<?php if($invites): ?>
<div class="card">
  <div class="card-header"><span class="card-title">Pending Invitations</span></div>
  <div class="card-body no-padding">
    <table class="data-table">
      <thead><tr><th>Email</th><th>Role</th><th>Expires</th><th style="text-align:right;">Action</th></tr></thead>
      <tbody>
        <?php foreach($invites as $inv): ?>
        <tr>
          <td class="cell-primary"><?=sanitize($inv['email'])?></td>
          <td><span class="badge badge-muted"><?=ucfirst($inv['role'])?></span></td>
          <td style="font-size:0.77rem;color:var(--text-muted);"><?=date('M j, g:i a',strtotime($inv['expires_at']))?></td>
          <td style="text-align:right;">
            <form method="POST" style="display:inline;">
              <?=csrf_field()?><input type="hidden" name="action" value="cancel_invite"><input type="hidden" name="invite_id" value="<?=$inv['id']?>">
              <button type="submit" class="btn btn-danger btn-sm" style="font-size:0.72rem;">Cancel</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
</div>

<!-- Invite form sidebar -->
<div>
  <div class="card">
    <div class="card-header"><span class="card-title">Invite Staff Member</span></div>
    <div class="card-body">

      <?php if (isIndividualPlan()): ?>
      <!-- Individual plan: assistants and accountants allowed; teachers not -->
      <div style="background:rgba(82,71,230,0.06);border:1px solid rgba(82,71,230,0.18);border-radius:var(--radius-sm);padding:11px 14px;font-size:0.79rem;color:var(--text-secondary);margin-bottom:16px;line-height:1.6;">
        <strong style="color:var(--text-primary);">👤 Individual Teacher Plan</strong><br>
        You are the only teacher. You can invite <strong>assistants</strong> or <strong>accountants</strong> to help you.
        <a href="subscription.php" style="color:var(--accent);font-weight:600;display:block;margin-top:6px;">Upgrade to Academy to add more teachers →</a>
      </div>
      <form method="POST">
        <?=csrf_field()?><input type="hidden" name="action" value="invite">
        <div class="form-group">
          <label class="form-label required">Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="assistant@example.com" required>
        </div>
        <div class="form-group">
          <label class="form-label">Role</label>
          <select name="role" class="form-control">
            <option value="assistant">Assistant</option>
            <option value="accountant">Accountant</option>
          </select>
          <span class="form-hint">Teachers not available on Individual plan.</span>
        </div>
        <button type="submit" class="btn btn-primary w-full">Send Invitation →</button>
      </form>

      <?php else: ?>
      <!-- Academy plan: all roles available -->
      <?php if(!$limit['ok']): ?>
      <div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-sm);padding:12px 14px;font-size:0.8rem;color:var(--danger);margin-bottom:14px;">
        Teacher limit reached (<?=$limit['used']?>/<?=$limit['max']?>). <a href="subscription.php" style="color:var(--danger);font-weight:700;">Upgrade →</a>
      </div>
      <?php endif; ?>
      <form method="POST">
        <?=csrf_field()?><input type="hidden" name="action" value="invite">
        <div class="form-group">
          <label class="form-label required">Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="teacher@example.com" required <?=!$limit['ok']?'disabled':''?>>
        </div>
        <div class="form-group">
          <label class="form-label">Role</label>
          <select name="role" class="form-control" <?=!$limit['ok']?'disabled':''?>>
            <option value="teacher">Teacher</option>
            <option value="assistant">Assistant</option>
            <option value="accountant">Accountant</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary w-full" <?=!$limit['ok']?'disabled':''?>>Send Invitation →</button>
      </form>
      <?php endif; ?>

      <div style="margin-top:14px;font-size:0.76rem;color:var(--text-muted);line-height:1.6;">
        The invited person will receive a link to create their account and join your center.
      </div>
    </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
