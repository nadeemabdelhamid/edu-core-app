<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher');

$pdo = getDB();
$cid = centerId();
$uid = authId();

$pageTitle  = 'Start Attendance Session';
$activePage = 'attendance';
$breadcrumbs = [
    ['label' => 'Attendance', 'href' => '/educore/modules/attendance/'],
    ['label' => 'New Session'],
];

// Load groups
$groupQuery = isRole('owner')
    ? "SELECT g.*, u.name as teacher_name FROM `groups` g LEFT JOIN users u ON u.id=g.teacher_id WHERE g.center_id=? AND g.is_active=1 ORDER BY g.name"
    : "SELECT g.*, u.name as teacher_name FROM `groups` g LEFT JOIN users u ON u.id=g.teacher_id WHERE g.center_id=? AND g.teacher_id=? AND g.is_active=1 ORDER BY g.name";

$groupParams = isRole('owner') ? [$cid] : [$cid, $uid];
$groupStmt   = $pdo->prepare($groupQuery);
$groupStmt->execute($groupParams);
$groups = $groupStmt->fetchAll();

$errors  = [];
$session = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();

    if ($_POST['action'] === 'start') {
        $groupId  = (int)($_POST['group_id'] ?? 0);
        $duration = max(2, min(60, (int)($_POST['duration'] ?? 5)));
        $topic    = trim($_POST['topic'] ?? '');

        $gStmt = $pdo->prepare("SELECT * FROM `groups` WHERE id=? AND center_id=?");
        $gStmt->execute([$groupId, $cid]);
        $group = $gStmt->fetch();

        if (!$group) { $errors[] = 'Invalid group selected.'; }

        if (!$errors) {
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

            auditLog('attendance.session_started', 'attendance_session', $sessionId, ['group_id'=>$groupId,'code'=>$code]);
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

// Load active session
if (isset($_GET['session'])) {
    $sid    = (int)$_GET['session'];
    $sStmt  = $pdo->prepare("SELECT s.*, g.name as group_name, g.subject FROM attendance_sessions s JOIN `groups` g ON g.id=s.group_id WHERE s.id=? AND s.center_id=?");
    $sStmt->execute([$sid, $cid]);
    $session = $sStmt->fetch();

    if ($session) {
        $recStmt = $pdo->prepare("SELECT ar.*, st.name as student_name FROM attendance_records ar JOIN students st ON st.id=ar.student_id WHERE ar.session_id=? ORDER BY ar.submitted_at DESC");
        $recStmt->execute([$sid]);
        $records = $recStmt->fetchAll();

        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM group_students WHERE group_id=? AND status='active'");
        $totalStmt->execute([$session['group_id']]);
        $totalStudents = $totalStmt->fetchColumn();
    }
}

include __DIR__ . '/../../templates/layout.php';
?>

<?php if ($session && $session['status'] === 'open'): ?>
<?php
    $present      = count($records ?? []);
    $rate         = $totalStudents > 0 ? round(($present / $totalStudents) * 100) : 0;
    $expiresTs    = strtotime($session['code_expires_at']);
    $durationSecs = $expiresTs - strtotime($session['created_at']);
    $code         = $session['session_code'];
    $codeParts    = [substr($code,0,3), substr($code,3,3)];
?>

<!-- Page header -->
<div class="page-header">
  <div>
    <div class="breadcrumb" style="margin-bottom:6px;">
      <a href="/educore/modules/attendance/">Attendance</a>
      <span class="material-symbols-outlined">chevron_right</span>
      <span class="crumb-current">Live Session</span>
    </div>
    <h1 class="page-title">Live Attendance Session</h1>
    <p class="page-subtitle"><?= sanitize($session['group_name']) ?> · <?= date('l, M j, Y') ?></p>
  </div>
  <form method="POST" style="display:inline;">
    <?= csrf_field() ?>
    <input type="hidden" name="action"     value="close">
    <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
    <button type="submit" class="btn btn-ghost"
            onclick="return confirm('Close this session? Students can no longer submit.')">
      <span class="material-symbols-outlined">stop_circle</span>
      End Session
    </button>
  </form>
</div>

<!-- ─── TOP ROW ──────────────────────────────────────────── -->
<div style="display:grid; grid-template-columns:1.1fr 0.9fr; gap:20px; margin-bottom:20px;">

  <!-- ╔══════════════════════════════════╗
       ║   LIVE CODE CARD                ║
       ╚══════════════════════════════════╝ -->
  <div style="
    background: linear-gradient(135deg, var(--primary) 0%, #3444a3 100%);
    border-radius: var(--radius-xl);
    padding: 32px;
    color: white;
    position: relative;
    overflow: hidden;
    box-shadow: 0 12px 40px rgba(64,81,181,0.35);
  ">
    <!-- Background decorations -->
    <div style="position:absolute; right:-40px; top:-40px; width:180px; height:180px; background:rgba(255,255,255,0.06); border-radius:50%;"></div>
    <div style="position:absolute; right:40px; bottom:-60px; width:130px; height:130px; background:rgba(255,255,255,0.04); border-radius:50%;"></div>

    <div style="position:relative; z-index:1;">
      <!-- Header row -->
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:28px;">
        <div>
          <div style="font-size:0.65rem; font-weight:800; letter-spacing:0.14em; text-transform:uppercase; opacity:0.7; margin-bottom:4px;">Attendance Code</div>
          <div style="font-size:0.95rem; font-weight:700; opacity:0.9;"><?= sanitize($session['group_name']) ?></div>
        </div>
        <div style="display:flex; align-items:center; gap:7px; background:rgba(255,255,255,0.18); backdrop-filter:blur(6px); padding:7px 14px; border-radius:var(--radius-full);">
          <span style="width:7px;height:7px; background:#4ade80; border-radius:50%; animation:livePulse 1.5s infinite; display:inline-block; box-shadow:0 0 8px #4ade80;"></span>
          <span style="font-size:0.68rem; font-weight:800; letter-spacing:0.12em; text-transform:uppercase;">LIVE</span>
        </div>
      </div>

      <!-- Code display -->
      <div style="text-align:center; margin-bottom:28px;">
        <div style="display:flex; align-items:center; justify-content:center; gap:14px;">
          <div style="display:flex; gap:6px;">
            <?php foreach (str_split($codeParts[0]) as $char): ?>
            <div style="
              width:54px; height:64px;
              background:rgba(255,255,255,0.15);
              border:1px solid rgba(255,255,255,0.25);
              border-radius:10px;
              display:flex; align-items:center; justify-content:center;
              font-size:1.8rem; font-weight:900; letter-spacing:0;
              backdrop-filter:blur(4px);
              box-shadow: inset 0 1px 0 rgba(255,255,255,0.2);
            "><?= $char ?></div>
            <?php endforeach; ?>
          </div>
          <div style="font-size:1.4rem; opacity:0.4; font-weight:300;">—</div>
          <div style="display:flex; gap:6px;">
            <?php foreach (str_split($codeParts[1]) as $char): ?>
            <div style="
              width:54px; height:64px;
              background:rgba(255,255,255,0.15);
              border:1px solid rgba(255,255,255,0.25);
              border-radius:10px;
              display:flex; align-items:center; justify-content:center;
              font-size:1.8rem; font-weight:900; letter-spacing:0;
              backdrop-filter:blur(4px);
              box-shadow: inset 0 1px 0 rgba(255,255,255,0.2);
            "><?= $char ?></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Timer bar -->
      <div style="margin-bottom:20px;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
          <div style="display:flex; align-items:center; gap:7px; font-size:0.82rem; opacity:0.85;">
            <span class="material-symbols-outlined" style="font-size:17px;">timer</span>
            Expires in
          </div>
          <span id="countdown" style="font-size:1rem; font-weight:800; font-variant-numeric:tabular-nums;">--:--</span>
        </div>
        <div style="height:5px; background:rgba(255,255,255,0.2); border-radius:5px; overflow:hidden;">
          <div id="timerBar" style="height:100%; background:#4ade80; border-radius:5px; width:100%; transition:width 1s linear;"></div>
        </div>
      </div>

      <!-- Instructions -->
      <div style="background:rgba(255,255,255,0.1); border-radius:var(--radius-md); padding:12px 16px; font-size:0.78rem; opacity:0.85; display:flex; align-items:center; gap:10px;">
        <span class="material-symbols-outlined" style="font-size:18px; opacity:0.7;">route</span>
        Students go to their portal → Attendance → Enter code
      </div>
    </div>
  </div>

  <!-- ╔══════════════════════════════════╗
       ║   STATS + SESSION INFO          ║
       ╚══════════════════════════════════╝ -->
  <div style="display:flex; flex-direction:column; gap:16px;">

    <!-- Attendance progress card -->
    <div class="card" style="flex:1;">
      <div class="card-header">
        <span class="card-title">Attendance Progress</span>
        <span style="font-size:0.82rem; color:var(--text-muted);" id="liveIndicator">
          <span style="display:inline-flex; align-items:center; gap:5px;">
            <span style="width:6px;height:6px;background:#4ade80;border-radius:50%;animation:livePulse 2s infinite;display:inline-block;"></span>
            Updating live
          </span>
        </span>
      </div>
      <div class="card-body">
        <!-- Big number -->
        <div style="display:flex; align-items:flex-end; gap:8px; margin-bottom:4px;">
          <span id="presentCount" style="font-size:3rem; font-weight:900; color:var(--primary); line-height:1; letter-spacing:-0.04em;"><?= $present ?></span>
          <span style="font-size:1.1rem; color:var(--text-muted); margin-bottom:6px; font-weight:500;">/ <?= $totalStudents ?> students</span>
        </div>
        <div style="font-size:0.82rem; color:var(--text-muted); margin-bottom:16px;">have submitted attendance</div>

        <!-- Rate bar -->
        <div style="display:flex; justify-content:space-between; font-size:0.78rem; color:var(--text-muted); margin-bottom:6px;">
          <span>Attendance rate</span>
          <span id="rateLabel" style="font-weight:700; color:var(--primary);"><?= $rate ?>%</span>
        </div>
        <div style="height:8px; background:var(--bg-elevated); border-radius:var(--radius-full); overflow:hidden; border:1px solid var(--border);">
          <div id="rateBar" style="height:100%; background:var(--primary); border-radius:var(--radius-full); width:<?= $rate ?>%; transition:width 0.6s ease;"></div>
        </div>

        <!-- Mini stats row -->
        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-top:16px;">
          <div style="text-align:center; padding:10px 8px; background:var(--bg-elevated); border-radius:var(--radius-md); border:1px solid var(--border);">
            <div id="presentNum" style="font-size:1.2rem; font-weight:800; color:var(--success);"><?= $present ?></div>
            <div style="font-size:0.68rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.05em; margin-top:2px;">Present</div>
          </div>
          <div style="text-align:center; padding:10px 8px; background:var(--bg-elevated); border-radius:var(--radius-md); border:1px solid var(--border);">
            <div id="absentNum" style="font-size:1.2rem; font-weight:800; color:var(--danger);"><?= $totalStudents - $present ?></div>
            <div style="font-size:0.68rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.05em; margin-top:2px;">Absent</div>
          </div>
          <div style="text-align:center; padding:10px 8px; background:var(--bg-elevated); border-radius:var(--radius-md); border:1px solid var(--border);">
            <div style="font-size:1.2rem; font-weight:800; color:var(--text-secondary);"><?= $totalStudents ?></div>
            <div style="font-size:0.68rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.05em; margin-top:2px;">Total</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Session info pill -->
    <div class="card">
      <div class="card-body" style="padding:16px 20px;">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:0.82rem;">
          <div>
            <div style="color:var(--text-muted); font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:3px;">Session ID</div>
            <div style="font-weight:700; color:var(--primary);">#<?= $session['id'] ?></div>
          </div>
          <div>
            <div style="color:var(--text-muted); font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:3px;">Started</div>
            <div style="font-weight:600;"><?= date('H:i', strtotime($session['created_at'])) ?></div>
          </div>
          <?php if ($session['topic']): ?>
          <div style="grid-column:1/-1;">
            <div style="color:var(--text-muted); font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:3px;">Topic</div>
            <div style="font-weight:600;"><?= sanitize($session['topic']) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($session['subject']): ?>
          <div style="grid-column:1/-1;">
            <div style="color:var(--text-muted); font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:3px;">Subject</div>
            <div style="font-weight:600;"><?= sanitize($session['subject']) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- ─── SUBMISSIONS TABLE ──────────────────────────────────── -->
<div class="card" id="submissionsCard">
  <div class="card-header">
    <div style="display:flex; align-items:center; gap:10px;">
      <span class="card-title">Student Submissions</span>
      <span id="submissionBadge" style="
        background:var(--primary-light); color:var(--primary);
        padding:2px 10px; border-radius:var(--radius-full);
        font-size:0.72rem; font-weight:700;
      "><?= $present ?></span>
    </div>
    <span style="font-size:0.78rem; color:var(--text-muted); display:flex; align-items:center; gap:5px;">
      <span class="material-symbols-outlined" style="font-size:14px;">sync</span>
      Auto-refreshing every 3s
    </span>
  </div>
  <div class="card-body no-padding" id="submissionsBody">
    <?php if (!empty($records)): ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Student</th>
          <th>Status</th>
          <th>Submitted at</th>
        </tr>
      </thead>
      <tbody id="submissionsRows">
        <?php foreach ($records as $i => $rec): ?>
        <tr>
          <td style="color:var(--text-muted); font-size:0.8rem;"><?= $i + 1 ?></td>
          <td>
            <div style="display:flex; align-items:center; gap:10px;">
              <div style="width:30px;height:30px;border-radius:50%;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:0.68rem;font-weight:700;flex-shrink:0;">
                <?= strtoupper(substr($rec['student_name'],0,2)) ?>
              </div>
              <span class="cell-primary"><?= sanitize($rec['student_name']) ?></span>
            </div>
          </td>
          <td><span class="badge badge-success"><span class="badge-dot"></span>Present</span></td>
          <td style="font-size:0.78rem; color:var(--text-muted);"><?= date('H:i:s', strtotime($rec['submitted_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state" id="emptyState" style="padding:48px 20px;">
      <div style="width:56px;height:56px;background:var(--primary-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
        <span class="material-symbols-outlined" style="font-size:28px;color:var(--primary);">groups</span>
      </div>
      <div class="empty-title">Waiting for students...</div>
      <div class="empty-desc" style="margin-top:4px;">Students will appear here as they submit the attendance code</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<!-- ═══════ START SESSION FORM ═══════════════════════════════ -->
<div class="page-header">
  <div>
    <h1 class="page-title">Start Attendance Session</h1>
    <p class="page-subtitle">Generate a live code for your students</p>
  </div>
  <a href="/educore/modules/attendance/" class="btn btn-ghost">
    <span class="material-symbols-outlined">arrow_back</span>
    Back
  </a>
</div>

<?php if ($errors): ?>
<div style="background:var(--danger-bg); border:1px solid #fca5a5; border-radius:var(--radius-md); padding:14px 18px; font-size:0.84rem; color:var(--danger-text); margin-bottom:20px; display:flex; align-items:center; gap:10px;">
  <span class="material-symbols-outlined" style="font-size:18px; flex-shrink:0;">error</span>
  <div><?php foreach ($errors as $e): ?><div>• <?= sanitize($e) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<?php if (empty($groups)): ?>
<div class="card">
  <div class="empty-state">
    <div style="font-size:2rem; opacity:0.3; margin-bottom:12px;">📚</div>
    <div class="empty-title">No groups available</div>
    <div class="empty-desc" style="margin-top:12px;">
      <a href="/educore/modules/groups/create.php" class="btn btn-primary btn-sm">Create a Group</a>
    </div>
  </div>
</div>

<?php else: ?>
<div style="max-width:600px;">
  <div class="card">
    <div class="card-header">
      <span class="card-title">Session Setup</span>
    </div>
    <div class="card-body">
      <form method="POST">
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
          <label class="form-label">Session Topic <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
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

        <div style="background:var(--primary-light); border:1px solid rgba(64,81,181,0.2); border-radius:var(--radius-md); padding:14px 16px; font-size:0.82rem; color:var(--text-secondary); margin-bottom:20px; display:flex; gap:10px; align-items:flex-start;">
          <span class="material-symbols-outlined" style="font-size:18px; color:var(--primary); flex-shrink:0; margin-top:1px;">info</span>
          <span><strong style="color:var(--primary);">How it works:</strong> A unique 6-character code is generated and displayed on your screen. Students enter it from their portal. The system validates their enrollment and prevents duplicate submissions.</span>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-full" style="justify-content:center;">
          <span class="material-symbols-outlined">play_arrow</span>
          Start Session & Generate Code
        </button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
$expiresAt    = $session ? strtotime($session['code_expires_at']) : 0;
$sessionId    = $session ? $session['id'] : 0;
$durationSecs = $session ? ($expiresAt - strtotime($session['created_at'])) : 300;
$totalStudents = $totalStudents ?? 0;

$extraScripts = <<<JS
<script>
// ─── COUNTDOWN ────────────────────────────────────────────
(function() {
  const expiresAt    = {$expiresAt};
  const durationSecs = {$durationSecs};
  if (!expiresAt) return;

  const cdEl   = document.getElementById('countdown');
  const barEl  = document.getElementById('timerBar');
  if (!cdEl) return;

  function update() {
    const remaining = Math.max(0, expiresAt - Math.floor(Date.now() / 1000));
    const m = Math.floor(remaining / 60);
    const s = remaining % 60;
    cdEl.textContent = m + ':' + String(s).padStart(2, '0');

    const pct = Math.min(100, Math.max(0, (remaining / durationSecs) * 100));
    if (barEl) {
      barEl.style.width = pct + '%';
      if (remaining < 60)  { barEl.style.background = '#ef4444'; cdEl.style.color = '#ef4444'; }
      else if (remaining < 120) { barEl.style.background = '#f59e0b'; cdEl.style.color = '#f59e0b'; }
    }

    if (remaining > 0) setTimeout(update, 1000);
    else cdEl.textContent = 'Expired';
  }
  update();
})();

// ─── LIVE POLL ────────────────────────────────────────────
(function() {
  const sessionId    = {$sessionId};
  const totalStudents = {$totalStudents};
  if (!sessionId) return;

  let lastCount = parseInt(document.getElementById('presentCount')?.textContent) || 0;

  function poll() {
    fetch('/educore/modules/attendance/api_poll.php?session=' + sessionId)
      .then(r => r.json())
      .then(data => {
        const count = data.count ?? 0;
        const total = data.total ?? totalStudents;

        // Update numbers
        const presentEl = document.getElementById('presentCount');
        const presentNumEl = document.getElementById('presentNum');
        const absentEl  = document.getElementById('absentNum');
        const rateLabel = document.getElementById('rateLabel');
        const rateBar   = document.getElementById('rateBar');
        const badgeEl   = document.getElementById('submissionBadge');

        if (presentEl)   presentEl.textContent   = count;
        if (presentNumEl) presentNumEl.textContent = count;
        if (absentEl)    absentEl.textContent    = total - count;
        if (badgeEl)     badgeEl.textContent      = count;

        const rate = total > 0 ? Math.round((count / total) * 100) : 0;
        if (rateLabel) rateLabel.textContent = rate + '%';
        if (rateBar)   rateBar.style.width   = rate + '%';

        // Update rows if new submissions
        if (data.rows && count > lastCount) {
          lastCount = count;
          const empty = document.getElementById('emptyState');
          if (empty) empty.remove();

          let tbody = document.getElementById('submissionsRows');
          if (!tbody) {
            document.getElementById('submissionsBody').innerHTML =
              '<table class="data-table"><thead><tr><th>#</th><th>Student</th><th>Status</th><th>Submitted at</th></tr></thead><tbody id="submissionsRows"></tbody></table>';
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
