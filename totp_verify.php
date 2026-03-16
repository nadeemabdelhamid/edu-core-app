<?php
/**
 * totp_verify.php
 * Step 2 of super_admin login: enter the 6-digit authenticator code.
 *
 * Flow:
 *   login.php → password OK → $_SESSION['2fa_pending'] set → redirect here
 *   here       → code OK    → login() called → dashboard
 *   here       → code bad   → error shown, rate-limited
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/totp.php';

// Already fully logged in
if (auth()) {
    redirect('/educore/dashboard.php');
}

// No pending challenge — send back to login
if (empty($_SESSION['2fa_pending']) || empty($_SESSION['2fa_user_id'])) {
    redirect('/educore/login.php');
}

$pdo    = getDB();
$userId = (int)$_SESSION['2fa_user_id'];
$userName = sanitize($_SESSION['2fa_user_name'] ?? 'Admin');

// Fetch the waiting super_admin user
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'super_admin' LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Sanity check: user must exist and have TOTP enabled
if (!$user || empty($user['totp_enabled']) || empty($user['totp_secret'])) {
    unset($_SESSION['2fa_pending'], $_SESSION['2fa_user_id'], $_SESSION['2fa_user_name']);
    redirect('/educore/login.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $code = trim(str_replace([' ', '-'], '', $_POST['totp_code'] ?? ''));

    // Rate limit: 5 wrong attempts per 5 minutes per IP+user
    if (!rateLimit($ip . ':2fa:' . $userId, '2fa_verify', 5, 300)) {
        $error = 'Too many incorrect attempts. Please wait 5 minutes before trying again.';
        auditLog('security.2fa_locked', 'user', $userId, ['ip' => $ip]);
    } elseif (strlen($code) !== 6 || !ctype_digit($code)) {
        $error = 'Please enter the 6-digit code shown in your authenticator app.';
    } elseif (!TOTP::verify($user['totp_secret'], $code)) {
        auditLog('security.2fa_failed', 'user', $userId, ['ip' => $ip]);
        $error = 'Incorrect code. Please check your authenticator app and try again.';
    } else {
        // ✓ Success — clear pending state and complete login
        unset($_SESSION['2fa_pending'], $_SESSION['2fa_user_id'], $_SESSION['2fa_user_name']);
        login($user);
        auditLog('security.2fa_success', 'user', $userId, ['ip' => $ip]);
        flash('success', 'Welcome back, ' . $user['name'] . '! 🔐');
        redirect('/educore/dashboard.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Two-Factor Authentication — EduCore</title>  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Geist:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/educore/assets/css/app.css">
  <style>
    body {
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh; padding: 20px;
      background: var(--bg-base);
    }
    body::before {
      content: '';
      position: fixed; top: -20%; left: 50%; transform: translateX(-50%);
      width: 700px; height: 500px;
      background: radial-gradient(ellipse, rgba(82,71,230,0.07) 0%, transparent 70%);
      pointer-events: none;
    }
    .totp-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow-lg);
      padding: 48px 44px;
      width: 100%; max-width: 420px;
      position: relative; z-index: 1;
    }
    .shield-wrap {
      width: 60px; height: 60px;
      background: var(--accent-subtle);
      border: 2px solid rgba(82,71,230,0.2);
      border-radius: 16px;
      display: flex; align-items: center; justify-content: center;
      font-size: 28px;
      margin: 0 auto 24px;
    }
    .totp-title {
      font-family: var(--font-display);
      font-size: 1.3rem; font-weight: 700;
      letter-spacing: -0.03em;
      text-align: center; color: var(--text-primary);
      margin-bottom: 8px;
    }
    .totp-sub {
      font-size: 0.84rem; color: var(--text-muted);
      text-align: center; line-height: 1.6;
      margin-bottom: 30px;
    }
    .code-input {
      width: 100%;
      text-align: center;
      font-family: var(--font-display);
      font-size: 2.2rem; font-weight: 700;
      letter-spacing: 0.3em;
      padding: 16px;
      border: 2px solid var(--border);
      border-radius: var(--radius-md);
      background: var(--bg-surface);
      color: var(--text-primary);
      outline: none;
      transition: border-color 0.15s, box-shadow 0.15s;
      -moz-appearance: textfield;
    }
    .code-input::-webkit-outer-spin-button,
    .code-input::-webkit-inner-spin-button { -webkit-appearance: none; }
    .code-input:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px var(--accent-glow);
    }
    .code-input::placeholder {
      color: var(--border-light);
      letter-spacing: 0.15em; font-size: 1.4rem;
    }
    .error-box {
      background: var(--danger-dim);
      border: 1px solid rgba(239,68,68,0.25);
      border-radius: var(--radius-sm);
      padding: 11px 14px; font-size: 0.82rem;
      color: var(--danger); margin-bottom: 18px;
      display: flex; align-items: center; gap: 8px;
    }
    .back-link {
      display: block; text-align: center;
      margin-top: 18px; font-size: 0.8rem;
      color: var(--text-muted); text-decoration: none;
      transition: color 0.15s;
    }
    .back-link:hover { color: var(--accent); }
    .hint {
      font-size: 0.76rem; color: var(--text-muted);
      text-align: center; margin-top: 16px; line-height: 1.6;
    }
  </style>
</head>
<body>

<div class="totp-card">
  <div class="shield-wrap">🔐</div>
  <h1 class="totp-title">Two-Factor Authentication</h1>
  <p class="totp-sub">
    Open your authenticator app and enter the<br>
    6-digit code for <strong>EduCore</strong>.
  </p>

  <?php if ($error): ?>
  <div class="error-box">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    <?= sanitize($error) ?>
  </div>
  <?php endif; ?>

  <form method="POST" id="totpForm">
    <?= csrf_field() ?>
    <input
      type="text"
      name="totp_code"
      class="code-input"
      placeholder="000000"
      maxlength="6"
      inputmode="numeric"
      pattern="\d{6}"
      autocomplete="one-time-code"
      autofocus
      required
      id="codeInput"
    >
    <button type="submit" class="btn btn-primary w-full btn-lg" style="margin-top:18px;">
      Verify &amp; Sign In
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="20 6 9 17 4 12"/>
      </svg>
    </button>
  </form>

  <p class="hint">Code refreshes every 30 seconds. If your code isn't working,<br>make sure your device clock is synchronized.</p>

  <a href="/educore/login.php" class="back-link">← Back to login</a>
</div>

<script>
// Auto-submit when 6 digits are entered
document.getElementById('codeInput').addEventListener('input', function() {
  const val = this.value.replace(/\D/g, '');
  this.value = val;
  if (val.length === 6) {
    setTimeout(() => document.getElementById('totpForm').submit(), 120);
  }
});
</script>

</body>
</html>
