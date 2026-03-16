<?php
require_once __DIR__ . '/includes/helpers.php';

// Redirect if already logged in
if (auth()) {
    header('Location: /educore/dashboard.php');
    exit;
}

// Ensure the table exists
$pdo = getDB();
try {
    $pdo->query("SELECT 1 FROM password_reset_requests LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        center_id INT NOT NULL,
        user_id INT NOT NULL,
        email VARCHAR(255) NOT NULL,
        requested_at DATETIME DEFAULT NOW(),
        status ENUM('pending','resolved','dismissed') DEFAULT 'pending',
        resolved_by INT NULL,
        resolved_at DATETIME NULL,
        message TEXT NULL
    )");
}

$errors  = [];
$success = false;
$email   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email   = trim(strtolower($_POST['email'] ?? ''));
    $message = trim($_POST['message'] ?? '');
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Rate limit: 3 requests per hour per IP
    if (!rateLimit($ip . ':forgot', 'forgot_password', 3, 3600)) {
        $errors[] = 'Too many requests. Please wait before trying again.';
    } elseif (!$email) {
        $errors[] = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        // Look up user
        $stmt = $pdo->prepare("SELECT u.id, u.name, u.center_id FROM users u WHERE u.email=? AND u.role IN('owner','teacher','assistant','accountant') LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user['center_id']) {
            // Check for duplicate pending request
            $dup = $pdo->prepare("SELECT id FROM password_reset_requests WHERE user_id=? AND status='pending' LIMIT 1");
            $dup->execute([$user['id']]);
            if (!$dup->fetchColumn()) {
                // Create the reset request
                $pdo->prepare("INSERT INTO password_reset_requests (center_id, user_id, email, message) VALUES (?,?,?,?)")
                    ->execute([$user['center_id'], $user['id'], $email, $message ?: null]);

                // Notify super admin(s)
                $admins = $pdo->query("SELECT id FROM users WHERE role='super_admin'")->fetchAll();
                foreach ($admins as $admin) {
                    pushNotification(
                        $admin['id'],
                        'password_reset',
                        'Password Reset Request',
                        ($user['name'] ?? $email) . ' is requesting a password reset.',
                        '/educore/modules/admin/centers.php?view=password_requests',
                        null
                    );
                }
            }
            // Always show success to prevent email enumeration
        }

        // Always show success message (whether account exists or not)
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password — EduCore</title>  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Geist:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
    .fp-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow-lg);
      padding: 48px 44px;
      max-width: 460px;
      width: 100%;
      position: relative;
      z-index: 1;
    }
    .fp-logo {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 32px;
      text-decoration: none;
    }
    .fp-logo-icon {
      width: 38px; height: 38px;
      background: var(--accent);
      border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px;
    }
    .fp-logo-text {
      font-family: var(--font-display);
      font-size: 1.3rem;
      font-weight: 800;
      color: var(--text-primary);
      letter-spacing: -0.04em;
    }
    .fp-logo-text span { color: var(--accent); }
    .fp-icon-wrap {
      width: 52px; height: 52px;
      background: rgba(82,71,230,0.1);
      border-radius: 14px;
      display: flex; align-items: center; justify-content: center;
      margin-bottom: 20px;
    }
    .auth-error {
      background: var(--danger-dim);
      border: 1px solid rgba(239,68,68,0.25);
      border-radius: var(--radius-sm);
      padding: 11px 14px;
      font-size: 0.82rem;
      color: var(--danger);
      margin-bottom: 18px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .auth-success {
      background: var(--success-dim);
      border: 1px solid rgba(34,197,94,0.25);
      border-radius: var(--radius-md);
      padding: 20px;
      font-size: 0.85rem;
      color: var(--success);
      text-align: center;
    }
    .auth-success .big-icon { font-size: 2.5rem; margin-bottom: 12px; }
    .auth-success strong { font-size: 1rem; display: block; margin-bottom: 6px; color: var(--text-primary); }
    .back-link {
      display: block;
      text-align: center;
      margin-top: 22px;
      font-size: 0.82rem;
      color: var(--accent);
      text-decoration: none;
      font-weight: 500;
    }
    .back-link:hover { text-decoration: underline; }
    @media (max-width: 500px) {
      .fp-card { padding: 32px 24px; }
    }
  </style>
</head>
<body>
<div class="fp-card">

  <a href="/educore/login.php" class="fp-logo">
    <div class="fp-logo-icon">🎓</div>
    <span class="fp-logo-text">Edu<span>Core</span></span>
  </a>

  <?php if ($success): ?>

  <div class="auth-success">
    <div class="big-icon">📬</div>
    <strong>Request Submitted</strong>
    Your password reset request has been sent to the platform administrator.<br>
    You'll receive an in-app notification once your password is reset — usually within 24 hours.
  </div>
  <a href="/educore/login.php" class="back-link">← Back to Sign In</a>

  <?php else: ?>

  <div class="fp-icon-wrap">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2">
      <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
      <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
    </svg>
  </div>

  <h2 style="font-size:1.35rem;font-weight:700;letter-spacing:-0.03em;margin-bottom:6px;">Forgot your password?</h2>
  <p style="font-size:0.84rem;color:var(--text-muted);margin-bottom:24px;">
    Enter your email address and your request will be sent to the platform administrator who will reset it for you.
  </p>

  <?php if ($errors): ?>
  <div class="auth-error">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <?= sanitize($errors[0]) ?>
  </div>
  <?php endif; ?>

  <form method="POST">
    <?= csrf_field() ?>

    <div class="form-group">
      <label class="form-label required">Your email address</label>
      <input type="email" name="email" class="form-control"
             placeholder="you@center.com"
             value="<?= sanitize($email) ?>" required autofocus>
    </div>

    <div class="form-group" style="margin-bottom:24px;">
      <label class="form-label">Message to admin <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
      <textarea name="message" class="form-control" rows="2"
                placeholder="e.g. I cannot access my account urgently"></textarea>
      <span class="form-hint">This will be seen by the platform administrator.</span>
    </div>

    <button type="submit" class="btn btn-primary w-full btn-lg">
      Submit Reset Request
    </button>
  </form>

  <a href="/educore/login.php" class="back-link">← Back to Sign In</a>

  <?php endif; ?>

</div>
</body>
</html>
