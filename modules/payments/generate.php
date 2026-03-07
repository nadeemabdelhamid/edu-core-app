<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','accountant');

$pdo = getDB();
$cid = centerId();

$pageTitle  = 'Generate Monthly Bills';
$activePage = 'payments';
$breadcrumbs = [
    ['label'=>'Dashboard', 'href'=>'/educore/dashboard.php'],
    ['label'=>'Payments',  'href'=>'/educore/modules/payments/'],
    ['label'=>'Generate Bills'],
];

$errors    = [];
$generated = 0;
$skipped   = 0;
$done      = false;

// Groups with fee
$grpStmt = $pdo->prepare("SELECT g.id,g.name,g.monthly_fee,COUNT(gs.id) AS student_count FROM `groups` g JOIN group_students gs ON gs.group_id=g.id AND gs.status='active' WHERE g.center_id=? AND g.is_active=1 AND g.monthly_fee>0 GROUP BY g.id ORDER BY g.name");
$grpStmt->execute([$cid]);
$groups = $grpStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $month     = $_POST['month'] ?? date('Y-m');
    $due_day   = max(1, min(28, (int)($_POST['due_day'] ?? 15)));
    $selGroups = $_POST['group_ids'] ?? [];

    if (!$month)     $errors[] = 'Month is required.';
    if (!$selGroups) $errors[] = 'Select at least one group.';

    if (!$errors) {
        $due_date = $month.'-'.str_pad($due_day, 2, '0', STR_PAD_LEFT);

        foreach ($selGroups as $gid) {
            // Get group fee
            $gf = $pdo->prepare("SELECT monthly_fee FROM `groups` WHERE id=? AND center_id=?");
            $gf->execute([$gid, $cid]);
            $fee = (float)($gf->fetchColumn() ?? 0);
            if ($fee <= 0) continue;

            // Get active students in this group
            $stuStmt = $pdo->prepare("SELECT s.id FROM group_students gs JOIN students s ON s.id=gs.student_id WHERE gs.group_id=? AND gs.status='active' AND s.center_id=? AND s.is_active=1");
            $stuStmt->execute([$gid, $cid]);
            $studs = $stuStmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($studs as $sid) {
                // Check if bill already exists for this student+group+month
                $dup = $pdo->prepare("SELECT id FROM payments WHERE center_id=? AND student_id=? AND group_id=? AND month_year=?");
                $dup->execute([$cid, $sid, $gid, $month]);
                if ($dup->fetch()) { $skipped++; continue; }

                $receipt = 'RCP-'.strtoupper(substr(uniqid(),6));
                $pdo->prepare("INSERT INTO payments (center_id,student_id,group_id,amount,paid_amount,payment_method,status,due_date,month_year,receipt_number) VALUES (?,?,?,?,0,'cash','unpaid',?,?,?)")
                    ->execute([$cid, $sid, $gid, $fee, $due_date, $month, $receipt]);
                $generated++;
            }
        }
        auditLog('payments.bulk_generated','payment',null,['month'=>$month,'generated'=>$generated]);
        $done = true;
    }
}

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">Generate Monthly Bills</h1>
    <p class="page-subtitle">Bulk-create payment records for an entire month</p>
  </div>
  <a href="/educore/modules/payments/" class="btn btn-ghost">← Back</a>
</div>

<?php if($done): ?>
<div style="background:rgba(34,197,94,0.07);border:1px solid rgba(34,197,94,0.3);border-radius:var(--radius-md);padding:28px;text-align:center;margin-bottom:20px;">
  <div style="font-size:2.5rem;margin-bottom:8px;">✅</div>
  <div style="font-family:var(--font-display);font-size:1.2rem;font-weight:700;color:var(--success);margin-bottom:6px;"><?= $generated ?> bills generated</div>
  <?php if($skipped): ?><div style="font-size:0.82rem;color:var(--text-muted);"><?= $skipped ?> skipped (already existed)</div><?php endif; ?>
  <div style="display:flex;gap:10px;justify-content:center;margin-top:16px;">
    <a href="/educore/modules/payments/" class="btn btn-primary">View Payments</a>
    <a href="generate.php" class="btn btn-ghost">Generate Again</a>
  </div>
</div>
<?php endif; ?>

<?php if($errors): ?>
<div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-md);padding:14px 18px;font-size:0.84rem;color:var(--danger);margin-bottom:20px;">
  <?php foreach($errors as $e): ?><div>• <?= sanitize($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if(!$done): ?>
<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;">
<form method="POST">
  <?=csrf_field()?>
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header"><span class="card-title">Billing Period</span></div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="form-group">
          <label class="form-label required">Month</label>
          <input type="month" name="month" class="form-control" value="<?= date('Y-m') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Due Day of Month</label>
          <input type="number" name="due_day" class="form-control" min="1" max="28" value="15">
          <span class="form-hint">e.g. 15 → due on the 15th</span>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="card-title">Select Groups</span>
      <button type="button" onclick="toggleAll()" class="btn btn-ghost btn-sm">Toggle All</button>
    </div>
    <div class="card-body no-padding">
      <?php if($groups): ?>
      <?php foreach($groups as $g): ?>
      <label style="display:flex;align-items:center;gap:14px;padding:14px 20px;border-bottom:1px solid var(--border);cursor:pointer;">
        <input type="checkbox" name="group_ids[]" value="<?=$g['id']?>" checked
               style="accent-color:var(--accent);width:16px;height:16px;flex-shrink:0;">
        <div style="flex:1;">
          <div style="font-weight:600;color:var(--text-primary);font-size:0.88rem;"><?= sanitize($g['name']) ?></div>
          <div style="font-size:0.72rem;color:var(--text-muted);"><?= $g['student_count'] ?> students</div>
        </div>
        <div style="font-weight:700;color:var(--accent);"><?= number_format($g['monthly_fee']) ?> EGP</div>
      </label>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="empty-state" style="padding:30px;">
        <div class="empty-icon">💳</div>
        <div class="empty-title">No groups with fees set</div>
        <div class="empty-desc"><a href="/educore/modules/groups/" class="btn btn-ghost btn-sm" style="margin-top:10px;">Set Group Fees</a></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div style="margin-top:16px;">
    <button type="submit" class="btn btn-primary btn-lg w-full" <?= !$groups?'disabled':''?>>Generate Bills →</button>
  </div>
</form>

<div>
  <div class="card">
    <div class="card-header"><span class="card-title">How it works</span></div>
    <div class="card-body" style="font-size:0.83rem;color:var(--text-secondary);line-height:1.7;">
      <p>This creates one unpaid payment record per active student per selected group.</p>
      <p>Students already billed for the selected month are automatically <strong>skipped</strong>.</p>
      <p>After generating, you can open each payment to record when it is collected.</p>
    </div>
  </div>
</div>
</div>
<?php endif; ?>

<script>
function toggleAll() {
    const boxes = document.querySelectorAll('input[name="group_ids[]"]');
    const allChecked = [...boxes].every(b=>b.checked);
    boxes.forEach(b => b.checked = !allChecked);
}
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
