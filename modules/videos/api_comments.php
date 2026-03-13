<?php
/**
 * api_comments.php
 * POST  { action:'post',   video_id, body, timestamp_sec? }        → post comment
 * POST  { action:'reply',  parent_id, body }                        → reply to comment
 * POST  { action:'delete', comment_id }                             → soft-delete
 * GET   ?video_id=N                                                 → fetch all comments
 */
require_once __DIR__ . '/../../includes/helpers.php';
requireAuth();
header('Content-Type: application/json');

$pdo    = getDB();
$cid    = centerId();
$uid    = authId();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: load comments ────────────────────────────────────────
if ($method === 'GET') {
    $videoId = (int)($_GET['video_id'] ?? 0);
    if (!$videoId) { echo json_encode(['error' => 'video_id required']); exit; }

    // Verify video is accessible in this center
    $vStmt = $pdo->prepare("SELECT id FROM videos WHERE id = ? AND center_id = ?");
    $vStmt->execute([$videoId, $cid]);
    if (!$vStmt->fetch()) { echo json_encode(['error' => 'Video not found']); exit; }

    $cStmt = $pdo->prepare("
        SELECT vc.*, u.name AS author_name
        FROM video_comments vc
        JOIN users u ON u.id = vc.author_id
        WHERE vc.video_id = ? AND vc.is_deleted = 0
        ORDER BY vc.timestamp_sec ASC, vc.created_at ASC");
    $cStmt->execute([$videoId]);
    $rows = $cStmt->fetchAll();

    // Build tree: top-level + replies
    $top     = [];
    $replies = [];
    foreach ($rows as $r) {
        $r['is_teacher'] = in_array($r['author_role'], ['teacher', 'owner', 'assistant']);
        $r['can_delete'] = ($r['author_id'] == $uid || isRole('owner', 'teacher'));
        $r['time_label'] = $r['timestamp_sec'] !== null ? formatTimestamp((int)$r['timestamp_sec']) : null;
        if ($r['parent_id'] === null) {
            $r['replies'] = [];
            $top[$r['id']] = $r;
        } else {
            $replies[] = $r;
        }
    }
    foreach ($replies as $r) {
        if (isset($top[$r['parent_id']])) {
            $top[$r['parent_id']]['replies'][] = $r;
        }
    }

    echo json_encode(['ok' => true, 'comments' => array_values($top)]);
    exit;
}

// ── POST: mutations ───────────────────────────────────────────
if ($method === 'POST') {
    csrf_verify();
    $data   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $data['action'] ?? '';

    if ($action === 'post') {
        $videoId   = (int)($data['video_id'] ?? 0);
        $body      = trim($data['body'] ?? '');
        $tsRaw     = $data['timestamp_sec'] ?? null;
        $tsSec     = ($tsRaw !== null && $tsRaw !== '') ? max(0, (int)$tsRaw) : null;

        if (!$videoId || !$body) { echo json_encode(['error' => 'video_id and body required']); exit; }
        if (mb_strlen($body) > 2000) { echo json_encode(['error' => 'Comment too long (max 2000 chars)']); exit; }

        // Verify video exists + student is enrolled (if group-scoped)
        $vStmt = $pdo->prepare("SELECT id, group_id FROM videos WHERE id = ? AND center_id = ?");
        $vStmt->execute([$videoId, $cid]);
        $video = $vStmt->fetch();
        if (!$video) { echo json_encode(['error' => 'Video not found']); exit; }

        if (isRole('student') && $video['group_id']) {
            $stStmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ? AND center_id = ? AND is_active = 1");
            $stStmt->execute([$uid, $cid]);
            $student = $stStmt->fetch();
            if ($student) {
                $enrStmt = $pdo->prepare("SELECT 1 FROM group_students WHERE group_id = ? AND student_id = ? AND status = 'active'");
                $enrStmt->execute([$video['group_id'], $student['id']]);
                if (!$enrStmt->fetch()) { echo json_encode(['error' => 'Not enrolled']); exit; }
            }
        }

        $rawRole  = auth()['role'] ?? 'student';
        $role     = in_array($rawRole, ['teacher','owner','assistant','student']) ? $rawRole : 'teacher';
        try {
        $pdo->prepare("INSERT INTO video_comments (video_id, center_id, author_id, author_role, timestamp_sec, body)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$videoId, $cid, $uid, $role, $tsSec, $body]);
        } catch (\Exception $e) {
            echo json_encode(['error' => 'Could not save comment: ' . $e->getMessage()]); exit;
        }

        $newId = $pdo->lastInsertId();
        $user  = auth();
        echo json_encode([
            'ok'         => true,
            'id'         => $newId,
            'author_name'=> sanitize($user['name']),
            'author_role'=> $role,
            'is_teacher' => in_array($role, ['teacher','owner','assistant']),
            'body'       => sanitize($body),
            'timestamp_sec' => $tsSec,
            'time_label' => $tsSec !== null ? formatTimestamp($tsSec) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'can_delete' => true,
            'replies'    => [],
        ]);
        exit;
    }

    if ($action === 'reply') {
        $parentId = (int)($data['parent_id'] ?? 0);
        $body     = trim($data['body'] ?? '');

        if (!$parentId || !$body) { echo json_encode(['error' => 'parent_id and body required']); exit; }
        if (mb_strlen($body) > 2000) { echo json_encode(['error' => 'Reply too long (max 2000 chars)']); exit; }

        // Fetch parent to inherit video_id
        $pStmt = $pdo->prepare("SELECT * FROM video_comments WHERE id = ? AND center_id = ? AND parent_id IS NULL");
        $pStmt->execute([$parentId, $cid]);
        $parent = $pStmt->fetch();
        if (!$parent) { echo json_encode(['error' => 'Parent comment not found']); exit; }

        $rawRole = auth()['role'] ?? 'student';
        $role    = in_array($rawRole, ['teacher','owner','assistant','student']) ? $rawRole : 'teacher';
        $pdo->prepare("INSERT INTO video_comments (video_id, center_id, parent_id, author_id, author_role, body)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$parent['video_id'], $cid, $parentId, $uid, $role, $body]);

        $newId = $pdo->lastInsertId();
        $user  = auth();
        echo json_encode([
            'ok'         => true,
            'id'         => $newId,
            'parent_id'  => $parentId,
            'author_name'=> sanitize($user['name']),
            'author_role'=> $role,
            'is_teacher' => in_array($role, ['teacher','owner','assistant']),
            'body'       => sanitize($body),
            'created_at' => date('Y-m-d H:i:s'),
            'can_delete' => true,
        ]);
        exit;
    }

    if ($action === 'delete') {
        $commentId = (int)($data['comment_id'] ?? 0);
        if (!$commentId) { echo json_encode(['error' => 'comment_id required']); exit; }

        $cStmt = $pdo->prepare("SELECT * FROM video_comments WHERE id = ? AND center_id = ?");
        $cStmt->execute([$commentId, $cid]);
        $comment = $cStmt->fetch();
        if (!$comment) { echo json_encode(['error' => 'Comment not found']); exit; }

        // Only author or owner/teacher can delete
        if ($comment['author_id'] != $uid && !isRole('owner', 'teacher')) {
            echo json_encode(['error' => 'Access denied']); exit;
        }

        $pdo->prepare("UPDATE video_comments SET is_deleted = 1 WHERE id = ?")->execute([$commentId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

function formatTimestamp(int $sec): string {
    $h = floor($sec / 3600);
    $m = floor(($sec % 3600) / 60);
    $s = $sec % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}
