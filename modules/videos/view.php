<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireAuth();

$pdo = getDB();
$cid = centerId();
$uid = authId();

$id = (int)($_GET['id'] ?? 0);

// Fetch video with creator info and view count
$stmt = $pdo->prepare("
    SELECT v.*, u.name AS creator_name, g.name AS group_name,
           COUNT(DISTINCT vw.id) AS total_views
    FROM videos v
    LEFT JOIN users    u  ON u.id  = v.created_by
    LEFT JOIN `groups` g  ON g.id  = v.group_id
    LEFT JOIN video_views vw ON vw.video_id = v.id
    WHERE v.id = ? AND v.center_id = ?
    GROUP BY v.id");
$stmt->execute([$id, $cid]);
$video = $stmt->fetch();

if (!$video) { flash('error', 'Video not found.'); redirect('index.php'); }

// ── Access Control ───────────────────────────────────────────
if (isRole('student')) {
    // Load student record
    $stStmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ? AND center_id = ? AND is_active = 1");
    $stStmt->execute([$uid, $cid]);
    $student = $stStmt->fetch();
    if (!$student) { flash('error', 'Student profile not found.'); redirect('/educore/login.php'); }
    $studentId = $student['id'];

    // Check enrollment if video is group-specific
    if ($video['group_id']) {
        $enrStmt = $pdo->prepare("SELECT 1 FROM group_students WHERE group_id = ? AND student_id = ? AND status = 'active'");
        $enrStmt->execute([$video['group_id'], $studentId]);
        if (!$enrStmt->fetch()) {
            flash('error', 'You do not have access to this lecture.');
            redirect('/educore/modules/students/videos.php');
        }
    }

    // Enforce available_from / available_until
    $now = time();
    if ($video['available_from'] && strtotime($video['available_from']) > $now) {
        flash('error', 'This video is not yet available.');
        redirect('/educore/modules/students/videos.php');
    }
    if ($video['available_until'] && strtotime($video['available_until']) < $now) {
        flash('error', 'This video is no longer available.');
        redirect('/educore/modules/students/videos.php');
    }
    if (!$video['is_visible']) {
        flash('error', 'This video is not currently available.');
        redirect('/educore/modules/students/videos.php');
    }

    // Load existing progress
    $progStmt = $pdo->prepare("SELECT last_position, completed FROM video_views WHERE video_id = ? AND student_id = ?");
    $progStmt->execute([$id, $studentId]);
    $progress = $progStmt->fetch();
    $resumeAt = (int)($progress['last_position'] ?? 0);

} elseif (!isRole('owner', 'teacher', 'assistant', 'super_admin')) {
    http_response_code(403); exit;
} else {
    $studentId = null;
    $resumeAt  = 0;
    $progress  = null;
}

$embedUrl  = videoEmbedUrl($video['video_provider'], $video['video_id']);

$pageTitle  = sanitize($video['title']);
$activePage = 'videos';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
];
if (isRole('student')) {
    $breadcrumbs[] = ['label' => 'Lectures', 'href' => '/educore/modules/students/videos.php'];
} else {
    $breadcrumbs[] = ['label' => 'Videos', 'href' => '/educore/modules/videos/'];
}
$breadcrumbs[] = ['label' => sanitize($video['title'])];

include __DIR__ . '/../../templates/layout.php';

// Build embed URL with autoplay at resume position for students
$autoplay = '';
if ($resumeAt > 0) {
    $autoplay = $video['video_provider'] === 'youtube'
        ? "&start={$resumeAt}&autoplay=1"
        : "#t={$resumeAt}";
}
?>

<div style="max-width:960px;margin:0 auto;">

  <!-- Player -->
  <div style="position:relative;padding-bottom:56.25%;border-radius:var(--radius-lg);overflow:hidden;background:#0f172a;margin-bottom:24px;box-shadow:var(--shadow-lg);">
    <iframe id="videoPlayer"
            src="<?= sanitize($embedUrl . $autoplay) ?>"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen"
            allowfullscreen
            style="position:absolute;inset:0;width:100%;height:100%;border:0;"
            oncontextmenu="return false;"></iframe>
  </div>

  <div style="display:grid;grid-template-columns:1fr auto;gap:24px;align-items:start;">

    <!-- Left: info -->
    <div>
      <h1 style="font-size:1.35rem;font-weight:700;color:var(--text-primary);margin-bottom:10px;line-height:1.3;">
        <?= sanitize($video['title']) ?>
      </h1>

      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;align-items:center;">
        <?php if ($video['group_name']): ?>
          <span class="badge badge-info"><?= sanitize($video['group_name']) ?></span>
        <?php endif; ?>
        <?php if ($video['duration_min']): ?>
          <span class="badge badge-muted">⏱ <?= (int)$video['duration_min'] ?> min</span>
        <?php endif; ?>
        <span style="font-size:0.78rem;color:var(--text-muted);">
          By <?= sanitize($video['creator_name']) ?> · <?= date('M j, Y', strtotime($video['created_at'])) ?>
        </span>
        <?php if (!isRole('student') && hasFeature('video_analytics')): ?>
          <span style="font-size:0.78rem;color:var(--text-muted);">
            · 👁 <?= (int)$video['total_views'] ?> students watched
          </span>
        <?php endif; ?>
        <?php if ($progress && $progress['completed']): ?>
          <span class="badge badge-success">✓ Completed</span>
        <?php elseif ($resumeAt > 0): ?>
          <span class="badge badge-warning">▶ Resumed from <?= gmdate('G:i:s', $resumeAt) ?></span>
        <?php endif; ?>
      </div>

      <?php if ($video['description']): ?>
      <div style="color:var(--text-secondary);font-size:0.88rem;line-height:1.7;white-space:pre-line;">
        <?= nl2br(sanitize($video['description'])) ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Right: actions for teacher -->
    <?php if (!isRole('student') && (isRole('owner') || $video['created_by'] == $uid)): ?>
    <div style="display:flex;flex-direction:column;gap:8px;min-width:140px;">
      <a href="edit.php?id=<?= $id ?>" class="btn btn-ghost btn-sm">✏️ Edit</a>
      <?php if (hasFeature('video_analytics')): ?>
      <a href="analytics.php?id=<?= $id ?>" class="btn btn-ghost btn-sm">📊 Analytics</a>
      <?php endif; ?>
      <form method="POST" action="delete.php" onsubmit="return confirm('Delete this video?')">
        <?= csrf_field() ?>
        <input type="hidden" name="video_id" value="<?= $id ?>">
        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);width:100%;">🗑 Delete</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (isRole('student') && $studentId): ?>
<?php
$extraScripts = <<<JS
<script>
(function () {
    const VIDEO_ID    = {$id};
    const STUDENT_ID  = {$studentId};
    const SAVE_EVERY  = 30; // seconds between saves
    const csrfToken   = document.querySelector('meta[name="csrf-token"]')?.content;
    let lastSaved     = 0;
    let currentTime   = {$resumeAt};

    // We poll via a hidden timer since YouTube/Vimeo iframes restrict postMessage in most setups
    setInterval(function () {
        currentTime += SAVE_EVERY;
        saveProgress(currentTime);
    }, SAVE_EVERY * 1000);

    function saveProgress(seconds) {
        fetch('/educore/modules/videos/api_progress.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({ video_id: VIDEO_ID, position: seconds }),
        }).catch(() => {});
    }

    // Save on page unload
    window.addEventListener('beforeunload', function () {
        navigator.sendBeacon && navigator.sendBeacon(
            '/educore/modules/videos/api_progress.php',
            JSON.stringify({ video_id: VIDEO_ID, position: currentTime, _csrf: csrfToken })
        );
    });
})();
</script>
JS;
?>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
