<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher');

if (!hasFeature('videos')) {
    flash('error', 'Videos are not available on your current plan.');
    redirect('/educore/modules/settings/subscription.php');
}

$pdo = getDB();
$cid = centerId();
$uid = authId();

$id       = (int)($_GET['id'] ?? 0);
$isEdit   = $id > 0;
$playlist = null;
$plVideos = [];

if ($isEdit) {
    $pStmt = $pdo->prepare("SELECT * FROM video_playlists WHERE id = ? AND center_id = ?");
    $pStmt->execute([$id, $cid]);
    $playlist = $pStmt->fetch();
    if (!$playlist) { flash('error', 'Playlist not found.'); redirect('playlists.php'); }
    if (!isRole('owner') && $playlist['created_by'] != $uid) {
        flash('error', 'Access denied.'); redirect('playlists.php');
    }
    // Ordered videos already in playlist
    $pvStmt = $pdo->prepare("
        SELECT v.id, v.title, v.duration_min, v.thumbnail_url, v.video_provider, v.video_id, pv.sort_order
        FROM playlist_videos pv
        JOIN videos v ON v.id = pv.video_id
        WHERE pv.playlist_id = ?
        ORDER BY pv.sort_order ASC");
    $pvStmt->execute([$id]);
    $plVideos = $pvStmt->fetchAll();
}

$pageTitle  = $isEdit ? 'Edit Playlist' : 'New Playlist';
$activePage = 'videos';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Videos',    'href' => '/educore/modules/videos/'],
    ['label' => 'Playlists', 'href' => '/educore/modules/videos/playlists.php'],
    ['label' => $pageTitle],
];

// All videos this user can pick from
$vWhere  = "WHERE v.center_id = ?";
$vParams = [$cid];
if (!isRole('owner')) { $vWhere .= " AND v.created_by = ?"; $vParams[] = $uid; }
$allVStmt = $pdo->prepare("SELECT v.id, v.title, v.duration_min, v.thumbnail_url, v.video_provider, v.video_id, g.name AS group_name
    FROM videos v LEFT JOIN `groups` g ON g.id = v.group_id $vWhere ORDER BY v.title");
$allVStmt->execute($vParams);
$allVideos = $allVStmt->fetchAll();

$plVideoIds = array_column($plVideos, 'id');

$gStmt = $pdo->prepare("SELECT id, name FROM `groups` WHERE center_id = ? AND is_active = 1 ORDER BY name");
$gStmt->execute([$cid]);
$groups = $gStmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $groupId     = (int)($_POST['group_id'] ?? 0) ?: null;
    $isVisible   = isset($_POST['is_visible']) ? 1 : 0;
    $videoIds    = array_map('intval', (array)($_POST['video_ids'] ?? []));

    if (!$title) $errors[] = 'Title is required.';

    if (!$errors) {
        if ($isEdit) {
            $pdo->prepare("UPDATE video_playlists SET title=?, description=?, group_id=?, is_visible=? WHERE id=? AND center_id=?")
                ->execute([$title, $description, $groupId, $isVisible, $id, $cid]);
            $pdo->prepare("DELETE FROM playlist_videos WHERE playlist_id = ?")->execute([$id]);
        } else {
            $pdo->prepare("INSERT INTO video_playlists (center_id, group_id, title, description, is_visible, created_by) VALUES (?,?,?,?,?,?)")
                ->execute([$cid, $groupId, $title, $description, $isVisible, $uid]);
            $id = $pdo->lastInsertId();
        }

        // Re-insert videos in submitted order (validated against center)
        $validStmt = $pdo->prepare("SELECT id FROM videos WHERE id = ? AND center_id = ?");
        foreach (array_values($videoIds) as $i => $vid) {
            $validStmt->execute([$vid, $cid]);
            if ($validStmt->fetch()) {
                $pdo->prepare("INSERT INTO playlist_videos (playlist_id, video_id, sort_order) VALUES (?,?,?)")
                    ->execute([$id, $vid, $i]);
            }
        }

        auditLog($isEdit ? 'playlist.updated' : 'playlist.created', 'playlist', $id, ['title' => $title]);
        flash('success', $isEdit ? 'Playlist updated.' : '"' . sanitize($title) . '" created.');
        redirect("playlist_view.php?id=$id");
    }
}

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= $pageTitle ?></h1>
    <p class="page-subtitle"><?= $isEdit ? 'Reorder videos by dragging' : 'Pick videos and set the order' ?></p>
  </div>
  <a href="playlists.php" class="btn btn-ghost">← Back to Playlists</a>
</div>

<?php if ($errors): ?>
<div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-md);padding:14px 18px;font-size:0.84rem;color:var(--danger);margin-bottom:20px;">
  <?php foreach ($errors as $e): ?><div>• <?= sanitize($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" id="plForm">
  <?= csrf_field() ?>

  <div style="display:grid;grid-template-columns:1fr 380px;gap:24px;">

    <!-- Left: video picker + order -->
    <div>
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header">
          <span class="card-title">Videos in This Playlist</span>
          <span style="font-size:0.75rem;color:var(--text-muted);">Drag to reorder</span>
        </div>
        <div class="card-body" style="padding:0;">
          <div id="sortableList" style="min-height:80px;">
            <?php foreach ($plVideos as $v): ?>
            <div class="sortable-item" data-id="<?= $v['id'] ?>" style="display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid var(--border);cursor:grab;user-select:none;">
              <span style="color:var(--text-muted);cursor:grab;">⠿</span>
              <?php $thumb = $v['thumbnail_url'] ?: ($v['video_provider'] === 'youtube' ? "https://img.youtube.com/vi/{$v['video_id']}/default.jpg" : ''); ?>
              <?php if ($thumb): ?>
                <img src="<?= sanitize($thumb) ?>" style="width:60px;height:34px;object-fit:cover;border-radius:4px;flex-shrink:0;">
              <?php else: ?>
                <div style="width:60px;height:34px;background:var(--bg-subtle);border-radius:4px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">🎬</div>
              <?php endif; ?>
              <div style="flex:1;min-width:0;">
                <div style="font-size:0.85rem;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($v['title']) ?></div>
                <?php if ($v['duration_min']): ?><div style="font-size:0.72rem;color:var(--text-muted);"><?= (int)$v['duration_min'] ?> min</div><?php endif; ?>
              </div>
              <button type="button" onclick="removeFromPlaylist(this, <?= $v['id'] ?>)"
                      style="background:none;border:none;cursor:pointer;color:var(--danger);font-size:1.1rem;padding:2px 4px;" title="Remove">×</button>
              <input type="hidden" name="video_ids[]" class="vid-hidden" value="<?= $v['id'] ?>">
            </div>
            <?php endforeach; ?>
          </div>
          <?php if (empty($plVideos)): ?>
          <div id="emptyMsg" style="padding:24px;text-align:center;color:var(--text-muted);font-size:0.85rem;">
            No videos added yet. Pick from the list →
          </div>
          <?php else: ?>
          <div id="emptyMsg" style="display:none;padding:24px;text-align:center;color:var(--text-muted);font-size:0.85rem;">
            No videos added yet. Pick from the list →
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Video picker -->
      <div class="card">
        <div class="card-header">
          <span class="card-title">Add Videos</span>
          <input type="text" id="videoSearch" placeholder="Search videos…"
                 style="font-size:0.78rem;padding:5px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-input);color:var(--text-primary);width:180px;">
        </div>
        <div class="card-body no-padding" style="max-height:360px;overflow-y:auto;">
          <?php foreach ($allVideos as $v): ?>
          <?php $inList = in_array($v['id'], $plVideoIds); ?>
          <div class="picker-row" data-id="<?= $v['id'] ?>" data-title="<?= strtolower(sanitize($v['title'])) ?>"
               style="display:flex;align-items:center;gap:10px;padding:9px 16px;border-bottom:1px solid var(--border);cursor:pointer;transition:background 0.15s;"
               onclick="toggleVideo(<?= $v['id'] ?>, '<?= addslashes(sanitize($v['title'])) ?>', '<?= addslashes(sanitize($v['thumbnail_url'] ?: ($v['video_provider'] === 'youtube' ? "https://img.youtube.com/vi/{$v['video_id']}/default.jpg" : ''))) ?>', <?= (int)$v['duration_min'] ?>)">
            <?php $thumb = $v['thumbnail_url'] ?: ($v['video_provider'] === 'youtube' ? "https://img.youtube.com/vi/{$v['video_id']}/default.jpg" : ''); ?>
            <?php if ($thumb): ?>
              <img src="<?= sanitize($thumb) ?>" style="width:52px;height:30px;object-fit:cover;border-radius:3px;flex-shrink:0;">
            <?php else: ?>
              <div style="width:52px;height:30px;background:var(--bg-subtle);border-radius:3px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:0.9rem;">🎬</div>
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
              <div style="font-size:0.83rem;font-weight:500;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($v['title']) ?></div>
              <div style="font-size:0.7rem;color:var(--text-muted);"><?= $v['duration_min'] ? (int)$v['duration_min'].' min · ' : '' ?><?= sanitize($v['group_name'] ?? 'Center-wide') ?></div>
            </div>
            <span class="add-check" style="font-size:1.1rem;color:<?= $inList ? '#22c55e' : 'var(--text-muted)' ?>;"><?= $inList ? '✓' : '+' ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Right: playlist details -->
    <div>
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header"><span class="card-title">Playlist Details</span></div>
        <div class="card-body">
          <div class="form-group">
            <label class="form-label required">Title</label>
            <input type="text" name="title" class="form-control"
                   value="<?= sanitize($playlist['title'] ?? $_POST['title'] ?? '') ?>"
                   placeholder="e.g. Algebra — Chapter 1" required autofocus>
          </div>
          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="3"
                      placeholder="What will students learn in this playlist?"><?= sanitize($playlist['description'] ?? $_POST['description'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Assign to Group</label>
            <select name="group_id" class="form-control">
              <option value="">Center-wide</option>
              <?php foreach ($groups as $g): ?>
              <option value="<?= $g['id'] ?>" <?= ($playlist['group_id'] ?? '') == $g['id'] ? 'selected' : '' ?>><?= sanitize($g['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
              <input type="checkbox" name="is_visible" style="width:16px;height:16px;accent-color:var(--accent);"
                     <?= ($playlist['is_visible'] ?? 1) ? 'checked' : '' ?>>
              <span style="font-size:0.85rem;font-weight:500;">Visible to students</span>
            </label>
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-lg w-full">
        <?= $isEdit ? 'Save Changes' : 'Create Playlist →' ?>
      </button>
    </div>
  </div>
</form>

<?php
$inListIds = json_encode($plVideoIds);
$extraScripts = <<<JS
<script>
// ── Track which videos are in the list ──────────────────────
let inList = new Set({$inListIds});

function toggleVideo(vid, title, thumb, dur) {
  if (inList.has(vid)) {
    removeFromPlaylist(null, vid);
  } else {
    addToPlaylist(vid, title, thumb, dur);
  }
}

function addToPlaylist(vid, title, thumb, dur) {
  if (inList.has(vid)) return;
  inList.add(vid);

  const list = document.getElementById('sortableList');
  const item = document.createElement('div');
  item.className = 'sortable-item';
  item.dataset.id = vid;
  item.style.cssText = 'display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid var(--border);cursor:grab;user-select:none;';

  const thumbHtml = thumb
    ? '<img src="' + thumb + '" style="width:60px;height:34px;object-fit:cover;border-radius:4px;flex-shrink:0;">'
    : '<div style="width:60px;height:34px;background:var(--bg-subtle);border-radius:4px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">🎬</div>';
  const durHtml = dur ? '<div style="font-size:0.72rem;color:var(--text-muted);">' + dur + ' min</div>' : '';

  item.innerHTML =
    '<span style="color:var(--text-muted);cursor:grab;">⠿</span>' +
    thumbHtml +
    '<div style="flex:1;min-width:0;">' +
      '<div style="font-size:0.85rem;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + title + '</div>' +
      durHtml +
    '</div>' +
    '<button type="button" onclick="removeFromPlaylist(this,' + vid + ')" style="background:none;border:none;cursor:pointer;color:var(--danger);font-size:1.1rem;padding:2px 4px;">×</button>' +
    '<input type="hidden" name="video_ids[]" class="vid-hidden" value="' + vid + '">';

  list.appendChild(item);
  document.getElementById('emptyMsg').style.display = 'none';

  // Update picker row
  const row = document.querySelector(`.picker-row[data-id="${vid}"] .add-check`);
  if (row) { row.textContent = '✓'; row.style.color = '#22c55e'; }

  initDrag();
}

function removeFromPlaylist(btn, vid) {
  inList.delete(vid);
  const item = document.querySelector(`.sortable-item[data-id="${vid}"]`);
  if (item) item.remove();

  const row = document.querySelector(`.picker-row[data-id="${vid}"] .add-check`);
  if (row) { row.textContent = '+'; row.style.color = 'var(--text-muted)'; }

  if (document.querySelectorAll('.sortable-item').length === 0) {
    document.getElementById('emptyMsg').style.display = 'block';
  }
}

// ── Simple drag-and-drop sort ────────────────────────────────
let dragEl = null;

function initDrag() {
  document.querySelectorAll('.sortable-item').forEach(el => {
    el.draggable = true;
    el.addEventListener('dragstart', e => { dragEl = el; e.dataTransfer.effectAllowed = 'move'; });
    el.addEventListener('dragover',  e => {
      e.preventDefault();
      const rect = el.getBoundingClientRect();
      const after = e.clientY > rect.top + rect.height / 2;
      const list  = document.getElementById('sortableList');
      if (after) list.insertBefore(dragEl, el.nextSibling);
      else       list.insertBefore(dragEl, el);
    });
    el.addEventListener('dragend', () => { dragEl = null; });
  });
}
initDrag();

// ── Video search filter ───────────────────────────────────────
document.getElementById('videoSearch').addEventListener('input', function () {
  const q = this.value.toLowerCase();
  document.querySelectorAll('.picker-row').forEach(row => {
    row.style.display = row.dataset.title.includes(q) ? '' : 'none';
  });
});

// ── Hover on picker rows ─────────────────────────────────────
document.querySelectorAll('.picker-row').forEach(row => {
  row.addEventListener('mouseenter', () => row.style.background = 'var(--bg-hover)');
  row.addEventListener('mouseleave', () => row.style.background = '');
});
</script>
JS;
include __DIR__ . '/../../templates/layout_end.php';
?>
