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
    $stStmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ? AND center_id = ? AND is_active = 1");
    $stStmt->execute([$uid, $cid]);
    $student = $stStmt->fetch();
    if (!$student) { flash('error', 'Student profile not found.'); redirect('/educore/login.php'); }
    $studentId = $student['id'];

    if ($video['group_id']) {
        $enrStmt = $pdo->prepare("SELECT 1 FROM group_students WHERE group_id = ? AND student_id = ? AND status = 'active'");
        $enrStmt->execute([$video['group_id'], $studentId]);
        if (!$enrStmt->fetch()) {
            flash('error', 'You do not have access to this lecture.');
            redirect('/educore/modules/students/videos.php');
        }
    }

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

    // ── FIX 1: Record initial view immediately on page load ──
    // This ensures teacher analytics shows the student as having watched
    // even if they close the page before the 30-second interval fires.
    $pdo->prepare("
        INSERT INTO video_views (video_id, student_id, center_id, watch_seconds, last_position, completed, first_watched)
        VALUES (?, ?, ?, 0, ?, 0, NOW())
        ON DUPLICATE KEY UPDATE
            last_watched = NOW()
    ")->execute([$id, $studentId, $cid, $resumeAt]);

} elseif (!isRole('owner', 'teacher', 'assistant', 'super_admin')) {
    http_response_code(403); exit;
} else {
    $studentId = null;
    $resumeAt  = 0;
    $progress  = null;
}

$embedUrl = videoEmbedUrl($video['video_provider'], $video['video_id']);

$pageTitle  = sanitize($video['title']);
$activePage = 'videos';
$breadcrumbs = [['label' => 'Dashboard', 'href' => '/educore/dashboard.php']];
if (isRole('student')) {
    $breadcrumbs[] = ['label' => 'Lectures', 'href' => '/educore/modules/students/videos.php'];
} else {
    $breadcrumbs[] = ['label' => 'Videos', 'href' => '/educore/modules/videos/'];
}
$breadcrumbs[] = ['label' => sanitize($video['title'])];

include __DIR__ . '/../../templates/layout.php';

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

    <!-- Right: actions for teacher/owner -->
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

  <!-- Attachments / Notes -->
  <?php if ($attachments || $canManageAtts): ?>
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header">
      <span class="card-title">📎 Notes &amp; Attachments</span>
      <?php if ($canManageAtts): ?>
      <label class="btn btn-ghost btn-sm" style="cursor:pointer;margin-bottom:0;" id="uploadPdfLabel">
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
            <a href="/educore/modules/videos/download_attachment.php?id=<?= $att['id'] ?>" class="btn btn-ghost btn-sm">⬇ Download</a>
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
      <!-- FIX 3: Upload progress feedback -->
      <div id="uploadProgress" style="display:none;margin-top:8px;font-size:0.8rem;color:var(--accent);">⏳ Uploading PDF…</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Comments & Q&A — visible to ALL roles -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">💬 Questions &amp; Comments</span>
      <button onclick="loadComments()" class="btn btn-ghost btn-sm" title="Refresh comments">↻ Refresh</button>
    </div>
    <div class="card-body">
      <!-- Post new comment - available to all roles -->
      <div style="margin-bottom:20px;">
        <textarea id="commentBody" class="form-control" rows="2"
                  placeholder="<?= isRole('student') ? 'Ask a question or share a thought…' : 'Answer a question or leave feedback for students…' ?>"
                  style="resize:vertical;font-size:0.85rem;"></textarea>
        <div style="display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap;">
          <input type="number" id="commentTs" placeholder="Timestamp (seconds)" min="0"
                 style="width:180px;font-size:0.8rem;padding:5px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-input);color:var(--text-primary);">
          <span style="font-size:0.72rem;color:var(--text-muted);">Optional: link to video moment</span>
          <button onclick="postComment()" class="btn btn-primary btn-sm" style="margin-left:auto;">Post</button>
        </div>
      </div>
      <div id="commentsList">
        <div style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:16px 0;">Loading comments…</div>
      </div>
    </div>
  </div>
</div>

<?php
// ── FIX 2 & 3: UNIFIED extraScripts block for ALL roles ──────
// Previously the teacher block used $extraScripts = (overwrites) instead of .= (append)
// and the student block was inside an if(student) condition — meaning teachers never
// got loadComments() called and their PDF upload was broken when viewing as teacher.
// Now: one clean block handles both roles.

$isStudentRole = (isRole('student') && $studentId) ? 'true' : 'false';
$studentIdJs   = (int)($studentId ?? 0);
$resumeAtJs    = (int)$resumeAt;
$idJs          = (int)$id;
$csrfJs        = "document.querySelector('meta[name=\"csrf-token\"]')?.content ?? ''";

$extraScripts = <<<JS
<script>
// Injected PHP vars
const VIDEO_ID   = {$idJs};
const RESUME_AT  = {$resumeAtJs};
const STUDENT_ID = {$studentIdJs};
const IS_STUDENT = {$isStudentRole};
const csrf       = {$csrfJs};
</script>
JS;

$extraScripts .= <<<'JSRAW'
<script>
// ── Progress tracking (student only) ─────────────────────────
if (IS_STUDENT) {
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
                JSON.stringify({ video_id: VIDEO_ID, position: currentTime })
            );
        });
    })();
}

// ── Comments — shared by all roles ───────────────────────────
async function loadComments() {
    const list = document.getElementById('commentsList');
    list.innerHTML = '<div style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:16px 0;">Loading…</div>';
    try {
        const res  = await fetch('/educore/modules/videos/api_comments.php?video_id=' + VIDEO_ID);
        const data = await res.json();
        if (!data.ok) {
            list.innerHTML = '<div style="text-align:center;color:var(--danger);font-size:0.82rem;padding:16px 0;">Could not load comments.</div>';
            return;
        }
        if (!data.comments.length) {
            list.innerHTML = '<div data-empty="1" style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:16px 0;">No comments yet. Be the first!</div>';
            return;
        }
        list.innerHTML = '';
        data.comments.forEach(c => list.appendChild(buildComment(c, false)));
    } catch(e) {
        list.innerHTML = '<div style="text-align:center;color:var(--danger);font-size:0.82rem;padding:16px 0;">Error loading comments.</div>';
    }
}

function buildComment(c, isReply) {
    isReply = isReply || false;
    const wrap = document.createElement('div');
    wrap.dataset.id = c.id;
    wrap.style.cssText = isReply
        ? 'padding:10px 12px;background:var(--bg-elevated);border-radius:var(--radius-md);margin-top:8px;border-left:3px solid var(--accent);'
        : 'border-bottom:1px solid var(--border);padding:12px 0;';

    const tsHtml = c.time_label
        ? '<a href="#" onclick="seekTo(' + c.timestamp_sec + ');return false;" style="font-size:0.7rem;font-weight:700;background:var(--accent-subtle);color:var(--accent);padding:1px 7px;border-radius:20px;text-decoration:none;margin-left:6px;">▶ ' + c.time_label + '</a>'
        : '';
    const teacherBadge = c.is_teacher
        ? '<span style="font-size:0.62rem;font-weight:700;background:#3b82f620;color:#3b82f6;padding:2px 7px;border-radius:20px;margin-left:4px;">Teacher</span>'
        : '';
    const deleteBtn = c.can_delete
        ? '<button onclick="deleteComment(' + c.id + ')" style="background:none;border:none;cursor:pointer;color:var(--danger);font-size:0.72rem;padding:0 4px;" title="Delete">✕</button>'
        : '';
    const replyBtn = !isReply
        ? '<button onclick="toggleReply(' + c.id + ')" style="background:none;border:none;cursor:pointer;color:var(--accent);font-size:0.78rem;padding:0;font-weight:500;">↩ Reply</button>'
        : '';

    wrap.innerHTML =
        '<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;flex-wrap:wrap;">' +
            '<span style="font-size:0.82rem;font-weight:700;color:var(--text-primary);">' + escHtml(c.author_name) + '</span>' +
            teacherBadge + tsHtml +
            '<span style="font-size:0.68rem;color:var(--text-muted);margin-left:auto;">' + formatDate(c.created_at) + '</span>' +
            deleteBtn +
        '</div>' +
        '<div style="font-size:0.85rem;color:var(--text-secondary);line-height:1.65;margin-bottom:8px;">' + escHtml(c.body) + '</div>' +
        '<div style="display:flex;gap:10px;">' + replyBtn + '</div>' +
        '<div id="replyForm' + c.id + '" style="display:none;margin-top:10px;">' +
            '<textarea class="form-control" rows="2" style="font-size:0.82rem;resize:none;" placeholder="Write a reply…"></textarea>' +
            '<div style="display:flex;gap:6px;margin-top:6px;justify-content:flex-end;">' +
                '<button onclick="toggleReply(' + c.id + ')" class="btn btn-ghost btn-sm">Cancel</button>' +
                '<button onclick="submitReply(' + c.id + ')" class="btn btn-primary btn-sm">Reply</button>' +
            '</div>' +
        '</div>' +
        '<div id="replies' + c.id + '"></div>';

    if (!isReply && c.replies && c.replies.length) {
        const repliesEl = wrap.querySelector('#replies' + c.id);
        c.replies.forEach(r => repliesEl.appendChild(buildComment(r, true)));
    }
    return wrap;
}

async function postComment() {
    const body = document.getElementById('commentBody').value.trim();
    const ts   = document.getElementById('commentTs').value;
    if (!body) { document.getElementById('commentBody').focus(); return; }
    const btn  = document.querySelector('#commentBody ~ div button.btn-primary');
    if (btn) btn.disabled = true;
    try {
        const res  = await fetch('/educore/modules/videos/api_comments.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ action: 'post', video_id: VIDEO_ID, body: body, timestamp_sec: ts ? parseInt(ts) : null }),
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
    } finally {
        if (btn) btn.disabled = false;
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
    const btn  = form.querySelector('.btn-primary');
    if (btn) btn.disabled = true;
    try {
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
    } finally {
        if (btn) btn.disabled = false;
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

// ── PDF attachment upload (teacher/owner only) ────────────────
async function uploadPdf(input) {
    const file = input.files[0];
    if (!file) return;
    const progress = document.getElementById('uploadProgress');
    if (progress) progress.style.display = 'block';
    const label = document.getElementById('uploadPdfLabel');
    if (label) label.style.pointerEvents = 'none';

    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('video_id', VIDEO_ID);
    fd.append('pdf', file);
    fd.append('_csrf', csrf);

    try {
        const res  = await fetch('/educore/modules/videos/api_attachments.php', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf },
            body: fd,
        });
        const data = await res.json();
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
                '<a href="' + data.download_url + '" class="btn btn-ghost btn-sm">⬇ Download</a>' +
                '<button onclick="deleteAttachment(' + data.id + ',this)" class="btn btn-ghost btn-icon" style="color:var(--danger);" title="Remove">' +
                    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>' +
                '</button>';
            document.getElementById('attachmentsList').appendChild(row);
        } else {
            alert(data.error || 'Upload failed. ' + (data.error || ''));
        }
    } catch(e) {
        alert('Upload error. Please check your connection and try again.');
    } finally {
        if (progress) progress.style.display = 'none';
        if (label) label.style.pointerEvents = '';
        input.value = '';
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
    else alert(data.error || 'Could not delete attachment.');
}

function seekTo(sec) {
    const frame = document.getElementById('videoPlayer');
    if (!frame) return;
    const base = frame.src.replace(/[&?]start=\d+/, '').replace(/#t=\d+/, '');
    frame.src  = base.includes('youtube') ? base + '&start=' + sec + '&autoplay=1' : base + '#t=' + sec;
}

function formatDate(dt) {
    try {
        const d = new Date(dt.replace(' ', 'T'));
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    } catch(e) { return dt; }
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}

// Load comments on page load for ALL roles
loadComments();
</script>
JSRAW;
?>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
