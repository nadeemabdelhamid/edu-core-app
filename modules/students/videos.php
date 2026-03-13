<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('student');

if (!hasFeature('videos')) {
    flash('info', 'Videos are not available on your current plan.');
    redirect('/educore/modules/students/home.php');
}

$pdo = getDB();
$cid = centerId();
$uid = authId();

$pageTitle  = 'Lectures';
$activePage = 'videos';
$breadcrumbs = [
    ['label' => 'My Dashboard', 'href' => '/educore/modules/students/home.php'],
    ['label' => 'Lectures'],
];

// Load student record
$stStmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ? AND center_id = ? AND is_active = 1");
$stStmt->execute([$uid, $cid]);
$student = $stStmt->fetch();
if (!$student) { flash('error', 'Student profile not found.'); redirect('/educore/login.php'); }
$studentId = $student['id'];

// Groups the student is enrolled in
$groupStmt = $pdo->prepare("
    SELECT g.id, g.name
    FROM group_students gs
    JOIN `groups` g ON g.id = gs.group_id
    WHERE gs.student_id = ? AND gs.status = 'active' AND g.center_id = ?
    ORDER BY g.name");
$groupStmt->execute([$studentId, $cid]);
$myGroups = $groupStmt->fetchAll();
$myGroupIds = array_column($myGroups, 'id');

$activeGroupFilter = (int)($_GET['group'] ?? 0);

// Build query: only videos belonging to enrolled groups (or center-wide) that are currently accessible
$now = date('Y-m-d H:i:s');
$placeholders = $myGroupIds ? implode(',', array_fill(0, count($myGroupIds), '?')) : '0';

$params = array_merge([$cid, $now, $now], $myGroupIds, $myGroupIds);

$groupWhere = '';
if ($activeGroupFilter && in_array($activeGroupFilter, $myGroupIds)) {
    $groupWhere = " AND (v.group_id = ?)";
    $params[]   = $activeGroupFilter;
}

$sql = "
    SELECT v.*,
           g.name AS group_name,
           u.name AS teacher_name,
           vw.last_position,
           vw.completed
    FROM videos v
    LEFT JOIN `groups` g  ON g.id  = v.group_id
    LEFT JOIN users    u  ON u.id  = v.created_by
    LEFT JOIN video_views vw ON vw.video_id = v.id AND vw.student_id = $studentId
    WHERE v.center_id = ?
      AND v.is_visible = 1
      AND (v.available_from  IS NULL OR v.available_from  <= ?)
      AND (v.available_until IS NULL OR v.available_until >= ?)
      AND (
          v.group_id IS NULL
          OR v.group_id IN ($placeholders)
          OR (v.group_id IS NULL AND 1 = (SELECT 1))
      )
      -- center-wide (no group) always shown if enrolled anywhere
      -- group videos shown only for enrolled groups
    $groupWhere
    ORDER BY v.sort_order ASC, v.created_at DESC";

// Rewrite query for clarity
$sql = "
    SELECT v.*,
           g.name AS group_name,
           u.name AS teacher_name,
           vw.last_position,
           vw.completed
    FROM videos v
    LEFT JOIN `groups` g  ON g.id  = v.group_id
    LEFT JOIN users    u  ON u.id  = v.created_by
    LEFT JOIN video_views vw ON vw.video_id = v.id AND vw.student_id = ?
    WHERE v.center_id = ?
      AND v.is_visible = 1
      AND (v.available_from  IS NULL OR v.available_from  <= ?)
      AND (v.available_until IS NULL OR v.available_until >= ?)
      AND (
            v.group_id IS NULL
            " . ($myGroupIds ? "OR v.group_id IN ($placeholders)" : '') . "
          )
      $groupWhere
    ORDER BY v.sort_order ASC, v.created_at DESC";

$params2 = array_merge(
    [$studentId, $cid, $now, $now],
    $myGroupIds,
    $activeGroupFilter ? [$activeGroupFilter] : []
);

$vStmt = $pdo->prepare($sql);
$vStmt->execute($params2);
$videos = $vStmt->fetchAll();

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Lectures</h1>
    <p class="page-subtitle">Recorded lessons from your teachers</p>
  </div>
</div>

<!-- Group filter tabs -->
<?php if (count($myGroups) > 1): ?>
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:12px 20px;">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <a href="videos.php" class="btn btn-sm <?= !$activeGroupFilter ? 'btn-primary' : 'btn-ghost' ?>">All Groups</a>
      <?php foreach ($myGroups as $g): ?>
      <a href="videos.php?group=<?= $g['id'] ?>" class="btn btn-sm <?= $activeGroupFilter == $g['id'] ? 'btn-primary' : 'btn-ghost' ?>"><?= sanitize($g['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($videos): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;">
  <?php foreach ($videos as $v):
    $thumb     = $v['thumbnail_url'] ?: ($v['video_provider'] === 'youtube' ? videoThumbnailUrl('youtube', $v['video_id']) : '');
    $resumeAt  = (int)($v['last_position'] ?? 0);
    $completed = !empty($v['completed']);
    $resumeStr = '';
    if ($resumeAt > 0 && !$completed) {
        $h = floor($resumeAt / 3600);
        $m = floor(($resumeAt % 3600) / 60);
        $s = $resumeAt % 60;
        $resumeStr = $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
  ?>
  <div class="card" style="overflow:hidden;display:flex;flex-direction:column;">
    <!-- Thumbnail -->
    <a href="/educore/modules/videos/view.php?id=<?= $v['id'] ?>"
       style="display:block;position:relative;aspect-ratio:16/9;background:#0f172a;overflow:hidden;flex-shrink:0;">
      <?php if ($thumb): ?>
        <img src="<?= sanitize($thumb) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
      <?php else: ?>
        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:2.5rem;">🎬</div>
      <?php endif; ?>
      <!-- Play overlay -->
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.25);opacity:0;transition:opacity 0.2s;" class="play-overlay">
        <div style="width:48px;height:48px;background:rgba(255,255,255,0.9);border-radius:50%;display:flex;align-items:center;justify-content:center;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="#1e293b"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        </div>
      </div>
      <?php if ($completed): ?>
        <div style="position:absolute;top:8px;right:8px;background:#22c55e;color:#fff;font-size:0.65rem;font-weight:700;padding:3px 8px;border-radius:20px;">✓ Done</div>
      <?php elseif ($resumeStr): ?>
        <div style="position:absolute;bottom:8px;left:8px;background:rgba(0,0,0,0.8);color:#fff;font-size:0.68rem;font-weight:600;padding:3px 8px;border-radius:4px;">▶ Resume <?= $resumeStr ?></div>
      <?php endif; ?>
      <?php if ($v['duration_min']): ?>
        <span style="position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,0.75);color:#fff;font-size:0.68rem;font-weight:600;padding:2px 6px;border-radius:4px;"><?= (int)$v['duration_min'] ?> min</span>
      <?php endif; ?>
    </a>

    <div class="card-body" style="padding:14px 16px;flex:1;display:flex;flex-direction:column;gap:6px;">
      <a href="/educore/modules/videos/view.php?id=<?= $v['id'] ?>"
         style="font-weight:600;color:var(--text-primary);text-decoration:none;font-size:0.9rem;line-height:1.4;">
        <?= sanitize($v['title']) ?>
      </a>
      <div style="font-size:0.76rem;color:var(--text-muted);">
        <?= sanitize($v['teacher_name']) ?>
        <?php if ($v['group_name']): ?> · <span class="badge badge-info" style="font-size:0.67rem;"><?= sanitize($v['group_name']) ?></span><?php endif; ?>
      </div>
      <a href="/educore/modules/videos/view.php?id=<?= $v['id'] ?>"
         class="btn btn-primary btn-sm" style="margin-top:auto;">
        <?= $resumeStr ? "▶ Resume" : ($completed ? "Watch Again" : "Watch") ?>
      </a>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php else: ?>
<div class="empty-state">
  <div class="empty-icon">🎬</div>
  <div class="empty-title">No lectures yet</div>
  <div class="empty-desc">Your teachers haven't added any video lectures yet. Check back later.</div>
</div>
<?php endif; ?>

<?php
$extraScripts = <<<'JS'
<script>
document.querySelectorAll('.card').forEach(card => {
  const overlay = card.querySelector('.play-overlay');
  if (!overlay) return;
  card.addEventListener('mouseenter', () => overlay.style.opacity = '1');
  card.addEventListener('mouseleave', () => overlay.style.opacity = '0');
});
</script>
JS;
include __DIR__ . '/../../templates/layout_end.php';
?>
