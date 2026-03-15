<?php
require_once __DIR__ . '/includes/helpers.php';

if (auth()) { redirect('/educore/dashboard.php'); }

$pdo   = getDB();
$token = trim($_GET['token'] ?? '');
$errors = [];

if (!$token) { redirect('/educore/login.php'); }

// Validate token
$invStmt = $pdo->prepare("SELECT si.*, c.name AS center_name FROM staff_invitations si JOIN centers c ON c.id=si.center_id WHERE si.token=? AND si.status='pending' AND si.expires_at > NOW()");
$invStmt->execute([$token]);
$inv = $invStmt->fetch();

if (!$inv) {
    $invalid = true;
}

$data = ['name'=>'', 'password'=>'', 'password2'=>''];

if (!isset($invalid) && $_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $data['name']      = trim($_POST['name'] ?? '');
    $data['password']  = $_POST['password']  ?? '';
    $data['password2'] = $_POST['password2'] ?? '';

    if (!$data['name'])                        $errors[] = 'Full name is required.';
    if (strlen($data['password']) < 8)         $errors[] = 'Password must be at least 8 characters.';
    if ($data['password'] !== $data['password2']) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $hash = password_hash($data['password'], PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (center_id, name, email, password, role, is_active) VALUES (?,?,?,?,?,1)")
                ->execute([$inv['center_id'], $data['name'], $inv['email'], $hash, $inv['role']]);
            $userId = $pdo->lastInsertId();
            $pdo->prepare("UPDATE staff_invitations SET status='accepted', accepted_at=NOW() WHERE id=?")->execute([$inv['id']]);
            $pdo->commit();

            $user = $pdo->prepare("SELECT * FROM users WHERE id=?");
            $user->execute([$userId]);
            login($user->fetch());
            flash('success', 'Welcome to '.sanitize($inv['center_name']).'! Your account is ready.');
            redirect('/educore/dashboard.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Account creation failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Accept Invitation — EduCore</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Nunito:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/educore/assets/css/app.css">
  <style>
    body { display:flex; align-items:center; justify-content:center; min-height:100vh; padding:30px 20px; }
    .wrap { max-width:480px; width:100%; }
    .card-outer { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-xl); padding:36px 40px; box-shadow:var(--shadow-lg); }
  </style>
</head>
<body>
<div class="wrap">
  <div style="text-align:center;margin-bottom:28px;">
    <div style="font-family:var(--font-display);font-size:1.5rem;font-weight:800;color:var(--accent);">🎓 EduCore</div>
  </div>
  <div class="card-outer">
    <?php if(isset($invalid)): ?>
    <div style="text-align:center;padding:20px 0;">
      <div style="font-size:3rem;margin-bottom:12px;">⛔</div>
      <h2 style="font-family:var(--font-display);font-size:1.2rem;font-weight:700;color:var(--text-primary);margin-bottom:8px;">Invalid or Expired Link</h2>
      <p style="font-size:0.84rem;color:var(--text-muted);">This invitation link is invalid or has expired. Please contact your center admin for a new invite.</p>
      <a href="/educore/login.php" class="btn btn-ghost" style="margin-top:20px;">← Back to Login</a>
    </div>
    <?php else: ?>
    <div style="margin-bottom:24px;">
      <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:6px;">You're invited to join</div>
      <h1 style="font-family:var(--font-display);font-size:1.4rem;font-weight:700;color:var(--text-primary);"><?=sanitize($inv['center_name'])?></h1>
      <div style="margin-top:6px;display:flex;gap:8px;align-items:center;">
        <span class="badge badge-info"><?=ucfirst($inv['role'])?></span>
        <span style="font-size:0.78rem;color:var(--text-muted);"><?=sanitize($inv['email'])?></span>
      </div>
    </div>

    <?php if($errors): ?>
    <div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-sm);padding:12px 14px;font-size:0.82rem;color:var(--danger);margin-bottom:18px;">
      <?php foreach($errors as $e): ?><div>• <?=sanitize($e)?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST">
      <?=csrf_field()?>
      <div class="form-group">
        <label class="form-label required">Your Full Name</label>
        <input type="text" name="name" class="form-control" value="<?=sanitize($data['name'])?>" required placeholder="Ahmed Mohamed">
      </div>
      <div class="form-group">
        <label class="form-label required">Password</label>
        <input type="password" name="password" class="form-control" required minlength="8" placeholder="Min. 8 characters">
      </div>
      <div class="form-group">
        <label class="form-label required">Confirm Password</label>
        <input type="password" name="password2" class="form-control" required placeholder="Repeat password">
      </div>
      <button type="submit" class="btn btn-primary w-full btn-lg" style="margin-top:8px;">Create Account & Join →</button>
    </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
