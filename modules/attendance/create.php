<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pdo = getDB();
$cid = centerId();
$uid = authId();

$pageTitle  = 'Attendance Session';
$activePage = 'attendance';
$breadcrumbs = [
    ['label' => 'Dashboard',  'href' => '/educore/dashboard.php'],
    ['label' => 'Attendance', 'href' => '/educore/modules/attendance/'],
    ['label' => 'New Session'],
];

// ── Load groups ─────────────────────────────────────────────
$groupQuery = isRole('owner', 'assistant')
    ? "SELECT g.*, u.name AS teacher_name FROM `groups` g LEFT JOIN users u ON u.id=g.teacher_id WHERE g.center_id=? AND g.is_active=1 ORDER BY g.name"
    : "SELECT g.*, u.name AS teacher_name FROM `groups` g LEFT JOIN users u ON u.id=g.teacher_id WHERE g.center_id=? AND g.teacher_id=? AND g.is_active=1 ORDER BY g.name";
$groupParams = isRole('owner', 'assistant') ? [$cid] : [$cid, $uid];
$groupStmt = $pdo->prepare($groupQuery);
$groupStmt->execute($groupParams);
$groups = $groupStmt->fetchAll();

$errors  = [];
$session = null;

// ── Handle POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();

    // ── Start session ────────────────────────────────────────
    if ($_POST['action'] === 'start') {
        $groupId  = (int)($_POST['group_id'] ?? 0);
        $duration = max(2, min(60, (int)($_POST['duration'] ?? 5)));
        $topic    = trim($_POST['topic'] ?? '');

        $gStmt = $pdo->prepare("SELECT * FROM `groups` WHERE id=? AND center_id=?");
        $gStmt->execute([$groupId, $cid]);
        $group = $gStmt->fetch();
        if (!$group) $errors[] = 'Invalid group.';

        // Block if already an open session for this group today
        if (!$errors) {
            $openCheck = $pdo->prepare("SELECT id FROM attendance_sessions WHERE group_id=? AND center_id=? AND status='open' AND session_date=CURDATE()");
            $openCheck->execute([$groupId, $cid]);
            if ($existing = $openCheck->fetch()) {
                redirect("create.php?session=" . $existing['id']);
            }
        }

        if (!$errors) {
            // Generate unique 6-char alphanumeric code (no ambiguous chars)
            do {
                $code = strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ23456789'), 0, 6));
                $chk  = $pdo->prepare("SELECT id FROM attendance_sessions WHERE session_code=? AND code_expires_at > NOW()");
                $chk->execute([$code]);
            } while ($chk->fetch());

            // Use MySQL NOW()+INTERVAL so expiry timezone matches the NOW()
            // check in submit.php — fixes "code always invalid" bug.
            $pdo->prepare("INSERT INTO attendance_sessions (center_id, group_id, teacher_id, session_code, code_expires_at, session_date, topic, status)
                            VALUES (?,?,?,?, NOW() + INTERVAL ? MINUTE, CURDATE(),?,'open')")
                ->execute([$cid, $groupId, $uid, $code, $duration, $topic]);
            $sessionId = $pdo->lastInsertId();

            auditLog('attendance.session_started', 'attendance_session', $sessionId, [
                'group_id' => $groupId, 'duration' => $duration, 'topic' => $topic
            ]);
            redirect("create.php?session=$sessionId");
        }
    }

    // ── Extend code expiry ───────────────────────────────────
    if ($_POST['action'] === 'extend') {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $extra     = max(1, min(30, (int)($_POST['extra'] ?? 5)));
        $pdo->prepare("UPDATE attendance_sessions SET code_expires_at = DATE_ADD(GREATEST(code_expires_at, NOW()), INTERVAL ? MINUTE) WHERE id=? AND center_id=?")
            ->execute([$extra, $sessionId, $cid]);
        flash('success', "Code extended by {$extra} minutes.");
        redirect("create.php?session=$sessionId");
    }

    // ── Regenerate code ──────────────────────────────────────
    if ($_POST['action'] === 'regen') {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $duration  = max(2, min(30, (int)($_POST['duration'] ?? 5)));
        do {
            $code = strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ23456789'), 0, 6));
            $chk  = $pdo->prepare("SELECT id FROM attendance_sessions WHERE session_code=? AND code_expires_at > NOW() AND id != ?");
            $chk->execute([$code, $sessionId]);
        } while ($chk->fetch());
        $pdo->prepare("UPDATE attendance_sessions SET session_code=?, code_expires_at=NOW() + INTERVAL ? MINUTE WHERE id=? AND center_id=?")
            ->execute([$code, $duration, $sessionId, $cid]);
        auditLog('attendance.code_regenerated', 'attendance_session', $sessionId);
        flash('success', 'New code generated.');
        redirect("create.php?session=$sessionId");
    }

    // ── Close session ─────────────────────────────────────────
    if ($_POST['action'] === 'close') {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $sStmt = $pdo->prepare("SELECT * FROM attendance_sessions WHERE id=? AND center_id=?");
        $sStmt->execute([$sessionId, $cid]);
        $toClose = $sStmt->fetch();

        if ($toClose) {
            // Auto-mark all enrolled students who didn't submit as absent
            $enrolledStmt = $pdo->prepare("SELECT student_id FROM group_students WHERE group_id=? AND status='active'");
            $enrolledStmt->execute([$toClose['group_id']]);
            $enrolled = $enrolledStmt->fetchAll(PDO::FETCH_COLUMN);

            $existStmt = $pdo->prepare("SELECT student_id FROM attendance_records WHERE session_id=?");
            $existStmt->execute([$sessionId]);
            $submitted = $existStmt->fetchAll(PDO::FETCH_COLUMN);

            $missing = array_diff($enrolled, $submitted);
            foreach ($missing as $sid) {
                $pdo->prepare("INSERT IGNORE INTO attendance_records (session_id, student_id, center_id, status, submitted_at, ip_address) VALUES (?,?,?,'absent',NOW(),'auto')")
                    ->execute([$sessionId, $sid, $cid]);
            }

            $pdo->prepare("UPDATE attendance_sessions SET status='closed' WHERE id=? AND center_id=?")
                ->execute([$sessionId, $cid]);

            auditLog('attendance.session_closed', 'attendance_session', $sessionId, [
                'auto_absent' => count($missing)
            ]);
        }

        flash('success', 'Session closed. Absent students marked automatically.');
        redirect('/educore/modules/attendance/');
    }
}

// ── Load existing session if ?session= ───────────────────────
$records       = [];
$totalStudents = 0;
if (isset($_GET['session'])) {
    $sid = (int)$_GET['session'];
    $sStmt = $pdo->prepare("SELECT s.*, g.name AS group_name, g.subject FROM attendance_sessions s JOIN `groups` g ON g.id=s.group_id WHERE s.id=? AND s.center_id=?");
    $sStmt->execute([$sid, $cid]);
    $session = $sStmt->fetch();

    if ($session) {
        $recStmt = $pdo->prepare("SELECT ar.*, st.name AS student_name FROM attendance_records ar JOIN students st ON st.id=ar.student_id WHERE ar.session_id=? ORDER BY ar.submitted_at DESC");
        $recStmt->execute([$sid]);
        $records = $recStmt->fetchAll();

        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM group_students WHERE group_id=? AND status='active'");
        $totalStmt->execute([$session['group_id']]);
        $totalStudents = (int)$totalStmt->fetchColumn();

        $pageTitle   = 'Live Session — ' . $session['group_name'];
        $breadcrumbs[2]['label'] = 'Live Session';
    }
}

$expiresAt = $session ? strtotime($session['code_expires_at']) : 0;
$sessionId = $session ? $session['id'] : 0;
$isExpired = $expiresAt > 0 && $expiresAt < time();

include __DIR__ . '/../../templates/layout.php';
?>

<?php if ($session && $session['status'] === 'open'): ?>
<!-- ═══════════════════════════════════════════════════════
     LIVE SESSION VIEW
════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div>
    <h1 class="page-title">
      <span class="badge badge-success" style="font-size:0.65rem; vertical-align:middle; margin-right:8px; animation:pulse 2s infinite;">● LIVE</span>
      <?= sanitize($session['group_name']) ?>
    </h1>
    <p class="page-subtitle">
      <?= sanitize($session['subject'] ?? '') ?>
      <?php if ($session['topic']): ?>· <?= sanitize($session['topic']) ?><?php endif; ?>
      · <?= date('l, M j, Y') ?>
    </p>
  </div>
  <form method="POST" style="display:inline;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="close">
    <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
    <button type="submit" class="btn btn-danger"
            onclick="return confirm('Close this session?\n\nAll absent students will be marked automatically.')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
      Close Session
    </button>
  </form>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">

  <!-- ── Attendance Code card ─────────────────────────────── -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Attendance Code</span>
      <div style="display:flex; gap:8px; align-items:center;">
        <span class="badge <?= $isExpired ? 'badge-danger' : 'badge-success' ?>" id="codeStatusBadge">
          <?= $isExpired ? '⚠ Expired' : '● Active' ?>
        </span>
      </div>
    </div>
    <div class="card-body" style="text-align:center; padding:32px 24px;">

      <div style="font-size:0.72rem; font-weight:600; letter-spacing:0.12em; text-transform:uppercase; color:var(--text-muted); margin-bottom:12px;">
        Share this code with your students
      </div>

      <!-- Big code display -->
      <div id="liveCode" style="
        font-family: var(--font-display);
        font-size: 3.2rem;
        font-weight: 800;
        letter-spacing: 0.3em;
        color: var(--accent);
        text-shadow: 0 0 30px var(--accent-glow);
        padding: 20px 0;
        border: 2px dashed var(--border-light);
        border-radius: var(--radius-md);
        background: var(--accent-subtle);
        user-select: all;
        cursor: copy;
        margin-bottom: 16px;
        transition: all 0.2s;
      " title="Click to copy" onclick="copyCode(this)">
        <?= sanitize($session['session_code']) ?>
      </div>

      <!-- Timer -->
      <div id="timerWrapper" style="display:flex; align-items:center; justify-content:center; gap:6px; font-size:0.9rem; margin-bottom:20px; color:var(--text-secondary);">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Expires in <strong id="countdown" style="color:var(--accent); font-family:var(--font-display);">--:--</strong>
      </div>

      <!-- Extend / Regen -->
      <div style="display:flex; gap:8px; justify-content:center; flex-wrap:wrap;">
        <form method="POST" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="extend">
          <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
          <select name="extra" class="form-control" style="width:100px; display:inline-block; padding:6px 10px; font-size:0.8rem;">
            <option value="5">+5 min</option>
            <option value="10">+10 min</option>
            <option value="15">+15 min</option>
          </select>
          <button type="submit" class="btn btn-ghost btn-sm">Extend</button>
        </form>

        <form method="POST" style="display:inline;" onsubmit="return confirm('Generate a new code? The old code will stop working.')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="regen">
          <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
          <input type="hidden" name="duration" value="5">
          <button type="submit" class="btn btn-ghost btn-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            New Code
          </button>
        </form>
      </div>

    </div>
  </div>

  <!-- ── Stats card ────────────────────────────────────────── -->
  <div class="card">
    <div class="card-header"><span class="card-title">Live Stats</span></div>
    <div class="card-body">

      <div class="stats-grid" style="grid-template-columns:1fr 1fr; margin-bottom:16px;">
        <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)">
          <div class="stat-value" id="presentCount"><?= count($records) ?></div>
          <div class="stat-label">Present</div>
        </div>
        <div class="stat-card" style="--stat-color:var(--text-muted);--stat-bg:var(--bg-elevated)">
          <div class="stat-value"><?= $totalStudents ?></div>
          <div class="stat-label">Total in Group</div>
        </div>
      </div>

      <!-- Rate bar -->
      <?php
        $initRate = $totalStudents > 0 ? round((count($records) / $totalStudents) * 100) : 0;
        $initColor = $initRate >= 80 ? 'var(--success)' : ($initRate >= 60 ? 'var(--warning)' : 'var(--danger)');
      ?>
      <div style="margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; font-size:0.78rem; color:var(--text-muted); margin-bottom:6px;">
          <span>Submission rate</span>
          <span id="rateLabel" style="font-weight:600; color:<?= $initColor ?>;"><?= $initRate ?>%</span>
        </div>
        <div style="height:8px; background:var(--bg-elevated); border-radius:4px; overflow:hidden;">
          <div id="rateBar" style="height:100%; background:<?= $initColor ?>; border-radius:4px; width:<?= $initRate ?>%; transition:width 0.6s ease, background 0.3s;"></div>
        </div>
      </div>

      <!-- Session meta -->
      <div style="background:var(--bg-elevated); border-radius:var(--radius-sm); padding:14px; font-size:0.8rem;">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
          <div>
            <div style="color:var(--text-muted); font-size:0.7rem; margin-bottom:2px; text-transform:uppercase; letter-spacing:0.05em;">Group</div>
            <strong><?= sanitize($session['group_name']) ?></strong>
          </div>
          <div>
            <div style="color:var(--text-muted); font-size:0.7rem; margin-bottom:2px; text-transform:uppercase; letter-spacing:0.05em;">Date</div>
            <strong><?= date('M j, Y') ?></strong>
          </div>
          <?php if ($session['topic']): ?>
          <div style="grid-column:1/-1;">
            <div style="color:var(--text-muted); font-size:0.7rem; margin-bottom:2px; text-transform:uppercase; letter-spacing:0.05em;">Topic</div>
            <strong><?= sanitize($session['topic']) ?></strong>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ── Live Submissions table ─────────────────────────────── -->
<div class="card" id="submissionsCard">
  <div class="card-header">
    <span class="card-title">Submissions</span>
    <span style="font-size:0.75rem; color:var(--text-muted); display:flex; align-items:center; gap:4px;">
      <span id="pollDot" style="width:6px;height:6px;background:var(--success);border-radius:50%;display:inline-block;animation:pulse 2s infinite;"></span>
      Auto-refreshing
    </span>
  </div>
  <div class="card-body no-padding" id="submissionsBody">
    <?php if (!empty($records)): ?>
    <table class="data-table">
      <thead>
        <tr><th>#</th><th>Student</th><th>Status</th><th>Submitted</th></tr>
      </thead>
      <tbody id="submissionsRows">
        <?php foreach ($records as $i => $rec): ?>
        <tr>
          <td style="color:var(--text-muted);"><?= $i + 1 ?></td>
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
      <div class="empty-title">Waiting for students…</div>
      <div class="empty-desc">Students will appear here as they enter the code</div>
    </div>
    <?php endif; ?>
  </div>
</div>


<?php elseif ($session && $session['status'] === 'closed'): ?>
<!-- Redirect to view if already closed -->
<?php redirect("view.php?id=" . $session['id']); ?>


<?php else: ?>
<!-- ═══════════════════════════════════════════════════════
     START SESSION FORM
════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div>
    <h1 class="page-title">Start Attendance Session</h1>
    <p class="page-subtitle">Generate a secure, time-limited code for your students</p>
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
    <div class="empty-title">No groups assigned to you</div>
    <div class="empty-desc">
      <?php if (isRole('owner')): ?>
      <a href="/educore/modules/groups/create.php" class="btn btn-primary btn-sm" style="margin-top:14px;">Create a Group</a>
      <?php else: ?>
      Ask the center owner to assign a group to your account.
      <?php endif; ?>
    </div>
  </div>
</div>
<?php else: ?>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; max-width:820px;">

  <!-- Start form -->
  <div class="card">
    <div class="card-header"><span class="card-title">Session Setup</span></div>
    <div class="card-body">
      <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="start">

        <div class="form-group">
          <label class="form-label required">Group</label>
          <select name="group_id" id="groupSelect" class="form-control" required onchange="updateGroupInfo(this)">
            <option value="">Select a group…</option>
            <?php foreach ($groups as $g): ?>
            <option value="<?= $g['id'] ?>"
                    data-teacher="<?= sanitize($g['teacher_name'] ?? '') ?>"
                    data-subject="<?= sanitize($g['subject'] ?? '') ?>">
              <?= sanitize($g['name']) ?><?= $g['subject'] ? ' — ' . sanitize($g['subject']) : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="groupInfo" style="display:none; background:var(--bg-elevated); border-radius:var(--radius-sm); padding:10px 14px; font-size:0.8rem; color:var(--text-secondary); margin-bottom:16px;">
          <strong style="color:var(--text-primary);" id="groupInfoText"></strong>
        </div>

        <div class="form-group">
          <label class="form-label">Session Topic <span style="color:var(--text-muted);">(optional)</span></label>
          <input type="text" name="topic" class="form-control" placeholder="e.g. Chapter 4 — Quadratic Equations" maxlength="100">
        </div>

        <div class="form-group">
          <label class="form-label required">Code Valid For</label>
          <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-top:6px;">
            <?php foreach ([3=>'3 min', 5=>'5 min', 10=>'10 min', 15=>'15 min', 20=>'20 min', 30=>'30 min'] as $val => $label): ?>
            <label style="
              display:flex; align-items:center; justify-content:center;
              padding:10px; border-radius:var(--radius-sm);
              border:1px solid var(--border); cursor:pointer;
              font-size:0.82rem; font-weight:500;
              transition:all 0.15s;
            " class="duration-opt">
              <input type="radio" name="duration" value="<?= $val ?>" <?= $val===5?'checked':'' ?> style="display:none;">
              <?= $label ?>
            </label>
            <?php endforeach; ?>
          </div>
          <span class="form-hint">Students must submit within this window after the code is shown.</span>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-full" style="margin-top:4px;">
          🚀 Generate Code &amp; Start Session
        </button>
      </form>
    </div>
  </div>

  <!-- How it works -->
  <div>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><span class="card-title">How it works</span></div>
      <div class="card-body" style="font-size:0.84rem;">
        <?php
        $steps = [
          ['🎲', 'Code generated', 'A unique 6-character code is created with a countdown timer.'],
          ['📺', 'Display to class', 'Show the code on your screen or write it on the board.'],
          ['📱', 'Students submit', 'Each student opens their portal and types the code.'],
          ['✅', 'Instant validation', 'System checks: correct code, not expired, enrolled, no duplicate.'],
          ['📊', 'Session closes', 'Absent students are auto-marked when you close the session.'],
        ];
        foreach ($steps as $i => [$icon, $title, $desc]):
        ?>
        <div style="display:flex; gap:12px; <?= $i < count($steps)-1 ? 'margin-bottom:14px;' : '' ?>">
          <div style="width:28px; height:28px; background:var(--bg-elevated); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0;"><?= $icon ?></div>
          <div>
            <div style="font-weight:600; color:var(--text-primary); font-size:0.82rem;"><?= $title ?></div>
            <div style="color:var(--text-muted); font-size:0.78rem; margin-top:2px;"><?= $desc ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card" style="border-color:rgba(245,166,35,0.25);">
      <div class="card-body" style="font-size:0.8rem; color:var(--text-secondary);">
        <div style="color:var(--accent); font-weight:600; margin-bottom:6px;">🔒 Fraud Prevention</div>
        <div style="color:var(--text-muted); line-height:1.7;">
          • One submission per student per session<br>
          • Code valid only for enrolled students<br>
          • All submissions timestamped + IP logged<br>
          • Teacher can override any record manually
        </div>
      </div>
    </div>
  </div>

</div>

<style>
.duration-opt:has(input:checked) {
  background: var(--accent-subtle);
  border-color: var(--accent);
  color: var(--accent);
}
.duration-opt:hover { border-color: var(--border-light); }
</style>

<script>
document.querySelectorAll('.duration-opt').forEach(label => {
  label.addEventListener('click', () => {
    document.querySelectorAll('.duration-opt').forEach(l => l.style.cssText = '');
  });
});

function updateGroupInfo(sel) {
  const opt = sel.options[sel.selectedIndex];
  const info = document.getElementById('groupInfo');
  const text = document.getElementById('groupInfoText');
  if (opt.value) {
    const teacher = opt.dataset.teacher;
    const subject = opt.dataset.subject;
    text.textContent = [subject, teacher ? 'Teacher: ' + teacher : ''].filter(Boolean).join(' · ');
    info.style.display = 'block';
  } else {
    info.style.display = 'none';
  }
}
</script>

<?php endif; ?>
<?php endif; ?>


<?php
// ── JavaScript for live session ───────────────────────────────
$extraScripts = <<<JS
<script>
// ── COUNTDOWN TIMER ────────────────────────────────────────────
(function() {
  const expiresAt = {$expiresAt};
  if (!expiresAt) return;
  const el = document.getElementById('countdown');
  const badge = document.getElementById('codeStatusBadge');
  const codeEl = document.getElementById('liveCode');
  if (!el) return;

  function update() {
    const now  = Math.floor(Date.now() / 1000);
    const diff = expiresAt - now;
    if (diff <= 0) {
      el.textContent = 'Expired';
      el.style.color = 'var(--danger)';
      if (badge) { badge.textContent = '⚠ Expired'; badge.className = 'badge badge-danger'; }
      if (codeEl) { codeEl.style.opacity = '0.4'; codeEl.style.borderColor = 'var(--danger)'; codeEl.style.color = 'var(--danger)'; }
      document.getElementById('pollDot')?.style.setProperty('background','var(--danger)');
      return;
    }
    const m = Math.floor(diff / 60);
    const s = diff % 60;
    el.textContent = m + ':' + String(s).padStart(2, '0');
    if (diff < 30)      el.style.color = 'var(--danger)';
    else if (diff < 90) el.style.color = 'var(--warning)';
    else                el.style.color = 'var(--success)';
    setTimeout(update, 500);
  }
  update();
})();

// ── COPY CODE ON CLICK ─────────────────────────────────────────
function copyCode(el) {
  const code = el.textContent.trim();
  navigator.clipboard?.writeText(code).then(() => {
    const orig = el.style.background;
    el.style.background = 'rgba(34,197,94,0.2)';
    setTimeout(() => el.style.background = orig, 600);
  });
}

// ── LIVE POLL FOR SUBMISSIONS ──────────────────────────────────
(function() {
  const sessionId = {$sessionId};
  if (!sessionId) return;
  const total = {$totalStudents};
  let lastCount = document.querySelectorAll('#submissionsRows tr')?.length || 0;

  function poll() {
    fetch('/educore/modules/attendance/api_poll.php?session=' + sessionId)
      .then(r => r.json())
      .then(data => {
        if (data.error) return;

        // Update count + rate
        const count = data.count || 0;
        const pEl = document.getElementById('presentCount');
        if (pEl) pEl.textContent = count;

        const rate = total > 0 ? Math.round((count / total) * 100) : 0;
        const color = rate >= 80 ? 'var(--success)' : (rate >= 60 ? 'var(--warning)' : 'var(--danger)');
        const rLabel = document.getElementById('rateLabel');
        const rBar   = document.getElementById('rateBar');
        if (rLabel) { rLabel.textContent = rate + '%'; rLabel.style.color = color; }
        if (rBar)   { rBar.style.width = rate + '%'; rBar.style.background = color; }

        // Update rows if new submissions
        if (data.rows && count > lastCount) {
          lastCount = count;
          const empty = document.getElementById('emptyState');
          if (empty) empty.remove();

          let tbody = document.getElementById('submissionsRows');
          if (!tbody) {
            document.getElementById('submissionsBody').innerHTML =
              '<table class="data-table"><thead><tr><th>#</th><th>Student</th><th>Status</th><th>Submitted</th></tr></thead><tbody id="submissionsRows"></tbody></table>';
            tbody = document.getElementById('submissionsRows');
          }
          tbody.innerHTML = data.rows;
        }
      })
      .catch(() => {});
    setTimeout(poll, 2500);
  }
  setTimeout(poll, 2500);
})();
</script>
JS;

include __DIR__ . '/../../templates/layout_end.php';
?>
