<?php
require_once __DIR__ . '/includes/helpers.php';

if (auth()) { header('Location: /educore/dashboard.php'); exit; }

$errors = [];
$data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'center_name' => trim($_POST['center_name'] ?? ''),
        'name'        => trim($_POST['name'] ?? ''),
        'email'       => trim($_POST['email'] ?? ''),
        'phone'       => trim($_POST['phone'] ?? ''),
        'password'    => $_POST['password'] ?? '',
        'password2'   => $_POST['password2'] ?? '',
    ];

    if (!$data['center_name']) $errors[] = 'Center name is required.';
    if (!$data['name'])        $errors[] = 'Your name is required.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
    if (strlen($data['password']) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($data['password'] !== $data['password2']) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $pdo = getDB();
        // Check email uniqueness
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

            // Create slug from center name
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $data['center_name']));
            $slug = trim($slug, '-') . '-' . substr(uniqid(), -5);

            // Insert center
            // Create center in 'pending' status — requires super_admin approval
            $pdo->prepare("INSERT INTO centers (name, slug, email, phone, status) VALUES (?,?,?,?,'pending')")
                ->execute([$data['center_name'], $slug, $data['email'], $data['phone']]);
            $centerId = $pdo->lastInsertId();

            // Insert owner user with is_active=0 until approved
            $hashed = password_hash($data['password'], PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (center_id, name, email, password, role, is_active) VALUES (?,?,?,?,'owner',0)")
                ->execute([$centerId, $data['name'], $data['email'], $hashed]);
            $userId = $pdo->lastInsertId();

            // Assign starter plan (inactive until approved)
            $planStmt = $pdo->prepare("SELECT id FROM plans WHERE slug='starter' LIMIT 1");
            $planStmt->execute();
            $planId = $planStmt->fetchColumn();
            if ($planId) {
                $pdo->prepare("INSERT INTO subscriptions (center_id, plan_id, status, starts_at, expires_at, trial_ends_at)
                                VALUES (?,?,'pending',NOW(),DATE_ADD(NOW(), INTERVAL 14 DAY),DATE_ADD(NOW(), INTERVAL 14 DAY))")
                    ->execute([$centerId, $planId]);
            }

            $pdo->commit();

            // Do NOT auto-login — show pending message
            flash('success', 'pending_approval');
            header('Location: /educore/register.php?registered=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Registration failed. Please try again.';
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
      display: flex; align-items: center; justify-content: center;
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
      max-width: 560px;
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

      <div class="section-divider"><span>Center info</span></div>

      <div class="form-group">
        <label class="form-label required">Center / Academy Name</label>
        <input type="text" name="center_name" class="form-control"
               placeholder="e.g. Bright Minds Academy"
               value="<?= sanitize($data['center_name'] ?? '') ?>" required>
      </div>

      <div class="section-divider"><span>Your account</span></div>

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
          <label class="form-label">Phone</label>
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
</body>
</html>
