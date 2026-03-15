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

$pageTitle  = 'Upload Video';
$activePage = 'videos';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Videos',    'href' => '/educore/modules/videos/'],
    ['label' => 'Upload Video'],
];

$gStmt = $pdo->prepare("SELECT id, name FROM `groups` WHERE center_id = ? AND is_active = 1 ORDER BY name");
$gStmt->execute([$cid]);
$groups = $gStmt->fetchAll();

// Teacher: only their groups
if (!isRole('owner')) {
    $gStmt2 = $pdo->prepare("SELECT g.id, g.name FROM `groups` g WHERE g.center_id = ? AND g.teacher_id = ? AND g.is_active = 1 ORDER BY g.name");
    $gStmt2->execute([$cid, $uid]);
    $groups = $gStmt2->fetchAll();
}

$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $url         = trim($_POST['video_url'] ?? '');
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $groupId     = (int)($_POST['group_id'] ?? 0) ?: null;
    $durationMin = max(0, (int)($_POST['duration_min'] ?? 0));
    $isVisible   = isset($_POST['is_visible']) ? 1 : 0;
    $availFrom   = !empty($_POST['available_from']) ? date('Y-m-d H:i:s', strtotime($_POST['available_from'])) : null;
    $availUntil  = !empty($_POST['available_until']) ? date('Y-m-d H:i:s', strtotime($_POST['available_until'])) : null;

    $old = compact('url', 'title', 'description', 'groupId', 'durationMin', 'isVisible', 'availFrom', 'availUntil');

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

        // Academy plan: teacher owns the video; owner upload = global
        $ownerTeacherId = (isRole('teacher') && isAcademyPlan()) ? $uid : null;
        $isGlobal       = (isRole('owner') && isAcademyPlan()) ? 1 : 0;

        $pdo->prepare("INSERT INTO videos
            (center_id, group_id, title, description, video_provider, video_id,
             duration_min, thumbnail_url, is_visible, available_from, available_until,
             created_by, owner_teacher_id, is_global)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$cid, $groupId, $title, $description, $provider, $videoId,
                       $durationMin, $thumb, $isVisible, $availFrom, $availUntil,
                       $uid, $ownerTeacherId, $isGlobal]);

        $newId = $pdo->lastInsertId();
        auditLog('video.created', 'video', $newId, ['title' => $title, 'provider' => $provider]);
        flash('success', '"' . sanitize($title) . '" added successfully.');
        redirect('index.php');
    }
}

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Upload Video</h1>
    <p class="page-subtitle">Paste a YouTube or Vimeo URL to add a lecture</p>
  </div>
  <a href="index.php" class="btn btn-ghost">← Back to Videos</a>
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

    <!-- Video URL -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><span class="card-title">Video Source</span></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label required">YouTube or Vimeo URL</label>
          <input type="url" name="video_url" id="videoUrl" class="form-control"
                 value="<?= sanitize($old['url'] ?? '') ?>"
                 placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/..."
                 required autofocus>
          <span class="form-hint">The video will be embedded — students watch it inside EduCore.</span>
        </div>

        <!-- Live preview -->
        <div id="previewArea" style="display:none;margin-top:12px;">
          <div style="font-size:0.75rem;font-weight:600;color:var(--text-muted);margin-bottom:6px;">PREVIEW</div>
          <div style="position:relative;padding-bottom:56.25%;border-radius:var(--radius-md);overflow:hidden;background:#0f172a;">
            <iframe id="previewFrame" src="" allowfullscreen
                    style="position:absolute;inset:0;width:100%;height:100%;border:0;"></iframe>
          </div>
        </div>
      </div>
    </div>

    <!-- Details -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><span class="card-title">Details</span></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label required">Title</label>
          <input type="text" name="title" class="form-control"
                 value="<?= sanitize($old['title'] ?? '') ?>"
                 placeholder="e.g. Chapter 3 — Algebraic Expressions" required>
        </div>

        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="3"
                    placeholder="Brief description of what this lecture covers"><?= sanitize($old['description'] ?? '') ?></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group">
            <label class="form-label">Assign to Group</label>
            <select name="group_id" class="form-control">
              <option value="">Center-wide (all groups)</option>
              <?php foreach ($groups as $g): ?>
              <option value="<?= $g['id'] ?>" <?= ($old['groupId'] ?? '') == $g['id'] ? 'selected' : '' ?>>
                <?= sanitize($g['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Duration (minutes)</label>
            <input type="number" name="duration_min" class="form-control"
                   value="<?= (int)($old['durationMin'] ?? 0) ?>" min="0" max="9999"
                   placeholder="0">
          </div>
        </div>
      </div>
    </div>

    <!-- Visibility -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><span class="card-title">Visibility & Scheduling</span></div>
      <div class="card-body">
        <div class="form-group">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
            <input type="checkbox" name="is_visible" style="width:16px;height:16px;accent-color:var(--accent);"
                   <?= ($old['isVisible'] ?? 1) ? 'checked' : '' ?>>
            <span style="font-size:0.85rem;font-weight:500;">Visible to students</span>
          </label>
          <span class="form-hint" style="margin-top:4px;display:block;">Uncheck to hide while you set things up.</span>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Available From</label>
            <input type="datetime-local" name="available_from" class="form-control"
                   value="<?= $old['availFrom'] ? date('Y-m-d\TH:i', strtotime($old['availFrom'])) : '' ?>">
            <span class="form-hint">Leave blank = always available</span>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Available Until</label>
            <input type="datetime-local" name="available_until" class="form-control"
                   value="<?= $old['availUntil'] ? date('Y-m-d\TH:i', strtotime($old['availUntil'])) : '' ?>">
            <span class="form-hint">Leave blank = no expiry</span>
          </div>
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg w-full">
      Save Video →
    </button>
  </form>
</div>

<!-- Right sidebar tips -->
<div>
  <div class="card">
    <div class="card-header"><span class="card-title">Tips</span></div>
    <div class="card-body" style="font-size:0.83rem;">
      <?php foreach ([
        ['🎥', 'YouTube (Unlisted)', 'Upload to YouTube, set to Unlisted, paste the link. Free and works everywhere.'],
        ['🔒', 'Vimeo (Private)', 'Create a private Vimeo video with domain restriction. More secure, paid plan required.'],
        ['📅', 'Schedule release', 'Set "Available From" to a future date — students see a locked card until then.'],
        ['👁',  'Hidden videos', 'Uncheck Visible to draft a video without students seeing it.'],
      ] as [$icon, $title, $desc]): ?>
      <div style="display:flex;gap:12px;margin-bottom:14px;">
        <div style="font-size:1.4rem;line-height:1;"><?= $icon ?></div>
        <div>
          <div style="font-weight:600;color:var(--text-primary);margin-bottom:2px;"><?= $title ?></div>
          <div style="color:var(--text-muted);font-size:0.78rem;"><?= $desc ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
const urlInput    = document.getElementById('videoUrl');
const previewArea = document.getElementById('previewArea');
const previewFrame= document.getElementById('previewFrame');

function parseVideoUrl(url) {
  // YouTube
  let m = url.match(/youtu(?:be\.com\/(?:watch\?v=|shorts\/)|\.be\/)([A-Za-z0-9_-]{11})/);
  if (m) return { provider: 'youtube', id: m[1] };
  // Vimeo
  m = url.match(/vimeo\.com\/(?:(?:channels|groups)\/[^\/]+\/)?(?:videos?\/)?(\d+)/);
  if (m) return { provider: 'vimeo', id: m[1] };
  return null;
}

function buildEmbedUrl(provider, id) {
  if (provider === 'youtube') return `https://www.youtube.com/embed/${id}?rel=0&modestbranding=1`;
  if (provider === 'vimeo')   return `https://player.vimeo.com/video/${id}?dnt=1`;
  return '';
}

urlInput.addEventListener('blur', function () {
  const parsed = parseVideoUrl(this.value.trim());
  if (parsed) {
    previewFrame.src = buildEmbedUrl(parsed.provider, parsed.id);
    previewArea.style.display = 'block';
  } else {
    previewArea.style.display = 'none';
    previewFrame.src = '';
  }
});

// Restore preview if page reloaded with errors
(function () {
  const val = urlInput.value.trim();
  if (val) { urlInput.dispatchEvent(new Event('blur')); }
})();
</script>
JS;
include __DIR__ . '/../../templates/layout_end.php';
?>
