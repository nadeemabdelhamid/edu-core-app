<?php
/**
 * api_attachments.php
 * Handles PDF attachment upload (POST, multipart) and delete (POST, JSON).
 *
 * POST upload:  multipart/form-data  { action:'upload', video_id, pdf: <file> }
 * POST delete:  application/json    { action:'delete', attachment_id }
 */
require_once __DIR__ . '/../../includes/helpers.php';
header('Content-Type: application/json');
requireRole('owner', 'teacher');

csrf_verify();

$pdo = getDB();
$cid = centerId();
$uid = authId();

$action = $_POST['action'] ?? (json_decode(file_get_contents('php://input'), true)['action'] ?? '');

// ── UPLOAD ────────────────────────────────────────────────────
if ($action === 'upload') {
    $videoId = (int)($_POST['video_id'] ?? 0);

    // Verify video ownership / access
    $vStmt = $pdo->prepare("SELECT id, created_by FROM videos WHERE id = ? AND center_id = ?");
    $vStmt->execute([$videoId, $cid]);
    $video = $vStmt->fetch();
    if (!$video) { echo json_encode(['error' => 'Video not found']); exit; }
    if (!isRole('owner') && $video['created_by'] != $uid) { echo json_encode(['error' => 'Access denied']); exit; }

    // Validate file
    if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'No file received or upload error']); exit;
    }

    $file     = $_FILES['pdf'];
    $origName = basename($file['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $maxSize  = 20 * 1024 * 1024; // 20 MB

    if ($ext !== 'pdf') { echo json_encode(['error' => 'Only PDF files are allowed']); exit; }
    if ($file['size'] > $maxSize) { echo json_encode(['error' => 'File must be under 20 MB']); exit; }

    // Validate MIME type (basic check)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== 'application/pdf') { echo json_encode(['error' => 'Invalid file type']); exit; }

    // Max 5 attachments per video
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM video_attachments WHERE video_id = ?");
    $countStmt->execute([$videoId]);
    if ($countStmt->fetchColumn() >= 5) {
        echo json_encode(['error' => 'Maximum 5 attachments per video']); exit;
    }

    // Store file
    $uploadDir = __DIR__ . '/../../uploads/video_docs/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $storedName = 'vid_' . $videoId . '_' . uniqid() . '.pdf';
    $destPath   = $uploadDir . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode(['error' => 'Failed to save file']); exit;
    }

    $pdo->prepare("INSERT INTO video_attachments (video_id, center_id, filename, original_name, file_size, uploaded_by)
                   VALUES (?,?,?,?,?,?)")
        ->execute([$videoId, $cid, $storedName, $origName, $file['size'], $uid]);

    $newId = $pdo->lastInsertId();
    auditLog('video.attachment.upload', 'video', $videoId, ['file' => $origName]);

    echo json_encode([
        'ok'            => true,
        'id'            => $newId,
        'original_name' => $origName,
        'file_size'     => $file['size'],
        'download_url'  => "/educore/modules/videos/download_attachment.php?id=$newId",
    ]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────
if ($action === 'delete') {
    $data = json_decode(file_get_contents('php://input'), true);
    $attId = (int)($data['attachment_id'] ?? $_POST['attachment_id'] ?? 0);

    $aStmt = $pdo->prepare("
        SELECT va.*, v.created_by AS video_creator
        FROM video_attachments va
        JOIN videos v ON v.id = va.video_id
        WHERE va.id = ? AND va.center_id = ?");
    $aStmt->execute([$attId, $cid]);
    $att = $aStmt->fetch();

    if (!$att) { echo json_encode(['error' => 'Attachment not found']); exit; }
    if (!isRole('owner') && $att['video_creator'] != $uid && $att['uploaded_by'] != $uid) {
        echo json_encode(['error' => 'Access denied']); exit;
    }

    // Remove file from disk
    $path = __DIR__ . '/../../uploads/video_docs/' . $att['filename'];
    if (file_exists($path)) unlink($path);

    $pdo->prepare("DELETE FROM video_attachments WHERE id = ?")->execute([$attId]);
    auditLog('video.attachment.delete', 'video', $att['video_id'], ['file' => $att['original_name']]);

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
