<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pageTitle  = 'Attendance';
$activePage = 'attendance';
$breadcrumbs = [
    ['label' => 'Dashboard',  'href' => '/educore/dashboard.php'],
    ['label' => 'Attendance'],
];

$pdo    = getDB();
$cid    = centerId();
$uid    = authId();
$page   = max(1, (int)($_GET['page'] ?? 1));
$gid    = (int)($_GET['group'] ?? 0);
$status = $_GET['status'] ?? '';
$from   = $_GET['from'] ?? '';
$to     = $_GET['to'] ?? '';

// ── Build query ─────────────────────────────────────────────
$where  = "WHERE s.center_id = ?";
$params = [$cid];

// Academy plan: scope teachers to their sessions; individual plan owner sees all
if (isRole('teacher') && isAcademyPlan()) {
    $where  .= " AND s.teacher_id = ?";
    $params[] = $uid;
}
if ($gid)    { $where .= " AND s.group_id = ?";     $params[] = $gid; }
if ($status) { $where .= " AND s.status = ?";        $params[] = $status; }
if ($from)   { $where .= " AND s.session_date >= ?"; $params[] = $from; }
if ($to)     { $where .= " AND s.session_date <= ?"; $params[] = $to; }

$query = "SELECT s.*, g.name AS group_name, u.name AS teacher_name,
                 COUNT(CASE WHEN ar.status IN ('present','late') THEN 1 END) AS present_count,
                 (SELECT COUNT(*) FROM group_students gs WHERE gs.group_id=s.group_id AND gs.status='active') AS total_students
          FROM attendance_sessions s
          JOIN `groups` g ON g.id = s.group_id
          JOIN users u ON u.id = s.teacher_id
          LEFT JOIN attendance_records ar ON ar.session_id = s.id
          $where
          GROUP BY s.id
          ORDER BY s.session_date DESC, s.created_at DESC";

$result   = paginate($query, $params, $page, 20);
$sessions = $result['data'];

// ── Overview stats ───────────────────────────────────────────
$statParams = [$cid];
$statWhere  = "WHERE s.center_id=?";
if (isRole('teacher') && isAcademyPlan()) { $statWhere .= " AND s.teacher_id=?"; $statParams[] = $uid; }

$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT s.id) AS total_sessions,
        COUNT(CASE WHEN s.status='open' THEN 1 END) AS live_sessions,
        COUNT(CASE WHEN ar.status IN ('present','late') THEN 1 END) AS total_present,
        COUNT(ar.id) AS total_records,
        (SELECT COUNT(DISTINCT ar2.student_id)
         FROM attendance_records ar2
         JOIN attendance_sessions s2 ON s2.id=ar2.session_id
         WHERE s2.center_id=? AND s2.session_date=CURDATE()) AS today_present
    FROM attendance_sessions s
    LEFT JOIN attendance_records ar ON ar.session_id=s.id
    $statWhere");
$allParams = array_merge([$cid], $statParams);
$statsStmt->execute($allParams);
$stats = $statsStmt->fetch();

// Avg rate
$rateStmt = $pdo->prepare("
    SELECT ROUND(
        100.0 * SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END) /
        NULLIF(COUNT(gs.student_id),0), 1
    ) AS avg_rate
    FROM attendance_sessions s
    JOIN group_students gs ON gs.group_id=s.group_id AND gs.status='active'
    LEFT JOIN attendance_records ar ON ar.session_id=s.id AND ar.student_id=gs.student_id
    WHERE s.center_id=? AND s.status='closed'");
$rateStmt->execute([$cid]);
$avgRate = $rateStmt->fetchColumn() ?? 0;

// ── Groups dropdown ──────────────────────────────────────────
// Groups dropdown: academy teachers only see their own groups
if (isRole('teacher') && isAcademyPlan()) {
    $gStmt = $pdo->prepare("SELECT id, name FROM `groups` WHERE center_id=? AND teacher_id=? AND is_active=1 ORDER BY name");
    $gStmt->execute([$cid, $uid]);
} else {
    $gStmt = $pdo->prepare("SELECT id, name FROM `groups` WHERE center_id=? AND is_active=1 ORDER BY name");
    $gStmt->execute([$cid]);
}
$groups = $gStmt->fetchAll();

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Attendance</h1>
    <p class="page-subtitle"><?= number_format($result['total']) ?> total sessions</p>
  </div>
  <div style="display:flex; gap:10px;">
    <a href="reports.php" class="btn btn-ghost">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      Reports
    </a>
    <a href="create.php" class="btn btn-primary">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      New Session
    </a>
  </div>
</div>

<!-- STATS -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr); margin-bottom:24px;">
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-value"><?= number_format($stats['total_sessions']) ?></div>
    <div class="stat-label">Total Sessions</div>
  </div>
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)">
    <div class="stat-value"><?= (int)$stats['live_sessions'] ?></div>
    <div class="stat-label">Live Now <?php if($stats['live_sessions']>0): ?><span style="display:inline-block;width:6px;height:6px;background:var(--success);border-radius:50%;animation:pulse 2s infinite;margin-left:4px;vertical-align:middle;"></span><?php endif; ?></div>
  </div>
  <div class="stat-card" style="--stat-color:#3b82f6;--stat-bg:rgba(59,130,246,0.1)">
    <div class="stat-value"><?= (int)$stats['today_present'] ?></div>
    <div class="stat-label">Present Today</div>
  </div>
  <div class="stat-card" style="--stat-color:#8b5cf6;--stat-bg:rgba(139,92,246,0.1)">
    <div class="stat-value"><?= $avgRate ?>%</div>
    <div class="stat-label">Avg Rate (closed)</div>
  </div>
</div>

<!-- FILTERS -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:14px 20px;">
    <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <select name="group" class="form-control" style="width:180px;" onchange="this.form.submit()">
        <option value="">All Groups</option>
        <?php foreach ($groups as $g): ?>
        <option value="<?= $g['id'] ?>" <?= $gid==$g['id']?'selected':'' ?>><?= sanitize($g['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="status" class="form-control" style="width:130px;" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="open"   <?= $status==='open'   ?'selected':'' ?>>● Live</option>
        <option value="closed" <?= $status==='closed' ?'selected':'' ?>>Closed</option>
      </select>

      <input type="date" name="from" class="form-control" style="width:148px;" value="<?= sanitize($from) ?>" onchange="this.form.submit()">
      <span style="color:var(--text-muted); font-size:0.8rem;">–</span>
      <input type="date" name="to" class="form-control" style="width:148px;" value="<?= sanitize($to) ?>" onchange="this.form.submit()">

      <?php if ($gid||$status||$from||$to): ?>
        <a href="index.php" class="btn btn-ghost btn-sm" style="color:var(--danger); margin-left:4px;">✕ Clear</a>
      <?php endif; ?>

      <span style="margin-left:auto; font-size:0.78rem; color:var(--text-muted);">
        <?= $result['total'] ?> sessions
      </span>
    </form>
  </div>
</div>

<!-- TABLE -->
<div class="card">
  <div class="card-body no-padding">
    <?php if ($sessions): ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Group</th>
            <th>Teacher</th>
            <th>Topic</th>
            <th>Attendance</th>
            <th>Rate</th>
            <th>Status</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sessions as $sess): ?>
          <?php
            $present   = (int)$sess['present_count'];
            $total     = (int)$sess['total_students'];
            $rate      = $total > 0 ? round(($present / $total) * 100) : 0;
            $rateColor = $rate >= 80 ? 'var(--success)' : ($rate >= 60 ? 'var(--warning)' : 'var(--danger)');
          ?>
          <tr>
            <td class="cell-primary"><?= date('M j, Y', strtotime($sess['session_date'])) ?></td>
            <td>
              <a href="/educore/modules/groups/view.php?id=<?= $sess['group_id'] ?>" style="color:var(--text-primary); text-decoration:none; font-weight:500;">
                <?= sanitize($sess['group_name']) ?>
              </a>
            </td>
            <td style="color:var(--text-secondary); font-size:0.84rem;"><?= sanitize($sess['teacher_name']) ?></td>
            <td style="color:var(--text-muted); font-size:0.8rem; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= sanitize($sess['topic'] ?: '—') ?></td>
            <td>
              <span style="color:var(--success); font-weight:600;"><?= $present ?></span>
              <span style="color:var(--text-muted); font-size:0.78rem;"> / <?= $total ?></span>
            </td>
            <td>
              <div style="display:flex; align-items:center; gap:8px;">
                <div style="width:46px; height:4px; background:var(--bg-elevated); border-radius:3px; overflow:hidden;">
                  <div style="height:100%; width:<?= $rate ?>%; background:<?= $rateColor ?>; border-radius:3px;"></div>
                </div>
                <span style="font-size:0.78rem; font-weight:600; color:<?= $rateColor ?>;"><?= $rate ?>%</span>
              </div>
            </td>
            <td>
              <?php if ($sess['status']==='open'): ?>
                <span class="badge badge-success" style="animation:pulse 2s infinite;">● Live</span>
              <?php else: ?>
                <span class="badge badge-muted">Closed</span>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex; gap:6px; justify-content:flex-end;">
                <a href="view.php?id=<?= $sess['id'] ?>" class="btn btn-ghost btn-icon" title="View">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </a>
                <?php if ($sess['status']==='open'): ?>
                <a href="create.php?session=<?= $sess['id'] ?>" class="btn btn-success btn-sm">Resume</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($result['last_page'] > 1): ?>
    <div class="pagination">
      <span>Page <?= $result['current_page'] ?> of <?= $result['last_page'] ?></span>
      <div class="page-btns">
        <?php for ($i=1; $i<=$result['last_page']; $i++): ?>
          <a href="?page=<?=$i?>&group=<?=$gid?>&status=<?=$status?>&from=<?=$from?>&to=<?=$to?>"
             class="page-btn <?= $i===$result['current_page']?'active':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">📋</div>
      <div class="empty-title">No sessions found</div>
      <div class="empty-desc">
        <?php if ($gid||$status||$from||$to): ?>
          No sessions match your filters. <a href="index.php" style="color:var(--accent);">Clear filters</a>
        <?php else: ?>
          <a href="create.php" class="btn btn-primary btn-sm" style="margin-top:14px;">Start First Session</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<style>
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }
</style>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
