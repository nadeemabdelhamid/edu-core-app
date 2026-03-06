<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher');

$pdo = getDB();
$cid = centerId();
$uid = authId();

$pageTitle  = 'Start Attendance Session';
$activePage = 'attendance';
$breadcrumbs = [
    ['label' => 'Dashboard',  'href' => '/educore/dashboard.php'],
    ['label' => 'Attendance', 'href' => '/educore/modules/attendance/'],
    ['label' => 'New Session'],
];

// Load groups for this center (teacher sees only their groups)
$groupQuery = isRole('owner')
    ? "SELECT g.*, u.name as teacher_name FROM `groups` g LEFT JOIN users u ON u.id=g.teacher_id WHERE g.center_id=? AND g.is_active=1 ORDER BY g.name"
    : "SELECT g.*, u.name as teacher_name FROM `groups` g LEFT JOIN users u ON u.id=g.teacher_id WHERE g.center_id=? AND g.teacher_id=? AND g.is_active=1 ORDER BY g.name";

$groupParams = isRole('owner') ? [$cid] : [$cid, $uid];
$groupStmt = $pdo->prepare($groupQuery);
$groupStmt->execute($groupParams);
$groups = $groupStmt->fetchAll();

$errors = [];
$session = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();

    if ($_POST['action'] === 'start') {
        $groupId  = (int)($_POST['group_id'] ?? 0);
        $duration = max(2, min(60, (int)($_POST['duration'] ?? 5)));
        $topic    = trim($_POST['topic'] ?? '');

        // Validate group belongs to center
        $gStmt = $pdo->prepare("SELECT * FROM `groups` WHERE id=? AND center_id=?");
        $gStmt->execute([$groupId, $cid]);
        $group = $gStmt->fetch();

        if (!$group) { $errors[] = 'Invalid group selected.'; }

        if (!$errors) {
            // Generate unique 6-char code
            do {
                $code = strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ23456789'), 0, 6));
                $chk  = $pdo->prepare("SELECT id FROM attendance_sessions WHERE session_code=? AND code_expires_at > NOW()");
                $chk->execute([$code]);
            } while ($chk->fetch());

            $expires = date('Y-m-d H:i:s', time() + ($duration * 60));

            $pdo->prepare("INSERT INTO attendance_sessions (center_id, group_id, teacher_id, session_code, code_expires_at, session_date, topic, status)
                            VALUES (?,?,?,?,?,NOW(),?,'open')")
                ->execute([$cid, $groupId, $uid, $code, $expires, $topic]);
            $sessionId = $pdo->lastInsertId();

            auditLog('attendance.session_started', 'attendance_session', $sessionId, ['group_id'=>$groupId, 'code'=>$code]);

            // Reload with session
            redirect("create.php?session=$sessionId");
        }
    }

    if ($_POST['action'] === 'close') {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $pdo->prepare("UPDATE attendance_sessions SET status='closed' WHERE id=? AND center_id=?")
            ->execute([$sessionId, $cid]);
        flash('success', 'Session closed successfully.');
        redirect('/educore/modules/attendance/');
    }
}

// Load active session if passed
if (isset($_GET['session'])) {
    $sid = (int)$_GET['session'];
    $sStmt = $pdo->prepare("SELECT s.*, g.name as group_name FROM attendance_sessions s JOIN `groups` g ON g.id=s.group_id WHERE s.id=? AND s.center_id=?");
    $sStmt->execute([$sid, $cid]);
    $session = $sStmt->fetch();

    if ($session) {
        // Get attendance records for this session
        $recStmt = $pdo->prepare("SELECT ar.*, st.name as student_name FROM attendance_records ar JOIN students st ON st.id=ar.student_id WHERE ar.session_id=? ORDER BY ar.submitted_at DESC");
        $recStmt->execute([$sid]);
        $records = $recStmt->fetchAll();

        // Get total students in group
        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM group_students WHERE group_id=? AND status='active'");
        $totalStmt->execute([$session['group_id']]);
        $totalStudents = $totalStmt->fetchColumn();
    }
}

include __DIR__ . '/../../templates/layout.php';
?>

<?php if ($session && $session['status'] === 'open'): ?>
<!-- ═══════ LIVE SESSION VIEW ═══════ -->
<div class="page-header">
  <div>
    <h1 class="page-title">Live Attendance Session</h1>
    <p class="page-subtitle"><?= sanitize($session['group_name']) ?> · Session #<?= $session['id'] ?></p>
  </div>
  <form method="POST" style="display:inline;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="close">
    <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
    <button type="submit" class="btn btn-danger"
            onclick="return confirm('Close this session? Students can no longer submit.')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
      Close Session
    </button>
  </form>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">

  <!-- Code display -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Attendance Code</span>
      <span class="badge badge-success">● Live</span>
    </div>
    <div class="card-body attendance-code-display">
      <div class="code-label">Share this code with your students</div>
      <div class="code-value" id="liveCode"><?= sanitize($session['session_code']) ?></div>
      <div class="code-timer">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Expires in <span class="timer-val" id="countdown">--:--</span>
      </div>
      <div style="margin-top:20px; font-size:0.78rem; color:var(--text-muted);">
        Students go to their portal → Attendance → Enter code
      </div>
    </div>
  </div>

  <!-- Stats -->
  <div class="card">
    <div class="card-header"><span class="card-title">Session Stats</span></div>
    <div class="card-body">
      <div class="stats-grid" style="grid-template-columns:1fr 1fr; margin-bottom:0;">
        <div class="stat-card" style="--stat-color:#22c55e; --stat-bg:rgba(34,197,94,0.1)">
          <div class="stat-value" id="presentCount"><?= count($records ?? []) ?></div>
          <div class="stat-label">Present</div>
        </div>
        <div class="stat-card" style="--stat-color:var(--text-muted); --stat-bg:var(--bg-elevated)">
          <div class="stat-value"><?= $totalStudents ?></div>
          <div class="stat-label">Total in Group</div>
        </div>
      </div>

      <div style="margin-top:16px;">
        <div style="display:flex; justify-content:space-between; font-size:0.78rem; color:var(--text-muted); margin-bottom:6px;">
          <span>Attendance rate</span>
          <span id="rateLabel"><?= $totalStudents > 0 ? round((count($records ?? []) / $totalStudents) * 100) : 0 ?>%</span>
        </div>
        <div style="height:6px; background:var(--bg-elevated); border-radius:3px; overflow:hidden;">
          <div id="rateBar" style="height:100%; background:var(--success); border-radius:3px; width:<?= $totalStudents > 0 ? round((count($records ?? []) / $totalStudents) * 100) : 0 ?>%; transition:width 0.5s ease;"></div>
        </div>
      </div>

      <div style="margin-top:16px; padding:12px; background:var(--bg-elevated); border-radius:var(--radius-sm); font-size:0.8rem;">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
          <div><span style="color:var(--text-muted);">Group:</span><br><strong><?= sanitize($session['group_name']) ?></strong></div>
          <div><span style="color:var(--text-muted);">Date:</span><br><strong><?= date('M j, Y') ?></strong></div>
          <?php if ($session['topic']): ?>
          <div style="grid-column:1/-1;"><span style="color:var(--text-muted);">Topic:</span><br><strong><?= sanitize($session['topic']) ?></strong></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Submissions table (live updates) -->
<div class="card" id="submissionsCard">
  <div class="card-header">
    <span class="card-title">Submissions</span>
    <span style="font-size:0.78rem; color:var(--text-muted);">Updates automatically</span>
  </div>
  <div class="card-body no-padding" id="submissionsBody">
    <?php if (!empty($records)): ?>
    <table class="data-table">
      <thead>
        <tr><th>#</th><th>Student</th><th>Status</th><th>Time</th></tr>
      </thead>
      <tbody id="submissionsRows">
        <?php foreach ($records as $i => $rec): ?>
        <tr>
          <td style="color:var(--text-muted)"><?= $i + 1 ?></td>
          <td class="cell-primary"><?= sanitize($rec['student_name']) ?></td>
          <td><span class="badge badge-success">Present</span></td>
          <td style="font-size:0.78rem; color:var(--text-muted);"><?= date('H:i:s', strtotime($rec['submitted_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state" id="emptyState">
      <div class="empty-icon">⏳</div>
      <div class="empty-title">Waiting for students...</div>
      <div class="empty-desc">Students will appear here as they submit the code</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<!-- ═══════ START SESSION FORM ═══════ -->
<div class="page-header">
  <div>
    <h1 class="page-title">Start Attendance Session</h1>
    <p class="page-subtitle">Generate a code for your students to scan in</p>
  </div>
  <a href="/educore/modules/attendance/" class="btn btn-ghost">← Back</a>
</div>

<?php if ($errors): ?>
<div style="background:var(--danger-dim); border:1px solid rgba(239,68,68,0.25); border-radius:var(--radius-md); padding:14px 18px; font-size:0.84rem; color:var(--danger); margin-bottom:20px;">
  <?php foreach ($errors as $e): ?><div>• <?= sanitize($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($groups)): ?>
<div class="card">
  <div class="empty-state">
    <div class="empty-icon">📚</div>
    <div class="empty-title">No groups available</div>
    <div class="empty-desc">
      <a href="/educore/modules/groups/create.php" class="btn btn-primary btn-sm" style="margin-top:14px;">Create a Group</a>
    </div>
  </div>
</div>
<?php else: ?>
<div style="max-width:600px;">
  <div class="card">
    <div class="card-header"><span class="card-title">Session Setup</span></div>
    <div class="card-body">
      <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="start">

        <div class="form-group">
          <label class="form-label required">Group</label>
          <select name="group_id" class="form-control" required>
            <option value="">Select a group...</option>
            <?php foreach ($groups as $g): ?>
            <option value="<?= $g['id'] ?>"><?= sanitize($g['name']) ?><?= $g['subject'] ? ' — ' . sanitize($g['subject']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Session Topic <span style="color:var(--text-muted)">(optional)</span></label>
          <input type="text" name="topic" class="form-control" placeholder="e.g. Chapter 4 — Algebra">
        </div>

        <div class="form-group">
          <label class="form-label required">Code Expiry Duration</label>
          <select name="duration" class="form-control">
            <option value="3">3 minutes</option>
            <option value="5" selected>5 minutes</option>
            <option value="10">10 minutes</option>
            <option value="15">15 minutes</option>
            <option value="30">30 minutes</option>
          </select>
          <span class="form-hint">Students must submit within this time window.</span>
        </div>

        <div style="background:var(--accent-subtle); border:1px solid rgba(245,166,35,0.2); border-radius:var(--radius-sm); padding:14px; font-size:0.82rem; color:var(--text-secondary); margin-bottom:20px;">
          <strong style="color:var(--accent);">How it works:</strong> A unique code is generated and shown on your screen. Students enter it from their portal. The system validates their group membership and prevents duplicate submissions.
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-full">
          🚀 Start Session & Generate Code
        </button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
$expiresAt = $session ? strtotime($session['code_expires_at']) : 0;
$sessionId = $session ? $session['id'] : 0;
$extraScripts = <<<JS
<script>
// ─── COUNTDOWN TIMER ─────────────────────────────────────
(function() {
  const expiresAt = {$expiresAt};
  if (!expiresAt) return;
  const el = document.getElementById('countdown');
  if (!el) return;

  function update() {
    const now = Math.floor(Date.now() / 1000);
    const diff = expiresAt - now;
    if (diff <= 0) {
      el.textContent = 'Expired';
      el.parentElement.style.color = 'var(--danger)';
      return;
    }
    const m = Math.floor(diff / 60);
    const s = diff % 60;
    el.textContent = m + ':' + String(s).padStart(2, '0');
    if (diff < 60) el.style.color = 'var(--danger)';
    else if (diff < 120) el.style.color = 'var(--warning)';
    setTimeout(update, 1000);
  }
  update();
})();

// ─── LIVE POLL FOR SUBMISSIONS ───────────────────────────
(function() {
  const sessionId = {$sessionId};
  if (!sessionId) return;

  let lastCount = document.querySelectorAll('#submissionsRows tr')?.length || 0;

  function poll() {
    fetch('/educore/modules/attendance/api_poll.php?session=' + sessionId)
      .then(r => r.json())
      .then(data => {
        if (data.count !== undefined) {
          document.getElementById('presentCount').textContent = data.count;
          const rate = data.total > 0 ? Math.round((data.count / data.total) * 100) : 0;
          document.getElementById('rateLabel').textContent = rate + '%';
          document.getElementById('rateBar').style.width = rate + '%';
        }

        if (data.rows && data.count > lastCount) {
          lastCount = data.count;
          const empty = document.getElementById('emptyState');
          if (empty) empty.remove();

          let tbody = document.getElementById('submissionsRows');
          if (!tbody) {
            document.getElementById('submissionsBody').innerHTML = '<table class="data-table"><thead><tr><th>#</th><th>Student</th><th>Status</th><th>Time</th></tr></thead><tbody id="submissionsRows"></tbody></table>';
            tbody = document.getElementById('submissionsRows');
          }
          tbody.innerHTML = data.rows;
        }
      })
      .catch(() => {});

    setTimeout(poll, 3000);
  }

  setTimeout(poll, 3000);
})();
</script>
JS;

include __DIR__ . '/../../templates/layout_end.php';
?>
