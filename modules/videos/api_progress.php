<?php
/**
 * api_progress.php
 * POST endpoint — saves student's watch position and marks completion.
 * Called every 30 seconds by view.php JS, and on page unload via sendBeacon.
 *
 * Payload (JSON body):
 *   { video_id: int, position: int }
 *
 * Security:
 *   - Student must be authenticated
 *   - Student must be enrolled in the group that owns the video (or video is center-wide)
 *   - Rate-limited to 1 call per 15 s per student via rate_limits table
 */

require_once __DIR__ . '/../../includes/helpers.php';
header('Content-Type: application/json');

// Only students can call this endpoint
if (!isRole('student')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Accept JSON body (sendBeacon) or POST fields
$body = file_get_contents('php://input');
$data = $body ? json_decode($body, true) : $_POST;

$videoId  = (int)($data['video_id'] ?? 0);
$position = max(0, (int)($data['position'] ?? 0));

if (!$videoId) {
    http_response_code(400);
    echo json_encode(['error' => 'video_id required']);
    exit;
}

$pdo = getDB();
$cid = centerId();
$uid = authId();

// Load student record
$stStmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ? AND center_id = ? AND is_active = 1");
$stStmt->execute([$uid, $cid]);
$student = $stStmt->fetch();
if (!$student) {
    http_response_code(403);
    echo json_encode(['error' => 'Student not found']);
    exit;
}
$studentId = $student['id'];

// Rate limit: 1 request per 15 seconds per student per video
if (!rateLimit("student_{$studentId}_video_{$videoId}", 'video_progress', 1, 15)) {
    echo json_encode(['ok' => true, 'skipped' => true]);
    exit;
}

// Fetch video and verify access
$vStmt = $pdo->prepare("SELECT id, group_id, duration_min FROM videos WHERE id = ? AND center_id = ? AND is_visible = 1");
$vStmt->execute([$videoId, $cid]);
$video = $vStmt->fetch();
if (!$video) {
    http_response_code(404);
    echo json_encode(['error' => 'Video not found']);
    exit;
}

// Verify enrollment if group-restricted
if ($video['group_id']) {
    $enrStmt = $pdo->prepare("SELECT 1 FROM group_students WHERE group_id = ? AND student_id = ? AND status = 'active'");
    $enrStmt->execute([$video['group_id'], $studentId]);
    if (!$enrStmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Not enrolled in this group']);
        exit;
    }
}

// Determine completion (>= 80 % of duration)
$durationSec = (int)$video['duration_min'] * 60;
$completed   = ($durationSec > 0 && $position >= ($durationSec * 0.8)) ? 1 : 0;

// Upsert video_views
$upsert = $pdo->prepare("
    INSERT INTO video_views (video_id, student_id, center_id, watch_seconds, last_position, completed)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        watch_seconds = watch_seconds + VALUES(watch_seconds),
        last_position = VALUES(last_position),
        completed     = GREATEST(completed, VALUES(completed)),
        last_watched  = NOW()
");
// watch_seconds increment: difference since last call (approx 30 s unless first call)
$upsert->execute([$videoId, $studentId, $cid, 30, $position, $completed]);

echo json_encode(['ok' => true, 'completed' => (bool)$completed]);
