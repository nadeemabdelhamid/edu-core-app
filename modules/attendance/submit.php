<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('student');

$pdo = getDB();
$uid = authId();
$cid = centerId();

$pageTitle  = 'Submit Attendance';
$activePage = 'attendance';

$errors  = [];
$success = false;

// Get student record
$stStmt = $pdo->prepare("SELECT * FROM students WHERE user_id = ? AND center_id = ?");
$stStmt->execute([$uid, $cid]);
$student = $stStmt->fetch();

if (!$student) {
    flash('error', 'Student profile not found.');
    redirect('/educore/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $code = strtoupper(trim($_POST['code'] ?? ''));

    if (!$code) {
        $errors[] = 'Please enter the attendance code.';
    } elseif (!preg_match('/^[A-Z0-9]{6}$/', $code)) {
        $errors[] = 'Invalid code format.';
    } else {
        // Find open session with matching code
        $sessStmt = $pdo->prepare("SELECT * FROM attendance_sessions WHERE session_code=? AND center_id=? AND status='open' AND code_expires_at > NOW()");
        $sessStmt->execute([$code, $cid]);
        $session = $sessStmt->fetch();

        if (!$session) {
            $errors[] = 'Code is invalid or has expired. Ask your teacher for the current code.';
        } else {
            // Check student is in this group
            $enrollStmt = $pdo->prepare("SELECT id FROM group_students WHERE group_id=? AND student_id=? AND status='active'");
            $enrollStmt->execute([$session['group_id'], $student['id']]);
            if (!$enrollStmt->fetch()) {
                $errors[] = 'You are not enrolled in this group.';
            } else {
                // Check duplicate submission
                $dupStmt = $pdo->prepare("SELECT id FROM attendance_records WHERE session_id=? AND student_id=?");
                $dupStmt->execute([$session['id'], $student['id']]);
                if ($dupStmt->fetch()) {
                    $errors[] = 'You have already submitted attendance for this session.';
                } else {
                    // Record attendance
                    $pdo->prepare("INSERT INTO attendance_records (session_id, student_id, center_id, status, submitted_at, ip_address) VALUES (?,?,?,'present',NOW(),?)")
                        ->execute([$session['id'], $student['id'], $cid, $_SERVER['REMOTE_ADDR'] ?? null]);
                    $success = true;
                    auditLog('attendance.submitted', 'attendance_record', null, ['session_id' => $session['id']]);
                }
            }
        }
    }
}

include __DIR__ . '/../../templates/layout.php';
?>

<div style="max-width:460px; margin:0 auto;">
  <div class="page-header">
    <div>
      <h1 class="page-title">Mark Attendance</h1>
      <p class="page-subtitle">Enter the code shown by your teacher</p>
    </div>
  </div>

  <?php if ($success): ?>
  <div class="card">
    <div class="card-body" style="text-align:center; padding:50px 30px;">
      <div style="width:64px; height:64px; background:var(--success-dim); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px; font-size:28px;">✅</div>
      <div style="font-family:var(--font-display); font-size:1.4rem; font-weight:700; color:var(--success); margin-bottom:8px;">Attendance Recorded!</div>
      <div style="font-size:0.85rem; color:var(--text-muted);">Your attendance has been successfully submitted.</div>
      <button onclick="location.reload()" class="btn btn-ghost" style="margin-top:20px;">Submit Another</button>
    </div>
  </div>
  <?php else: ?>

  <?php if ($errors): ?>
  <div style="background:var(--danger-dim); border:1px solid rgba(239,68,68,0.25); border-radius:var(--radius-md); padding:14px 18px; font-size:0.84rem; color:var(--danger); margin-bottom:20px;">
    <?php foreach ($errors as $e): ?><div>• <?= sanitize($e) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header"><span class="card-title">Enter Attendance Code</span></div>
    <div class="card-body">
      <form method="POST" action="">
        <?= csrf_field() ?>

        <div class="form-group" style="margin-bottom:24px;">
          <label class="form-label required">6-Character Code</label>
          <input type="text" name="code" class="form-control"
                 placeholder="e.g. AB3X7K"
                 maxlength="6"
                 autocomplete="off"
                 style="text-align:center; font-family:var(--font-display); font-size:2rem; font-weight:700; letter-spacing:0.3em; text-transform:uppercase; padding:16px;"
                 oninput="this.value=this.value.toUpperCase()"
                 autofocus required>
          <span class="form-hint" style="text-align:center; display:block;">Ask your teacher for today's session code</span>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-full">Submit Attendance</button>
      </form>
    </div>
  </div>

  <!-- Recent attendance -->
  <?php
  $recentStmt = $pdo->prepare("SELECT ar.submitted_at, g.name as group_name, a.topic FROM attendance_records ar JOIN attendance_sessions a ON a.id=ar.session_id JOIN `groups` g ON g.id=a.group_id WHERE ar.student_id=? ORDER BY ar.submitted_at DESC LIMIT 5");
  $recentStmt->execute([$student['id']]);
  $recentAttendance = $recentStmt->fetchAll();
  ?>

  <?php if ($recentAttendance): ?>
  <div class="card" style="margin-top:20px;">
    <div class="card-header"><span class="card-title">My Recent Attendance</span></div>
    <div class="card-body no-padding">
      <table class="data-table">
        <thead><tr><th>Group</th><th>Topic</th><th>Date</th></tr></thead>
        <tbody>
          <?php foreach ($recentAttendance as $rec): ?>
          <tr>
            <td class="cell-primary"><?= sanitize($rec['group_name']) ?></td>
            <td><?= sanitize($rec['topic'] ?: '—') ?></td>
            <td style="font-size:0.78rem;"><?= date('M j, H:i', strtotime($rec['submitted_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
