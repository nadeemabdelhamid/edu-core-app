<?php
require_once __DIR__ . '/includes/helpers.php';

if (auth()) { header('Location: /educore/dashboard.php'); exit; }

$errors = [];
$data = [];
$branches = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'center_name'    => trim($_POST['center_name'] ?? ''),
        'center_address' => trim($_POST['center_address'] ?? ''),
        'center_phone'   => trim($_POST['center_phone'] ?? ''),
        'name'           => trim($_POST['name'] ?? ''),
        'email'          => trim($_POST['email'] ?? ''),
        'phone'          => trim($_POST['phone'] ?? ''),
        'password'       => $_POST['password'] ?? '',
        'password2'      => $_POST['password2'] ?? '',
    ];

    // Collect branches
    $branchNames    = $_POST['branch_name'] ?? [];
    $branchAddrs    = $_POST['branch_address'] ?? [];
    $branchPhones   = $_POST['branch_phone'] ?? [];
    $branches = [];
    foreach ($branchNames as $i => $bName) {
        $bName = trim($bName);
        if ($bName !== '') {
            $branches[] = [
                'name'    => $bName,
                'address' => trim($branchAddrs[$i] ?? ''),
                'phone'   => trim($branchPhones[$i] ?? ''),
            ];
        }
    }

    if (!$data['center_name']) $errors[] = 'Center name is required.';
    if (!$data['name'])        $errors[] = 'Your name is required.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
    if (strlen($data['password']) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($data['password'] !== $data['password2']) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $pdo = getDB();
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$data['email']]);
        if ($check->fetch()) {
            $errors[] = 'Email already registered.';
        }
    }

    if (!$errors) {
        $pdo = getDB();
        try {
            $pdo->beginTransaction();

            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $data['center_name']));
            $slug = trim($slug, '-') . '-' . substr(uniqid(), -5);

            // Build branches JSON
            $branchesJson = count($branches) ? json_encode($branches) : null;

            $pdo->prepare("INSERT INTO centers (name, slug, email, phone, address, branches, status) VALUES (?,?,?,?,?,?,'pending')")
                ->execute([$data['center_name'], $slug, $data['email'], $data['center_phone'], $data['center_address'], $branchesJson]);
            $centerId = $pdo->lastInsertId();

            $hashed = password_hash($data['password'], PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (center_id, name, email, password, role, is_active) VALUES (?,?,?,?,'owner',0)")
                ->execute([$centerId, $data['name'], $data['email'], $hashed]);
            $userId = $pdo->lastInsertId();

            $planStmt = $pdo->prepare("SELECT id FROM plans WHERE slug='starter' LIMIT 1");
            $planStmt->execute();
            $planId = $planStmt->fetchColumn();
            if ($planId) {
                $pdo->prepare("INSERT INTO subscriptions (center_id, plan_id, status, starts_at, expires_at, trial_ends_at)
                                VALUES (?,?,'pending',NOW(),DATE_ADD(NOW(), INTERVAL 14 DAY),DATE_ADD(NOW(), INTERVAL 14 DAY))")
                    ->execute([$centerId, $planId]);
            }

            $pdo->commit();

            flash('success', 'pending_approval');
            header('Location: /educore/register.php?registered=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Registration failed. Please try again. ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account — EduCore</title>
  <link rel="stylesheet" href="/educore/assets/css/app.css">
  <style>
    body {
      display: flex; align-items: flex-start; justify-content: center;
      min-height: 100vh; padding: 30px 20px;
      background: var(--bg-base);
    }
    body::before {
      content: '';
      position: fixed;
      top: -20%; right: -10%;
      width: 600px; height: 600px;
      background: radial-gradient(ellipse, rgba(245,166,35,0.05) 0%, transparent 70%);
      pointer-events: none;
    }
    .register-wrap {
      max-width: 700px;
      width: 100%;
      position: relative;
      z-index: 1;
    }
    .register-logo {
      display: flex; align-items: center; gap: 10px;
      margin-bottom: 32px;
      text-decoration: none;
    }
    .logo-icon {
      width: 38px; height: 38px;
      background: linear-gradient(135deg, var(--accent), #e0751f);
      border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px;
      box-shadow: 0 0 20px rgba(245,166,35,0.25);
    }
    .logo-text {
      font-family: var(--font-display);
      font-size: 1.2rem; font-weight: 800;
      color: var(--text-primary);
      letter-spacing: -0.03em;
    }
    .logo-text span { color: var(--accent); }
    .register-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius-xl);
      padding: 36px 40px;
      box-shadow: var(--shadow-lg);
    }
    .register-title {
      font-family: var(--font-display);
      font-size: 1.5rem; font-weight: 700;
      color: var(--text-primary);
      letter-spacing: -0.03em;
      margin-bottom: 6px;
    }
    .register-sub {
      font-size: 0.84rem;
      color: var(--text-muted);
      margin-bottom: 28px;
    }
    .section-divider {
      display: flex; align-items: center; gap: 10px;
      margin: 22px 0 18px;
    }
    .section-divider::before, .section-divider::after {
      content: ''; flex: 1; height: 1px; background: var(--border);
    }
    .section-divider span {
      font-size: 0.68rem; font-weight: 700;
      letter-spacing: 0.1em; text-transform: uppercase;
      color: var(--text-muted); padding: 0 4px;
    }
    .trial-badge {
      display: inline-flex; align-items: center; gap: 5px;
      background: var(--accent-subtle);
      border: 1px solid rgba(245,166,35,0.25);
      border-radius: 20px;
      padding: 4px 12px;
      font-size: 0.72rem; font-weight: 700;
      color: var(--accent);
      margin-bottom: 20px;
    }
    .login-redirect {
      text-align: center;
      margin-top: 20px;
      font-size: 0.82rem;
      color: var(--text-muted);
    }
    .login-redirect a { color: var(--accent); text-decoration: none; font-weight: 600; }
    .branch-row {
      background: var(--bg-elevated);
      border: 1px solid var(--border);
      border-radius: var(--radius-md);
      padding: 14px 16px;
      margin-bottom: 10px;
      position: relative;
    }
    .branch-row .remove-branch {
      position: absolute; top: 10px; right: 10px;
      background: none; border: none; cursor: pointer;
      color: var(--danger); font-size: 1rem; line-height: 1;
      padding: 2px 6px; border-radius: 4px;
    }
    .branch-row .remove-branch:hover { background: var(--danger-dim); }
  </style>
</head>
<body>
<div class="register-wrap">

  <a href="/educore/login.php" class="register-logo">
    <div class="logo-icon">🎓</div>
    <span class="logo-text">Edu<span>Core</span></span>
  </a>

  <div class="register-card">
    <div class="trial-badge">
      ✨ 14-day free trial • No credit card required
    </div>

    <h1 class="register-title">Create your center account</h1>
    <p class="register-sub">Get started in 60 seconds</p>

    <?php if (isset($_GET['registered'])): ?>
    <div style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-radius:var(--radius-sm);padding:20px 22px;font-size:0.88rem;color:var(--success);margin-bottom:20px;text-align:center;">
      <div style="font-size:1.5rem;margin-bottom:8px;">🎉</div>
      <div style="font-weight:700;font-size:1rem;margin-bottom:6px;">Registration submitted!</div>
      <div style="color:var(--text-secondary);">Your account is <strong>pending review</strong> by our team. You will be able to log in once approved.<br>We typically review within 24 hours.</div>
    </div>
    <?php elseif ($errors): ?>
    <div style="background:var(--danger-dim); border:1px solid rgba(239,68,68,0.25); border-radius:var(--radius-sm); padding:12px 14px; font-size:0.82rem; color:var(--danger); margin-bottom:20px;">
      <?php foreach ($errors as $e): ?>
        <div>• <?= sanitize($e) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="">
      <?= csrf_field() ?>

      <!-- ── CENTER INFO ── -->
      <div class="section-divider"><span>Center Info</span></div>

      <div class="form-group">
        <label class="form-label required">Center / Academy Name</label>
        <input type="text" name="center_name" class="form-control"
               placeholder="e.g. Bright Minds Academy"
               value="<?= sanitize($data['center_name'] ?? '') ?>" required>
      </div>

      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label">Main Address</label>
          <input type="text" name="center_address" class="form-control"
                 placeholder="e.g. 12 Nile St, Cairo"
                 value="<?= sanitize($data['center_address'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Center Phone</label>
          <input type="tel" name="center_phone" class="form-control"
                 placeholder="+20 100 000 0000"
                 value="<?= sanitize($data['center_phone'] ?? '') ?>">
        </div>
      </div>

      <!-- ── BRANCHES ── -->
      <div class="section-divider"><span>Branches <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></span></div>

      <div id="branchesContainer">
        <?php if (!empty($branches)): ?>
          <?php foreach ($branches as $i => $b): ?>
          <div class="branch-row" id="branch_<?= $i ?>">
            <button type="button" class="remove-branch" onclick="removeBranch(<?= $i ?>)" title="Remove branch">✕</button>
            <div class="form-row cols-3" style="grid-template-columns:1fr 1fr 1fr;">
              <div class="form-group" style="margin:0 0 10px;">
                <label class="form-label" style="font-size:0.75rem;">Branch Name</label>
                <input type="text" name="branch_name[]" class="form-control form-control-sm"
                       placeholder="e.g. Cairo Branch"
                       value="<?= sanitize($b['name']) ?>">
              </div>
              <div class="form-group" style="margin:0 0 10px;">
                <label class="form-label" style="font-size:0.75rem;">Address</label>
                <input type="text" name="branch_address[]" class="form-control form-control-sm"
                       placeholder="Branch address"
                       value="<?= sanitize($b['address']) ?>">
              </div>
              <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.75rem;">Phone</label>
                <input type="tel" name="branch_phone[]" class="form-control form-control-sm"
                       placeholder="+20 100 000 0000"
                       value="<?= sanitize($b['phone']) ?>">
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <button type="button" onclick="addBranch()" class="btn btn-ghost btn-sm" style="margin-bottom:8px;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Branch
      </button>

      <!-- ── YOUR ACCOUNT ── -->
      <div class="section-divider"><span>Your Account</span></div>

      <div class="form-group">
        <label class="form-label required">Your Full Name</label>
        <input type="text" name="name" class="form-control"
               placeholder="Ahmed Mohamed"
               value="<?= sanitize($data['name'] ?? '') ?>" required>
      </div>

      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label required">Email</label>
          <input type="email" name="email" class="form-control"
                 placeholder="you@example.com"
                 value="<?= sanitize($data['email'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Personal Phone</label>
          <input type="tel" name="phone" class="form-control"
                 placeholder="+20 100 000 0000"
                 value="<?= sanitize($data['phone'] ?? '') ?>">
        </div>
      </div>

      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label required">Password</label>
          <input type="password" name="password" class="form-control"
                 placeholder="Min. 8 characters" required minlength="8">
        </div>
        <div class="form-group">
          <label class="form-label required">Confirm Password</label>
          <input type="password" name="password2" class="form-control"
                 placeholder="Repeat password" required>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-full btn-lg" style="margin-top:8px;">
        Create Account & Start Trial →
      </button>
    </form>

    <div class="login-redirect">
      Already have an account? <a href="/educore/login.php">Sign in</a>
    </div>
  </div>

</div>

<script>
let branchCount = <?= count($branches) ?>;

function addBranch() {
  const id = branchCount++;
  const div = document.createElement('div');
  div.className = 'branch-row';
  div.id = 'branch_' + id;
  div.innerHTML =
    '<button type="button" class="remove-branch" onclick="removeBranch(' + id + ')" title="Remove">✕</button>' +
    '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">' +
      '<div class="form-group" style="margin:0;">' +
        '<label class="form-label" style="font-size:0.75rem;">Branch Name</label>' +
        '<input type="text" name="branch_name[]" class="form-control" placeholder="e.g. Alexandria Branch">' +
      '</div>' +
      '<div class="form-group" style="margin:0;">' +
        '<label class="form-label" style="font-size:0.75rem;">Address</label>' +
        '<input type="text" name="branch_address[]" class="form-control" placeholder="Branch address">' +
      '</div>' +
      '<div class="form-group" style="margin:0;">' +
        '<label class="form-label" style="font-size:0.75rem;">Phone</label>' +
        '<input type="tel" name="branch_phone[]" class="form-control" placeholder="+20 100 000 0000">' +
      '</div>' +
    '</div>';
  document.getElementById('branchesContainer').appendChild(div);
  div.querySelector('input').focus();
}

function removeBranch(id) {
  const el = document.getElementById('branch_' + id);
  if (el) el.remove();
}
</script>
</body>
</html>
