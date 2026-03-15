<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

if (!hasFeature('videos')) {
    flash('error', 'Videos are not available on your current plan. Upgrade to Pro or higher.');
    redirect('/educore/modules/settings/subscription.php');
}

$pdo = getDB();
$cid = centerId();
$uid = authId();

$pageTitle  = 'Videos';
$activePage = 'videos';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Videos'],
];

$gid    = (int)($_GET['group'] ?? 0);
$page   = max(1, (int)($_GET['page'] ?? 1));

$where  = "WHERE v.center_id = ?";
$params = [$cid];

// Academy plan: teacher sees their own videos OR global (owner-uploaded) videos
// Individual plan: owner sees all, no restriction
if (isRole('teacher') && isAcademyPlan()) {
    $where  .= " AND (v.owner_teacher_id = ? OR v.is_global = 1)";
    $params[] = $uid;
} elseif (!isRole('owner', 'assistant')) {
    // fallback for non-scoped roles
    $where .= " AND v.created_by = ?";
    $params[] = $uid;
}

if ($gid) { $where .= " AND v.group_id = ?"; $params[] = $gid; }

$query = "SELECT v.*,
                 g.name AS group_name,
                 u.name AS creator_name,
                 COUNT(DISTINCT vw.id) AS view_count
          FROM videos v
          LEFT JOIN `groups` g  ON g.id  = v.group_id
          LEFT JOIN users u     ON u.id  = v.created_by
          LEFT JOIN video_views vw ON vw.video_id = v.id
          $where
          GROUP BY v.id
          ORDER BY v.sort_order ASC, v.created_at DESC";

$result = paginate($query, $params, $page, 20);
$videos = $result['data'];

// Stats
$statsStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT v.id)   AS total_videos,
           COUNT(DISTINCT vw.id)  AS total_views,
           COUNT(DISTINCT v.group_id) AS groups_covered
    FROM videos v
    LEFT JOIN video_views vw ON vw.video_id = v.id
    WHERE v.center_id = ?");
$statsStmt->execute([$cid]);
$stats = $statsStmt->fetch();

$gStmt = $pdo->prepare("SELECT id, name FROM `groups` WHERE center_id = ? AND is_active = 1 ORDER BY name");
$gStmt->execute([$cid]);
$groups = $gStmt->fetchAll();

// Handle delete from index
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if ($_POST['action'] === 'delete') {
        $delId = (int)($_POST['video_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id, title, created_by FROM videos WHERE id = ? AND center_id = ?");
        $chk->execute([$delId, $cid]);
        $vid = $chk->fetch();
        if ($vid) {
            if (!isRole('owner') && $vid['created_by'] != $uid) {
                flash('error', 'You can only delete your own videos.');
            } else {
                $pdo->prepare("DELETE FROM video_views WHERE video_id = ?")->execute([$delId]);
                $pdo->prepare("DELETE FROM videos WHERE id = ? AND center_id = ?")->execute([$delId, $cid]);
                auditLog('video.deleted', 'video', $delId, ['title' => $vid['title']]);
                flash('success', '"' . sanitize($vid['title']) . '" deleted.');
            }
        }
    }
    redirect('index.php' . ($gid ? "?group=$gid" : ''));
}

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Videos</h1>
    <p class="page-subtitle">Recorded lectures for your groups</p>
  </div>
  <?php if (!isRole('assistant')): ?>
  <div style="display:flex;gap:8px;">
    <a href="playlists.php" class="btn btn-ghost">🎞️ Playlists</a>
    <a href="create.php" class="btn btn-primary">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Upload New Video
  </a>
  </div>
  <?php endif; ?>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px;">
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-value"><?= (int)$stats['total_videos'] ?></div>
    <div class="stat-label">Total Videos</div>
  </div>
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)">
    <div class="stat-value"><?= (int)$stats['total_views'] ?></div>
    <div class="stat-label">Total Views</div>
  </div>
  <div class="stat-card" style="--stat-color:#8b5cf6;--stat-bg:rgba(139,92,246,0.1)">
    <div class="stat-value"><?= (int)$stats['groups_covered'] ?></div>
    <div class="stat-label">Groups Covered</div>
  </div>
</div>

<!-- Filters -->
<?php if ($groups): ?>
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:12px 20px;">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <a href="index.php" class="btn btn-sm <?= !$gid ? 'btn-primary' : 'btn-ghost' ?>">All Groups</a>
      <?php foreach ($groups as $g): ?>
      <a href="index.php?group=<?= $g['id'] ?>" class="btn btn-sm <?= $gid == $g['id'] ? 'btn-primary' : 'btn-ghost' ?>"><?= sanitize($g['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($videos): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;">
  <?php foreach ($videos as $v):
    $thumb = $v['thumbnail_url'] ?: ($v['video_provider'] === 'youtube' ? videoThumbnailUrl('youtube', $v['video_id']) : '');
    $now = time();
    $isScheduled = $v['available_from'] && strtotime($v['available_from']) > $now;
    $isExpired   = $v['available_until'] && strtotime($v['available_until']) < $now;
  ?>
  <div class="card" style="overflow:hidden;">
    <!-- Thumbnail -->
    <a href="view.php?id=<?= $v['id'] ?>" style="display:block;position:relative;aspect-ratio:16/9;background:#0f172a;overflow:hidden;">
      <?php if ($thumb): ?>
        <img src="<?= sanitize($thumb) ?>" alt="" style="width:100%;height:100%;object-fit:cover;opacity:<?= $v['is_visible'] ? '1' : '0.5' ?>;">
      <?php else: ?>
        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:2.5rem;">🎬</div>
      <?php endif; ?>
      <!-- Play overlay -->
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.3);opacity:0;transition:opacity 0.2s;" onmouseenter="this.style.opacity='1'" onmouseleave="this.style.opacity='0'">
        <div style="width:52px;height:52px;background:rgba(255,255,255,0.9);border-radius:50%;display:flex;align-items:center;justify-content:center;">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="#1e293b"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        </div>
      </div>
      <!-- Provider badge -->
      <span style="position:absolute;top:8px;left:8px;background:rgba(0,0,0,0.7);color:#fff;font-size:0.65rem;font-weight:700;padding:2px 7px;border-radius:20px;text-transform:uppercase;letter-spacing:0.05em;">
        <?= $v['video_provider'] ?>
      </span>
      <?php if ($v['duration_min']): ?>
      <span style="position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,0.75);color:#fff;font-size:0.72rem;font-weight:600;padding:2px 7px;border-radius:4px;">
        <?= (int)$v['duration_min'] ?> min
      </span>
      <?php endif; ?>
    </a>

    <div class="card-body" style="padding:14px 16px;">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:6px;">
        <a href="view.php?id=<?= $v['id'] ?>" style="font-weight:600;color:var(--text-primary);font-size:0.9rem;text-decoration:none;line-height:1.4;flex:1;" class="truncate">
          <?= sanitize($v['title']) ?>
        </a>
      </div>

      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
        <?php if ($v['group_name']): ?>
          <span class="badge badge-info"><?= sanitize($v['group_name']) ?></span>
        <?php else: ?>
          <span class="badge badge-muted">Center-wide</span>
        <?php endif; ?>
        <?php if (!$v['is_visible']): ?>
          <span class="badge badge-warning">Hidden</span>
        <?php elseif ($isScheduled): ?>
          <span class="badge badge-info">Scheduled</span>
        <?php elseif ($isExpired): ?>
          <span class="badge badge-muted">Expired</span>
        <?php else: ?>
          <span class="badge badge-success">Visible</span>
        <?php endif; ?>
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.75rem;color:var(--text-muted);">
        <span>👁 <?= (int)$v['view_count'] ?> views · <?= sanitize($v['creator_name']) ?></span>
        <div style="display:flex;gap:4px;">
          <a href="view.php?id=<?= $v['id'] ?>" class="btn btn-ghost btn-icon" title="Watch">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </a>
          <?php if (isRole('owner') || $v['created_by'] == $uid): ?>
          <a href="edit.php?id=<?= $v['id'] ?>" class="btn btn-ghost btn-icon" title="Edit" style="color:var(--accent);">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </a>
          <?php if (hasFeature('video_analytics')): ?>
          <a href="analytics.php?id=<?= $v['id'] ?>" class="btn btn-ghost btn-icon" title="Analytics" style="color:#8b5cf6;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
          </a>
          <?php endif; ?>
          <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this video? This cannot be undone.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="video_id" value="<?= $v['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-icon" title="Delete" style="color:var(--danger);">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($result['last_page'] > 1): ?>
<div class="pagination" style="margin-top:24px;">
  <span>Page <?= $result['current_page'] ?> of <?= $result['last_page'] ?></span>
  <div class="page-btns">
    <?php for ($i = 1; $i <= $result['last_page']; $i++): ?>
    <a href="?page=<?= $i ?>&group=<?= $gid ?>" class="page-btn <?= $i === $result['current_page'] ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="empty-state">
  <div class="empty-icon">🎬</div>
  <div class="empty-title">No videos yet</div>
  <div class="empty-desc">Add your first recorded lecture to get started.</div>
  <?php if (!isRole('assistant')): ?>
  <a href="create.php" class="btn btn-primary btn-sm" style="margin-top:14px;">Upload First Video</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
