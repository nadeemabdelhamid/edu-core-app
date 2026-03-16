<?php
require_once __DIR__ . '/../../includes/helpers.php';
// Teachers, assistants, accountants go straight to their password/security page
if (isRole('teacher', 'assistant', 'accountant')) {
    redirect('/educore/modules/settings/security.php');
}
requireRole('owner');

$pdo = getDB();
$cid = centerId();

$pageTitle  = 'Center Settings';
$activePage = 'settings';
$breadcrumbs = [
    ['label'=>'Dashboard', 'href'=>'/educore/dashboard.php'],
    ['label'=>'Settings'],
];

// Load center
$center = $pdo->prepare("SELECT * FROM centers WHERE id=?");
$center->execute([$cid]);
$center = $center->fetch();

$settings = json_decode($center['settings'] ?? '{}', true) ?? [];
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'profile';

    if ($action === 'profile') {
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $tz      = $_POST['timezone'] ?? 'UTC';

        if (!$name) $errors[] = 'Center name is required.';

        if (!$errors) {
            $pdo->prepare("UPDATE centers SET name=?,phone=?,address=?,timezone=?,updated_at=NOW() WHERE id=?")
                ->execute([$name,$phone,$address,$tz,$cid]);
            auditLog('settings.profile_updated','center',$cid);
            flash('success','Center profile updated.');
            redirect('/educore/modules/settings/');
        }
    }

    if ($action === 'branches') {
        $branchNames    = $_POST['branch_name']    ?? [];
        $branchAddrs    = $_POST['branch_address'] ?? [];
        $branchPhones   = $_POST['branch_phone']   ?? [];

        $branches = [];
        foreach ($branchNames as $i => $name) {
            $name = trim($name);
            if (!$name) continue;
            $branches[] = [
                'id'      => uniqid('br_'),
                'name'    => $name,
                'address' => trim($branchAddrs[$i] ?? ''),
                'phone'   => trim($branchPhones[$i] ?? ''),
            ];
        }
        $pdo->prepare("UPDATE centers SET branches=?,updated_at=NOW() WHERE id=?")
            ->execute([json_encode($branches), $cid]);
        auditLog('settings.branches_updated','center',$cid);
        flash('success','Branches updated successfully.');
        redirect('/educore/modules/settings/?tab=branches');
    }

    if ($action === 'preferences') {
        $settings['currency']      = $_POST['currency']      ?? 'EGP';
        $settings['date_format']   = $_POST['date_format']   ?? 'M j, Y';
        $settings['academic_year'] = $_POST['academic_year'] ?? date('Y');
        $settings['late_minutes']  = max(1,(int)($_POST['late_minutes']??15));
        $pdo->prepare("UPDATE centers SET settings=? WHERE id=?")->execute([json_encode($settings),$cid]);
        flash('success','Preferences saved.');
        redirect('/educore/modules/settings/');
    }
}

$branches = json_decode($center['branches'] ?? '[]', true) ?? [];
$timezones = ['UTC','Africa/Cairo','Asia/Riyadh','Asia/Dubai','Europe/London','Europe/Paris','America/New_York','America/Los_Angeles'];
$currencies = ['EGP'=>'EGP — Egyptian Pound','USD'=>'USD — US Dollar','SAR'=>'SAR — Saudi Riyal','AED'=>'AED — UAE Dirham','EUR'=>'EUR — Euro','GBP'=>'GBP — British Pound'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">Center Settings</h1><p class="page-subtitle">Manage your center profile and preferences</p></div>
</div>

<!-- Tab nav -->
<?php $activeTab = $_GET['tab'] ?? 'profile'; ?>
<div style="display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);padding-bottom:0;overflow-x:auto;">
  <?php foreach([
    ['profile','🏫','Profile'],
    ['branches','🏢','Branches'],
    ['subscription','💳','Subscription'],
    ['staff','👥','Staff'],
    ['notifications','🔔','Notifications'],
    ['security','🔐','Security'],
  ] as [$tab,$ico,$lbl]): ?>
  <?php $isActive = ($activeTab === $tab || ($tab==='profile' && $activeTab==='')); ?>
  <a href="<?=$tab==='subscription'||$tab==='staff'||$tab==='notifications'||$tab==='security'?$tab.'.php':'?tab='.$tab?>"
     style="display:flex;align-items:center;gap:6px;padding:10px 16px;font-size:0.84rem;font-weight:600;text-decoration:none;white-space:nowrap;border-bottom:2px solid <?=$isActive?'var(--accent)':'transparent'?>;color:<?=$isActive?'var(--accent)':'var(--text-muted)'?>;margin-bottom:-1px;transition:color 0.15s;">
    <?=$ico?> <?=$lbl?>
  </a>
  <?php endforeach; ?>
</div>

<?php if($activeTab==='branches'): ?>
<!-- ═══════════════════════════ BRANCHES TAB ═══════════════════════════ -->
<div class="page-header" style="margin-bottom:20px;">
  <div>
    <h2 style="font-size:1.1rem;font-weight:700;color:var(--text-primary);">Center Branches</h2>
    <p class="page-subtitle">Add and manage branch locations for your center</p>
  </div>
  <button type="button" class="btn btn-primary" onclick="addBranch()">+ Add Branch</button>
</div>

<form method="POST" id="branchForm">
  <?=csrf_field()?><input type="hidden" name="action" value="branches">
  <div id="branchList" style="display:flex;flex-direction:column;gap:16px;margin-bottom:20px;">
  <?php if(empty($branches)): ?>
    <!-- Empty state: JS will add first branch on load -->
  <?php else: ?>
    <?php foreach($branches as $i => $branch): ?>
    <div class="card branch-row">
      <div class="card-header" style="padding:14px 20px;">
        <span class="card-title" style="font-size:0.88rem;">Branch <?= $i+1 ?></span>
        <button type="button" class="btn btn-danger btn-sm" onclick="removeBranch(this)">Remove</button>
      </div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
          <div class="form-group" style="margin:0;">
            <label class="form-label required">Branch Name</label>
            <input type="text" name="branch_name[]" class="form-control" value="<?=sanitize($branch['name'])?>" required placeholder="e.g. Main Branch, Cairo">
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Phone</label>
            <input type="tel" name="branch_phone[]" class="form-control" value="<?=sanitize($branch['phone']??'')?>" placeholder="+20 100 000 0000">
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Address</label>
            <input type="text" name="branch_address[]" class="form-control" value="<?=sanitize($branch['address']??'')?>" placeholder="Street, City">
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>
  <button type="submit" class="btn btn-primary">Save Branches</button>
</form>

<script>
const branchTemplate = `
<div class="card branch-row" style="animation:fadeIn 0.2s ease;">
  <div class="card-header" style="padding:14px 20px;">
    <span class="card-title" style="font-size:0.88rem;">New Branch</span>
    <button type="button" class="btn btn-danger btn-sm" onclick="removeBranch(this)">Remove</button>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
      <div class="form-group" style="margin:0;">
        <label class="form-label required">Branch Name</label>
        <input type="text" name="branch_name[]" class="form-control" required placeholder="e.g. Main Branch, Cairo">
      </div>
      <div class="form-group" style="margin:0;">
        <label class="form-label">Phone</label>
        <input type="tel" name="branch_phone[]" class="form-control" placeholder="+20 100 000 0000">
      </div>
      <div class="form-group" style="margin:0;">
        <label class="form-label">Address</label>
        <input type="text" name="branch_address[]" class="form-control" placeholder="Street, City">
      </div>
    </div>
  </div>
</div>`;

function addBranch() {
  const list = document.getElementById('branchList');
  const div = document.createElement('div');
  div.innerHTML = branchTemplate;
  list.appendChild(div.firstElementChild);
  div.firstElementChild?.querySelector('input')?.focus();
}
function removeBranch(btn) {
  const rows = document.querySelectorAll('.branch-row');
  if (rows.length <= 1) { alert('You must have at least one branch.'); return; }
  btn.closest('.branch-row').remove();
}
// Auto-add first branch if list is empty
document.addEventListener('DOMContentLoaded', function() {
  const list = document.getElementById('branchList');
  if (!list.querySelector('.branch-row')) addBranch();
});
</script>
<?php else: ?>
<!-- ═══════════════════════════ PROFILE / PREFERENCES TAB ══════════════════════════ -->

<?php if($errors): ?>
<div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-md);padding:14px 18px;font-size:0.84rem;color:var(--danger);margin-bottom:20px;">
  <?php foreach($errors as $e): ?><div>• <?=sanitize($e)?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;">
<div>

<!-- Center Profile -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">Center Profile</span></div>
  <div class="card-body">
    <form method="POST">
      <?=csrf_field()?><input type="hidden" name="action" value="profile">
      <div class="form-group">
        <label class="form-label required">Center Name</label>
        <input type="text" name="name" class="form-control" value="<?=sanitize($center['name'])?>" required>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="form-group">
          <label class="form-label">Phone</label>
          <input type="tel" name="phone" class="form-control" value="<?=sanitize($center['phone']??'')?>">
        </div>
        <div class="form-group">
          <label class="form-label">Timezone</label>
          <select name="timezone" class="form-control">
            <?php foreach($timezones as $tz): ?>
            <option value="<?=$tz?>" <?=$center['timezone']===$tz?'selected':''?>><?=$tz?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Address</label>
        <textarea name="address" class="form-control" rows="2"><?=sanitize($center['address']??'')?></textarea>
      </div>
      <button type="submit" class="btn btn-primary">Save Profile</button>
    </form>
  </div>
</div>

<!-- Preferences -->
<div class="card">
  <div class="card-header"><span class="card-title">Preferences</span></div>
  <div class="card-body">
    <form method="POST">
      <?=csrf_field()?><input type="hidden" name="action" value="preferences">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="form-group">
          <label class="form-label">Currency</label>
          <select name="currency" class="form-control">
            <?php foreach($currencies as $v=>$l): ?>
            <option value="<?=$v?>" <?=($settings['currency']??'EGP')===$v?'selected':''?>><?=$l?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Academic Year</label>
          <input type="number" name="academic_year" class="form-control" value="<?=$settings['academic_year']??date('Y')?>" min="2020" max="2035">
        </div>
        <div class="form-group">
          <label class="form-label">Late Threshold (minutes)</label>
          <input type="number" name="late_minutes" class="form-control" value="<?=$settings['late_minutes']??15?>" min="1" max="60">
          <span class="form-hint">Arrivals within this window count as "late" not "absent"</span>
        </div>
        <div class="form-group">
          <label class="form-label">Date Format</label>
          <select name="date_format" class="form-control">
            <?php foreach(['M j, Y'=>'Jan 5, 2025','Y-m-d'=>'2025-01-05','d/m/Y'=>'05/01/2025'] as $v=>$ex): ?>
            <option value="<?=$v?>" <?=($settings['date_format']??'M j, Y')===$v?'selected':''?>><?=$ex?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Save Preferences</button>
    </form>
  </div>
</div>
</div>

<!-- Sidebar info -->
<div>
  <div class="card" style="margin-bottom:14px;">
    <div class="card-header"><span class="card-title">Center Info</span></div>
    <div class="card-body" style="font-size:0.82rem;padding:0;">
      <?php foreach([
        ['Slug',$center['slug']],
        ['Email',$center['email']],
        ['Status',ucfirst($center['status'])],
        ['Created',date('M j, Y',strtotime($center['created_at']))],
      ] as [$l,$v]):?>
      <div style="display:flex;justify-content:space-between;padding:10px 20px;border-bottom:1px solid var(--border);">
        <span style="color:var(--text-muted);font-size:0.7rem;text-transform:uppercase;"><?=$l?></span>
        <span style="font-weight:500;color:var(--text-primary);font-size:0.82rem;"><?=sanitize($v)?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">Quick Links</span></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:6px;padding:14px 16px;">
      <?php foreach([
        ['subscription.php','💳','Subscription & Plan'],
        ['staff.php','👥','Manage Staff'],
        ['security.php','🔐','Security'],
        ['/educore/modules/reports/','📊','Analytics'],
      ] as [$href,$ico,$lbl]): ?>
      <a href="<?=$href?>" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:var(--radius-sm);text-decoration:none;color:var(--text-secondary);font-size:0.84rem;transition:background 0.15s;" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
        <?=$ico?> <?=$lbl?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</div>

<?php endif; // end tab check ?>
<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
