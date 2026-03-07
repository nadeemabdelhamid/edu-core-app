<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pdo = getDB();
$cid = centerId();
$sid = (int)($_GET['id'] ?? 0);
if (!$sid) redirect('/educore/modules/students/');

// Load student + linked user
$stmt = $pdo->prepare("
    SELECT s.*, u.id AS uid, u.email AS login_email, u.is_active AS login_active, u.last_login
    FROM students s
    LEFT JOIN users u ON u.id = s.user_id
    WHERE s.id = ? AND s.center_id = ?
");
$stmt->execute([$sid, $cid]);
$student = $stmt->fetch();
if (!$student) { flash('error', 'Student not found.'); redirect('/educore/modules/students/'); }

$pageTitle  = 'Credentials — ' . sanitize($student['name']);
$activePage = 'students';
$breadcrumbs = [
    ['label' => 'Dashboard',  'href' => '/educore/dashboard.php'],
    ['label' => 'Students',   'href' => '/educore/modules/students/'],
    ['label' => sanitize($student['name']), 'href' => 'view.php?id=' . $sid],
    ['label' => 'Credentials'],
];

// ── Helpers ────────────────────────────────────────────────
function genPassword($len = 8) {
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
        $c = $pdo->prepare("SELECT id FROM users WHERE email=?"); $c->execute([$email]);
        if (!$c->fetch()) break;
        $email = $base . $cid . '_' . $i . '@student.local'; $i++;
    }
    return $email;
}

$newCreds = null;
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ── CREATE new account ──────────────────────────────
    if ($action === 'create') {
        if ($student['uid']) { flash('error', 'Account already exists.'); redirect("credentials.php?id=$sid"); }

        $customUser = trim($_POST['custom_username'] ?? '');
        $customPass = trim($_POST['custom_password'] ?? '');

        if ($customUser) {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=?"); $chk->execute([$customUser]);
            if ($chk->fetch()) $errors[] = 'That username is already taken.';
        }

        if (!$errors) {
            $username = $customUser ?: genUsername($student['name'], $cid, $pdo);
            $password = $customPass ?: genPassword();
            $hashed   = password_hash($password, PASSWORD_BCRYPT);

            $pdo->prepare("INSERT INTO users (center_id,name,email,password,role,is_active) VALUES (?,?,?,?,'student',1)")
                ->execute([$cid, $student['name'], $username, $hashed]);
            $uid = $pdo->lastInsertId();
            $pdo->prepare("UPDATE students SET user_id=? WHERE id=?")->execute([$uid, $sid]);

            $newCreds = ['username' => $username, 'password' => $password];
            auditLog('student.account_created', 'student', $sid, ['login' => $username]);

            // Reload student
            $stmt->execute([$sid, $cid]); $student = $stmt->fetch();
            flash('success', 'Login account created successfully.');
        }
    }

    // ── RESET password ──────────────────────────────────
    if ($action === 'reset_password') {
        if (!$student['uid']) { flash('error', 'No account exists.'); redirect("credentials.php?id=$sid"); }

        $customPass = trim($_POST['new_password'] ?? '');
        $password   = $customPass ?: genPassword();
        $hashed     = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password=?, updated_at=NOW() WHERE id=? AND center_id=?")
            ->execute([$hashed, $student['uid'], $cid]);

        $newCreds = ['username' => $student['login_email'], 'password' => $password];
        auditLog('student.password_reset', 'student', $sid, ['by' => authId()]);
        flash('success', 'Password reset successfully.');
        $stmt->execute([$sid, $cid]); $student = $stmt->fetch();
    }

    // ── CHANGE username ─────────────────────────────────
    if ($action === 'change_username') {
        if (!$student['uid']) { flash('error', 'No account exists.'); redirect("credentials.php?id=$sid"); }

        $newUser = trim($_POST['new_username'] ?? '');
        if (!$newUser) { $errors[] = 'Username cannot be empty.'; }
        else {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?"); $chk->execute([$newUser, $student['uid']]);
            if ($chk->fetch()) $errors[] = 'That username is already taken by another account.';
        }
        if (!$errors) {
            $pdo->prepare("UPDATE users SET email=?, updated_at=NOW() WHERE id=? AND center_id=?")->execute([$newUser, $student['uid'], $cid]);
            auditLog('student.username_changed', 'student', $sid, ['new_login' => $newUser]);
            flash('success', 'Username updated.');
            redirect("credentials.php?id=$sid");
        }
        $stmt->execute([$sid, $cid]); $student = $stmt->fetch();
    }

    // ── TOGGLE account active ───────────────────────────
    if ($action === 'toggle_active') {
        if ($student['uid']) {
            $newState = $student['login_active'] ? 0 : 1;
            $pdo->prepare("UPDATE users SET is_active=? WHERE id=? AND center_id=?")->execute([$newState, $student['uid'], $cid]);
            flash('success', $newState ? 'Account re-enabled.' : 'Account suspended.');
            redirect("credentials.php?id=$sid");
        }
    }

    // ── DELETE account ──────────────────────────────────
    if ($action === 'delete_account') {
        if ($student['uid']) {
            $pdo->prepare("UPDATE students SET user_id=NULL WHERE id=?")->execute([$sid]);
            $pdo->prepare("DELETE FROM users WHERE id=? AND center_id=?")->execute([$student['uid'], $cid]);
            auditLog('student.account_deleted', 'student', $sid);
            flash('success', 'Login account removed.');
            redirect("credentials.php?id=$sid");
        }
    }
}

// Pull new creds from session (redirected from create.php)
if (!$newCreds && !empty($_SESSION['new_student_creds'])) {
    $newCreds = $_SESSION['new_student_creds'];
    unset($_SESSION['new_student_creds']);
}

$colors = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4'];
$color  = $colors[abs(crc32($student['name'])) % count($colors)];
$hasAccount = !empty($student['uid']);

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:14px;">
    <div class="avatar" style="width:44px;height:44px;border-radius:var(--radius-md);background:linear-gradient(135deg,<?=$color?>,<?=$color?>99);color:#fff;font-weight:700;font-size:1rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <?= strtoupper(substr($student['name'],0,2)) ?>
    </div>
    <div>
      <h1 class="page-title"><?= sanitize($student['name']) ?></h1>
      <p class="page-subtitle">Login Credentials Management</p>
    </div>
  </div>
  <a href="view.php?id=<?= $sid ?>" class="btn btn-ghost">← Back to Profile</a>
</div>

<?php if ($errors): ?>
<div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-md);padding:14px 18px;font-size:0.84rem;color:var(--danger);margin-bottom:20px;">
  <?php foreach ($errors as $e): ?><div>• <?= sanitize($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── NEW CREDENTIALS CARD (shown once after create/reset) ── -->
<?php if ($newCreds): ?>
<div style="background:linear-gradient(135deg,rgba(245,166,35,0.12),rgba(245,166,35,0.04));border:2px solid var(--accent);border-radius:var(--radius-lg);padding:28px;margin-bottom:24px;" id="credsCard">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
    <span style="font-size:2rem;">🔑</span>
    <div>
      <div style="font-family:var(--font-display);font-size:1.1rem;font-weight:700;color:var(--text-primary);">Student Login Credentials</div>
      <div style="font-size:0.78rem;color:var(--text-muted);">Share these with the student. This is the only time the password is shown in plain text.</div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
    <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-md);padding:16px;">
      <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:6px;">Username / Login</div>
      <div style="font-family:monospace;font-size:1rem;font-weight:700;color:var(--accent);word-break:break-all;" id="credUser"><?= sanitize($newCreds['username']) ?></div>
    </div>
    <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-md);padding:16px;">
      <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:6px;">Password</div>
      <div style="font-family:monospace;font-size:1.4rem;font-weight:800;color:var(--text-primary);letter-spacing:0.15em;" id="credPass"><?= sanitize($newCreds['password']) ?></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
    <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-md);padding:14px;">
      <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:4px;">Student Name</div>
      <div style="font-weight:600;color:var(--text-primary);"><?= sanitize($student['name']) ?></div>
    </div>
    <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-md);padding:14px;">
      <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:4px;">Student Code</div>
      <div style="font-weight:700;color:var(--accent);font-family:monospace;font-size:1.1rem;">#<?= sanitize($student['student_code']) ?></div>
    </div>
  </div>

  <div style="display:flex;gap:10px;margin-top:20px;padding-top:20px;border-top:1px solid var(--border);">
    <button onclick="printCreds()" class="btn btn-primary">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print / Save
    </button>
    <button onclick="copyCreds()" class="btn btn-ghost">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
      Copy to Clipboard
    </button>
    <div style="margin-left:auto;font-size:0.75rem;color:var(--text-muted);display:flex;align-items:center;gap:4px;">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Password won't be shown again
    </div>
  </div>
</div>

<!-- Hidden printable version -->
<div id="printArea" style="display:none;">
  <div style="font-family:Arial,sans-serif;padding:30px;max-width:400px;border:2px solid #f5a623;border-radius:8px;">
    <h2 style="margin:0 0 4px;font-size:1.1rem;">EduCore — Student Login</h2>
    <p style="margin:0 0 20px;font-size:0.8rem;color:#666;">Keep this card safe</p>
    <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
      <tr><td style="padding:8px;color:#666;width:40%;">Name</td><td style="padding:8px;font-weight:600;"><?= sanitize($student['name']) ?></td></tr>
      <tr style="background:#f9f9f9;"><td style="padding:8px;color:#666;">Code</td><td style="padding:8px;font-weight:600;font-family:monospace;">#<?= sanitize($student['student_code']) ?></td></tr>
      <tr><td style="padding:8px;color:#666;">Username</td><td style="padding:8px;font-family:monospace;font-size:0.85rem;"><?= sanitize($newCreds['username']) ?></td></tr>
      <tr style="background:#f9f9f9;"><td style="padding:8px;color:#666;">Password</td><td style="padding:8px;font-family:monospace;font-size:1.1rem;font-weight:700;letter-spacing:2px;"><?= sanitize($newCreds['password']) ?></td></tr>
      <tr><td style="padding:8px;color:#666;">Login URL</td><td style="padding:8px;font-size:0.8rem;">http://localhost/educore/login.php</td></tr>
    </table>
  </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;">
<div>

<?php if ($hasAccount): ?>
<!-- ── ACCOUNT STATUS ─────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;<?= !$student['login_active'] ? 'border-color:rgba(239,68,68,0.3);' : 'border-color:rgba(34,197,94,0.2);' ?>">
  <div class="card-header">
    <span class="card-title">Account Status</span>
    <span class="badge <?= $student['login_active'] ? 'badge-success' : 'badge-danger' ?>"><?= $student['login_active'] ? '● Active' : '✗ Suspended' ?></span>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">
      <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);padding:14px;text-align:center;">
        <div style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:6px;">Username</div>
        <div style="font-family:monospace;font-size:0.82rem;color:var(--accent);word-break:break-all;font-weight:600;"><?= sanitize($student['login_email']) ?></div>
      </div>
      <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);padding:14px;text-align:center;">
        <div style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:6px;">Password</div>
        <div style="font-size:1.2rem;color:var(--text-muted);">••••••••</div>
      </div>
      <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);padding:14px;text-align:center;">
        <div style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:6px;">Last Login</div>
        <div style="font-size:0.82rem;font-weight:600;color:var(--text-primary);"><?= $student['last_login'] ? date('M j, Y', strtotime($student['last_login'])) : 'Never' ?></div>
      </div>
    </div>
  </div>
</div>

<!-- ── CHANGE USERNAME ───────────────────────────────── -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">Change Username</span></div>
  <div class="card-body">
    <form method="POST">
      <?= csrf_field() ?><input type="hidden" name="action" value="change_username">
      <div style="display:flex;gap:10px;align-items:flex-end;">
        <div class="form-group" style="flex:1;margin:0;">
          <label class="form-label">New Username / Login</label>
          <input type="text" name="new_username" class="form-control"
                 value="<?= sanitize($student['login_email']) ?>" required>
        </div>
        <button type="submit" class="btn btn-ghost">Update</button>
      </div>
    </form>
  </div>
</div>

<!-- ── RESET PASSWORD ────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <span class="card-title">Reset Password</span>
    <span style="font-size:0.75rem;color:var(--text-muted);">New credentials will be shown on screen</span>
  </div>
  <div class="card-body">
    <form method="POST">
      <?= csrf_field() ?><input type="hidden" name="action" value="reset_password">
      <div style="display:flex;gap:10px;align-items:flex-end;">
        <div class="form-group" style="flex:1;margin:0;">
          <label class="form-label">New Password <span style="color:var(--text-muted);">(leave blank to auto-generate)</span></label>
          <div style="display:flex;gap:8px;">
            <input type="text" name="new_password" id="newPwdField" class="form-control" placeholder="Leave blank to generate…">
            <button type="button" onclick="genPwd('newPwdField')" class="btn btn-ghost btn-sm" style="flex-shrink:0;">Generate</button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary" onclick="return confirm('Reset this student\'s password?')">Reset</button>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ── CREATE ACCOUNT ────────────────────────────────── -->
<div class="card" style="border-color:rgba(245,166,35,0.3);">
  <div class="card-header" style="background:var(--accent-subtle);">
    <span class="card-title" style="color:var(--accent);">🔑 Create Login Account</span>
  </div>
  <div class="card-body">
    <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);padding:14px;font-size:0.82rem;color:var(--text-secondary);margin-bottom:20px;line-height:1.6;">
      This student has no login account yet. Creating one will let them:<br>
      <strong style="color:var(--text-primary);">· Submit attendance</strong> using a session code<br>
      <strong style="color:var(--text-primary);">· Take exams</strong> from their student portal<br>
      <strong style="color:var(--text-primary);">· View their results</strong> and attendance history
    </div>
    <form method="POST">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="form-group">
        <label class="form-label">Username / Login <span style="color:var(--text-muted);">(leave blank to auto-generate)</span></label>
        <input type="text" name="custom_username" id="custUser" class="form-control"
               placeholder="e.g. ahmed@student.local or leave blank">
      </div>
      <div class="form-group">
        <label class="form-label">Password <span style="color:var(--text-muted);">(leave blank to auto-generate)</span></label>
        <div style="display:flex;gap:8px;">
          <input type="text" name="custom_password" id="custPass" class="form-control" placeholder="Leave blank to auto-generate">
          <button type="button" onclick="genPwd('custPass')" class="btn btn-ghost btn-sm" style="flex-shrink:0;">Generate</button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-full btn-lg">Create Login Account →</button>
    </form>
  </div>
</div>
<?php endif; ?>

</div>

<!-- Right sidebar -->
<div>
  <!-- Student info card -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header"><span class="card-title">Student Info</span></div>
    <div class="card-body" style="font-size:0.82rem;">
      <?php foreach ([
        'Code'   => '#' . sanitize($student['student_code']),
        'Phone'  => sanitize($student['phone'] ?: '—'),
        'Email'  => sanitize($student['email'] ?: '—'),
      ] as $k => $v): ?>
      <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);">
        <span style="color:var(--text-muted);font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;"><?= $k ?></span>
        <span style="font-weight:500;color:var(--text-primary);"><?= $v ?></span>
      </div>
      <?php endforeach; ?>
      <a href="view.php?id=<?= $sid ?>" class="btn btn-ghost w-full btn-sm" style="margin-top:14px;">View Full Profile</a>
    </div>
  </div>

  <!-- Login URL hint -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header"><span class="card-title">Student Login URL</span></div>
    <div class="card-body" style="font-size:0.82rem;">
      <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);padding:10px 12px;font-family:monospace;font-size:0.78rem;color:var(--accent);word-break:break-all;margin-bottom:10px;">
        http://localhost/educore/login.php
      </div>
      <button onclick="navigator.clipboard.writeText('http://localhost/educore/login.php')" class="btn btn-ghost btn-sm w-full">Copy URL</button>
    </div>
  </div>

  <?php if ($hasAccount): ?>
  <!-- Danger zone -->
  <div class="card" style="border-color:rgba(239,68,68,0.2);">
    <div class="card-header"><span class="card-title" style="color:var(--danger);">Account Actions</span></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:10px;">
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle_active">
        <button type="submit" class="btn w-full btn-sm" style="background:var(--warning-dim);color:var(--warning);border-color:rgba(245,158,11,0.3);"
                onclick="return confirm('<?= $student['login_active'] ? 'Suspend' : 'Re-enable' ?> this account?')">
          <?= $student['login_active'] ? '⏸ Suspend Account' : '▶ Re-enable Account' ?>
        </button>
      </form>
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete_account">
        <button type="submit" class="btn btn-danger w-full btn-sm"
                onclick="return confirm('Delete login account? The student will no longer be able to log in. Student record is kept.')">
          Delete Login Account
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
</div>

<!-- Printable credential slip (hidden, triggered by JS) -->
<div id="printSlip" style="display:none;position:fixed;inset:0;background:#fff;z-index:9999;padding:40px;font-family:Arial,sans-serif;">
  <div style="max-width:380px;margin:0 auto;border:2px solid #f5a623;border-radius:10px;padding:28px;">
    <div style="text-align:center;margin-bottom:20px;">
      <div style="font-size:1.8rem;margin-bottom:4px;">🎓</div>
      <h2 style="margin:0;font-size:1.2rem;color:#1a1a1a;">EduCore Student Login</h2>
      <p style="margin:4px 0 0;font-size:0.75rem;color:#888;">Keep this card safe — do not share your password</p>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:0.88rem;">
      <tr style="border-bottom:1px solid #eee;"><td style="padding:10px 0;color:#666;width:35%;">Student</td><td style="padding:10px 0;font-weight:600;" id="ps_name"></td></tr>
      <tr style="border-bottom:1px solid #eee;"><td style="padding:10px 0;color:#666;">Code</td><td style="padding:10px 0;font-family:monospace;font-weight:700;color:#f5a623;font-size:1rem;" id="ps_code">#<?= sanitize($student['student_code']) ?></td></tr>
      <tr style="border-bottom:1px solid #eee;"><td style="padding:10px 0;color:#666;">Username</td><td style="padding:10px 0;font-family:monospace;font-size:0.78rem;word-break:break-all;" id="ps_user"></td></tr>
      <tr style="border-bottom:1px solid #eee;background:#fffbf0;"><td style="padding:12px 0;color:#666;">Password</td><td style="padding:12px 0;font-family:monospace;font-size:1.3rem;font-weight:800;letter-spacing:3px;" id="ps_pass"></td></tr>
      <tr><td style="padding:10px 0;color:#666;">Login URL</td><td style="padding:10px 0;font-size:0.72rem;color:#555;">http://localhost/educore/login.php</td></tr>
    </table>
    <div style="margin-top:20px;text-align:center;">
      <button onclick="window.print()" style="background:#f5a623;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-size:0.9rem;font-weight:600;cursor:pointer;">🖨 Print</button>
      <button onclick="closePrint()" style="background:#eee;color:#333;border:none;padding:10px 24px;border-radius:6px;font-size:0.9rem;margin-left:10px;cursor:pointer;">Close</button>
    </div>
  </div>
</div>

<script>
function genPwd(fieldId) {
    const c = 'abcdefghjkmnpqrstuvwxyz23456789';
    let p = '';
    for (let i = 0; i < 8; i++) p += c[Math.floor(Math.random() * c.length)];
    document.getElementById(fieldId).value = p;
}

function printCreds() {
    const user = document.getElementById('credUser')?.textContent || '<?= sanitize($student['login_email'] ?? '') ?>';
    const pass = document.getElementById('credPass')?.textContent || '';
    document.getElementById('ps_name').textContent = '<?= sanitize($student['name']) ?>';
    document.getElementById('ps_user').textContent = user;
    document.getElementById('ps_pass').textContent = pass;
    document.getElementById('printSlip').style.display = 'block';
}

function closePrint() {
    document.getElementById('printSlip').style.display = 'none';
}

function copyCreds() {
    const user = document.getElementById('credUser')?.textContent || '';
    const pass = document.getElementById('credPass')?.textContent || '';
    const text = `Student: <?= sanitize($student['name']) ?>\nCode: #<?= sanitize($student['student_code']) ?>\nUsername: ${user}\nPassword: ${pass}\nLogin: http://localhost/educore/login.php`;
    navigator.clipboard.writeText(text).then(() => {
        const btn = event.target.closest('button');
        const orig = btn.innerHTML;
        btn.innerHTML = '✓ Copied!';
        setTimeout(() => btn.innerHTML = orig, 2000);
    });
}
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
