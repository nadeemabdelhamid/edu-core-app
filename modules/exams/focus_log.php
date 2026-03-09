<?php
/**
 * focus_log.php — Receive student focus/blur events during live exams
 * Called via fetch() from take.php (no page reload)
 */
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('student');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false]); exit;
}

// CSRF check (token sent in POST body)
$token = $_POST['csrf_token'] ?? '';
if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403); echo json_encode(['ok'=>false,'err'=>'csrf']); exit;
}

$pdo   = getDB();
$cid   = centerId();
$uid   = authId();

$examId = (int)($_POST['exam_id']       ?? 0);
$subId  = (int)($_POST['submission_id'] ?? 0);
$event  = $_POST['event'] ?? '';

if (!$examId || !$subId || !in_array($event, ['blur','focus'])) {
    http_response_code(400); echo json_encode(['ok'=>false,'err'=>'bad input']); exit;
}

// Verify the submission belongs to this student
$chk = $pdo->prepare("SELECT s.id, s.student_id FROM exam_submissions s
    JOIN students st ON st.id=s.student_id
    WHERE s.id=? AND s.exam_id=? AND st.user_id=? AND s.center_id=? AND s.status='in_progress'");
$chk->execute([$subId, $examId, $uid, $cid]);
$sub = $chk->fetch();

if (!$sub) {
    // Submission not found / not in progress – silently ignore
    echo json_encode(['ok'=>false,'err'=>'no submission']); exit;
}

// Deduplicate: don't insert same event twice in a row within 2 seconds
$last = $pdo->prepare("SELECT event FROM exam_focus_log
    WHERE submission_id=? ORDER BY created_at DESC LIMIT 1");
$last->execute([$subId]);
$lastEvent = $last->fetchColumn();

if ($lastEvent === $event) {
    // Same event back-to-back — skip
    echo json_encode(['ok'=>true,'skipped'=>true]); exit;
}

$pdo->prepare("INSERT INTO exam_focus_log
    (exam_id, submission_id, student_id, center_id, event, created_at)
    VALUES (?,?,?,?,?,NOW())")
    ->execute([$examId, $subId, $sub['student_id'], $cid, $event]);

echo json_encode(['ok'=>true]);
