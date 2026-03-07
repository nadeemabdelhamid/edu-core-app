<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('student');

$pdo = getDB();
$uid = authId();
$cid = centerId();

$pageTitle  = 'My Profile';
$activePage = 'student_profile';

$stStmt = $pdo->prepare("SELECT s.*, c.name AS center_name, u.email AS login_email, u.last_login FROM students s JOIN centers c ON c.id=s.center_id JOIN users u ON u.id=s.user_id WHERE s.user_id=? AND s.center_id=?");
$stStmt->execute([$uid, $cid]);
$student = $stStmt->fetch();
if (!$student) { flash('error','Profile not found.'); redirect('/educore/modules/students/home.php'); }

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new1    = $_POST['new_password'] ?? '';
        $new2    = $_POST['confirm_password'] ?? '';

        $userStmt = $pdo->prepare("SELECT password FROM users WHERE id=?");
        $userStmt->execute([$uid]);
        $userRow  = $userStmt->fetch();

        if (!password_verify($current, $userRow['password'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new1) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        } elseif ($new1 !== $new2) {
            $errors[] = 'New passwords do not match.';
        } else {
            $pdo->prepare("UPDATE users SET password=?, updated_at=NOW() WHERE id=?")->execute([password_hash($new1, PASSWORD_BCRYPT), $uid]);
            auditLog('student.password_changed', 'student', $student['id']);
            flash('success', 'Password changed successfully.');
            redirect('profile.php');
        }
    }
}

// Stats for sidebar
$attStmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status='present') AS present, SUM(status='late') AS late FROM attendance_records WHERE student_id=? AND center_id=?");
$attStmt->execute([$student['id'], $cid]);
$attStats = $attStmt->fetch();
$attRate  = (int)$attStats['total'] > 0 ? round((($attStats['present']+$attStats['late'])/(int)$attStats['total'])*100) : 0;

$groupCnt = $pdo->prepare("SELECT COUNT(*) FROM group_students WHERE student_id=? AND status='active'");
$groupCnt->execute([$student['id']]); $groupCount = $groupCnt->fetchColumn();

$examCnt = $pdo->prepare("SELECT COUNT(*), ROUND(AVG(percentage),1) FROM exam_submissions WHERE student_id=? AND status IN('submitted','graded')");
$examCnt->execute([$student['id']]); [$examCount,$examAvg] = $examCnt->fetch(PDO::FETCH_NUM);

$colors   = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4'];
$stuColor = $colors[abs(crc32($student['name'])) % count($colors)];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">My Profile</h1><p class="page-subtitle">Account details and settings</p></div>
  <a href="/educore/modules/students/home.php" class="btn btn-ghost">&#x2190; Home</a>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:start;">
<div>

<!-- Profile card -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="display:flex;align-items:center;gap:20px;padding:24px;">
    <div style="width:72px;height:72px;border-radius:var(--radius-lg);background:linear-gradient(135deg,<?=$stuColor?>,<?=$stuColor?>99);color:#fff;font-family:var(--font-display);font-size:1.6rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <?= strtoupper(substr($student['name'],0,2)) ?>
    </div>
    <div>
      <div style="font-family:var(--font-display);font-size:1.3rem;font-weight:800;color:var(--text-primary);"><?= sanitize($student['name']) ?></div>
      <div style="font-size:0.82rem;color:var(--text-muted);margin-top:2px;"><?= sanitize($student['center_name']) ?></div>
      <div style="display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;">
        <?php if($student['student_code']): ?>
        <span style="font-family:monospace;font-size:0.8rem;background:var(--accent-subtle);color:var(--accent);padding:3px 10px;border-radius:20px;font-weight:700;">#<?= sanitize($student['student_code']) ?></span>
        <?php endif; ?>
        <span style="font-size:0.78rem;background:var(--bg-elevated);color:var(--text-secondary);padding:3px 10px;border-radius:20px;">Student</span>
        <?php if($student['last_login']): ?>
        <span style="font-size:0.75rem;color:var(--text-muted);">Last login: <?= date('M j, Y', strtotime($student['last_login'])) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Info -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">Personal Information</span></div>
  <div class="card-body" style="font-size:0.85rem;">
    <?php
    $infos = [
      ['Full Name',   $student['name']],
      ['Email',       $student['email'] ?: '—'],
      ['Phone',       $student['phone'] ?: '—'],
      ['Parent Phone',$student['parent_phone'] ?: '—'],
      ['Date of Birth',$student['date_of_birth'] ? date('F j, Y', strtotime($student['date_of_birth'])) : '—'],
      ['Gender',      $student['gender'] ? ucfirst($student['gender']) : '—'],
    ];
    foreach($infos as [$label,$val]):
    ?>
    <div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);">
      <span style="color:var(--text-muted);font-size:0.75rem;text-transform:uppercase;letter-spacing:0.06em;"><?=$label?></span>
      <span style="font-weight:500;color:var(--text-primary);"><?=sanitize($val)?></span>
    </div>
    <?php endforeach; ?>
    <?php if($student['notes']): ?>
    <div style="margin-top:12px;font-size:0.78rem;color:var(--text-muted);background:var(--bg-elevated);padding:10px;border-radius:var(--radius-sm);"><?=sanitize($student['notes'])?></div>
    <?php endif; ?>
  </div>
</div>

<!-- Login -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">Login Details</span></div>
  <div class="card-body">
    <div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);font-size:0.85rem;">
      <span style="color:var(--text-muted);font-size:0.75rem;text-transform:uppercase;letter-spacing:0.06em;">Username</span>
      <span style="font-family:monospace;font-size:0.84rem;color:var(--accent);"><?=sanitize($student['login_email'])?></span>
    </div>
    <div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);font-size:0.85rem;">
      <span style="color:var(--text-muted);font-size:0.75rem;text-transform:uppercase;letter-spacing:0.06em;">Password</span>
      <span style="color:var(--text-muted);">&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;</span>
    </div>
    <div style="display:flex;justify-content:space-between;padding:9px 0;font-size:0.85rem;">
      <span style="color:var(--text-muted);font-size:0.75rem;text-transform:uppercase;letter-spacing:0.06em;">Login URL</span>
      <span style="font-size:0.78rem;color:var(--text-secondary);">localhost/educore/login.php</span>
    </div>
  </div>
</div>

<!-- Change password -->
<div class="card">
  <div class="card-header"><span class="card-title">Change Password</span></div>
  <div class="card-body">
    <?php if($errors): ?>
    <div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-sm);padding:12px 16px;font-size:0.83rem;color:var(--danger);margin-bottom:16px;">
      <?php foreach($errors as $e): ?><div>&#x2022; <?=sanitize($e)?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <form method="POST">
      <?=csrf_field()?><input type="hidden" name="action" value="change_password">
      <div class="form-group">
        <label class="form-label">Current Password</label>
        <input type="password" name="current_password" class="form-control" required placeholder="Your current password">
      </div>
      <div class="form-group">
        <label class="form-label">New Password</label>
        <input type="password" name="new_password" class="form-control" required placeholder="At least 6 characters" minlength="6">
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label class="form-label">Confirm New Password</label>
        <input type="password" name="confirm_password" class="form-control" required placeholder="Repeat new password">
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:18px;">Update Password</button>
    </form>
  </div>
</div>

</div>

<!-- Sidebar stats -->
<div>
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header"><span class="card-title">My Stats</span></div>
    <div class="card-body" style="padding:0;">
      <?php
      $attRateColor = $attRate>=80?'var(--success)':($attRate>=60?'var(--warning)':'var(--danger)');
      $statItems = [
        ['Groups Enrolled', $groupCount, 'var(--accent)'],
        ['Attendance Rate',  $attRate.'%', $attRateColor],
        ['Exams Taken',      $examCount,   '#8b5cf6'],
        ['Avg Exam Score',   $examAvg ? $examAvg.'%' : '—', '#3b82f6'],
        ['Sessions Attended',(int)$attStats['present']+(int)$attStats['late'], 'var(--success)'],
        ['Sessions Absent',  (int)$attStats['total']-(int)$attStats['present']-(int)$attStats['late'], 'var(--danger)'],
      ];
      foreach($statItems as [$l,$v,$c]):
      ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 20px;border-bottom:1px solid var(--border);">
        <span style="font-size:0.78rem;color:var(--text-muted);"><?=$l?></span>
        <span style="font-weight:700;font-family:var(--font-display);color:<?=$c?>;"><?=$v?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">Quick Links</span></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:8px;">
      <a href="/educore/modules/attendance/submit.php" class="btn btn-ghost w-full" style="justify-content:flex-start;gap:10px;font-size:0.84rem;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Mark Attendance
      </a>
      <a href="/educore/modules/exams/student.php" class="btn btn-ghost w-full" style="justify-content:flex-start;gap:10px;font-size:0.84rem;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        My Exams
      </a>
      <a href="/educore/logout.php" class="btn w-full" style="justify-content:flex-start;gap:10px;font-size:0.84rem;background:var(--danger-dim);color:var(--danger);border:1px solid rgba(239,68,68,0.2);">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign Out
      </a>
    </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
