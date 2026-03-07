<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','assistant','accountant');

$pdo    = getDB();
$cid    = centerId();
$editId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$pay    = null;
$isEdit = false;

if ($editId) {
    $s = $pdo->prepare("SELECT * FROM payments WHERE id=? AND center_id=?");
    $s->execute([$editId,$cid]);
    $pay = $s->fetch();
    if (!$pay) { flash('error','Payment not found.'); redirect('/educore/modules/payments/'); }
    $isEdit = true;
}

// Pre-fill student if coming from student page
$preStudent = (int)($_GET['student'] ?? 0);

$pageTitle  = $isEdit ? 'Edit Payment' : 'Record Payment';
$activePage = 'payments';
$breadcrumbs = [
    ['label'=>'Dashboard', 'href'=>'/educore/dashboard.php'],
    ['label'=>'Payments',  'href'=>'/educore/modules/payments/'],
    ['label'=>$pageTitle],
];

$errors = [];
$data   = $pay ?? ['payment_method'=>'cash','status'=>'paid','paid_date'=>date('Y-m-d'),
                    'month_year'=>date('Y-m'),'discount'=>0];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $data = [
        'student_id'     => (int)$_POST['student_id'],
        'group_id'       => (int)$_POST['group_id'] ?: null,
        'amount'         => (float)$_POST['amount'],
        'discount'       => (float)($_POST['discount'] ?? 0),
        'paid_amount'    => (float)$_POST['paid_amount'],
        'payment_method' => $_POST['payment_method'] ?? 'cash',
        'status'         => $_POST['status'] ?? 'paid',
        'due_date'       => $_POST['due_date'] ?: null,
        'paid_date'      => $_POST['paid_date'] ?: null,
        'month_year'     => $_POST['month_year'] ?: null,
        'receipt_number' => trim($_POST['receipt_number'] ?? ''),
        'notes'          => trim($_POST['notes'] ?? ''),
    ];

    // Auto-generate receipt number
    if (!$data['receipt_number'] && !$isEdit) {
        $data['receipt_number'] = 'RCP-'.strtoupper(substr(uniqid(),6));
    }

    // Auto-derive status
    $net = $data['amount'] - $data['discount'];
    if ($data['paid_amount'] <= 0)        $data['status'] = $data['due_date'] && $data['due_date'] < date('Y-m-d') ? 'overdue' : 'unpaid';
    elseif ($data['paid_amount'] >= $net) $data['status'] = 'paid';
    else                                  $data['status'] = 'partial';

    if (!$data['student_id']) $errors[] = 'Student is required.';
    if ($data['amount'] <= 0) $errors[] = 'Amount must be greater than zero.';

    if (!$errors) {
        if ($isEdit) {
            $pdo->prepare("UPDATE payments SET student_id=?,group_id=?,amount=?,discount=?,paid_amount=?,payment_method=?,status=?,due_date=?,paid_date=?,month_year=?,receipt_number=?,notes=?,updated_at=NOW() WHERE id=? AND center_id=?")
                ->execute([$data['student_id'],$data['group_id'],$data['amount'],$data['discount'],$data['paid_amount'],$data['payment_method'],$data['status'],$data['due_date'],$data['paid_date'],$data['month_year'],$data['receipt_number'],$data['notes'],$editId,$cid]);
            auditLog('payment.updated','payment',$editId);
            flash('success','Payment updated.');
        } else {
            $pdo->prepare("INSERT INTO payments (center_id,student_id,group_id,amount,discount,paid_amount,payment_method,status,due_date,paid_date,month_year,receipt_number,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$cid,$data['student_id'],$data['group_id'],$data['amount'],$data['discount'],$data['paid_amount'],$data['payment_method'],$data['status'],$data['due_date'],$data['paid_date'],$data['month_year'],$data['receipt_number'],$data['notes']]);
            $newId = $pdo->lastInsertId();
            auditLog('payment.created','payment',$newId,['amount'=>$data['amount']]);
            flash('success','Payment recorded successfully.');
        }
        redirect('/educore/modules/payments/');
    }
}

// Load students & groups for dropdowns
$students = $pdo->prepare("SELECT id,name,student_code FROM students WHERE center_id=? AND is_active=1 ORDER BY name");
$students->execute([$cid]);
$studentList = $students->fetchAll();

$grpStmt = $pdo->prepare("SELECT id,name FROM `groups` WHERE center_id=? AND is_active=1 ORDER BY name");
$grpStmt->execute([$cid]);
$groupList = $grpStmt->fetchAll();

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title"><?= $pageTitle ?></h1>
    <p class="page-subtitle"><?= $isEdit ? 'Update payment details' : 'Record a new fee payment' ?></p>
  </div>
  <a href="/educore/modules/payments/" class="btn btn-ghost">← Back</a>
</div>

<?php if($errors): ?>
<div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-md);padding:14px 18px;font-size:0.84rem;color:var(--danger);margin-bottom:20px;">
  <?php foreach($errors as $e): ?><div>• <?= sanitize($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST">
  <?= csrf_field() ?>
  <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">

    <div>
      <!-- Student & Group -->
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">Student & Group</span></div>
        <div class="card-body">
          <div class="form-group">
            <label class="form-label required">Student</label>
            <select name="student_id" id="studentSelect" class="form-control" required onchange="loadStudentGroups(this.value)">
              <option value="">— Select student —</option>
              <?php foreach($studentList as $st): ?>
              <option value="<?=$st['id']?>" <?= (int)($data['student_id']??$preStudent)===$st['id']?'selected':''?>>
                <?= sanitize($st['name']) ?> <?= $st['student_code']?'(#'.sanitize($st['student_code']).')':'' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Group</label>
            <select name="group_id" id="groupSelect" class="form-control">
              <option value="">— No specific group —</option>
              <?php foreach($groupList as $g): ?>
              <option value="<?=$g['id']?>" <?= (int)($data['group_id']??0)===$g['id']?'selected':''?>
                      data-fee="<?= $g['monthly_fee'] ?? 0 ?>">
                <?= sanitize($g['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <!-- Amounts -->
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">Amount</span></div>
        <div class="card-body">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
            <div class="form-group">
              <label class="form-label required">Fee Amount (EGP)</label>
              <input type="number" name="amount" id="amountField" class="form-control" step="0.01" min="0"
                     value="<?= sanitize($data['amount']??'') ?>" required oninput="recalc()">
            </div>
            <div class="form-group">
              <label class="form-label">Discount (EGP)</label>
              <input type="number" name="discount" id="discountField" class="form-control" step="0.01" min="0"
                     value="<?= sanitize($data['discount']??0) ?>" oninput="recalc()">
            </div>
            <div class="form-group">
              <label class="form-label">Amount Paid (EGP)</label>
              <input type="number" name="paid_amount" id="paidField" class="form-control" step="0.01" min="0"
                     value="<?= sanitize($data['paid_amount']??'') ?>" oninput="recalc()">
            </div>
          </div>
          <!-- Net & balance display -->
          <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);padding:12px 16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;text-align:center;">
            <div>
              <div style="font-size:0.68rem;text-transform:uppercase;color:var(--text-muted);margin-bottom:3px;">Net Due</div>
              <div id="netDue" style="font-size:1.1rem;font-weight:700;color:var(--text-primary);">—</div>
            </div>
            <div>
              <div style="font-size:0.68rem;text-transform:uppercase;color:var(--text-muted);margin-bottom:3px;">Paid</div>
              <div id="paidDisplay" style="font-size:1.1rem;font-weight:700;color:var(--success);">—</div>
            </div>
            <div>
              <div style="font-size:0.68rem;text-transform:uppercase;color:var(--text-muted);margin-bottom:3px;">Balance</div>
              <div id="balance" style="font-size:1.1rem;font-weight:700;">—</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Details -->
      <div class="card">
        <div class="card-header"><span class="card-title">Payment Details</span></div>
        <div class="card-body">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div class="form-group">
              <label class="form-label">Payment Method</label>
              <select name="payment_method" class="form-control">
                <?php foreach(['cash'=>'Cash','bank_transfer'=>'Bank Transfer','card'=>'Card / Online','other'=>'Other'] as $v=>$l): ?>
                <option value="<?=$v?>" <?= ($data['payment_method']??'')===$v?'selected':''?>><?=$l?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Month / Period</label>
              <input type="month" name="month_year" class="form-control" value="<?= sanitize($data['month_year']??date('Y-m')) ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Due Date</label>
              <input type="date" name="due_date" class="form-control" value="<?= sanitize($data['due_date']??'') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Date Paid</label>
              <input type="date" name="paid_date" class="form-control" value="<?= sanitize($data['paid_date']??date('Y-m-d')) ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Receipt / Reference Number</label>
            <input type="text" name="receipt_number" class="form-control"
                   placeholder="Auto-generated if left blank"
                   value="<?= sanitize($data['receipt_number']??'') ?>">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"><?= sanitize($data['notes']??'') ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- Sidebar -->
    <div>
      <div class="card">
        <div class="card-body">
          <button type="submit" class="btn btn-primary w-full btn-lg">
            <?= $isEdit ? 'Save Changes' : 'Record Payment' ?>
          </button>
          <a href="/educore/modules/payments/" class="btn btn-ghost w-full" style="margin-top:8px;">Cancel</a>
        </div>
      </div>
      <?php if($isEdit): ?>
      <div class="card" style="margin-top:14px;">
        <div class="card-header"><span class="card-title">Receipt Info</span></div>
        <div class="card-body" style="font-size:0.82rem;">
          <div style="margin-bottom:6px;color:var(--text-muted);">Receipt #</div>
          <div style="font-family:monospace;font-weight:700;color:var(--accent);"><?= sanitize($pay['receipt_number']??'—') ?></div>
          <div style="margin-top:12px;">
            <a href="view.php?id=<?=$editId?>" class="btn btn-ghost btn-sm w-full">View / Print Receipt</a>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</form>

<script>
// Load group fee when group is selected
document.getElementById('groupSelect').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    const fee = parseFloat(opt.dataset.fee || 0);
    if (fee > 0) document.getElementById('amountField').value = fee.toFixed(2);
    recalc();
});

function recalc() {
    const amount  = parseFloat(document.getElementById('amountField').value)  || 0;
    const disc    = parseFloat(document.getElementById('discountField').value) || 0;
    const paid    = parseFloat(document.getElementById('paidField').value)     || 0;
    const net     = Math.max(0, amount - disc);
    const balance = net - paid;

    document.getElementById('netDue').textContent      = net.toLocaleString()      + ' EGP';
    document.getElementById('paidDisplay').textContent = paid.toLocaleString()     + ' EGP';
    const balEl = document.getElementById('balance');
    balEl.textContent  = Math.abs(balance).toLocaleString() + ' EGP';
    balEl.style.color  = balance <= 0 ? 'var(--success)' : 'var(--danger)';
}
recalc();
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
