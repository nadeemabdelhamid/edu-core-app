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

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ? AND center_id = ?");
$stmt->execute([$id, $cid]);
$video = $stmt->fetch();
if (!$video) { flash('error', 'Video not found.'); redirect('index.php'); }

// Only owner or creator can edit
// Academy plan: use owner_teacher_id; otherwise fall back to created_by
if (!isRole('owner')) {
    $ownerCol = (isAcademyPlan() && !empty($video['owner_teacher_id'])) ? 'owner_teacher_id' : 'created_by';
    if ($video[$ownerCol] != $uid) {
    flash('error', 'You can only edit your own video.');
        redirect('index.php');
    }
}

$pageTitle  = 'Edit Video';
$activePage = 'videos';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Videos',    'href' => '/educore/modules/videos/'],
    ['label' => 'Edit Video'],
];

$gStmt = $pdo->prepare("SELECT id, name FROM `groups` WHERE center_id = ? AND is_active = 1 ORDER BY name");
$gStmt->execute([$cid]);
$groups = $gStmt->fetchAll();
if (!isRole('owner')) {
    $gStmt2 = $pdo->prepare("SELECT g.id, g.name FROM `groups` g WHERE g.center_id = ? AND g.teacher_id = ? AND g.is_active = 1 ORDER BY g.name");
    $gStmt2->execute([$cid, $uid]);
    $groups = $gStmt2->fetchAll();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $url         = trim($_POST['video_url'] ?? '');
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $groupId     = (int)($_POST['group_id'] ?? 0) ?: null;
    $durationMin = max(0, (int)($_POST['duration_min'] ?? 0));
    $isVisible   = isset($_POST['is_visible']) ? 1 : 0;
    $availFrom   = !empty($_POST['available_from'])  ? date('Y-m-d H:i:s', strtotime($_POST['available_from']))  : null;
    $availUntil  = !empty($_POST['available_until']) ? date('Y-m-d H:i:s', strtotime($_POST['available_until'])) : null;

    if (!$title) $errors[] = 'Title is required.';
    if (!$url)   $errors[] = 'Video URL is required.';

    $parsed = $url ? videoIdFromUrl($url) : null;
    if ($url && !$parsed) $errors[] = 'Could not recognise that URL. Paste a YouTube or Vimeo link.';

    if ($groupId) {
        $gChk = $pdo->prepare("SELECT id FROM `groups` WHERE id = ? AND center_id = ?");
        $gChk->execute([$groupId, $cid]);
        if (!$gChk->fetch()) { $errors[] = 'Invalid group.'; $groupId = null; }
    }

    if (!$errors) {
        [$provider, $videoId] = $parsed;
        $thumb = videoThumbnailUrl($provider, $videoId);

        $pdo->prepare("UPDATE videos
            SET group_id=?, title=?, description=?, video_provider=?, video_id=?,
                duration_min=?, thumbnail_url=?, is_visible=?,
                available_from=?, available_until=?
            WHERE id=? AND center_id=?")
            ->execute([$groupId, $title, $description, $provider, $videoId,
                       $durationMin, $thumb, $isVisible, $availFrom, $availUntil,
                       $id, $cid]);

        auditLog('video.updated', 'video', $id, ['title' => $title]);
        flash('success', 'Video updated.');
        redirect('index.php');
    }
} else {
    // Pre-fill from DB
    $url         = videoEmbedUrl($video['video_provider'], $video['video_id']); // just for display
    $rawUrl      = $video['video_provider'] === 'youtube'
                    ? "https://www.youtube.com/watch?v={$video['video_id']}"
                    : "https://vimeo.com/{$video['video_id']}";
    $title       = $video['title'];
    $description = $video['description'] ?? '';
    $groupId     = $video['group_id'];
    $durationMin = $video['duration_min'];
    $isVisible   = $video['is_visible'];
    $availFrom   = $video['available_from'];
    $availUntil  = $video['available_until'];
}

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Edit Video</h1>
    <p class="page-subtitle"><?= sanitize($video['title']) ?></p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="view.php?id=<?= $id ?>" class="btn btn-ghost">Watch</a>
    <a href="index.php" class="btn btn-ghost">← Back</a>
  </div>
</div>

<?php if ($errors): ?>
<div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-md);padding:14px 18px;font-size:0.84rem;color:var(--danger);margin-bottom:20px;">
  <?php foreach ($errors as $e): ?><div>• <?= sanitize($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 360px;gap:24px;">
<div>
  <form method="POST" id="videoForm">
    <?= csrf_field() ?>

    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><span class="card-title">Video Source</span></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label required">YouTube or Vimeo URL</label>
          <input type="url" name="video_url" id="videoUrl" class="form-control"
                 value="<?= sanitize($rawUrl ?? '') ?>"
                 placeholder="https://www.youtube.com/watch?v=..." required>
        </div>
        <div id="previewArea" style="margin-top:12px;">
          <div style="font-size:0.75rem;font-weight:600;color:var(--text-muted);margin-bottom:6px;">PREVIEW</div>
          <div style="position:relative;padding-bottom:56.25%;border-radius:var(--radius-md);overflow:hidden;background:#0f172a;">
            <iframe id="previewFrame" src="<?= sanitize(videoEmbedUrl($video['video_provider'], $video['video_id'])) ?>"
                    allowfullscreen style="position:absolute;inset:0;width:100%;height:100%;border:0;"></iframe>
          </div>
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><span class="card-title">Details</span></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label required">Title</label>
          <input type="text" name="title" class="form-control"
                 value="<?= sanitize($title ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="3"><?= sanitize($description ?? '') ?></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group">
            <label class="form-label">Assign to Group</label>
            <select name="group_id" class="form-control">
              <option value="">Center-wide</option>
              <?php foreach ($groups as $g): ?>
              <option value="<?= $g['id'] ?>" <?= $groupId == $g['id'] ? 'selected' : '' ?>><?= sanitize($g['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Duration (minutes)</label>
            <input type="number" name="duration_min" class="form-control"
                   value="<?= (int)$durationMin ?>" min="0" max="9999">
          </div>
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><span class="card-title">Visibility & Scheduling</span></div>
      <div class="card-body">
        <div class="form-group">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
            <input type="checkbox" name="is_visible" style="width:16px;height:16px;accent-color:var(--accent);"
                   <?= $isVisible ? 'checked' : '' ?>>
            <span style="font-size:0.85rem;font-weight:500;">Visible to students</span>
          </label>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Available From</label>
            <input type="datetime-local" name="available_from" class="form-control"
                   value="<?= $availFrom ? date('Y-m-d\TH:i', strtotime($availFrom)) : '' ?>">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Available Until</label>
            <input type="datetime-local" name="available_until" class="form-control"
                   value="<?= $availUntil ? date('Y-m-d\TH:i', strtotime($availUntil)) : '' ?>">
          </div>
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg w-full">Save Changes</button>
  </form>
</div>

<div>
  <div class="card">
    <div class="card-header"><span class="card-title">Video Info</span></div>
    <div class="card-body" style="font-size:0.83rem;">
      <div style="display:flex;flex-direction:column;gap:8px;color:var(--text-secondary);">
        <div><strong>Provider:</strong> <?= ucfirst(sanitize($video['video_provider'])) ?></div>
        <div><strong>Video ID:</strong> <code><?= sanitize($video['video_id']) ?></code></div>
        <div><strong>Added:</strong> <?= date('M j, Y', strtotime($video['created_at'])) ?></div>
      </div>
    </div>
  </div>
</div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
const urlInput    = document.getElementById('videoUrl');
const previewFrame= document.getElementById('previewFrame');

function parseVideoUrl(url) {
  let m = url.match(/youtu(?:be\.com\/(?:watch\?v=|shorts\/)|\.be\/)([A-Za-z0-9_-]{11})/);
  if (m) return { provider: 'youtube', id: m[1] };
  m = url.match(/vimeo\.com\/(?:(?:channels|groups)\/[^\/]+\/)?(?:videos?\/)?(\d+)/);
  if (m) return { provider: 'vimeo', id: m[1] };
  return null;
}

urlInput.addEventListener('blur', function () {
  const parsed = parseVideoUrl(this.value.trim());
  if (parsed) {
    const base = parsed.provider === 'youtube'
      ? `https://www.youtube.com/embed/${parsed.id}?rel=0&modestbranding=1`
      : `https://player.vimeo.com/video/${parsed.id}?dnt=1`;
    previewFrame.src = base;
  }
});
</script>
JS;
include __DIR__ . '/../../templates/layout_end.php';
?>
