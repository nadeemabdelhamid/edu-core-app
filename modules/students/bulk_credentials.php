<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'assistant');

$pdo = getDB();
$cid = centerId();

$pageTitle  = 'Bulk Create Accounts';
$activePage = 'students';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Students',  'href' => '/educore/modules/students/'],
    ['label' => 'Bulk Create Accounts'],
];

function genPwd($len = 8) {
    $c = 'abcdefghjkmnpqrstuvwxyz23456789';
    $p = '';
    for ($i = 0; $i < $len; $i++) $p .= $c[random_int(0, strlen($c)-1)];
    return $p;
}

function genUsername($name, $cid, $pdo) {
    $base = strtolower(preg_replace('/[^a-z0-9]/i','', explode(' ', trim($name))[0])) ?: 'student';
    $email = $base . $cid . '@student.local';
    $i = 1;
    while (true) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE email=?"); $chk->execute([$email]);
        if (!$chk->fetch()) break;
        $email = $base . $cid . '_' . $i . '@student.local'; $i++;
    }
    return $email;
}

// Students without accounts
$stmt = $pdo->prepare("SELECT * FROM students WHERE center_id=? AND user_id IS NULL AND is_active=1 ORDER BY name");
$stmt->execute([$cid]);
$students = $stmt->fetchAll();

$generated = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_all') {
        foreach ($students as $s) {
            $username = genUsername($s['name'], $cid, $pdo);
            $password = genPwd();
            $hashed   = password_hash($password, PASSWORD_BCRYPT);

            $pdo->prepare("INSERT INTO users (center_id,name,email,password,role,is_active) VALUES (?,?,?,?,'student',1)")
                ->execute([$cid, $s['name'], $username, $hashed]);
            $uid = $pdo->lastInsertId();
            $pdo->prepare("UPDATE students SET user_id=? WHERE id=?")->execute([$uid, $s['id']]);

            $generated[] = [
                'id'       => $s['id'],
                'name'     => $s['name'],
                'code'     => $s['student_code'],
                'username' => $username,
                'password' => $password,
            ];
            auditLog('student.account_created', 'student', $s['id'], ['login' => $username, 'bulk' => true]);
        }

        // Store for display
        $_SESSION['bulk_creds'] = $generated;
        flash('success', count($generated) . ' accounts created.');
        redirect('bulk_credentials.php?done=1');
    }
}

// Show results
if (($_GET['done'] ?? '') === '1' && !empty($_SESSION['bulk_creds'])) {
    $generated = $_SESSION['bulk_creds'];
    unset($_SESSION['bulk_creds']);
}

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">Bulk Create Login Accounts</h1>
    <p class="page-subtitle">Generate accounts for all students who can't log in yet</p>
  </div>
  <a href="/educore/modules/students/" class="btn btn-ghost">← Back</a>
</div>

<?php if ($generated): ?>
<!-- ── Results ─────────────────────────────────────────────── -->
<div style="background:rgba(34,197,94,0.06);border:1px solid rgba(34,197,94,0.25);border-radius:var(--radius-md);padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:10px;">
  <span style="color:var(--success);font-size:1.2rem;">✓</span>
  <strong style="color:var(--text-primary);"><?= count($generated) ?> accounts created.</strong>
  <span style="color:var(--text-muted);font-size:0.82rem;">Print this page or export CSV before leaving — passwords won't be shown again.</span>
  <div style="margin-left:auto;display:flex;gap:8px;">
    <button onclick="exportCSV()" class="btn btn-ghost btn-sm">↓ Export CSV</button>
    <button onclick="window.print()" class="btn btn-primary btn-sm">🖨 Print All</button>
  </div>
</div>

<div class="card">
  <div class="card-body no-padding">
    <table class="data-table" id="credsTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Student Name</th>
          <th>Code</th>
          <th>Username</th>
          <th>Password</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($generated as $i => $g): ?>
        <tr>
          <td style="color:var(--text-muted);"><?= $i+1 ?></td>
          <td class="cell-primary"><?= sanitize($g['name']) ?></td>
          <td><span style="font-family:monospace;color:var(--accent);font-weight:700;">#<?= sanitize($g['code']) ?></span></td>
          <td><span style="font-family:monospace;font-size:0.82rem;color:var(--text-secondary);"><?= sanitize($g['username']) ?></span></td>
          <td><span style="font-family:monospace;font-size:1.05rem;font-weight:700;letter-spacing:0.1em;color:var(--text-primary);"><?= sanitize($g['password']) ?></span></td>
          <td style="text-align:right;">
            <a href="credentials.php?id=<?= $g['id'] ?>" class="btn btn-ghost btn-sm" style="font-size:0.75rem;">Manage</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Printable credential slips -->
<div id="printSlips" style="display:none;">
  <style>
    @media print {
      body > *:not(#printSlips) { display:none !important; }
      #printSlips { display:block !important; }
      .slip-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
      .slip { border:1.5px solid #f5a623; border-radius:8px; padding:14px; page-break-inside:avoid; }
    }
  </style>
  <div class="slip-grid">
    <?php foreach ($generated as $g): ?>
    <div class="slip">
      <div style="font-size:0.7rem;color:#888;margin-bottom:6px;">EduCore — Student Login Card</div>
      <div style="font-weight:700;font-size:0.95rem;margin-bottom:8px;"><?= sanitize($g['name']) ?></div>
      <table style="width:100%;font-size:0.78rem;border-collapse:collapse;">
        <tr><td style="color:#666;padding:3px 0;width:35%;">Code</td><td style="font-family:monospace;font-weight:700;color:#f5a623;">#<?= sanitize($g['code']) ?></td></tr>
        <tr><td style="color:#666;padding:3px 0;">Username</td><td style="font-family:monospace;font-size:0.72rem;word-break:break-all;"><?= sanitize($g['username']) ?></td></tr>
        <tr style="background:#fffbf0;"><td style="color:#666;padding:5px 0;">Password</td><td style="font-family:monospace;font-size:1.1rem;font-weight:800;letter-spacing:3px;"><?= sanitize($g['password']) ?></td></tr>
        <tr><td style="color:#666;padding:3px 0;font-size:0.65rem;">Login URL</td><td style="font-size:0.65rem;color:#555;">localhost/educore/login.php</td></tr>
      </table>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
function exportCSV() {
    const rows = [['Name','Code','Username','Password']];
    <?php foreach($generated as $g): ?>
    rows.push([<?= json_encode($g['name'])?>,<?= json_encode('#'.$g['code'])?>,<?= json_encode($g['username'])?>,<?= json_encode($g['password'])?>]);
    <?php endforeach; ?>
    const csv = rows.map(r => r.map(c => '"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = 'student_credentials_<?= date('Ymd') ?>.csv';
    a.click();
}
window.addEventListener('beforeprint', () => document.getElementById('printSlips').style.display='block');
window.addEventListener('afterprint',  () => document.getElementById('printSlips').style.display='none');
</script>

<?php else: ?>
<!-- ── Preview: students without accounts ─────────────────── -->
<?php if (!$students): ?>
<div class="card">
  <div class="empty-state">
    <div class="empty-icon">✅</div>
    <div class="empty-title">All students have login accounts</div>
    <div class="empty-desc"><a href="/educore/modules/students/" class="btn btn-primary btn-sm" style="margin-top:12px;">Back to Students</a></div>
  </div>
</div>
<?php else: ?>

<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <span class="card-title">Students Without Login Accounts (<?= count($students) ?>)</span>
  </div>
  <div class="card-body no-padding">
    <table class="data-table">
      <thead><tr><th>Student</th><th>Code</th><th>Contact</th><th>Groups</th></tr></thead>
      <tbody>
        <?php foreach ($students as $s):
          $colors = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4'];
          $c = $colors[abs(crc32($s['name'])) % count($colors)];
          $gc = $pdo->prepare("SELECT COUNT(*) FROM group_students WHERE student_id=? AND status='active'");
          $gc->execute([$s['id']]); $gcnt = $gc->fetchColumn();
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,<?=$c?>,<?=$c?>99);color:#fff;font-size:0.68rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?= strtoupper(substr($s['name'],0,2)) ?></div>
              <span class="cell-primary"><?= sanitize($s['name']) ?></span>
            </div>
          </td>
          <td><span style="font-family:monospace;color:var(--accent);font-size:0.82rem;">#<?= sanitize($s['student_code']) ?></span></td>
          <td style="font-size:0.82rem;color:var(--text-muted);"><?= sanitize($s['email'] ?: $s['phone'] ?: '—') ?></td>
          <td><?= $gcnt > 0 ? "<span class='badge badge-info'>$gcnt group".($gcnt>1?'s':'')."</span>" : '<span style="color:var(--text-muted);font-size:0.8rem;">None</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="border-color:rgba(245,166,35,0.3);">
  <div class="card-body">
    <div style="display:flex;align-items:flex-start;gap:20px;">
      <div style="flex:1;">
        <div style="font-family:var(--font-display);font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:6px;">
          Generate accounts for all <?= count($students) ?> students
        </div>
        <div style="font-size:0.83rem;color:var(--text-secondary);line-height:1.6;margin-bottom:0;">
          Each student will get a unique username (<code style="font-size:0.78rem;background:var(--bg-elevated);padding:1px 5px;border-radius:3px;">firstname<?= $cid ?>@student.local</code>) and a random 8-character password.
          Credentials will be shown on screen to print or export as CSV.
        </div>
      </div>
      <form method="POST" style="flex-shrink:0;">
        <?= csrf_field() ?><input type="hidden" name="action" value="generate_all">
        <button type="submit" class="btn btn-primary btn-lg"
                onclick="return confirm('Create <?= count($students) ?> login accounts now?')">
          🔑 Create <?= count($students) ?> Account<?= count($students)>1?'s':'' ?> →
        </button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
