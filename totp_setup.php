<?php
/**
 * totp_setup.php
 * Super admin page to enable, verify, and manage TOTP 2FA.
 * Accessible from the Security section of the admin panel.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/totp.php';
requireRole('super_admin');

$pdo = getDB();
$uid = authId();

// Fetch current super_admin row
$stmt = $pdo->prepare("SELECT id, name, email, totp_enabled, totp_secret FROM users WHERE id = ?");
$stmt->execute([$uid]);
$admin = $stmt->fetch();

$pageTitle   = '2FA Setup';
$activePage  = 'security';
$breadcrumbs = [
    ['label' => 'Super Admin', 'href' => '/educore/modules/admin/centers.php'],
    ['label' => '2FA Security Setup'],
];

$error   = '';
$success = '';
$newSecret = '';

// ── Generate a fresh pending secret ─────────────────────────
// We store it in session until the user verifies it with a correct code
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['new_secret'])) {
    $_SESSION['totp_pending_secret'] = TOTP::generateSecret();
    redirect('/educore/totp_setup.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ── Begin setup: generate a new secret and show QR ──────
    if ($action === 'begin_setup') {
        $_SESSION['totp_pending_secret'] = TOTP::generateSecret();
        redirect('/educore/totp_setup.php');
    }

    // ── Confirm setup: user verifies code matches the pending secret ──
    if ($action === 'confirm_setup') {
        $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $code = trim(str_replace(' ', '', $_POST['confirm_code'] ?? ''));
        $secret = $_SESSION['totp_pending_secret'] ?? '';

        if (!$secret) {
            $error = 'Setup session expired. Please start over.';
        } elseif (!rateLimit($ip . ':totp_setup:' . $uid, 'totp_setup', 5, 300)) {
            $error = 'Too many failed verification attempts. Please wait 5 minutes.';
        } elseif (!TOTP::verify($secret, $code)) {
            $error = 'The code did not match. Make sure you scanned the QR code correctly and try again.';
        } else {
            // Save the secret and enable TOTP
            $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1, updated_at = NOW() WHERE id = ?")
                ->execute([$secret, $uid]);
            unset($_SESSION['totp_pending_secret']);
            auditLog('security.2fa_enabled', 'user', $uid, ['ip' => $ip]);
            $success = '✅ Two-factor authentication is now active. You will be asked for your code on every login.';
            // Re-fetch
            $stmt->execute([$uid]);
            $admin = $stmt->fetch();
        }
    }

    // ── Disable TOTP ─────────────────────────────────────────
    if ($action === 'disable_totp') {
        $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $code = trim(str_replace(' ', '', $_POST['disable_code'] ?? ''));

        if (!TOTP::verify($admin['totp_secret'] ?? '', $code)) {
            $error = 'Current code incorrect. Enter your authenticator code to confirm disabling 2FA.';
        } else {
            $pdo->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0, updated_at = NOW() WHERE id = ?")
                ->execute([$uid]);
            unset($_SESSION['totp_pending_secret']);
            auditLog('security.2fa_disabled', 'user', $uid, ['ip' => $ip]);
            $success = 'Two-factor authentication has been disabled.';
            $stmt->execute([$uid]);
            $admin = $stmt->fetch();
        }
    }
}

// Pending setup state
$pendingSecret = $_SESSION['totp_pending_secret'] ?? '';
$isEnabled     = !empty($admin['totp_enabled']) && !empty($admin['totp_secret']);
$qrUri         = $pendingSecret ? TOTP::getUri($pendingSecret, $admin['email'], 'EduCore Admin') : '';
$qrUrl         = $pendingSecret ? TOTP::getQrUrl($qrUri) : '';

include __DIR__ . '/templates/layout.php';
?>

<style>
.totp-setup-card { max-width: 620px; }
.secret-display {
  font-family: monospace;
  font-size: 1.1rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  background: var(--bg-elevated);
  border: 1px dashed var(--border-light);
  border-radius: var(--radius-sm);
  padding: 12px 16px;
  color: var(--accent);
  word-break: break-all;
  text-align: center;
  user-select: all;
}
.step-num {
  width: 26px; height: 26px;
  background: var(--accent); color: #fff;
  border-radius: 50%;
  font-size: 0.78rem; font-weight: 700;
  display: inline-flex; align-items: center; justify-content: center;
  flex-shrink: 0; margin-right: 10px;
}
.step { display: flex; align-items: flex-start; gap: 2px; margin-bottom: 18px; }
.step-body { flex: 1; }
.step-label { font-weight: 600; font-size: 0.9rem; color: var(--text-primary); margin-bottom: 5px; }
.step-desc  { font-size: 0.82rem; color: var(--text-secondary); line-height: 1.6; }
.status-pill {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 14px; border-radius: 99px;
  font-size: 0.8rem; font-weight: 700;
}
.status-on  { background: var(--success-dim); color: var(--success); border: 1px solid rgba(16,185,129,0.25); }
.status-off { background: var(--bg-elevated);  color: var(--text-muted); border: 1px solid var(--border); }
</style>

<div class="page-header">
  <div>
    <h1 class="page-title">🔐 Two-Factor Authentication</h1>
    <p class="page-subtitle">Protect the super admin account with TOTP — Google Authenticator, Authy, or any RFC 6238 app</p>
  </div>
</div>

<?php if ($error): ?>
<div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-md);padding:13px 16px;font-size:0.84rem;color:var(--danger);margin-bottom:20px;display:flex;align-items:center;gap:8px;">
  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <?= sanitize($error) ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div style="background:var(--success-dim);border:1px solid rgba(16,185,129,0.25);border-radius:var(--radius-md);padding:13px 16px;font-size:0.84rem;color:var(--success);margin-bottom:20px;">
  <?= sanitize($success) ?>
</div>
<?php endif; ?>

<!-- Current status -->
<div class="card totp-setup-card" style="margin-bottom:20px;">
  <div class="card-header">
    <span class="card-title">Current Status</span>
    <?php if ($isEnabled): ?>
    <span class="status-pill status-on">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
      2FA Enabled
    </span>
    <?php else: ?>
    <span class="status-pill status-off">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      2FA Disabled
    </span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($isEnabled): ?>
    <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:20px;line-height:1.7;">
      Two-factor authentication is <strong style="color:var(--success);">active</strong>. Every login requires your email/password <em>plus</em> the 6-digit code from your authenticator app.
    </p>
    <!-- Disable form — requires a valid code to confirm -->
    <div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.18);border-radius:var(--radius-md);padding:16px 18px;">
      <div style="font-size:0.84rem;font-weight:600;color:var(--danger);margin-bottom:12px;">⚠️ Disable Two-Factor Authentication</div>
      <p style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:14px;">Enter your current authenticator code to confirm. The super admin account will no longer require 2FA on login.</p>
      <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="disable_totp">
        <div class="form-group" style="margin:0;flex:1;min-width:150px;">
          <label class="form-label">Current 6-digit code</label>
          <input type="text" name="disable_code" class="form-control" maxlength="6" inputmode="numeric"
                 pattern="\d{6}" placeholder="000000" required autocomplete="one-time-code">
        </div>
        <button type="submit" class="btn btn-danger" onclick="return confirm('Disable 2FA? The super admin account will be less secure.')">
          Disable 2FA
        </button>
      </form>
    </div>

    <?php elseif ($pendingSecret): ?>
    <!-- Active setup session -->
    <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:22px;line-height:1.7;">
      Follow the steps below to link your authenticator app. <strong>Do not close this page</strong> until you have verified a code — the setup will be lost.
    </p>

    <div class="step">
      <span class="step-num">1</span>
      <div class="step-body">
        <div class="step-label">Open your authenticator app</div>
        <div class="step-desc">Google Authenticator, Authy, 1Password, Bitwarden, or any app that supports TOTP (RFC 6238).</div>
      </div>
    </div>

    <div class="step">
      <span class="step-num">2</span>
      <div class="step-body">
        <div class="step-label">Scan the QR code</div>
        <div class="step-desc" style="margin-bottom:14px;">Point your app at the code below. It will automatically add EduCore.</div>
        <div style="text-align:center;margin:16px 0;">
          <img src="<?= $qrUrl ?>" alt="TOTP QR Code" width="220" height="220"
               style="border:8px solid #fff;border-radius:var(--radius-md);box-shadow:var(--shadow-md);display:inline-block;">
        </div>
        <div style="font-size:0.78rem;color:var(--text-muted);text-align:center;margin-bottom:8px;">Can't scan? Enter this key manually:</div>
        <div class="secret-display"><?= TOTP::formatSecret($pendingSecret) ?></div>
        <button type="button" onclick="navigator.clipboard.writeText('<?= $pendingSecret ?>').then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy key',2000)})"
                class="btn btn-ghost btn-sm" style="width:100%;margin-top:8px;">Copy key</button>
      </div>
    </div>

    <div class="step" style="margin-bottom:0;">
      <span class="step-num">3</span>
      <div class="step-body">
        <div class="step-label">Verify with a code</div>
        <div class="step-desc" style="margin-bottom:14px;">Enter the 6-digit code your app shows for EduCore to complete setup.</div>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="confirm_setup">
          <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <div class="form-group" style="margin:0;flex:1;min-width:150px;">
              <label class="form-label required">6-digit code from app</label>
              <input type="text" name="confirm_code" class="form-control"
                     style="font-size:1.1rem;letter-spacing:0.15em;text-align:center;font-weight:700;"
                     maxlength="6" inputmode="numeric" pattern="\d{6}"
                     placeholder="000000" required autocomplete="one-time-code" autofocus>
            </div>
            <button type="submit" class="btn btn-primary">Confirm &amp; Enable</button>
          </div>
        </form>
      </div>
    </div>

    <div style="margin-top:20px;border-top:1px solid var(--border);padding-top:14px;text-align:center;">
      <form method="POST" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="begin_setup">
        <button type="submit" class="btn btn-ghost btn-sm">↻ Generate new QR code</button>
      </form>
    </div>

    <?php else: ?>
    <!-- Not enabled, no pending setup -->
    <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:20px;line-height:1.7;">
      Two-factor authentication is <strong style="color:var(--text-muted);">not active</strong>. Anyone who obtains your password can access the super admin panel. Enable 2FA to add a second layer of protection.
    </p>
    <div style="background:rgba(82,71,230,0.05);border:1px solid rgba(82,71,230,0.15);border-radius:var(--radius-md);padding:14px 16px;font-size:0.82rem;color:var(--text-secondary);margin-bottom:20px;line-height:1.7;">
      <strong style="color:var(--text-primary);">How it works:</strong> After enabling, every login will require your password <em>plus</em> a 6-digit code from your phone app. Even if your password is compromised, no one can access the super admin account without your physical device.
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="begin_setup">
      <button type="submit" class="btn btn-primary">
        🔐 Set Up Two-Factor Authentication
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- App recommendations -->
<div class="card totp-setup-card">
  <div class="card-header"><span class="card-title">Recommended Authenticator Apps</span></div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">
      <?php foreach ([
        ['name' => 'Google Authenticator', 'icon' => '🔵', 'note' => 'iOS & Android — free'],
        ['name' => 'Authy',                'icon' => '🟣', 'note' => 'iOS & Android — cloud backup'],
        ['name' => '1Password',            'icon' => '🔑', 'note' => 'Password manager + TOTP'],
        ['name' => 'Bitwarden',            'icon' => '🛡️', 'note' => 'Open source, free tier'],
      ] as $app): ?>
      <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 14px;">
        <div style="font-size:1.2rem;margin-bottom:5px;"><?= $app['icon'] ?></div>
        <div style="font-size:0.84rem;font-weight:600;color:var(--text-primary);"><?= $app['name'] ?></div>
        <div style="font-size:0.74rem;color:var(--text-muted);margin-top:2px;"><?= $app['note'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/templates/layout_end.php'; ?>
