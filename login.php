<?php
require_once __DIR__ . '/includes/helpers.php';

// Redirect if already logged in
if (auth()) {
    header('Location: /educore/dashboard.php');
    exit;
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email    = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Rate limit: 10 attempts per 15 min per IP
    if (!rateLimit($ip . ':' . $email, 'login', 10, 900)) {
        $errors[] = 'Too many login attempts. Please wait 15 minutes and try again.';
    } elseif (!$email || !$password) {
        $errors[] = 'Email and password are required.';
    } else {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && !$user['is_active']) {
            // Check if center is pending approval
            $cStmt = $pdo->prepare("SELECT status FROM centers WHERE id=? LIMIT 1");
            $cStmt->execute([$user['center_id']]);
            $centerStatus = $cStmt->fetchColumn();
            if ($centerStatus === 'pending') {
                $errors[] = 'Your account is pending approval by our team. You will be notified once approved.';
            } else {
                $errors[] = 'This account has been suspended. Contact your administrator.';
            }
        } elseif ($user && password_verify($password, $user['password'])) {
            // Also block if center is pending (extra safety check)
            $cStmt = $pdo->prepare("SELECT status FROM centers WHERE id=? LIMIT 1");
            $cStmt->execute([$user['center_id']]);
            $centerStatus = $cStmt->fetchColumn();
            if ($centerStatus === 'pending' && $user['role'] !== 'super_admin') {
                $errors[] = 'Your account is pending approval by our team. You will be notified once approved.';
            } else {
                login($user);
                flash('success', 'Welcome back, ' . $user['name'] . '!');
                header('Location: /educore/dashboard.php');
                exit;
            }
        } else {
            $errors[] = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In — EduCore</title>
  <link rel="stylesheet" href="/educore/assets/css/app.css">
  <style>
    body {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background: var(--bg-base);
      padding: 20px;
    }

    /* Ambient background effect */
    body::before {
      content: '';
      position: fixed;
      top: -30%; left: 50%;
      transform: translateX(-50%);
      width: 800px; height: 600px;
      background: radial-gradient(ellipse, rgba(82,71,230,0.06) 0%, transparent 70%);
      pointer-events: none;
      z-index: 0;
    }

    .auth-wrapper {
      display: grid;
      grid-template-columns: 1fr 1fr;
      max-width: 960px;
      width: 100%;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius-xl);
      overflow: hidden;
      box-shadow: var(--shadow-lg);
      position: relative;
      z-index: 1;
    }

    /* Left panel */
    .auth-panel {
      background: var(--bg-surface);
      padding: 60px 50px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      border-right: 1px solid var(--border);
      position: relative;
      overflow: hidden;
    }

    .auth-panel::before {
      content: '';
      position: absolute;
      bottom: -80px; right: -80px;
      width: 300px; height: 300px;
      background: radial-gradient(circle, rgba(82,71,230,0.08) 0%, transparent 70%);
    }

    .panel-logo {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 48px;
    }

    .panel-logo-icon {
      width: 42px; height: 42px;
      background: var(--accent);
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 20px;
      box-shadow: 0 0 24px rgba(82,71,230,0.3);
    }

    .panel-logo-text {
      font-family: var(--font-display);
      font-size: 1.4rem;
      font-weight: 800;
      color: var(--text-primary);
      letter-spacing: -0.04em;
    }

    .panel-logo-text span { color: var(--accent); }

    .panel-headline {
      font-family: var(--font-display);
      font-size: 1.8rem;
      font-weight: 800;
      color: var(--text-primary);
      letter-spacing: -0.04em;
      line-height: 1.15;
      margin-bottom: 16px;
    }

    .panel-headline em {
      font-style: normal;
      color: var(--accent);
    }

    .panel-desc {
      font-size: 0.88rem;
      color: var(--text-secondary);
      line-height: 1.7;
      margin-bottom: 40px;
    }

    .feature-list {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .feature-item {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.82rem;
      color: var(--text-secondary);
    }

    .feature-dot {
      width: 6px; height: 6px;
      border-radius: 50%;
      background: var(--accent);
      flex-shrink: 0;
    }

    /* Right panel — form */
    .auth-form-panel {
      padding: 60px 50px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .form-heading {
      font-family: var(--font-display);
      font-size: 1.45rem;
      font-weight: 700;
      color: var(--text-primary);
      letter-spacing: -0.03em;
      margin-bottom: 6px;
    }

    .form-subheading {
      font-size: 0.84rem;
      color: var(--text-muted);
      margin-bottom: 36px;
    }

    .auth-error {
      background: var(--danger-dim);
      border: 1px solid rgba(239,68,68,0.25);
      border-radius: var(--radius-sm);
      padding: 11px 14px;
      font-size: 0.82rem;
      color: var(--danger);
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .forgot-link {
      font-size: 0.78rem;
      color: var(--accent);
      text-decoration: none;
      font-weight: 500;
    }
    .forgot-link:hover { text-decoration: underline; }

    .divider {
      display: flex;
      align-items: center;
      gap: 12px;
      margin: 24px 0;
      color: var(--text-muted);
      font-size: 0.72rem;
    }
    .divider::before, .divider::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--border);
    }

    .register-link {
      text-align: center;
      font-size: 0.82rem;
      color: var(--text-muted);
    }
    .register-link a { color: var(--accent); text-decoration: none; font-weight: 600; }
    .register-link a:hover { text-decoration: underline; }

    .demo-credentials {
      background: var(--bg-elevated);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 12px 14px;
      font-size: 0.75rem;
      color: var(--text-muted);
      margin-top: 20px;
      line-height: 1.8;
    }

    .demo-credentials strong { color: var(--text-secondary); }

    @media (max-width: 768px) {
      .auth-wrapper { grid-template-columns: 1fr; }
      .auth-panel { display: none; }
      .auth-form-panel { padding: 40px 30px; }
    }
  </style>
</head>
<body>

<div class="auth-wrapper">

  <!-- Left: Branding panel -->
  <div class="auth-panel">
    <div class="panel-logo">
      <div class="panel-logo-icon">🎓</div>
      <span class="panel-logo-text">Edu<span>Core</span></span>
    </div>

    <h1 class="panel-headline">
      Manage your<br>
      center <em>smarter</em>,<br>
      not harder.
    </h1>

    <p class="panel-desc">
      The all-in-one SaaS platform for private educational centers.
      Students, attendance, exams, and payments — unified.
    </p>

    <div class="feature-list">
      <div class="feature-item"><span class="feature-dot"></span> Code-based fraud-proof attendance</div>
      <div class="feature-item"><span class="feature-dot"></span> Full student & group management</div>
      <div class="feature-item"><span class="feature-dot"></span> Exam creation and auto-grading</div>
      <div class="feature-item"><span class="feature-dot"></span> Payment tracking and receipts</div>
      <div class="feature-item"><span class="feature-dot"></span> Real-time reports and analytics</div>
    </div>
  </div>

  <!-- Right: Form -->
  <div class="auth-form-panel">
    <h2 class="form-heading">Sign in to your account</h2>
    <p class="form-subheading">Enter your credentials to continue</p>

    <?php if ($errors): ?>
    <div class="auth-error">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      <?= sanitize($errors[0]) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="">
      <?= csrf_field() ?>

      <div class="form-group">
        <label class="form-label required">Email address</label>
        <input type="email" name="email" class="form-control"
               placeholder="you@center.com"
               value="<?= sanitize($email) ?>" required autofocus>
      </div>

      <div class="form-group">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:7px;">
          <label class="form-label required" style="margin:0">Password</label>
          <a href="/educore/forgot-password.php" class="forgot-link">Forgot password?</a>
        </div>
        <input type="password" name="password" class="form-control"
               placeholder="••••••••" required>
      </div>

      <div style="margin-bottom:24px; display:flex; align-items:center; gap:8px;">
        <input type="checkbox" id="remember" name="remember"
               style="accent-color: var(--accent); width:15px; height:15px; cursor:pointer;">
        <label for="remember" style="font-size:0.82rem; color:var(--text-secondary); cursor:pointer;">
          Keep me signed in for 7 days
        </label>
      </div>

      <button type="submit" class="btn btn-primary w-full btn-lg">
        Sign In
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
      </button>
    </form>

    <div class="divider">or</div>

    <div class="register-link">
      Don't have a center yet?
      <a href="/educore/register.php">Create your account →</a>
    </div>

    <div class="demo-credentials">
      <strong>Demo credentials:</strong><br>
      Admin: admin@educore.io / password<br>
      <span style="color:var(--text-muted);font-size:0.68rem;">
        After setup, create your own center account via Register.
      </span>
    </div>
  </div>

</div>

</body>
</html>
