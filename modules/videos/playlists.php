<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

if (!hasFeature('videos')) {
    flash('error', 'Videos are not available on your current plan.');
    redirect('/educore/modules/settings/subscription.php');
}

$pdo = getDB();
$cid = centerId();
$uid = authId();

$pageTitle  = 'Playlists';
$activePage = 'videos';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Videos',    'href' => '/educore/modules/videos/'],
    ['label' => 'Playlists'],
];

// Delete handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if ($_POST['action'] === 'delete') {
        $delId = (int)($_POST['playlist_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id, title, created_by FROM video_playlists WHERE id = ? AND center_id = ?");
        $chk->execute([$delId, $cid]);
        $pl = $chk->fetch();
        if ($pl && (isRole('owner') || $pl['created_by'] == $uid)) {
            $pdo->prepare("DELETE FROM playlist_videos WHERE playlist_id = ?")->execute([$delId]);
            $pdo->prepare("DELETE FROM video_playlists WHERE id = ?")->execute([$delId]);
            flash('success', '"' . sanitize($pl['title']) . '" deleted.');
        }
    }
    redirect('playlists.php');
}

$where  = "WHERE vp.center_id = ?";
$params = [$cid];
if (!isRole('owner', 'assistant')) { $where .= " AND vp.created_by = ?"; $params[] = $uid; }

$stmt = $pdo->prepare("
    SELECT vp.*, u.name AS creator_name, g.name AS group_name,
           COUNT(DISTINCT pv.video_id) AS video_count
    FROM video_playlists vp
    LEFT JOIN users    u  ON u.id  = vp.created_by
    LEFT JOIN `groups` g  ON g.id  = vp.group_id
    LEFT JOIN playlist_videos pv ON pv.playlist_id = vp.id
    $where
    GROUP BY vp.id
    ORDER BY vp.created_at DESC");
$stmt->execute($params);
$playlists = $stmt->fetchAll();

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Playlists</h1>
    <p class="page-subtitle">Organise videos into course sequences</p>
  </div>
  <?php if (!isRole('assistant')): ?>
  <a href="playlist_edit.php" class="btn btn-primary">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New Playlist
  </a>
  <?php endif; ?>
</div>

<?php if ($playlists): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;">
  <?php foreach ($playlists as $pl): ?>
  <div class="card">
    <div class="card-body" style="padding:20px;">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:10px;">
        <div>
          <a href="playlist_view.php?id=<?= $pl['id'] ?>"
             style="font-weight:700;font-size:1rem;color:var(--text-primary);text-decoration:none;display:block;line-height:1.3;margin-bottom:4px;">
            <?= sanitize($pl['title']) ?>
          </a>
          <div style="font-size:0.75rem;color:var(--text-muted);">
            <?= (int)$pl['video_count'] ?> video<?= $pl['video_count'] != 1 ? 's' : '' ?>
            <?php if ($pl['group_name']): ?> · <span class="badge badge-info" style="font-size:0.65rem;"><?= sanitize($pl['group_name']) ?></span><?php endif; ?>
          </div>
        </div>
        <span style="font-size:2rem;line-height:1;">🎞️</span>
      </div>

      <?php if ($pl['description']): ?>
      <p style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:12px;line-height:1.5;">
        <?= sanitize(mb_substr($pl['description'], 0, 120)) ?><?= mb_strlen($pl['description']) > 120 ? '…' : '' ?>
      </p>
      <?php endif; ?>

      <div style="display:flex;gap:6px;align-items:center;font-size:0.75rem;color:var(--text-muted);margin-bottom:12px;">
        <?= sanitize($pl['creator_name']) ?> · <?= date('M j, Y', strtotime($pl['created_at'])) ?>
        <?php if (!$pl['is_visible']): ?>
          · <span class="badge badge-warning" style="font-size:0.65rem;">Hidden</span>
        <?php endif; ?>
      </div>

      <div style="display:flex;gap:8px;">
        <a href="playlist_view.php?id=<?= $pl['id'] ?>" class="btn btn-primary btn-sm" style="flex:1;text-align:center;">View</a>
        <?php if (isRole('owner') || $pl['created_by'] == $uid): ?>
        <a href="playlist_edit.php?id=<?= $pl['id'] ?>" class="btn btn-ghost btn-sm" title="Edit">✏️</a>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this playlist? Videos are NOT deleted.')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
          <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);" title="Delete">🗑</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty-state">
  <div class="empty-icon">🎞️</div>
  <div class="empty-title">No playlists yet</div>
  <div class="empty-desc">Group related videos into a course sequence so students progress through material in order.</div>
  <?php if (!isRole('assistant')): ?>
  <a href="playlist_edit.php" class="btn btn-primary btn-sm" style="margin-top:14px;">Create First Playlist</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
