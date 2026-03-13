<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireAuth();

$pdo = getDB();
$cid = centerId();
$uid = authId();

$id = (int)($_GET['id'] ?? 0);

$pStmt = $pdo->prepare("
    SELECT vp.*, u.name AS creator_name, g.name AS group_name
    FROM video_playlists vp
    LEFT JOIN users u    ON u.id  = vp.created_by
    LEFT JOIN `groups` g ON g.id  = vp.group_id
    WHERE vp.id = ? AND vp.center_id = ?");
$pStmt->execute([$id, $cid]);
$playlist = $pStmt->fetch();
if (!$playlist) { flash('error', 'Playlist not found.'); redirect('playlists.php'); }

// Students: check visibility + group enrollment
if (isRole('student')) {
    if (!$playlist['is_visible']) { flash('error', 'This playlist is not available.'); redirect('/educore/modules/students/videos.php'); }
    if ($playlist['group_id']) {
        $stStmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ? AND center_id = ? AND is_active = 1");
        $stStmt->execute([$uid, $cid]);
        $student = $stStmt->fetch();
        if (!$student) { redirect('/educore/login.php'); }
        $enrStmt = $pdo->prepare("SELECT 1 FROM group_students WHERE group_id = ? AND student_id = ? AND status = 'active'");
        $enrStmt->execute([$playlist['group_id'], $student['id']]);
        if (!$enrStmt->fetch()) { flash('error', 'You do not have access to this playlist.'); redirect('/educore/modules/students/videos.php'); }
    }
    $studentId = $student['id'] ?? null;
} else {
    $studentId = null;
}

// Fetch ordered videos with progress (if student)
$pvStmt = $pdo->prepare("
    SELECT v.*, pv.sort_order,
           g.name AS group_name,
           vw.last_position, vw.completed
    FROM playlist_videos pv
    JOIN videos v ON v.id = pv.video_id
    LEFT JOIN `groups` g ON g.id = v.group_id
    LEFT JOIN video_views vw ON vw.video_id = v.id AND vw.student_id = ?
    WHERE pv.playlist_id = ?
    ORDER BY pv.sort_order ASC");
$pvStmt->execute([$studentId, $id]);
$videos = $pvStmt->fetchAll();

// Active video: from URL or first incomplete, else first
$activeIdx = 0;
$activeVid = (int)($_GET['v'] ?? 0);
if ($activeVid) {
    foreach ($videos as $i => $v) { if ($v['id'] === $activeVid) { $activeIdx = $i; break; } }
} else {
    foreach ($videos as $i => $v) { if (!$v['completed']) { $activeIdx = $i; break; } }
}
$current   = $videos[$activeIdx] ?? null;
$nextVideo = $videos[$activeIdx + 1] ?? null;
$prevVideo = $videos[$activeIdx - 1] ?? null;

$completedCount = count(array_filter($videos, fn($v) => $v['completed']));
$progressPct    = count($videos) > 0 ? round($completedCount / count($videos) * 100) : 0;

$pageTitle  = sanitize($playlist['title']);
$activePage = 'videos';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Videos',    'href' => '/educore/modules/videos/'],
    ['label' => 'Playlists', 'href' => 'playlists.php'],
    ['label' => sanitize($playlist['title'])],
];

include __DIR__ . '/../../templates/layout.php';
?>

<div style="display:grid;grid-template-columns:1fr 320px;gap:24px;max-width:1200px;align-items:start;">

  <!-- Left: player + info -->
  <div>
    <?php if ($current): ?>
    <?php
      $resumeAt = (int)($current['last_position'] ?? 0);
      $autoplay = '';
      if ($resumeAt > 0) {
          $autoplay = $current['video_provider'] === 'youtube' ? "&start={$resumeAt}&autoplay=1" : "#t={$resumeAt}";
      }
      $embedUrl = videoEmbedUrl($current['video_provider'], $current['video_id']) . $autoplay;
    ?>
    <div style="position:relative;padding-bottom:56.25%;border-radius:var(--radius-lg);overflow:hidden;background:#0f172a;margin-bottom:20px;box-shadow:var(--shadow-lg);">
      <iframe id="videoPlayer" src="<?= sanitize($embedUrl) ?>"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen"
              allowfullscreen style="position:absolute;inset:0;width:100%;height:100%;border:0;"
              oncontextmenu="return false;"></iframe>
    </div>

    <!-- Video nav -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
      <?php if ($prevVideo): ?>
        <a href="?id=<?= $id ?>&v=<?= $prevVideo['id'] ?>" class="btn btn-ghost btn-sm">← Previous</a>
      <?php else: ?><span></span><?php endif; ?>
      <span style="font-size:0.78rem;color:var(--text-muted);"><?= $activeIdx + 1 ?> / <?= count($videos) ?></span>
      <?php if ($nextVideo): ?>
        <a href="?id=<?= $id ?>&v=<?= $nextVideo['id'] ?>" class="btn btn-primary btn-sm">Next →</a>
      <?php else: ?><span></span><?php endif; ?>
    </div>

    <h2 style="font-size:1.2rem;font-weight:700;color:var(--text-primary);margin-bottom:8px;"><?= sanitize($current['title']) ?></h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
      <?php if ($current['duration_min']): ?><span class="badge badge-muted">⏱ <?= (int)$current['duration_min'] ?> min</span><?php endif; ?>
      <?php if ($current['completed']): ?><span class="badge badge-success">✓ Completed</span>
      <?php elseif ($resumeAt > 0): ?><span class="badge badge-warning">▶ Resumed</span><?php endif; ?>
    </div>
    <?php if ($current['description']): ?>
    <p style="font-size:0.86rem;color:var(--text-secondary);line-height:1.7;margin-bottom:20px;"><?= nl2br(sanitize($current['description'])) ?></p>
    <?php endif; ?>

    <?php else: ?>
    <div class="empty-state"><div class="empty-icon">🎞️</div><div class="empty-title">No videos in this playlist</div></div>
    <?php endif; ?>

    <!-- PDF Attachments -->
    <?php
    $attStmt = $pdo->prepare("SELECT * FROM video_attachments WHERE video_id = ? AND center_id = ? ORDER BY created_at ASC");
    $attStmt->execute([$current['id'] ?? 0, $cid]);
    $attachments = $attStmt->fetchAll();
    if ($attachments):
    ?>
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><span class="card-title">📎 Notes & Attachments</span></div>
      <div class="card-body" style="padding:10px 16px;">
        <?php foreach ($attachments as $att): ?>
        <a href="/educore/modules/videos/download_attachment.php?id=<?= $att['id'] ?>"
           style="display:flex;align-items:center;gap:10px;padding:8px 0;text-decoration:none;color:var(--text-primary);border-bottom:1px solid var(--border);">
          <span style="font-size:1.3rem;">📄</span>
          <div style="flex:1;">
            <div style="font-size:0.85rem;font-weight:500;"><?= sanitize($att['original_name']) ?></div>
            <div style="font-size:0.72rem;color:var(--text-muted);"><?= round($att['file_size']/1024) ?> KB</div>
          </div>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Comments section -->
    <?php if ($current): ?>
    <div class="card">
      <div class="card-header"><span class="card-title">💬 Questions & Comments</span></div>
      <div class="card-body">
        <!-- Post comment form -->
        <div style="display:flex;gap:10px;margin-bottom:20px;">
          <div style="flex:1;">
            <textarea id="commentBody" class="form-control" rows="2"
                      placeholder="Ask a question or leave a comment…" style="resize:vertical;font-size:0.85rem;"></textarea>
            <div style="display:flex;align-items:center;gap:10px;margin-top:8px;">
              <input type="number" id="commentTs" placeholder="Timestamp (s)" min="0"
                     style="width:140px;font-size:0.8rem;padding:5px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-input);color:var(--text-primary);">
              <span style="font-size:0.72rem;color:var(--text-muted);">Optional: link to a video moment</span>
              <button onclick="postComment()" class="btn btn-primary btn-sm" style="margin-left:auto;">Post</button>
            </div>
          </div>
        </div>

        <!-- Comments list -->
        <div id="commentsList" style="display:flex;flex-direction:column;gap:16px;">
          <div style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:20px 0;" id="commentsLoading">Loading comments…</div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Right: playlist sidebar -->
  <div>
    <div class="card" style="position:sticky;top:20px;">
      <div class="card-header">
        <div>
          <div class="card-title"><?= sanitize($playlist['title']) ?></div>
          <?php if ($playlist['group_name']): ?>
          <div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px;"><?= sanitize($playlist['group_name']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Progress bar -->
      <?php if ($studentId): ?>
      <div style="padding:10px 16px;border-bottom:1px solid var(--border);">
        <div style="display:flex;justify-content:space-between;font-size:0.72rem;color:var(--text-muted);margin-bottom:5px;">
          <span><?= $completedCount ?> / <?= count($videos) ?> completed</span>
          <span><?= $progressPct ?>%</span>
        </div>
        <div style="height:5px;background:var(--bg-subtle);border-radius:99px;overflow:hidden;">
          <div style="height:100%;width:<?= $progressPct ?>%;background:var(--accent);border-radius:99px;transition:width 0.4s;"></div>
        </div>
      </div>
      <?php endif; ?>

      <div style="max-height:calc(100vh - 200px);overflow-y:auto;">
        <?php foreach ($videos as $i => $v):
          $isActive   = ($i === $activeIdx);
          $vThumb     = $v['thumbnail_url'] ?: ($v['video_provider'] === 'youtube' ? "https://img.youtube.com/vi/{$v['video_id']}/default.jpg" : '');
          $vCompleted = !empty($v['completed']);
          $vResumed   = !$vCompleted && $v['last_position'] > 0;
        ?>
        <a href="?id=<?= $id ?>&v=<?= $v['id'] ?>"
           style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);text-decoration:none;background:<?= $isActive ? 'var(--accent-subtle)' : 'transparent' ?>;transition:background 0.15s;"
           onmouseenter="this.style.background='<?= $isActive ? 'var(--accent-subtle)' : 'var(--bg-hover)' ?>'"
           onmouseleave="this.style.background='<?= $isActive ? 'var(--accent-subtle)' : 'transparent' ?>'">
          <span style="font-size:0.72rem;color:var(--text-muted);width:16px;text-align:center;flex-shrink:0;"><?= $i + 1 ?></span>
          <?php if ($vThumb): ?>
            <img src="<?= sanitize($vThumb) ?>" style="width:48px;height:27px;object-fit:cover;border-radius:3px;flex-shrink:0;">
          <?php else: ?>
            <div style="width:48px;height:27px;background:var(--bg-subtle);border-radius:3px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:0.8rem;">🎬</div>
          <?php endif; ?>
          <div style="flex:1;min-width:0;">
            <div style="font-size:0.8rem;font-weight:<?= $isActive ? '700' : '500' ?>;color:<?= $isActive ? 'var(--accent)' : 'var(--text-primary)' ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($v['title']) ?></div>
            <?php if ($v['duration_min']): ?><div style="font-size:0.68rem;color:var(--text-muted);"><?= (int)$v['duration_min'] ?> min</div><?php endif; ?>
          </div>
          <?php if ($vCompleted): ?>
            <span style="color:#22c55e;font-size:0.9rem;flex-shrink:0;">✓</span>
          <?php elseif ($vResumed): ?>
            <span style="color:var(--accent);font-size:0.65rem;flex-shrink:0;">▶</span>
          <?php elseif ($isActive): ?>
            <span style="color:var(--accent);font-size:0.8rem;flex-shrink:0;">▶</span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>

      <?php if (!isRole('student') && (isRole('owner') || $playlist['created_by'] == $uid)): ?>
      <div style="padding:10px 14px;border-top:1px solid var(--border);">
        <a href="playlist_edit.php?id=<?= $id ?>" class="btn btn-ghost btn-sm w-full" style="text-align:center;">✏️ Edit Playlist</a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$videoIdJs = (int)($current['id'] ?? 0);
$studentIdJs = (int)($studentId ?? 0);
$resumeAtJs  = (int)($current['last_position'] ?? 0);
$nextUrl = $nextVideo ? "?id={$id}&v={$nextVideo['id']}" : '';
$extraScripts = <<<JS
<script>
// ── Progress saver ───────────────────────────────────────────
(function () {
  if (!{$studentIdJs}) return;
  const VIDEO_ID   = {$videoIdJs};
  const SAVE_EVERY = 30;
  const csrf       = document.querySelector('meta[name="csrf-token"]')?.content;
  let cur          = {$resumeAtJs};

  setInterval(() => {
    cur += SAVE_EVERY;
    fetch('/educore/modules/videos/api_progress.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ video_id: VIDEO_ID, position: cur }),
    }).catch(() => {});
  }, SAVE_EVERY * 1000);

  window.addEventListener('beforeunload', () => {
    navigator.sendBeacon && navigator.sendBeacon(
      '/educore/modules/videos/api_progress.php',
      JSON.stringify({ video_id: VIDEO_ID, position: cur, _csrf: csrf })
    );
  });
})();

// ── Comments ─────────────────────────────────────────────────
const VIDEO_ID   = {$videoIdJs};
const csrf       = document.querySelector('meta[name="csrf-token"]')?.content;

async function loadComments() {
  const res  = await fetch('/educore/modules/videos/api_comments.php?video_id=' + VIDEO_ID);
  const data = await res.json();
  if (!data.ok) return;

  const list = document.getElementById('commentsList');
  if (data.comments.length === 0) {
    list.innerHTML = '<div style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:20px 0;">No comments yet. Be the first!</div>';
    return;
  }
  list.innerHTML = '';
  data.comments.forEach(c => list.appendChild(buildComment(c)));
}

function buildComment(c, isReply = false) {
  const wrap = document.createElement('div');
  wrap.dataset.id = c.id;
  wrap.style.cssText = isReply
    ? 'padding:10px 12px;background:var(--bg-subtle);border-radius:var(--radius-md);margin-top:8px;'
    : 'border-bottom:1px solid var(--border);padding-bottom:14px;';

  const tsHtml = c.time_label ? `<a href="#" onclick="seekTo(${c.timestamp_sec});return false;"
    style="font-size:0.7rem;font-weight:700;background:var(--accent-subtle);color:var(--accent);padding:1px 7px;border-radius:20px;text-decoration:none;margin-left:6px;">▶ ${c.time_label}</a>` : '';

  const teacherBadge = c.is_teacher
    ? '<span style="font-size:0.62rem;font-weight:700;background:#3b82f620;color:#3b82f6;padding:1px 6px;border-radius:20px;margin-left:4px;">Teacher</span>' : '';

  const deleteBtn = c.can_delete
    ? `<button onclick="deleteComment(${c.id})" style="background:none;border:none;cursor:pointer;color:var(--danger);font-size:0.72rem;padding:0 4px;" title="Delete">✕</button>` : '';

  const replyBtn = !isReply
    ? `<button onclick="toggleReply(${c.id})" style="background:none;border:none;cursor:pointer;color:var(--accent);font-size:0.72rem;padding:0;">Reply</button>` : '';

  wrap.innerHTML = `
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:5px;flex-wrap:wrap;">
      <span style="font-size:0.8rem;font-weight:700;color:var(--text-primary);">${c.author_name}</span>
      ${teacherBadge}
      ${tsHtml}
      <span style="font-size:0.68rem;color:var(--text-muted);margin-left:auto;">${formatDate(c.created_at)}</span>
      ${deleteBtn}
    </div>
    <div style="font-size:0.85rem;color:var(--text-secondary);line-height:1.6;margin-bottom:6px;">${escHtml(c.body)}</div>
    <div style="display:flex;gap:8px;align-items:center;">${replyBtn}</div>
    <div class="reply-form" id="replyForm${c.id}" style="display:none;margin-top:8px;">
      <textarea class="form-control" rows="2" style="font-size:0.82rem;resize:none;" placeholder="Write a reply…"></textarea>
      <div style="display:flex;gap:6px;margin-top:6px;justify-content:flex-end;">
        <button onclick="toggleReply(${c.id})" class="btn btn-ghost btn-sm">Cancel</button>
        <button onclick="submitReply(${c.id})" class="btn btn-primary btn-sm">Reply</button>
      </div>
    </div>
    <div class="replies-list" id="replies${c.id}"></div>
  `;

  if (!isReply && c.replies) {
    const repliesEl = wrap.querySelector('.replies-list');
    c.replies.forEach(r => repliesEl.appendChild(buildComment(r, true)));
  }

  return wrap;
}

async function postComment() {
  const body = document.getElementById('commentBody').value.trim();
  const ts   = document.getElementById('commentTs').value;
  if (!body) return;

  const res  = await fetch('/educore/modules/videos/api_comments.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
    body: JSON.stringify({ action: 'post', video_id: VIDEO_ID, body, timestamp_sec: ts || null }),
  });
  const data = await res.json();
  if (data.ok) {
    document.getElementById('commentBody').value = '';
    document.getElementById('commentTs').value = '';
    const list = document.getElementById('commentsList');
    const placeholder = list.querySelector('div[style*="No comments"]') || list.querySelector('div[style*="Loading"]');
    if (placeholder) placeholder.remove();
    list.prepend(buildComment(data));
  }
}

function toggleReply(id) {
  const f = document.getElementById('replyForm' + id);
  f.style.display = f.style.display === 'none' ? 'block' : 'none';
  if (f.style.display === 'block') f.querySelector('textarea').focus();
}

async function submitReply(parentId) {
  const form = document.getElementById('replyForm' + parentId);
  const body = form.querySelector('textarea').value.trim();
  if (!body) return;

  const res  = await fetch('/educore/modules/videos/api_comments.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
    body: JSON.stringify({ action: 'reply', parent_id: parentId, body }),
  });
  const data = await res.json();
  if (data.ok) {
    form.style.display = 'none';
    form.querySelector('textarea').value = '';
    const repliesEl = document.getElementById('replies' + parentId);
    repliesEl.appendChild(buildComment(data, true));
  }
}

async function deleteComment(id) {
  if (!confirm('Delete this comment?')) return;
  const res  = await fetch('/educore/modules/videos/api_comments.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
    body: JSON.stringify({ action: 'delete', comment_id: id }),
  });
  const data = await res.json();
  if (data.ok) {
    const el = document.querySelector(`[data-id="${id}"]`);
    if (el) el.remove();
  }
}

function seekTo(sec) {
  // Reload iframe with timestamp (YouTube: &start=N, Vimeo: #t=N)
  const frame = document.getElementById('videoPlayer');
  if (!frame) return;
  const src = frame.src;
  // Strip existing start params
  const base = src.replace(/[&?]start=\d+/, '').replace(/#t=\d+/, '');
  frame.src = base.includes('youtube') ? base + '&start=' + sec + '&autoplay=1' : base + '#t=' + sec;
}

function formatDate(dt) {
  const d = new Date(dt.replace(' ', 'T'));
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}

loadComments();
</script>
JS;
include __DIR__ . '/../../templates/layout_end.php';
?>
