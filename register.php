<?php
require_once __DIR__ . '/includes/helpers.php';

if (auth()) { header('Location: /educore/dashboard.php'); exit; }

$errors   = [];
$data     = [];
$branches = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $data = [
        'center_name'    => trim($_POST['center_name']    ?? ''),
        'center_address' => trim($_POST['center_address'] ?? ''),
        'center_phone'   => trim($_POST['center_phone']   ?? ''),
        'name'           => trim($_POST['name']           ?? ''),
        'email'          => trim(strtolower($_POST['email'] ?? '')),
        'phone'          => trim($_POST['phone']          ?? ''),
        'password'       => $_POST['password']  ?? '',
        'password2'      => $_POST['password2'] ?? '',
    ];

    // Collect branch rows
    $branchNames  = $_POST['branch_name']    ?? [];
    $branchAddrs  = $_POST['branch_address'] ?? [];
    $branchPhones = $_POST['branch_phone']   ?? [];
    $branches = [];
    foreach ($branchNames as $i => $bName) {
        $bName = trim($bName);
        if ($bName !== '') {
            $branches[] = [
                'name'    => $bName,
                'address' => trim($branchAddrs[$i]  ?? ''),
                'phone'   => trim($branchPhones[$i] ?? ''),
            ];
        }
    }

    // Validate
    if (!$data['center_name']) $errors[] = 'Center name is required.';
    if (!$data['name'])        $errors[] = 'Your name is required.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
    if (strlen($data['password']) < 8)             $errors[] = 'Password must be at least 8 characters.';
    if ($data['password'] !== $data['password2'])  $errors[] = 'Passwords do not match.';
    if (count($branches) > 10)                     $errors[] = 'Maximum 10 branches allowed.';

    if (!$errors) {
        $pdo   = getDB();
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$data['email']]);
        if ($check->fetch()) $errors[] = 'Email already registered.';
    }

    if (!$errors) {
        $pdo = getDB();
        try {
            $pdo->beginTransaction();

            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $data['center_name']));
            $slug = trim($slug, '-') . '-' . substr(uniqid(), -5);

            $pdo->prepare("INSERT INTO centers (name, slug, email, phone, address, status) VALUES (?,?,?,?,?,'pending')")
                ->execute([$data['center_name'], $slug, $data['email'], $data['center_phone'], $data['center_address']]);
            $centerId = (int)$pdo->lastInsertId();

            $hashed = password_hash($data['password'], PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (center_id, name, email, password, role, is_active) VALUES (?,?,?,?,'owner',0)")
                ->execute([$centerId, $data['name'], $data['email'], $hashed]);

            // Save branches
            if (!empty($branches)) {
                try {
                    $brStmt = $pdo->prepare(
                        "INSERT INTO center_branches (center_id, name, address, phone, is_main, sort_order) VALUES (?,?,?,?,0,?)"
                    );
                    foreach ($branches as $idx => $br) {
                        $brStmt->execute([$centerId, $br['name'], $br['address'], $br['phone'], $idx]);
                    }
                } catch (Exception $be) {
                    // Fallback: store as JSON if center_branches table doesn't exist yet
                    $pdo->prepare("UPDATE centers SET branches = ? WHERE id = ?")
                        ->execute([json_encode($branches), $centerId]);
                }
            }

            // Assign individual plan trial
            $planStmt = $pdo->prepare("SELECT id FROM plans WHERE slug='individual' LIMIT 1");
            $planStmt->execute();
            $planId = $planStmt->fetchColumn();
            if ($planId) {
                $pdo->prepare(
                    "INSERT INTO subscriptions (center_id, plan_id, status, starts_at, expires_at, trial_ends_at)
                     VALUES (?,?,'trial',NOW(),DATE_ADD(NOW(), INTERVAL 14 DAY),DATE_ADD(NOW(), INTERVAL 14 DAY))"
                )->execute([$centerId, $planId]);
            }

            $pdo->commit();
            header('Location: /educore/register.php?registered=1');
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Registration failed. Please try again.';
            error_log('[EduCore register] ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account — EduCore</title>  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Geist:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/educore/assets/css/app.css">
  <style>
    body {
      display: flex; align-items: flex-start; justify-content: center;
      min-height: 100vh; padding: 30px 20px;
      background: var(--bg-base);
    }
    body::before {
      content: '';
      position: fixed; top: -20%; right: -10%;
      width: 600px; height: 600px;
      background: radial-gradient(ellipse, rgba(82,71,230,0.05) 0%, transparent 70%);
      pointer-events: none;
    }
    .register-wrap { max-width: 720px; width: 100%; position: relative; z-index: 1; }
    .register-logo  { display: flex; align-items: center; gap: 10px; margin-bottom: 32px; text-decoration: none; }
    .logo-icon {
      width: 38px; height: 38px;
      background: linear-gradient(135deg, var(--accent), #3d34c9);
      border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px;
      box-shadow: 0 0 20px rgba(82,71,230,0.25);
    }
    .logo-text { font-family: var(--font-display); font-size: 1.2rem; font-weight: 800; color: var(--text-primary); letter-spacing: -0.03em; }
    .logo-text span { color: var(--accent); }
    .register-card {
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: var(--radius-xl); padding: 36px 40px;
      box-shadow: var(--shadow-lg);
    }
    .register-title { font-family: var(--font-display); font-size: 1.5rem; font-weight: 700; color: var(--text-primary); letter-spacing: -0.03em; margin-bottom: 6px; }
    .register-sub   { font-size: 0.84rem; color: var(--text-muted); margin-bottom: 28px; }
    .section-divider { display: flex; align-items: center; gap: 10px; margin: 24px 0 18px; }
    .section-divider::before, .section-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
    .section-divider span { font-size: 0.68rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); padding: 0 4px; }
    .trial-badge {
      display: inline-flex; align-items: center; gap: 5px;
      background: var(--accent-subtle); border: 1px solid rgba(82,71,230,0.2);
      border-radius: 20px; padding: 4px 12px;
      font-size: 0.72rem; font-weight: 700; color: var(--accent); margin-bottom: 20px;
    }
    .login-redirect { text-align: center; margin-top: 20px; font-size: 0.82rem; color: var(--text-muted); }
    .login-redirect a { color: var(--accent); text-decoration: none; font-weight: 600; }

    /* Branches */
    .branch-row {
      background: var(--bg-elevated); border: 1px solid var(--border);
      border-radius: var(--radius-md); padding: 18px 16px 14px;
      margin-bottom: 10px; position: relative;
      animation: fadeSlideIn 0.2s ease;
    }
    @keyframes fadeSlideIn { from { opacity:0; transform:translateY(-5px); } to { opacity:1; transform:translateY(0); } }
    .branch-label {
      position: absolute; top: -10px; left: 14px;
      font-size: 0.63rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
      background: var(--accent); color: #fff;
      padding: 2px 10px; border-radius: 99px;
    }
    .branch-remove {
      position: absolute; top: 10px; right: 10px;
      background: none; border: none; cursor: pointer;
      color: var(--text-muted); font-size: 1rem; line-height: 1;
      padding: 3px 7px; border-radius: 4px; transition: all 0.12s;
    }
    .branch-remove:hover { color: var(--danger); background: var(--danger-dim); }
    .add-branch-btn {
      width: 100%; border: 2px dashed var(--border); background: transparent;
      color: var(--text-muted); border-radius: var(--radius-md);
      padding: 11px; font-size: 0.84rem; font-weight: 600; font-family: var(--font-body);
      cursor: pointer; transition: all 0.15s;
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .add-branch-btn:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-subtle); }
    .branches-meta { font-size: 0.74rem; color: var(--text-muted); margin-top: 7px; text-align: right; }

    @media (max-width: 640px) { .register-card { padding: 24px 18px; } }
  </style>
</head>
<body>
<div class="register-wrap">

  <a href="/educore/login.php" class="register-logo">
    <div class="logo-icon">🎓</div>
    <span class="logo-text">Edu<span>Core</span></span>
  </a>

  <div class="register-card">

    <div class="trial-badge">✨ 14-day free trial · No credit card required</div>
    <h1 class="register-title">Create your center account</h1>
    <p class="register-sub">Get started in minutes</p>

    <!-- Success -->
    <?php if (isset($_GET['registered'])): ?>
    <div style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-radius:var(--radius-md);padding:24px;text-align:center;">
      <div style="font-size:2rem;margin-bottom:10px;">🎉</div>
      <div style="font-weight:700;font-size:1rem;margin-bottom:8px;color:var(--success);">Registration submitted!</div>
      <div style="color:var(--text-secondary);font-size:0.86rem;line-height:1.7;">
        Your account is <strong>pending review</strong> by our team.<br>
        We'll notify you once approved — usually within 24 hours.
      </div>
    </div>
    <?php else: ?>

    <!-- Errors -->
    <?php if ($errors): ?>
    <div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-sm);padding:12px 14px;font-size:0.82rem;color:var(--danger);margin-bottom:20px;">
      <?php foreach ($errors as $e): ?><div>• <?= sanitize($e) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="">
      <?= csrf_field() ?>

      <!-- CENTER INFO -->
      <div class="section-divider"><span>Center Info</span></div>

      <div class="form-group">
        <label class="form-label required">Center / Academy Name</label>
        <input type="text" name="center_name" class="form-control"
               placeholder="e.g. Bright Minds Academy"
               value="<?= sanitize($data['center_name'] ?? '') ?>" required>
      </div>

      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label">Main Address <small style="font-weight:400;color:var(--text-muted);">Head office</small></label>
          <input type="text" name="center_address" class="form-control"
                 placeholder="12 Nile St, Cairo"
                 value="<?= sanitize($data['center_address'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Center Phone</label>
          <input type="tel" name="center_phone" class="form-control"
                 placeholder="+20 100 000 0000"
                 value="<?= sanitize($data['center_phone'] ?? '') ?>">
        </div>
      </div>

      <!-- BRANCHES -->
      <div class="section-divider">
        <span>Branches <small style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</small></span>
      </div>

      <p style="font-size:0.8rem;color:var(--text-muted);margin:-4px 0 14px;line-height:1.6;">
        Does your center operate from more than one location? Add each branch below.
        You can also manage branches later from Settings.
      </p>

      <div id="branchesContainer">
        <?php foreach ($branches as $i => $br): ?>
        <div class="branch-row" id="branch_<?= $i ?>">
          <span class="branch-label">Branch <?= $i + 1 ?></span>
          <button type="button" class="branch-remove" onclick="removeBranch(<?= $i ?>)" title="Remove branch">✕</button>
          <div class="form-row cols-2" style="margin-top:6px;">
            <div class="form-group" style="margin-bottom:10px;">
              <label class="form-label required" style="font-size:0.75rem;">Branch Name</label>
              <input type="text" name="branch_name[]" class="form-control"
                     placeholder="e.g. Nasr City Branch"
                     value="<?= sanitize($br['name']) ?>" required>
            </div>
            <div class="form-group" style="margin-bottom:10px;">
              <label class="form-label" style="font-size:0.75rem;">Phone</label>
              <input type="tel" name="branch_phone[]" class="form-control"
                     placeholder="+20 100 000 0000"
                     value="<?= sanitize($br['phone']) ?>">
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" style="font-size:0.75rem;">Address</label>
            <input type="text" name="branch_address[]" class="form-control"
                   placeholder="Street, district, city"
                   value="<?= sanitize($br['address']) ?>">
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <button type="button" class="add-branch-btn" id="addBranchBtn" onclick="addBranch()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        Add a Branch
      </button>
      <p class="branches-meta" id="branchesMeta"></p>

      <!-- YOUR ACCOUNT -->
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
        Create Account &amp; Start Trial →
      </button>
    </form>

    <?php endif; ?>

    <div class="login-redirect">
      Already have an account? <a href="/educore/login.php">Sign in</a>
    </div>
  </div>

</div>

<script>
const MAX_BRANCHES = 10;
let branchCount = <?= count($branches) ?>;

function updateUI() {
  const rows  = document.querySelectorAll('#branchesContainer .branch-row');
  const btn   = document.getElementById('addBranchBtn');
  const meta  = document.getElementById('branchesMeta');
  const count = rows.length;

  // Re-number labels
  rows.forEach((row, i) => {
    const lbl = row.querySelector('.branch-label');
    if (lbl) lbl.textContent = 'Branch ' + (i + 1);
  });

  if (count >= MAX_BRANCHES) {
    btn.style.display = 'none';
    meta.textContent  = 'Maximum of ' + MAX_BRANCHES + ' branches reached.';
  } else {
    btn.style.display = '';
    meta.textContent  = count > 0 ? count + ' branch' + (count !== 1 ? 'es' : '') + ' added.' : '';
  }
}

function addBranch() {
  if (document.querySelectorAll('#branchesContainer .branch-row').length >= MAX_BRANCHES) return;
  const id  = branchCount++;
  const num = document.querySelectorAll('#branchesContainer .branch-row').length + 1;
  const div = document.createElement('div');
  div.className = 'branch-row';
  div.id = 'branch_' + id;
  div.innerHTML =
    '<span class="branch-label">Branch ' + num + '</span>' +
    '<button type="button" class="branch-remove" onclick="removeBranch(' + id + ')" title="Remove">✕</button>' +
    '<div class="form-row cols-2" style="margin-top:6px;">' +
      '<div class="form-group" style="margin-bottom:10px;">' +
        '<label class="form-label required" style="font-size:0.75rem;">Branch Name</label>' +
        '<input type="text" name="branch_name[]" class="form-control" placeholder="e.g. Heliopolis Branch" required>' +
      '</div>' +
      '<div class="form-group" style="margin-bottom:10px;">' +
        '<label class="form-label" style="font-size:0.75rem;">Phone</label>' +
        '<input type="tel" name="branch_phone[]" class="form-control" placeholder="+20 100 000 0000">' +
      '</div>' +
    '</div>' +
    '<div class="form-group" style="margin-bottom:0;">' +
      '<label class="form-label" style="font-size:0.75rem;">Address</label>' +
      '<input type="text" name="branch_address[]" class="form-control" placeholder="Street, district, city">' +
    '</div>';
  document.getElementById('branchesContainer').appendChild(div);
  div.querySelector('input').focus();
  updateUI();
}

function removeBranch(id) {
  const el = document.getElementById('branch_' + id);
  if (!el) return;
  el.style.transition = 'opacity .15s, transform .15s';
  el.style.opacity    = '0';
  el.style.transform  = 'translateY(-4px)';
  setTimeout(() => { el.remove(); updateUI(); }, 160);
}

updateUI();
</script>
</body>
</html>
