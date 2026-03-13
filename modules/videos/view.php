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

<?php
$attStmt = $pdo->prepare("SELECT * FROM video_attachments WHERE video_id = ? AND center_id = ? ORDER BY created_at ASC");
$attStmt->execute([$id, $cid]);
$attachments = $attStmt->fetchAll();
$canManageAtts = !isRole('student') && (isRole('owner') || $video['created_by'] == $uid);
?>
<div style="max-width:960px;margin:20px auto 0;">
  <?php if ($attachments || $canManageAtts): ?>
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header">
      <span class="card-title">📎 Notes &amp; Attachments</span>
      <?php if ($canManageAtts): ?>
      <label class="btn btn-ghost btn-sm" style="cursor:pointer;margin-bottom:0;">
        + Add PDF
        <input type="file" id="pdfUploadInput" accept=".pdf" style="display:none;" onchange="uploadPdf(this)">
      </label>
      <?php endif; ?>
    </div>
    <div class="card-body" style="padding:<?= $attachments ? '4px 16px' : '16px' ?>;">
      <div id="attachmentsList">
        <?php if ($attachments): ?>
          <?php foreach ($attachments as $att): ?>
          <div class="att-row" data-id="<?= $att['id'] ?>" style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--border);">
            <span style="font-size:1.2rem;">📄</span>
            <div style="flex:1;">
              <div style="font-size:0.85rem;font-weight:500;color:var(--text-primary);"><?= sanitize($att['original_name']) ?></div>
              <div style="font-size:0.72rem;color:var(--text-muted);"><?= round($att['file_size']/1024) ?> KB</div>
            </div>
            <a href="/educore/modules/videos/download_attachment.php?id=<?= $att['id'] ?>" class="btn btn-ghost btn-sm">Download</a>
            <?php if ($canManageAtts): ?>
            <button onclick="deleteAttachment(<?= $att['id'] ?>, this)" class="btn btn-ghost btn-icon" style="color:var(--danger);" title="Remove">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>
            </button>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p id="noAttMsg" style="color:var(--text-muted);font-size:0.83rem;margin:0;">No attachments yet. Upload a PDF to share notes with students.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Comments & Q&A -->
  <div class="card">
    <div class="card-header"><span class="card-title">💬 Questions &amp; Comments</span></div>
    <div class="card-body">
      <div style="margin-bottom:20px;">
        <textarea id="commentBody" class="form-control" rows="2"
                  placeholder="Ask a question or share a thought…" style="resize:vertical;font-size:0.85rem;"></textarea>
        <div style="display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap;">
          <input type="number" id="commentTs" placeholder="Timestamp (seconds)" min="0"
                 style="width:180px;font-size:0.8rem;padding:5px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-input);color:var(--text-primary);">
          <span style="font-size:0.72rem;color:var(--text-muted);">Optional: link comment to a video moment</span>
          <button onclick="postComment()" class="btn btn-primary btn-sm" style="margin-left:auto;">Post</button>
        </div>
      </div>
      <div id="commentsList">
        <div style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:16px 0;">Loading comments…</div>
      </div>
    </div>
  </div>
</div>

<?php if (isRole('student') && $studentId): ?>
<?php
$extraScripts = <<<JS
<script>
// ── PHP vars injected safely ──────────────────────────────────
const VIDEO_ID   = {$id};
const RESUME_AT  = {$resumeAt};
const STUDENT_ID = {$studentId};
const csrf       = document.querySelector('meta[name="csrf-token"]')?.content;
</script>
JS;
$extraScripts .= <<<'JS'
<script>
// ── Progress saver ───────────────────────────────────────────
(function () {
    const SAVE_EVERY = 30;
    let currentTime  = RESUME_AT;

    setInterval(function () {
        currentTime += SAVE_EVERY;
        fetch('/educore/modules/videos/api_progress.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ video_id: VIDEO_ID, position: currentTime }),
        }).catch(() => {});
    }, SAVE_EVERY * 1000);

    window.addEventListener('beforeunload', function () {
        navigator.sendBeacon && navigator.sendBeacon(
            '/educore/modules/videos/api_progress.php',
            JSON.stringify({ video_id: VIDEO_ID, position: currentTime, _csrf: csrf })
        );
    });
})();

// ── Comments ─────────────────────────────────────────────────
async function loadComments() {
    const res  = await fetch('/educore/modules/videos/api_comments.php?video_id=' + VIDEO_ID);
    const data = await res.json();
    const list = document.getElementById('commentsList');
    if (!data.ok) { list.innerHTML = '<div style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:16px 0;">Could not load comments.</div>'; return; }
    if (!data.comments.length) {
        list.innerHTML = '<div style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:16px 0;">No comments yet. Be the first!</div>';
        return;
    }
    list.innerHTML = '';
    data.comments.forEach(c => list.appendChild(buildComment(c)));
}

function buildComment(c, isReply) {
    isReply = isReply || false;
    const wrap = document.createElement('div');
    wrap.dataset.id = c.id;
    wrap.style.cssText = isReply
        ? 'padding:10px 12px;background:var(--bg-subtle);border-radius:var(--radius-md);margin-top:8px;'
        : 'border-bottom:1px solid var(--border);padding-bottom:14px;';

    const tsHtml = c.time_label
        ? '<a href="#" onclick="seekTo(' + c.timestamp_sec + ');return false;" style="font-size:0.7rem;font-weight:700;background:var(--accent-subtle);color:var(--accent);padding:1px 7px;border-radius:20px;text-decoration:none;margin-left:6px;">▶ ' + c.time_label + '</a>'
        : '';
    const teacherBadge = c.is_teacher
        ? '<span style="font-size:0.62rem;font-weight:700;background:#3b82f620;color:#3b82f6;padding:1px 6px;border-radius:20px;margin-left:4px;">Teacher</span>'
        : '';
    const deleteBtn = c.can_delete
        ? '<button onclick="deleteComment(' + c.id + ')" style="background:none;border:none;cursor:pointer;color:var(--danger);font-size:0.72rem;padding:0 4px;">✕</button>'
        : '';
    const replyBtn = !isReply
        ? '<button onclick="toggleReply(' + c.id + ')" style="background:none;border:none;cursor:pointer;color:var(--accent);font-size:0.72rem;">Reply</button>'
        : '';

    wrap.innerHTML =
        '<div style="display:flex;align-items:center;gap:6px;margin-bottom:5px;flex-wrap:wrap;">' +
            '<span style="font-size:0.8rem;font-weight:700;color:var(--text-primary);">' + escHtml(c.author_name) + '</span>' +
            teacherBadge + tsHtml +
            '<span style="font-size:0.68rem;color:var(--text-muted);margin-left:auto;">' + formatDate(c.created_at) + '</span>' +
            deleteBtn +
        '</div>' +
        '<div style="font-size:0.85rem;color:var(--text-secondary);line-height:1.6;margin-bottom:6px;">' + escHtml(c.body) + '</div>' +
        '<div>' + replyBtn + '</div>' +
        '<div id="replyForm' + c.id + '" style="display:none;margin-top:8px;">' +
            '<textarea class="form-control" rows="2" style="font-size:0.82rem;resize:none;" placeholder="Write a reply…"></textarea>' +
            '<div style="display:flex;gap:6px;margin-top:6px;justify-content:flex-end;">' +
                '<button onclick="toggleReply(' + c.id + ')" class="btn btn-ghost btn-sm">Cancel</button>' +
                '<button onclick="submitReply(' + c.id + ')" class="btn btn-primary btn-sm">Reply</button>' +
            '</div>' +
        '</div>' +
        '<div id="replies' + c.id + '"></div>';

    if (!isReply && c.replies) {
        c.replies.forEach(function(r) {
            wrap.querySelector('#replies' + c.id).appendChild(buildComment(r, true));
        });
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
        body: JSON.stringify({ action: 'post', video_id: VIDEO_ID, body: body, timestamp_sec: ts || null }),
    });
    const data = await res.json();
    if (data.ok) {
        document.getElementById('commentBody').value = '';
        document.getElementById('commentTs').value   = '';
        const list = document.getElementById('commentsList');
        const ph   = list.querySelector('[data-placeholder]');
        if (ph) ph.remove();
        list.prepend(buildComment(data));
    } else {
        alert(data.error || 'Could not post comment.');
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
        body: JSON.stringify({ action: 'reply', parent_id: parentId, body: body }),
    });
    const data = await res.json();
    if (data.ok) {
        form.style.display = 'none';
        form.querySelector('textarea').value = '';
        document.getElementById('replies' + parentId).appendChild(buildComment(data, true));
    } else {
        alert(data.error || 'Could not post reply.');
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
    if (data.ok) { const el = document.querySelector('[data-id="' + id + '"]'); if (el) el.remove(); }
}

function seekTo(sec) {
    const frame = document.getElementById('videoPlayer');
    if (!frame) return;
    const base = frame.src.replace(/[&?]start=\d+/, '').replace(/#t=\d+/, '');
    frame.src  = base.includes('youtube') ? base + '&start=' + sec + '&autoplay=1' : base + '#t=' + sec;
}

function formatDate(dt) {
    const d = new Date(dt.replace(' ', 'T'));
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}

loadComments();
</script>
JS;
?>
<?php
$extraScripts = <<<JS
<script>
const csrf     = document.querySelector('meta[name="csrf-token"]')?.content;
const VIDEO_ID = {$id};
</script>
JS;
$extraScripts .= <<<'JSRAW'
<script>
// ── PDF attachment upload ─────────────────────────────────────
async function uploadPdf(input) {
    const file = input.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('video_id', VIDEO_ID);
    fd.append('pdf', file);
    fd.append('_csrf', csrf);

    const btn  = input.closest('label');
    const orig = btn.textContent;
    btn.textContent = 'Uploading…';

    const res  = await fetch('/educore/modules/videos/api_attachments.php', {
        method: 'POST', headers: { 'X-CSRF-TOKEN': csrf }, body: fd,
    });
    const data = await res.json();
    btn.textContent = orig;
    input.value = '';

    if (data.ok) {
        const noMsg = document.getElementById('noAttMsg');
        if (noMsg) noMsg.remove();
        const row = document.createElement('div');
        row.className = 'att-row';
        row.dataset.id = data.id;
        row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--border);';
        row.innerHTML =
            '<span style="font-size:1.2rem;">📄</span>' +
            '<div style="flex:1;">' +
                '<div style="font-size:0.85rem;font-weight:500;color:var(--text-primary);">' + escHtml(data.original_name) + '</div>' +
                '<div style="font-size:0.72rem;color:var(--text-muted);">' + Math.round(data.file_size / 1024) + ' KB</div>' +
            '</div>' +
            '<a href="' + data.download_url + '" class="btn btn-ghost btn-sm">Download</a>' +
            '<button onclick="deleteAttachment(' + data.id + ',this)" class="btn btn-ghost btn-icon" style="color:var(--danger);" title="Remove">' +
                '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>' +
            '</button>';
        document.getElementById('attachmentsList').appendChild(row);
    } else {
        alert(data.error || 'Upload failed');
    }
}

async function deleteAttachment(id, btn) {
    if (!confirm('Remove this attachment?')) return;
    const res  = await fetch('/educore/modules/videos/api_attachments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ action: 'delete', attachment_id: id }),
    });
    const data = await res.json();
    if (data.ok) btn.closest('.att-row').remove();
}

// ── Comments (shared by all roles) ───────────────────────────
async function loadComments() {
    const res  = await fetch('/educore/modules/videos/api_comments.php?video_id=' + VIDEO_ID);
    const data = await res.json();
    const list = document.getElementById('commentsList');
    if (!data.ok) {
        list.innerHTML = '<div style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:16px 0;">Could not load comments.</div>';
        return;
    }
    if (!data.comments.length) {
        list.innerHTML = '<div style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:16px 0;">No comments yet.</div>';
        return;
    }
    list.innerHTML = '';
    data.comments.forEach(function(c) { list.appendChild(buildComment(c, false)); });
}

function buildComment(c, isReply) {
    isReply = isReply || false;
    const wrap = document.createElement('div');
    wrap.dataset.id = c.id;
    wrap.style.cssText = isReply
        ? 'padding:10px 12px;background:var(--bg-subtle);border-radius:var(--radius-md);margin-top:8px;'
        : 'border-bottom:1px solid var(--border);padding-bottom:14px;';

    const tsHtml = c.time_label
        ? '<a href="#" onclick="seekTo(' + c.timestamp_sec + ');return false;" style="font-size:0.7rem;font-weight:700;background:var(--accent-subtle);color:var(--accent);padding:1px 7px;border-radius:20px;text-decoration:none;margin-left:6px;">▶ ' + c.time_label + '</a>'
        : '';
    const teacherBadge = c.is_teacher
        ? '<span style="font-size:0.62rem;font-weight:700;background:#3b82f620;color:#3b82f6;padding:1px 6px;border-radius:20px;margin-left:4px;">Teacher</span>'
        : '';
    const deleteBtn = c.can_delete
        ? '<button onclick="deleteComment(' + c.id + ')" style="background:none;border:none;cursor:pointer;color:var(--danger);font-size:0.72rem;padding:0 4px;">✕</button>'
        : '';
    const replyBtn = !isReply
        ? '<button onclick="toggleReply(' + c.id + ')" style="background:none;border:none;cursor:pointer;color:var(--accent);font-size:0.72rem;">Reply</button>'
        : '';

    wrap.innerHTML =
        '<div style="display:flex;align-items:center;gap:6px;margin-bottom:5px;flex-wrap:wrap;">' +
            '<span style="font-size:0.8rem;font-weight:700;color:var(--text-primary);">' + escHtml(c.author_name) + '</span>' +
            teacherBadge + tsHtml +
            '<span style="font-size:0.68rem;color:var(--text-muted);margin-left:auto;">' + formatDate(c.created_at) + '</span>' +
            deleteBtn +
        '</div>' +
        '<div style="font-size:0.85rem;color:var(--text-secondary);line-height:1.6;margin-bottom:6px;">' + escHtml(c.body) + '</div>' +
        '<div>' + replyBtn + '</div>' +
        '<div id="replyForm' + c.id + '" style="display:none;margin-top:8px;">' +
            '<textarea class="form-control" rows="2" style="font-size:0.82rem;resize:none;" placeholder="Write a reply…"></textarea>' +
            '<div style="display:flex;gap:6px;margin-top:6px;justify-content:flex-end;">' +
                '<button onclick="toggleReply(' + c.id + ')" class="btn btn-ghost btn-sm">Cancel</button>' +
                '<button onclick="submitReply(' + c.id + ')" class="btn btn-primary btn-sm">Reply</button>' +
            '</div>' +
        '</div>' +
        '<div id="replies' + c.id + '"></div>';

    if (!isReply && c.replies) {
        c.replies.forEach(function(r) {
            wrap.querySelector('#replies' + c.id).appendChild(buildComment(r, true));
        });
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
        body: JSON.stringify({ action: 'post', video_id: VIDEO_ID, body: body, timestamp_sec: ts || null }),
    });
    const data = await res.json();
    if (data.ok) {
        document.getElementById('commentBody').value = '';
        document.getElementById('commentTs').value   = '';
        const list = document.getElementById('commentsList');
        const ph   = list.querySelector('[data-empty]');
        if (ph) ph.remove();
        list.prepend(buildComment(data, false));
    } else {
        alert(data.error || 'Could not post comment.');
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
        body: JSON.stringify({ action: 'reply', parent_id: parentId, body: body }),
    });
    const data = await res.json();
    if (data.ok) {
        form.style.display = 'none';
        form.querySelector('textarea').value = '';
        document.getElementById('replies' + parentId).appendChild(buildComment(data, true));
    } else {
        alert(data.error || 'Could not post reply.');
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
    if (data.ok) { const el = document.querySelector('[data-id="' + id + '"]'); if (el) el.remove(); }
}

function seekTo(sec) {
    const frame = document.getElementById('videoPlayer');
    if (!frame) return;
    const base = frame.src.replace(/[&?]start=\d+/, '').replace(/#t=\d+/, '');
    frame.src  = base.includes('youtube') ? base + '&start=' + sec + '&autoplay=1' : base + '#t=' + sec;
}

function formatDate(dt) {
    const d = new Date(dt.replace(' ', 'T'));
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}
function escHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
}

loadComments();
</script>
JSRAW;
?>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
