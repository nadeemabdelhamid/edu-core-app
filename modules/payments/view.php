<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','assistant','accountant');

$pdo = getDB();
$cid = centerId();
$pid = (int)($_GET['id'] ?? 0);
if (!$pid) redirect('/educore/modules/payments/');

$stmt = $pdo->prepare("SELECT p.*,s.name AS student_name,s.student_code,s.phone AS student_phone,s.email AS student_email,g.name AS group_name,c.name AS center_name FROM payments p JOIN students s ON s.id=p.student_id LEFT JOIN `groups` g ON g.id=p.group_id JOIN centers c ON c.id=p.center_id WHERE p.id=? AND p.center_id=?");
$stmt->execute([$pid,$cid]);
$pay = $stmt->fetch();
if (!$pay) { flash('error','Payment not found.'); redirect('/educore/modules/payments/'); }

$pageTitle  = 'Payment Receipt';
$activePage = 'payments';
$breadcrumbs = [
    ['label'=>'Dashboard', 'href'=>'/educore/dashboard.php'],
    ['label'=>'Payments',  'href'=>'/educore/modules/payments/'],
    ['label'=>'Receipt #'.sanitize($pay['receipt_number']??$pid)],
];

// Handle delete
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete') {
    csrf_verify();
    $pdo->prepare("DELETE FROM payments WHERE id=? AND center_id=?")->execute([$pid,$cid]);
    auditLog('payment.deleted','payment',$pid);
    flash('success','Payment deleted.');
    redirect('/educore/modules/payments/');
}

$net     = $pay['amount'] - ($pay['discount']??0);
$balance = $net - $pay['paid_amount'];
$badgeMap = ['paid'=>'badge-success','partial'=>'badge-warning','unpaid'=>'badge-danger','overdue'=>'badge-danger'];
$methodLabel = ['cash'=>'Cash','bank_transfer'=>'Bank Transfer','card'=>'Card / Online','other'=>'Other'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">Receipt</h1><p class="page-subtitle"><?= sanitize($pay['receipt_number']??'#'.$pid) ?></p></div>
  <div style="display:flex;gap:10px;">
    <button onclick="window.print()" class="btn btn-ghost">🖨 Print</button>
    <a href="record.php?id=<?=$pid?>" class="btn btn-ghost">Edit</a>
    <form method="POST" style="display:inline;">
      <?=csrf_field()?><input type="hidden" name="action" value="delete">
      <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this payment record?')">Delete</button>
    </form>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;">

<!-- Receipt card -->
<div class="card" id="receiptCard">
  <div class="card-body" style="padding:32px;">
    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:28px;padding-bottom:20px;border-bottom:2px solid var(--border);">
      <div>
        <div style="font-family:var(--font-display);font-size:1.6rem;font-weight:800;color:var(--accent);">EduCore</div>
        <div style="font-size:0.8rem;color:var(--text-muted);"><?= sanitize($pay['center_name']) ?></div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:4px;">Receipt</div>
        <div style="font-family:monospace;font-size:1rem;font-weight:700;color:var(--text-primary);"><?= sanitize($pay['receipt_number']??'#'.$pid) ?></div>
        <div style="font-size:0.78rem;color:var(--text-muted);margin-top:4px;"><?= $pay['paid_date']?date('M j, Y',strtotime($pay['paid_date'])):date('M j, Y',strtotime($pay['created_at'])) ?></div>
        <span class="badge <?= $badgeMap[$pay['status']] ?>" style="margin-top:6px;display:inline-block;"><?= ucfirst($pay['status']) ?></span>
      </div>
    </div>

    <!-- Student info -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
      <div>
        <div style="font-size:0.68rem;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px;">Billed To</div>
        <div style="font-weight:700;color:var(--text-primary);font-size:1rem;"><?= sanitize($pay['student_name']) ?></div>
        <?php if($pay['student_code']): ?><div style="font-size:0.78rem;color:var(--accent);font-family:monospace;">#<?= sanitize($pay['student_code']) ?></div><?php endif; ?>
        <?php if($pay['student_phone']): ?><div style="font-size:0.78rem;color:var(--text-muted);"><?= sanitize($pay['student_phone']) ?></div><?php endif; ?>
      </div>
      <div>
        <div style="font-size:0.68rem;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px;">For</div>
        <div style="font-weight:600;color:var(--text-primary);"><?= sanitize($pay['group_name']??'General Fee') ?></div>
        <?php if($pay['month_year']): ?>
        <div style="font-size:0.78rem;color:var(--text-muted);"><?= date('F Y',strtotime($pay['month_year'].'-01')) ?> fees</div>
        <?php endif; ?>
        <div style="font-size:0.78rem;color:var(--text-muted);"><?= $methodLabel[$pay['payment_method']]??'' ?></div>
      </div>
    </div>

    <!-- Amount breakdown -->
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
      <thead>
        <tr style="border-bottom:1px solid var(--border);">
          <th style="padding:8px 0;text-align:left;font-size:0.72rem;text-transform:uppercase;color:var(--text-muted);">Description</th>
          <th style="padding:8px 0;text-align:right;font-size:0.72rem;text-transform:uppercase;color:var(--text-muted);">Amount (EGP)</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="padding:10px 0;font-size:0.9rem;color:var(--text-primary);">Monthly Fee — <?= sanitize($pay['group_name']??'') ?></td>
          <td style="padding:10px 0;text-align:right;font-weight:600;"><?= number_format($pay['amount'],2) ?></td>
        </tr>
        <?php if(($pay['discount']??0)>0): ?>
        <tr>
          <td style="padding:4px 0;font-size:0.85rem;color:var(--success);">Discount</td>
          <td style="padding:4px 0;text-align:right;color:var(--success);">− <?= number_format($pay['discount'],2) ?></td>
        </tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr style="border-top:2px solid var(--border);">
          <td style="padding:12px 0;font-weight:700;font-size:0.95rem;">Net Due</td>
          <td style="padding:12px 0;text-align:right;font-weight:800;font-size:1.1rem;"><?= number_format($net,2) ?></td>
        </tr>
        <tr>
          <td style="padding:4px 0;color:var(--success);font-size:0.9rem;">Paid</td>
          <td style="padding:4px 0;text-align:right;color:var(--success);font-weight:700;"><?= number_format($pay['paid_amount'],2) ?></td>
        </tr>
        <?php if($balance>0): ?>
        <tr>
          <td style="padding:4px 0;color:var(--danger);font-size:0.9rem;">Balance Remaining</td>
          <td style="padding:4px 0;text-align:right;color:var(--danger);font-weight:700;"><?= number_format($balance,2) ?></td>
        </tr>
        <?php endif; ?>
      </tfoot>
    </table>

    <?php if($pay['notes']): ?>
    <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);padding:12px 16px;font-size:0.82rem;color:var(--text-secondary);">
      <strong>Note:</strong> <?= sanitize($pay['notes']) ?>
    </div>
    <?php endif; ?>

    <div style="margin-top:24px;padding-top:16px;border-top:1px solid var(--border);text-align:center;font-size:0.72rem;color:var(--text-muted);">
      Generated by EduCore · <?= date('Y-m-d H:i') ?>
    </div>
  </div>
</div>

<!-- Sidebar info -->
<div>
  <div class="card" style="margin-bottom:14px;">
    <div class="card-header"><span class="card-title">Payment Info</span></div>
    <div class="card-body" style="font-size:0.83rem;padding:0;">
      <?php foreach([
        ['Status',      ucfirst($pay['status'])],
        ['Method',      $methodLabel[$pay['payment_method']]??'—'],
        ['Month',       $pay['month_year']?date('M Y',strtotime($pay['month_year'].'-01')):'—'],
        ['Due Date',    $pay['due_date']?date('M j, Y',strtotime($pay['due_date'])):'—'],
        ['Paid Date',   $pay['paid_date']?date('M j, Y',strtotime($pay['paid_date'])):'—'],
        ['Created',     date('M j, Y',strtotime($pay['created_at']))],
      ] as [$l,$v]):?>
      <div style="display:flex;justify-content:space-between;padding:10px 20px;border-bottom:1px solid var(--border);">
        <span style="color:var(--text-muted);font-size:0.72rem;text-transform:uppercase;"><?=$l?></span>
        <span style="font-weight:500;color:var(--text-primary);"><?=$v?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-body" style="display:flex;flex-direction:column;gap:8px;">
      <a href="record.php?id=<?=$pid?>" class="btn btn-primary w-full">Edit Payment</a>
      <a href="record.php?student=<?=$pay['student_id']?>" class="btn btn-ghost w-full">Add Another Payment</a>
      <a href="?student=<?=$pay['student_id']?>" style="display:none"></a>
    </div>
  </div>
</div>

</div>

<style>
@media print {
  .sidebar,.topbar,.page-header,.btn,form[method=POST],.card:not(#receiptCard) { display:none!important; }
  #receiptCard { box-shadow:none!important;border:none!important; }
  body { background:#fff!important; }
}
</style>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
