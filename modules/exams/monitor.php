<?php
/**
 * monitor.php — Live Focus Monitor
 * Shows teachers which students left the exam tab during an active exam
 * Auto-refreshes every 10 seconds
 */
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','assistant');

$pdo = getDB();
$cid = centerId();
$uid = authId();
$eid = (int)($_GET['id'] ?? 0);
if (!$eid) redirect('/educore/modules/exams/');

$eStmt = $pdo->prepare("SELECT e.*,g.name AS group_name
    FROM exams e LEFT JOIN `groups` g ON g.id=e.group_id
    WHERE e.id=? AND e.center_id=?");
$eStmt->execute([$eid,$cid]);
$exam = $eStmt->fetch();
if (!$exam) { flash('error','Exam not found.'); redirect('/educore/modules/exams/'); }

$pageTitle  = 'Focus Monitor — '.sanitize($exam['title']);
$activePage = 'exams';
$breadcrumbs = [
    ['label'=>'Dashboard','href'=>'/educore/dashboard.php'],
    ['label'=>'Exams','href'=>'/educore/modules/exams/'],
    ['label'=>sanitize($exam['title']),'href'=>"view.php?id=$eid"],
    ['label'=>'Focus Monitor'],
];

// ── AJAX mode: return JSON data ───────────────────────────────────────────────
if ($_GET['json'] ?? false) {
    header('Content-Type: application/json');

    // All active submissions for this exam
    $subStmt = $pdo->prepare("
        SELECT es.id AS sub_id, es.student_id, es.started_at,
               st.name AS student_name, st.student_code,
               u.email
        FROM exam_submissions es
        JOIN students st ON st.id=es.student_id
        JOIN users u ON u.id=st.user_id
        WHERE es.exam_id=? AND es.center_id=? AND es.status='in_progress'
        ORDER BY st.name");
    $subStmt->execute([$eid,$cid]);
    $subs = $subStmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    foreach ($subs as $s) {
        // Get all focus events for this submission, newest first
        $evStmt = $pdo->prepare("
            SELECT event, created_at
            FROM exam_focus_log
            WHERE submission_id=? AND exam_id=?
            ORDER BY created_at ASC");
        $evStmt->execute([$s['sub_id'], $eid]);
        $events = $evStmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate total time away (sum of blur→focus gaps)
        $awaySeconds = 0;
        $violations  = [];
        $blurTime    = null;
        $currentlyAway = false;

        foreach ($events as $ev) {
            if ($ev['event'] === 'blur') {
                $blurTime = strtotime($ev['created_at']);
                $currentlyAway = true;
            } elseif ($ev['event'] === 'focus' && $blurTime !== null) {
                $gap = strtotime($ev['created_at']) - $blurTime;
                $awaySeconds += $gap;
                $violations[] = [
                    'left_at'     => $ev['created_at'],  // approximation
                    'returned_at' => $ev['created_at'],
                    'seconds'     => $gap,
                    'blur_at'     => date('H:i:s', $blurTime),
                    'focus_at'    => date('H:i:s', strtotime($ev['created_at'])),
                ];
                $blurTime = null;
                $currentlyAway = false;
            }
        }

        // Still away right now
        if ($currentlyAway && $blurTime) {
            $awaySeconds += time() - $blurTime;
        }

        // Last blur event timestamp
        $lastBlurRow = null;
        foreach (array_reverse($events) as $ev) {
            if ($ev['event'] === 'blur') { $lastBlurRow = $ev; break; }
        }

        $data[] = [
            'student_id'     => $s['student_id'],
            'sub_id'         => $s['sub_id'],
            'name'           => $s['student_name'],
            'code'           => $s['student_code'],
            'started_at'     => $s['started_at'],
            'currently_away' => $currentlyAway,
            'last_blur'      => $lastBlurRow['created_at'] ?? null,
            'away_seconds'   => $awaySeconds,
            'violation_count'=> count($violations),
            'violations'     => array_slice(array_reverse($violations), 0, 5), // last 5
        ];
    }

    // Stats
    $totalActive  = count($subs);
    $totalAway    = count(array_filter($data, fn($d)=>$d['currently_away']));
    $flaggedCount = count(array_filter($data, fn($d)=>$d['violation_count']>0));

    echo json_encode([
        'ok'           => true,
        'exam_status'  => $exam['status'],
        'total_active' => $totalActive,
        'total_away'   => $totalAway,
        'flagged_count'=> $flaggedCount,
        'students'     => $data,
        'timestamp'    => date('H:i:s'),
    ]);
    exit;
}

include __DIR__ . '/../../templates/layout.php';
?>

<style>
.mon-header {
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:20px;flex-wrap:wrap;gap:12px;
}
.mon-stats {
    display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px;
}
.mon-stat {
    background:var(--bg-card);border:1px solid var(--border);
    border-radius:var(--radius-md);padding:14px 18px;text-align:center;
}
.mon-stat-val {
    font-family:var(--font-display);font-size:1.8rem;font-weight:800;
    line-height:1;margin-bottom:4px;
}
.mon-stat-lbl { font-size:.68rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em; }

.student-grid {
    display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;
}
.student-card {
    background:var(--bg-card);border:1px solid var(--border);
    border-radius:var(--radius-md);padding:16px;
    transition:border-color .3s,box-shadow .3s;
}
.student-card.away {
    border-color:rgba(239,68,68,.5);
    box-shadow:0 0 0 1px rgba(239,68,68,.15), 0 4px 20px rgba(239,68,68,.1);
    animation: pulseRed 2s ease-in-out infinite;
}
.student-card.flagged {
    border-color:rgba(245,158,11,.4);
}
@keyframes pulseRed {
    0%,100% { box-shadow:0 0 0 1px rgba(239,68,68,.15),0 4px 20px rgba(239,68,68,.1); }
    50%      { box-shadow:0 0 0 3px rgba(239,68,68,.25),0 4px 30px rgba(239,68,68,.2); }
}

.sc-head { display:flex;align-items:center;gap:10px;margin-bottom:12px; }
.sc-avatar {
    width:36px;height:36px;border-radius:50%;
    background:var(--bg-elevated);border:2px solid var(--border);
    display:flex;align-items:center;justify-content:center;
    font-family:var(--font-display);font-size:.85rem;font-weight:700;
    color:var(--text-secondary);flex-shrink:0;
}
.sc-avatar.away-av { border-color:var(--danger);background:rgba(239,68,68,.1);color:var(--danger); }
.sc-name { font-weight:600;font-size:.88rem;color:var(--text-primary);line-height:1.2; }
.sc-code { font-size:.7rem;color:var(--text-muted); }

.sc-status-pill {
    margin-left:auto;font-size:.68rem;font-weight:700;padding:3px 10px;
    border-radius:10px;white-space:nowrap;
}
.pill-active  { background:rgba(34,197,94,.12);color:var(--success); }
.pill-away    { background:rgba(239,68,68,.15);color:var(--danger);animation:pillBlink 1.5s ease-in-out infinite; }
@keyframes pillBlink { 0%,100%{opacity:1} 50%{opacity:.6} }

.sc-metrics { display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px; }
.sc-metric { background:var(--bg-elevated);border-radius:var(--radius-sm);padding:8px 10px; }
.sc-metric-val { font-family:var(--font-display);font-size:1.1rem;font-weight:700;color:var(--text-primary); }
.sc-metric-lbl { font-size:.62rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em; }

.violation-list { list-style:none; }
.violation-item {
    display:flex;align-items:center;gap:8px;padding:5px 0;
    border-bottom:1px solid var(--border);font-size:.74rem;
}
.violation-item:last-child { border-bottom:none; }
.viol-time { color:var(--text-muted); }
.viol-dur  { font-weight:600;color:var(--warning); }

.live-badge {
    display:inline-flex;align-items:center;gap:5px;
    font-size:.72rem;font-weight:600;color:var(--success);
    background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);
    border-radius:20px;padding:4px 10px;
}
.live-dot {
    width:7px;height:7px;border-radius:50%;background:var(--success);
    animation:livePulse 1.4s ease-in-out infinite;
}
@keyframes livePulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.7)} }

.refresh-bar {
    display:flex;align-items:center;gap:12px;font-size:.75rem;color:var(--text-muted);
}
.refresh-progress {
    flex:1;max-width:100px;height:3px;background:var(--bg-elevated);
    border-radius:2px;overflow:hidden;
}
.refresh-fill {
    height:100%;background:var(--accent);width:0%;
    transition:width 10s linear;
}

.empty-monitor {
    text-align:center;padding:60px 20px;color:var(--text-muted);
}
.empty-monitor .big-icon { font-size:3rem;margin-bottom:14px; }

.filter-bar {
    display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;
}
.filter-btn {
    padding:5px 14px;border-radius:20px;font-size:.75rem;font-weight:600;
    border:1px solid var(--border);color:var(--text-muted);cursor:pointer;
    background:none;transition:all .15s;
}
.filter-btn:hover,.filter-btn.active {
    border-color:var(--accent);color:var(--accent);background:var(--accent-subtle);
}

#lastUpdated { font-size:.74rem;color:var(--text-muted); }
</style>

<div class="mon-header">
  <div>
    <h1 class="page-title" style="margin-bottom:4px;">👁 Focus Monitor</h1>
    <p class="page-subtitle"><?=sanitize($exam['title'])?> · <?=sanitize($exam['group_name']??'All Students')?></p>
  </div>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <div class="live-badge">
      <span class="live-dot"></span> Live
    </div>
    <div class="refresh-bar">
      <span id="lastUpdated">Loading…</span>
      <div class="refresh-progress"><div class="refresh-fill" id="refreshFill"></div></div>
    </div>
    <a href="view.php?id=<?=$eid?>" class="btn btn-ghost btn-sm">← Back to Exam</a>
    <a href="results.php?id=<?=$eid?>" class="btn btn-ghost btn-sm">Results</a>
  </div>
</div>

<!-- Stats -->
<div class="mon-stats" id="monStats">
  <div class="mon-stat">
    <div class="mon-stat-val" id="statActive" style="color:var(--success);">—</div>
    <div class="mon-stat-lbl">In Progress</div>
  </div>
  <div class="mon-stat">
    <div class="mon-stat-val" id="statAway" style="color:var(--danger);">—</div>
    <div class="mon-stat-lbl">Currently Away</div>
  </div>
  <div class="mon-stat">
    <div class="mon-stat-val" id="statFlagged" style="color:var(--warning);">—</div>
    <div class="mon-stat-lbl">Flagged Students</div>
  </div>
  <div class="mon-stat">
    <div class="mon-stat-val" id="statStatus" style="font-size:1rem;">—</div>
    <div class="mon-stat-lbl">Exam Status</div>
  </div>
</div>

<!-- Filter -->
<div class="filter-bar">
  <span style="font-size:.78rem;font-weight:600;color:var(--text-secondary);">Show:</span>
  <button class="filter-btn active" id="fAll"     onclick="setFilter('all')">All Students</button>
  <button class="filter-btn"        id="fAway"    onclick="setFilter('away')">Away Now</button>
  <button class="filter-btn"        id="fFlagged" onclick="setFilter('flagged')">Flagged Only</button>
  <button class="filter-btn"        id="fSafe"    onclick="setFilter('safe')">Clean</button>
</div>

<!-- Student cards -->
<div class="student-grid" id="studentGrid">
  <div class="empty-monitor">
    <div class="big-icon">🔄</div>
    <div>Loading monitor data…</div>
  </div>
</div>

<script>
const MON_EXAM_ID = <?=$eid?>;
let monFilter = 'all';
let monData   = [];

function setFilter(f) {
  monFilter = f;
  ['all','away','flagged','safe'].forEach(x => {
    document.getElementById('f'+x.charAt(0).toUpperCase()+x.slice(1))
      ?.classList.toggle('active', x === f);
  });
  renderCards();
}

function fmtSeconds(s) {
  s = Math.round(s);
  if (s < 60) return s + 's';
  return Math.floor(s/60) + 'm ' + (s%60) + 's';
}

function initials(name) {
  return name.split(' ').map(w=>w[0]).join('').toUpperCase().slice(0,2);
}

function renderCards() {
  const grid = document.getElementById('studentGrid');
  let list = monData;

  if (monFilter === 'away')    list = list.filter(s => s.currently_away);
  if (monFilter === 'flagged') list = list.filter(s => s.violation_count > 0);
  if (monFilter === 'safe')    list = list.filter(s => s.violation_count === 0);

  if (!list.length) {
    let msg = 'No students match this filter.';
    if (monFilter === 'all' && !monData.length) msg = 'No students are currently taking this exam.';
    if (monFilter === 'away')    msg = '✅ No students are currently away from the exam.';
    if (monFilter === 'flagged') msg = '✅ No focus violations detected yet.';
    if (monFilter === 'safe')    msg = 'No students with clean records in current filter.';

    grid.innerHTML = `<div class="empty-monitor" style="grid-column:1/-1;">
      <div class="big-icon">${monFilter==='away'||monFilter==='flagged' ? '✅' : '👥'}</div>
      <div>${msg}</div>
    </div>`;
    return;
  }

  grid.innerHTML = list.map(s => {
    const awayClass    = s.currently_away ? 'away' : (s.violation_count > 0 ? 'flagged' : '');
    const avatarClass  = s.currently_away ? 'away-av' : '';
    const statusPill   = s.currently_away
      ? `<span class="sc-status-pill pill-away">⚠️ AWAY NOW</span>`
      : `<span class="sc-status-pill pill-active">✓ On Page</span>`;

    const lastBlurStr = s.last_blur
      ? new Date(s.last_blur).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit',second:'2-digit'})
      : '—';

    const violHtml = s.violations.length ? `
      <div style="margin-top:10px;">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
                    color:var(--text-muted);margin-bottom:6px;">Recent Absences</div>
        <ul class="violation-list">
          ${s.violations.map(v => `
          <li class="violation-item">
            <span style="color:var(--danger);">↗</span>
            <span class="viol-time">${v.blur_at}</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            <span class="viol-time">${v.focus_at}</span>
            <span class="viol-dur">(${fmtSeconds(v.seconds)})</span>
          </li>`).join('')}
        </ul>
      </div>` : '';

    return `
    <div class="student-card ${awayClass}" id="sc_${s.student_id}">
      <div class="sc-head">
        <div class="sc-avatar ${avatarClass}">${initials(s.name)}</div>
        <div>
          <div class="sc-name">${escHtml(s.name)}</div>
          <div class="sc-code">${escHtml(s.code||'')}</div>
        </div>
        ${statusPill}
      </div>
      <div class="sc-metrics">
        <div class="sc-metric">
          <div class="sc-metric-val" style="color:${s.violation_count>0?'var(--warning)':'var(--success)'};">${s.violation_count}</div>
          <div class="sc-metric-lbl">Violations</div>
        </div>
        <div class="sc-metric">
          <div class="sc-metric-val" style="color:${s.away_seconds>30?'var(--danger)':'var(--text-primary)'};">${fmtSeconds(s.away_seconds)}</div>
          <div class="sc-metric-lbl">Total Away</div>
        </div>
      </div>
      <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:4px;">
        Last left at: <strong style="color:var(--text-secondary);">${lastBlurStr}</strong>
      </div>
      ${violHtml}
    </div>`;
  }).join('');
}

function escHtml(str) {
  return String(str)
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;');
}

function updateStats(d) {
  document.getElementById('statActive').textContent  = d.total_active;
  document.getElementById('statAway').textContent    = d.total_away;
  document.getElementById('statFlagged').textContent = d.flagged_count;

  const statusEl = document.getElementById('statStatus');
  const statusMap = {active:'🟢 Active',closed:'🔴 Closed',draft:'⚪ Draft',archived:'⚫ Archived'};
  statusEl.textContent = statusMap[d.exam_status] || d.exam_status;
}

// ── Fetch + render ─────────────────────────────────────────────────────────
async function fetchMonitor() {
  try {
    const res  = await fetch(`monitor.php?id=${MON_EXAM_ID}&json=1`);
    const data = await res.json();
    if (!data.ok) return;

    monData = data.students;
    updateStats(data);
    renderCards();

    document.getElementById('lastUpdated').textContent = 'Updated ' + data.timestamp;
  } catch(e) {
    document.getElementById('lastUpdated').textContent = 'Update failed';
  }
}

// ── Auto-refresh every 10 seconds ────────────────────────────────────────
function startRefreshCycle() {
  const fill = document.getElementById('refreshFill');
  fill.style.width = '0%';
  // Force reflow
  fill.offsetWidth;
  fill.style.transition = 'width 10s linear';
  fill.style.width = '100%';
}

fetchMonitor();
startRefreshCycle();

setInterval(() => {
  fetchMonitor();
  // Reset progress bar
  const fill = document.getElementById('refreshFill');
  fill.style.transition = 'none';
  fill.style.width = '0%';
  fill.offsetWidth;
  fill.style.transition = 'width 10s linear';
  fill.style.width = '100%';
}, 10000);
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
