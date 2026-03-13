<?php
/**
 * download_attachment.php
 * Serves a PDF attachment securely after verifying access rights.
 * Never exposes the real filesystem path in the URL.
 */
require_once __DIR__ . '/../../includes/helpers.php';
requireAuth();

$pdo = getDB();
$cid = centerId();
$uid = authId();

$attId = (int)($_GET['id'] ?? 0);

$aStmt = $pdo->prepare("
    SELECT va.*, v.group_id, v.is_visible, v.available_from, v.available_until, v.created_by AS video_creator
    FROM video_attachments va
    JOIN videos v ON v.id = va.video_id
    WHERE va.id = ? AND va.center_id = ?");
$aStmt->execute([$attId, $cid]);
$att = $aStmt->fetch();

if (!$att) { http_response_code(404); die('Attachment not found.'); }

// Students: must be enrolled in the group and video must be accessible
if (isRole('student')) {
    $stStmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ? AND center_id = ? AND is_active = 1");
    $stStmt->execute([$uid, $cid]);
    $student = $stStmt->fetch();
    if (!$student) { http_response_code(403); die('Access denied.'); }

    $now = time();
    if (!$att['is_visible'])                                                   { http_response_code(403); die('Access denied.'); }
    if ($att['available_from']  && strtotime($att['available_from'])  > $now)  { http_response_code(403); die('Not yet available.'); }
    if ($att['available_until'] && strtotime($att['available_until']) < $now)  { http_response_code(403); die('No longer available.'); }

    if ($att['group_id']) {
        $enrStmt = $pdo->prepare("SELECT 1 FROM group_students WHERE group_id = ? AND student_id = ? AND status = 'active'");
        $enrStmt->execute([$att['group_id'], $student['id']]);
        if (!$enrStmt->fetch()) { http_response_code(403); die('Not enrolled.'); }
    }
} elseif (!isRole('owner', 'teacher', 'assistant', 'super_admin')) {
    http_response_code(403); die('Access denied.');
}

$filePath = __DIR__ . '/../../uploads/video_docs/' . $att['filename'];
if (!file_exists($filePath)) { http_response_code(404); die('File not found.'); }

// Serve the file
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . addslashes($att['original_name']) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, no-cache');
readfile($filePath);
exit;
