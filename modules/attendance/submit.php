<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('student');

$pdo = getDB();
$uid = authId();
$cid = centerId();

$pageTitle  = 'Mark Attendance';
$activePage = 'attendance';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Attendance'],
];

// Get student record linked to this user
$stStmt = $pdo->prepare("SELECT * FROM students WHERE user_id = ? AND center_id = ? AND is_active = 1");
$stStmt->execute([$uid, $cid]);
$student = $stStmt->fetch();

if (!$student) {
    flash('error', 'Student profile not found. Please contact your teacher.');
    redirect('/educore/dashboard.php');
}

$errors  = [];
$success = false;
$successSession = null;

// ── Handle submission ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $code = strtoupper(trim($_POST['code'] ?? ''));

    if (!$code) {
        $errors[] = 'Please enter the attendance code.';
    } elseif (!preg_match('/^[A-Z0-9]{6}$/', $code)) {
        $errors[] = 'Code must be exactly 6 characters (letters and numbers only).';
    } else {
        // Find open session with matching code, scoped to center
        $sessStmt = $pdo->prepare("
            SELECT s.*, g.name AS group_name, g.subject
            FROM attendance_sessions s
            JOIN `groups` g ON g.id = s.group_id
            WHERE s.session_code = ? AND s.center_id = ? AND s.status = 'open' AND s.code_expires_at > NOW()");
        $sessStmt->execute([$code, $cid]);
        $session = $sessStmt->fetch();

        if (!$session) {
            $errors[] = 'Code is invalid or has expired. Ask your teacher for the current code.';
        } else {
            // Verify student is enrolled in this group
            $enrollStmt = $pdo->prepare("SELECT id FROM group_students WHERE group_id=? AND student_id=? AND status='active'");
            $enrollStmt->execute([$session['group_id'], $student['id']]);
            if (!$enrollStmt->fetch()) {
                $errors[] = 'You are not enrolled in this group. Contact your teacher if this is incorrect.';
            } else {
                // Check for duplicate submission
                $dupStmt = $pdo->prepare("SELECT id FROM attendance_records WHERE session_id=? AND student_id=?");
                $dupStmt->execute([$session['id'], $student['id']]);
                if ($dupStmt->fetch()) {
                    $errors[] = 'You have already submitted attendance for this session.';
                } else {
                    // ✅ Record attendance
                    $pdo->prepare("INSERT INTO attendance_records (session_id, student_id, center_id, status, submitted_at, ip_address) VALUES (?,?,?,'present',NOW(),?)")
                        ->execute([$session['id'], $student['id'], $cid, $_SERVER['REMOTE_ADDR'] ?? null]);

                    $success = true;
                    $successSession = $session;
                    auditLog('attendance.submitted', 'attendance_record', $session['id'], [
                        'student_id' => $student['id'],
                        'group'      => $session['group_name'],
                    ]);
                }
            }
        }
    }
}

// ── Load student's attendance history ─────────────────────
$histStmt = $pdo->prepare("
    SELECT ar.status, ar.submitted_at, g.name AS group_name, a.topic, a.session_date
    FROM attendance_records ar
    JOIN attendance_sessions a ON a.id = ar.session_id
    JOIN `groups` g ON g.id = a.group_id
    WHERE ar.student_id = ? AND ar.center_id = ?
    ORDER BY a.session_date DESC, ar.submitted_at DESC
    LIMIT 10");
$histStmt->execute([$student['id'], $cid]);
$history = $histStmt->fetchAll();

// ── Per-group attendance rate ─────────────────────────────
$rateStmt = $pdo->prepare("
    SELECT g.name AS group_name,
           COUNT(s.id) AS total,
           SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END) AS present_count,
           ROUND(100.0 * SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END) / NULLIF(COUNT(s.id),0), 1) AS rate
    FROM group_students gs
    JOIN `groups` g ON g.id = gs.group_id
    JOIN attendance_sessions s ON s.group_id = gs.group_id AND s.center_id = ? AND s.status = 'closed'
    LEFT JOIN attendance_records ar ON ar.session_id = s.id AND ar.student_id = gs.student_id
    WHERE gs.student_id = ? AND gs.status = 'active'
    GROUP BY g.id
    ORDER BY g.name");
$rateStmt->execute([$cid, $student['id']]);
$groupRates = $rateStmt->fetchAll();

$statusBadge = ['present'=>'badge-success','absent'=>'badge-danger','late'=>'badge-warning','excused'=>'badge-info'];

include __DIR__ . '/../../templates/layout.php';
?>

<div style="max-width:540px; margin:0 auto;">
  <div class="page-header">
    <div>
      <h1 class="page-title">Mark Attendance</h1>
      <p class="page-subtitle">Enter the code shown by your teacher</p>
    </div>
  </div>

  <?php if ($success && $successSession): ?>
  <!-- ── Success state ───────────────────────────────────── -->
  <div class="card" style="border-color:rgba(34,197,94,0.3);">
    <div class="card-body" style="text-align:center; padding:50px 30px;">
      <div style="width:72px; height:72px; background:rgba(34,197,94,0.15); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px; font-size:32px;">✅</div>
      <div style="font-family:var(--font-display); font-size:1.5rem; font-weight:800; color:var(--success); margin-bottom:8px;">Attendance Recorded!</div>
      <div style="color:var(--text-secondary); font-size:0.9rem; margin-bottom:20px;">
        <strong><?= sanitize($successSession['group_name']) ?></strong>
        <?php if ($successSession['topic']): ?>— <?= sanitize($successSession['topic']) ?><?php endif; ?>
        <br>
        <span style="color:var(--text-muted); font-size:0.8rem;"><?= date('l, M j, Y · H:i') ?></span>
      </div>
      <a href="submit.php" class="btn btn-ghost" style="margin-top:4px;">← Mark Another</a>
    </div>
  </div>

  <?php else: ?>
  <!-- ── Error display ──────────────────────────────────── -->
  <?php if ($errors): ?>
  <div style="background:var(--danger-dim); border:1px solid rgba(239,68,68,0.25); border-radius:var(--radius-md); padding:14px 18px; font-size:0.85rem; color:var(--danger); margin-bottom:20px;">
    <?php foreach ($errors as $e): ?><div>• <?= sanitize($e) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ── Code input form ─────────────────────────────────── -->
  <div class="card">
    <div class="card-header"><span class="card-title">Enter Attendance Code</span></div>
    <div class="card-body">
      <form method="POST" autocomplete="off">
        <?= csrf_field() ?>

        <div class="form-group" style="margin-bottom:24px;">
          <input type="text" name="code" id="codeInput"
                 class="form-control"
                 placeholder="XXXXXX"
                 maxlength="6"
                 style="text-align:center; font-family:var(--font-display); font-size:2.5rem; font-weight:800; letter-spacing:0.4em; text-transform:uppercase; padding:20px; border-width:2px;"
                 oninput="formatCode(this)"
                 autofocus
                 required>
          <div style="text-align:center; margin-top:8px; font-size:0.78rem; color:var(--text-muted);">
            Ask your teacher for today's session code
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-full" id="submitBtn">
          Submit Attendance
        </button>
      </form>
    </div>
  </div>

  <?php endif; ?>

  <!-- ── My group attendance rates ──────────────────────── -->
  <?php if ($groupRates): ?>
  <div class="card" style="margin-top:20px;">
    <div class="card-header"><span class="card-title">My Attendance by Group</span></div>
    <div class="card-body no-padding">
      <?php foreach ($groupRates as $gr): ?>
      <?php
        $rate = (float)$gr['rate'];
        $color = $rate >= 80 ? 'var(--success)' : ($rate >= 60 ? 'var(--warning)' : 'var(--danger)');
      ?>
      <div style="display:flex; align-items:center; gap:12px; padding:12px 20px; border-bottom:1px solid var(--border);">
        <div style="flex:1; min-width:0;">
          <div style="font-weight:500; color:var(--text-primary); font-size:0.85rem;"><?= sanitize($gr['group_name']) ?></div>
          <div style="font-size:0.72rem; color:var(--text-muted);"><?= $gr['present_count'] ?> / <?= $gr['total'] ?> sessions</div>
        </div>
        <div style="width:80px;">
          <div style="height:4px; background:var(--bg-elevated); border-radius:2px; overflow:hidden; margin-bottom:4px;">
            <div style="height:100%; width:<?= $rate ?>%; background:<?= $color ?>; border-radius:2px;"></div>
          </div>
          <div style="font-size:0.78rem; font-weight:600; color:<?= $color ?>; text-align:right;"><?= $rate ?>%</div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Recent history ─────────────────────────────────── -->
  <?php if ($history): ?>
  <div class="card" style="margin-top:20px;">
    <div class="card-header"><span class="card-title">Recent Attendance</span></div>
    <div class="card-body no-padding">
      <table class="data-table">
        <thead><tr><th>Date</th><th>Group</th><th>Topic</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($history as $rec): ?>
          <tr>
            <td style="font-size:0.78rem; color:var(--text-muted);"><?= date('M j', strtotime($rec['session_date'])) ?></td>
            <td class="cell-primary" style="font-size:0.84rem;"><?= sanitize($rec['group_name']) ?></td>
            <td style="font-size:0.78rem; color:var(--text-muted);"><?= sanitize($rec['topic'] ?: '—') ?></td>
            <td><span class="badge <?= $statusBadge[$rec['status']] ?>"><?= ucfirst($rec['status']) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function formatCode(input) {
  input.value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
  const btn = document.getElementById('submitBtn');
  if (btn) btn.disabled = input.value.length !== 6;
}
document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('submitBtn');
  if (btn) btn.disabled = true;
});
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
