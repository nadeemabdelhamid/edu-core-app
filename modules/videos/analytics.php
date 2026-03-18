<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher');

if (!hasFeature('videos')) {
    flash('error', 'Video analytics require a plan with Videos enabled.');
    redirect('/educore/modules/settings/subscription.php');
}

$pdo = getDB();
$cid = centerId();
$uid = authId();

$id = (int)($_GET['id'] ?? 0);

$vStmt = $pdo->prepare("SELECT v.*, g.name AS group_name FROM videos v LEFT JOIN `groups` g ON g.id = v.group_id WHERE v.id = ? AND v.center_id = ?");
$vStmt->execute([$id, $cid]);
$video = $vStmt->fetch();
if (!$video) { flash('error', 'Video not found.'); redirect('index.php'); }
if (!isRole('owner') && $video['created_by'] != $uid) { flash('error', 'Access denied.'); redirect('index.php'); }

$pageTitle  = 'Analytics — ' . sanitize($video['title']);
$activePage = 'videos';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Videos',    'href' => '/educore/modules/videos/'],
    ['label' => sanitize($video['title']), 'href' => "view.php?id=$id"],
    ['label' => 'Analytics'],
];

// ── Handle CSV export ────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $expStmt = $pdo->prepare("
        SELECT s.name AS student_name, g.name AS group_name,
               vw.watch_seconds, vw.last_position, vw.completed,
               vw.first_watched, vw.last_watched
        FROM video_views vw
        JOIN students s  ON s.id  = vw.student_id
        LEFT JOIN group_students gs ON gs.student_id = s.id AND gs.status = 'active'
        LEFT JOIN `groups` g ON g.id = gs.group_id AND g.id = ?
        WHERE vw.video_id = ? AND vw.center_id = ?
        ORDER BY vw.last_watched DESC");
    $expStmt->execute([$video['group_id'], $id, $cid]);
    $rows = $expStmt->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="video_analytics_' . $id . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student', 'Group', 'Watch Time (s)', 'Last Position (s)', 'Completed', 'First Watched', 'Last Watched']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['student_name'],
            $r['group_name'] ?? '—',
            $r['watch_seconds'],
            $r['last_position'],
            $r['completed'] ? 'Yes' : 'No',
            $r['first_watched'],
            $r['last_watched'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Aggregate stats ──────────────────────────────────────────
$aggStmt = $pdo->prepare("
    SELECT COUNT(*)                             AS total_views,
           COUNT(DISTINCT vw.student_id)        AS unique_viewers,
           ROUND(AVG(vw.watch_seconds) / 60, 1) AS avg_watch_min,
           ROUND(100.0 * SUM(vw.completed) / NULLIF(COUNT(*), 0), 1) AS completion_rate
    FROM video_views vw
    WHERE vw.video_id = ? AND vw.center_id = ?");
$aggStmt->execute([$id, $cid]);
$agg = $aggStmt->fetch();

// ── Per-student table ────────────────────────────────────────
$tStmt = $pdo->prepare("
    SELECT s.name AS student_name, g.name AS group_name,
           vw.watch_seconds, vw.last_position, vw.completed,
           vw.first_watched, vw.last_watched
    FROM video_views vw
    JOIN students s ON s.id = vw.student_id
    LEFT JOIN group_students gs ON gs.student_id = s.id AND gs.status = 'active'
                               AND gs.group_id = ?
    LEFT JOIN `groups` g ON g.id = gs.group_id
    WHERE vw.video_id = ? AND vw.center_id = ?
    ORDER BY vw.last_watched DESC");
$tStmt->execute([$video['group_id'], $id, $cid]);
$rows = $tStmt->fetchAll();

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Analytics</h1>
    <p class="page-subtitle"><?= sanitize($video['title']) ?></p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="?id=<?= $id ?>&export=csv" class="btn btn-ghost">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export CSV
    </a>
    <a href="view.php?id=<?= $id ?>" class="btn btn-ghost">← Back to Video</a>
  </div>
</div>

<!-- Stat cards -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-value"><?= (int)$agg['total_views'] ?></div>
    <div class="stat-label">Total Views</div>
  </div>
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)">
    <div class="stat-value"><?= (int)$agg['unique_viewers'] ?></div>
    <div class="stat-label">Unique Viewers</div>
  </div>
  <div class="stat-card" style="--stat-color:#f59e0b;--stat-bg:rgba(245,158,11,0.1)">
    <div class="stat-value"><?= number_format((float)$agg['avg_watch_min'], 1) ?> min</div>
    <div class="stat-label">Avg Watch Time</div>
  </div>
  <div class="stat-card" style="--stat-color:#8b5cf6;--stat-bg:rgba(139,92,246,0.1)">
    <div class="stat-value"><?= number_format((float)$agg['completion_rate'], 1) ?>%</div>
    <div class="stat-label">Completion Rate</div>
  </div>
</div>

<!-- Student table -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Student Watch Report</span>
    <span style="font-size:0.78rem;color:var(--text-muted);"><?= count($rows) ?> student<?= count($rows) != 1 ? 's' : '' ?></span>
  </div>
  <div class="card-body no-padding">
    <?php if ($rows): ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Student</th>
          <th>Group</th>
          <th>Watch Time</th>
          <th>Last Position</th>
          <th>Last Watched</th>
          <th style="text-align:center;">Completed</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $watchMin = round($r['watch_seconds'] / 60, 1);
          $posMin   = $r['last_position'] ? gmdate($r['last_position'] >= 3600 ? 'G:i:s' : 'i:s', $r['last_position']) : '—';
        ?>
        <tr>
          <td>
            <div class="cell-primary"><?= sanitize($r['student_name']) ?></div>
          </td>
          <td style="color:var(--text-secondary);font-size:0.84rem;"><?= sanitize($r['group_name'] ?? '—') ?></td>
          <td><?= $watchMin ?> min</td>
          <td style="font-family:monospace;font-size:0.82rem;"><?= $posMin ?></td>
          <td style="color:var(--text-muted);font-size:0.78rem;"><?= date('M j, Y H:i', strtotime($r['last_watched'])) ?></td>
          <td style="text-align:center;">
            <?php if ($r['completed']): ?>
              <span style="color:#22c55e;font-size:1.1rem;">✓</span>
            <?php else: ?>
              <span style="color:var(--text-muted);font-size:1rem;">✗</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state" style="padding:40px 20px;">
      <div class="empty-icon">📊</div>
      <div class="empty-title">No watch data yet</div>
      <div class="empty-desc">Students haven't watched this video yet.</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
