<?php
/**
 * api_poll.php — Live attendance polling endpoint
 * Called every 2.5s from create.php (teacher's live session view)
 */
require_once __DIR__ . '/../../includes/helpers.php';
requireAuth();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');

$sessionId = (int)($_GET['session'] ?? 0);
$cid       = centerId();

if (!$sessionId || !$cid) {
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$pdo = getDB();

// Verify session belongs to this center
$sess = $pdo->prepare("SELECT s.*, g.name AS group_name FROM attendance_sessions s JOIN `groups` g ON g.id=s.group_id WHERE s.id=? AND s.center_id=?");
$sess->execute([$sessionId, $cid]);
$session = $sess->fetch();

if (!$session) {
    echo json_encode(['error' => 'Session not found']);
    exit;
}

// Get present/late records
$recStmt = $pdo->prepare("
    SELECT ar.*, st.name AS student_name, st.student_code
    FROM attendance_records ar
    JOIN students st ON st.id = ar.student_id
    WHERE ar.session_id = ? AND ar.status IN ('present','late')
    ORDER BY ar.submitted_at DESC");
$recStmt->execute([$sessionId]);
$records = $recStmt->fetchAll();

// Total students in group
$totStmt = $pdo->prepare("SELECT COUNT(*) FROM group_students WHERE group_id=? AND status='active'");
$totStmt->execute([$session['group_id']]);
$total = (int)$totStmt->fetchColumn();

// Is code still live?
$isExpired = strtotime($session['code_expires_at']) < time();

// Build HTML rows for the live table
$rows = '';
foreach ($records as $i => $rec) {
    $name  = htmlspecialchars($rec['student_name'], ENT_QUOTES, 'UTF-8');
    $time  = date('H:i:s', strtotime($rec['submitted_at']));
    $badge = $rec['status'] === 'late' ? 'badge-warning' : 'badge-success';
    $label = ucfirst($rec['status']);
    $rows .= "<tr>
        <td style='color:var(--text-muted);'>" . ($i + 1) . "</td>
        <td class='cell-primary'>$name</td>
        <td><span class='badge $badge'>$label</span></td>
        <td style='font-size:0.78rem;color:var(--text-muted);'>$time</td>
    </tr>";
}

echo json_encode([
    'count'     => count($records),
    'total'     => $total,
    'rows'      => $rows,
    'status'    => $session['status'],
    'expired'   => $isExpired,
    'expires'   => $session['code_expires_at'],
    'group'     => $session['group_name'],
]);
