<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('student', 'owner', 'teacher', 'assistant');

$pdo = getDB();
$cid = centerId();
$uid = authId();
$user = auth();

// Link user to student record
$stuStmt = $pdo->prepare("SELECT * FROM students WHERE user_id = ? AND center_id = ?");
$stuStmt->execute([$uid, $cid]);
$student = $stuStmt->fetch();

$pageTitle  = 'Attendance Verification';
$activePage = 'attendance';

$error   = '';
$success = '';

// Handle code submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Collect the 6 individual inputs
    $parts = [];
    for ($i = 1; $i <= 6; $i++) {
        $parts[] = strtoupper(trim($_POST["c$i"] ?? ''));
    }
    $code = implode('', $parts);

    if (strlen($code) !== 6) {
        $error = 'Please enter all 6 characters of the code.';
    } else {
        // Find active session with this code
        $sessStmt = $pdo->prepare("
            SELECT s.*, g.name AS group_name
            FROM attendance_sessions s
            JOIN `groups` g ON g.id = s.group_id
            WHERE s.center_id = ?
              AND s.attendance_code = ?
              AND s.status = 'open'
              AND s.code_expires_at > NOW()
        ");
        $sessStmt->execute([$cid, $code]);
        $session = $sessStmt->fetch();

        if (!$session) {
            $error = 'Invalid or expired code. Please check with your instructor.';
        } elseif (!$student) {
            $error = 'Your account is not linked to a student record. Please contact your teacher.';
        } else {
            // Check enrollment
            $enrollCheck = $pdo->prepare("SELECT id FROM group_students WHERE group_id = ? AND student_id = ? AND status = 'active'");
            $enrollCheck->execute([$session['group_id'], $student['id']]);

            if (!$enrollCheck->fetch()) {
                $error = 'You are not enrolled in this group.';
            } else {
                // Check duplicate
                $dupCheck = $pdo->prepare("SELECT id FROM attendance_records WHERE session_id = ? AND student_id = ?");
                $dupCheck->execute([$session['id'], $student['id']]);

                if ($dupCheck->fetch()) {
                    $error = 'You have already submitted attendance for this session.';
                } else {
                    // Record attendance
                    $pdo->prepare("INSERT INTO attendance_records (session_id, student_id, center_id, status, submitted_at, ip_address)
                                    VALUES (?,?,?,'present',NOW(),?)")
                        ->execute([$session['id'], $student['id'], $cid, $_SERVER['REMOTE_ADDR'] ?? '']);
                    $success = 'Attendance confirmed for ' . $session['group_name'] . '!';
                }
            }
        }
    }
}

// Recent attendance history
$history = [];
if ($student) {
    $histStmt = $pdo->prepare("
        SELECT ar.*, s.session_date, g.name AS group_name
        FROM attendance_records ar
        JOIN attendance_sessions s ON s.id = ar.session_id
        JOIN `groups` g ON g.id = s.group_id
        WHERE ar.student_id = ?
        ORDER BY s.session_date DESC
        LIMIT 5
    ");
    $histStmt->execute([$student['id']]);
    $history = $histStmt->fetchAll();
}

// Check if there's a live session for student's groups
$liveSession = null;
if ($student) {
    $liveStmt = $pdo->prepare("
        SELECT s.*, g.name AS group_name, u.name AS teacher_name
        FROM attendance_sessions s
        JOIN `groups` g ON g.id = s.group_id
        JOIN users u ON u.id = s.teacher_id
        JOIN group_students gs ON gs.group_id = s.group_id AND gs.student_id = ?
        WHERE s.status = 'open' AND s.code_expires_at > NOW()
        LIMIT 1
    ");
    $liveStmt->execute([$student['id']]);
    $liveSession = $liveStmt->fetch();
}

$initials = strtoupper(substr($user['name'], 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Attendance Verification — EduCore</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
  <link rel="stylesheet" href="/educore/assets/css/app.css">
</head>
<body>

<div class="app-layout">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <a href="/educore/dashboard.php" class="sidebar-brand">
      <div class="brand-logo">
        <span class="material-symbols-outlined">school</span>
      </div>
      <div>
        <div class="brand-name">EduSaaS</div>
      </div>
    </a>

    <nav class="sidebar-nav">
      <a href="/educore/dashboard.php" class="nav-item">
        <span class="material-symbols-outlined">home</span> Home
      </a>
      <a href="/educore/modules/groups/" class="nav-item">
        <span class="material-symbols-outlined">book</span> Classes
      </a>
      <a href="/educore/modules/attendance/submit.php" class="nav-item active">
        <span class="material-symbols-outlined" style="font-variation-settings:'FILL' 1;">check_circle</span> Attendance
      </a>
      <a href="/educore/modules/students/view.php?self=1" class="nav-item">
        <span class="material-symbols-outlined">person</span> Profile
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="user-card">
        <div class="user-avatar"><?= $initials ?></div>
        <div style="flex:1; min-width:0;">
          <div class="user-name"><?= sanitize($user['name']) ?></div>
          <div class="user-role">
            ID: <?= sanitize($student['student_code'] ?? 'N/A') ?>
          </div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="main-content">

    <!-- Topbar -->
    <header class="topbar">
      <h1 style="font-size:1rem; font-weight:600; color:var(--text-primary);">Attendance Verification</h1>
      <div class="topbar-spacer"></div>
      <div class="topbar-search" style="width:240px;">
        <span class="material-symbols-outlined">search</span>
        <input type="text" placeholder="Search sessions...">
      </div>
      <button class="topbar-icon-btn">
        <span class="material-symbols-outlined">notifications</span>
      </button>
      <div class="user-avatar" style="width:32px;height:32px;font-size:0.7rem; background:var(--primary-light); color:var(--primary);"><?= $initials ?></div>
    </header>

    <main class="page-content" style="max-width:860px; margin:0 auto;">

      <!-- Breadcrumb -->
      <div class="breadcrumb" style="margin-bottom:8px;">
        <span>Attendance</span>
        <span class="material-symbols-outlined">chevron_right</span>
        <span class="crumb-current">Session Verification</span>
      </div>

      <h2 class="page-title" style="font-size:2rem; margin-bottom:4px;">Confirm Your Presence</h2>
      <p style="color:var(--text-muted); font-size:1rem; margin-bottom:28px;">
        Enter the 6-digit code provided by your instructor during the live session.
      </p>

      <!-- Main card -->
      <div class="card" style="margin-bottom:24px;">
        <div style="display:grid; grid-template-columns:2fr 3fr;">

          <!-- Left panel - session info -->
          <div style="background:var(--bg-elevated); padding:32px; border-right:1px solid var(--border);">

            <?php if ($liveSession): ?>
            <!-- Live now badge -->
            <div style="display:inline-flex; align-items:center; gap:7px; padding:5px 14px; background:#fee2e2; color:#dc2626; border-radius:var(--radius-full); font-size:0.72rem; font-weight:800; letter-spacing:0.06em; text-transform:uppercase; margin-bottom:24px;">
              <span style="width:7px;height:7px;background:#ef4444;border-radius:50%;animation:livePulse 1.5s infinite; display:inline-block;"></span>
              Live Now
            </div>

            <div style="display:flex; flex-direction:column; gap:20px;">
              <div>
                <div style="font-size:0.65rem; font-weight:700; letter-spacing:0.1em; text-transform:uppercase; color:var(--text-muted); margin-bottom:6px;">Current Course</div>
                <div style="font-size:1.1rem; font-weight:800; color:var(--text-primary); line-height:1.3;"><?= sanitize($liveSession['group_name']) ?></div>
                <?php if ($liveSession['topic']): ?>
                <div style="font-size:0.82rem; color:var(--text-muted); margin-top:3px;"><?= sanitize($liveSession['topic']) ?></div>
                <?php endif; ?>
              </div>

              <div>
                <div style="font-size:0.65rem; font-weight:700; letter-spacing:0.1em; text-transform:uppercase; color:var(--text-muted); margin-bottom:6px;">Instructor</div>
                <div style="display:flex; align-items:center; gap:10px;">
                  <div style="width:38px;height:38px;border-radius:var(--radius-md); background:var(--primary-light); display:flex;align-items:center;justify-content:center; font-weight:700; color:var(--primary); font-size:0.8rem; flex-shrink:0;">
                    <?= strtoupper(substr($liveSession['teacher_name'],0,2)) ?>
                  </div>
                  <span style="font-size:0.9rem; font-weight:600; color:var(--text-primary);"><?= sanitize($liveSession['teacher_name']) ?></span>
                </div>
              </div>

              <div>
                <div style="font-size:0.65rem; font-weight:700; letter-spacing:0.1em; text-transform:uppercase; color:var(--text-muted); margin-bottom:6px;">Date</div>
                <div style="display:flex;align-items:center;gap:8px;color:var(--text-secondary); font-size:0.875rem;">
                  <span class="material-symbols-outlined" style="font-size:18px;">event</span>
                  <?= date('l, M j, Y', strtotime($liveSession['session_date'])) ?>
                </div>
              </div>
            </div>

            <div style="margin-top:32px; padding-top:20px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between;">
              <span style="font-size:0.8rem; font-style:italic; color:var(--text-muted);">Session is live now</span>
              <a href="/educore/modules/attendance/" style="color:var(--primary); font-size:0.8rem; font-weight:600; text-decoration:none; display:flex; align-items:center; gap:4px;">
                Details <span class="material-symbols-outlined" style="font-size:14px;">open_in_new</span>
              </a>
            </div>

            <?php else: ?>
            <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:200px; text-align:center; color:var(--text-muted);">
              <span class="material-symbols-outlined" style="font-size:40px; opacity:0.3; margin-bottom:12px;">event_busy</span>
              <div style="font-weight:600; color:var(--text-secondary);">No live sessions</div>
              <div style="font-size:0.8rem; margin-top:4px;">Wait for your instructor to start a session</div>
            </div>
            <?php endif; ?>
          </div>

          <!-- Right panel - code entry -->
          <div style="padding:40px; display:flex; flex-direction:column; align-items:center; justify-content:center;">

            <!-- Icon -->
            <div style="width:56px;height:56px; background:var(--primary-light); border-radius:50%; display:flex; align-items:center; justify-content:center; margin-bottom:18px;">
              <span class="material-symbols-outlined" style="font-size:28px; color:var(--primary);">pin</span>
            </div>

            <h3 style="font-size:1.4rem; font-weight:800; color:var(--text-primary); margin-bottom:8px; text-align:center;">Enter Session Code</h3>
            <p style="color:var(--text-muted); font-size:0.875rem; text-align:center; margin-bottom:28px;">
              The code expires shortly. Please be quick!
            </p>

            <!-- Error / Success -->
            <?php if ($error): ?>
            <div style="width:100%; background:var(--danger-bg); border:1px solid #fca5a5; border-radius:var(--radius-md); padding:12px 16px; font-size:0.84rem; color:var(--danger-text); margin-bottom:20px; display:flex; align-items:center; gap:8px;">
              <span class="material-symbols-outlined" style="font-size:18px;">error</span>
              <?= sanitize($error) ?>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div style="width:100%; background:var(--success-bg); border:1px solid #a7f3d0; border-radius:var(--radius-md); padding:12px 16px; font-size:0.84rem; color:var(--success-text); margin-bottom:20px; display:flex; align-items:center; gap:8px;">
              <span class="material-symbols-outlined" style="font-size:18px;">check_circle</span>
              <?= sanitize($success) ?>
            </div>
            <?php endif; ?>

            <!-- 6-digit inputs -->
            <form method="POST" id="codeForm" style="width:100%;">
              <?= csrf_field() ?>

              <div class="code-input-grid" style="margin-bottom:24px;">
                <?php for ($i = 1; $i <= 6; $i++): ?>
                  <?php if ($i === 4): ?><span class="code-sep">—</span><?php endif; ?>
                  <input type="text" name="c<?= $i ?>" id="c<?= $i ?>"
                         maxlength="1" placeholder="•" autocomplete="off"
                         style="text-transform:uppercase;"
                         oninput="moveNext(this, <?= $i ?>)"
                         onkeydown="movePrev(event, <?= $i ?>)">
                <?php endfor; ?>
              </div>

              <!-- Timer -->
              <?php if ($liveSession): ?>
              <div style="display:flex; flex-direction:column; align-items:center; gap:10px; margin-bottom:24px;">
                <div style="display:flex; align-items:center; gap:8px; color:var(--text-secondary); font-weight:500;">
                  <span class="material-symbols-outlined" style="color:var(--primary); font-size:20px;">timer</span>
                  <span>Time remaining:</span>
                  <span id="timerVal" style="color:var(--primary); font-weight:800; font-variant-numeric:tabular-nums;">—</span>
                </div>
                <div class="progress-bar" style="width:100%;">
                  <div class="progress-fill" id="timerBar" style="background:var(--primary); width:100%;"></div>
                </div>
              </div>
              <?php endif; ?>

              <!-- Submit button -->
              <button type="submit" class="btn btn-primary btn-lg w-full" style="justify-content:center; gap:10px;">
                <span class="material-symbols-outlined">verified_user</span>
                Confirm Attendance
              </button>

            </form>

            <!-- Footer links -->
            <div style="display:flex; align-items:center; gap:16px; margin-top:20px; font-size:0.84rem; font-weight:500; color:var(--text-muted);">
              <a href="#history" style="text-decoration:none; color:var(--text-muted); display:flex; align-items:center; gap:5px; transition:color 0.15s;"
                 onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-muted)'">
                <span class="material-symbols-outlined" style="font-size:18px;">history</span>
                Attendance History
              </a>
              <span style="color:var(--border);">|</span>
              <a href="#" style="text-decoration:none; color:var(--text-muted); display:flex; align-items:center; gap:5px; transition:color 0.15s;"
                 onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-muted)'">
                <span class="material-symbols-outlined" style="font-size:18px;">help_outline</span>
                Need Help?
              </a>
            </div>

          </div>
        </div>
      </div>

      <!-- Tip cards -->
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px;">
        <div class="card">
          <div class="card-body" style="display:flex; align-items:flex-start; gap:16px; padding:20px;">
            <div style="width:46px;height:46px;background:#d1fae5;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
              <span class="material-symbols-outlined" style="color:#059669;">lightbulb</span>
            </div>
            <div>
              <div style="font-weight:700; margin-bottom:6px;">Quick Tip</div>
              <p style="font-size:0.84rem; color:var(--text-muted); line-height:1.6;">
                The instructor usually reveals the code twice: at the beginning and middle of the lecture. Keep your eyes on the projector!
              </p>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-body" style="display:flex; align-items:flex-start; gap:16px; padding:20px;">
            <div style="width:46px;height:46px;background:var(--warning-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
              <span class="material-symbols-outlined" style="color:var(--warning);">warning</span>
            </div>
            <div>
              <div style="font-weight:700; margin-bottom:6px;">Having Issues?</div>
              <p style="font-size:0.84rem; color:var(--text-muted); line-height:1.6;">
                If the code isn't working, ensure you are enrolled in the group or contact your teacher immediately after the session.
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- History -->
      <?php if ($history): ?>
      <div class="card" id="history">
        <div class="card-header">
          <span class="card-title">Recent Attendance</span>
        </div>
        <div class="card-body no-padding">
          <table class="data-table">
            <thead><tr><th>Date</th><th>Group</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($history as $h): ?>
              <tr>
                <td><?= date('M j, Y', strtotime($h['session_date'])) ?></td>
                <td class="cell-primary"><?= sanitize($h['group_name']) ?></td>
                <td>
                  <?php $bmap = ['present'=>'badge-success','absent'=>'badge-danger','late'=>'badge-warning','excused'=>'badge-info']; ?>
                  <span class="badge <?= $bmap[$h['status']] ?? 'badge-muted' ?>">
                    <span class="badge-dot"></span>
                    <?= ucfirst($h['status']) ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>

<script>
// Auto-advance between code inputs
function moveNext(el, idx) {
  el.value = el.value.toUpperCase();
  if (el.value.length === 1 && idx < 6) {
    document.getElementById('c' + (idx + 1)).focus();
  }
  if (idx === 6) {
    // All filled — auto submit
    const allFilled = [...Array(6).keys()].every(i => document.getElementById('c'+(i+1)).value.length === 1);
    if (allFilled) document.getElementById('codeForm').submit();
  }
}

function movePrev(e, idx) {
  if (e.key === 'Backspace' && !e.target.value && idx > 1) {
    document.getElementById('c' + (idx - 1)).focus();
  }
}

// Paste handler — distribute 6 chars
document.getElementById('c1').addEventListener('paste', function(e) {
  const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\W/g,'').toUpperCase();
  if (text.length >= 6) {
    e.preventDefault();
    for (let i = 1; i <= 6; i++) {
      document.getElementById('c' + i).value = text[i-1] || '';
    }
    document.getElementById('c6').focus();
  }
});

// Timer countdown
<?php if ($liveSession): ?>
const expiresAt = new Date('<?= date('c', strtotime($liveSession['code_expires_at'])) ?>').getTime();
const totalMs   = expiresAt - new Date('<?= date('c', strtotime($liveSession['created_at'])) ?>').getTime();

function updateTimer() {
  const remaining = Math.max(0, expiresAt - Date.now());
  const mins = Math.floor(remaining / 60000);
  const secs = Math.floor((remaining % 60000) / 1000);
  const timerEl = document.getElementById('timerVal');
  const barEl   = document.getElementById('timerBar');
  if (!timerEl) return;

  timerEl.textContent = mins + ':' + String(secs).padStart(2, '0');
  const pct = Math.max(0, (remaining / totalMs) * 100);
  barEl.style.width = pct + '%';

  // Color changes
  if (remaining < 60000) {
    timerEl.style.color = 'var(--danger)';
    barEl.style.background = 'var(--danger)';
  } else if (remaining < 120000) {
    timerEl.style.color = 'var(--warning)';
    barEl.style.background = 'var(--warning)';
  }

  if (remaining === 0) {
    timerEl.textContent = 'Expired';
    clearInterval(timerInterval);
  }
}

const timerInterval = setInterval(updateTimer, 1000);
updateTimer();
<?php endif; ?>
</script>

</body>
</html>
