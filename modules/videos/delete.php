<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('index.php'); }
csrf_verify();

$pdo = getDB();
$cid = centerId();
$uid = authId();

$id   = (int)($_POST['video_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, title, created_by FROM videos WHERE id = ? AND center_id = ?");
$stmt->execute([$id, $cid]);
$video = $stmt->fetch();

if (!$video) {
    flash('error', 'Video not found.');
    redirect('index.php');
}

// Academy plan: use owner_teacher_id; otherwise fall back to created_by
if (!isRole('owner')) {
    $ownerCol = (isAcademyPlan() && !empty($video['owner_teacher_id'])) ? 'owner_teacher_id' : 'created_by';
    if ($video[$ownerCol] != $uid) {
    flash('error', 'You can only delete your own video.');
        redirect('index.php');
    }
}

$pdo->prepare("DELETE FROM video_views WHERE video_id = ?")->execute([$id]);
$pdo->prepare("DELETE FROM videos WHERE id = ? AND center_id = ?")->execute([$id, $cid]);
auditLog('video.deleted', 'video', $id, ['title' => $video['title']]);
flash('success', '"' . sanitize($video['title']) . '" deleted.');
redirect('index.php');
