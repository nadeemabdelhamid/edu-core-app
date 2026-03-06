<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireAuth();

header('Content-Type: application/json');

$sessionId = (int)($_GET['session'] ?? 0);
$cid       = centerId();

if (!$sessionId || !$cid) {
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$pdo = getDB();

// Verify session belongs to this center
$sess = $pdo->prepare("SELECT * FROM attendance_sessions WHERE id=? AND center_id=?");
$sess->execute([$sessionId, $cid]);
$session = $sess->fetch();

if (!$session) {
    echo json_encode(['error' => 'Session not found']);
    exit;
}

// Get records
$recStmt = $pdo->prepare("SELECT ar.*, st.name as student_name FROM attendance_records ar JOIN students st ON st.id=ar.student_id WHERE ar.session_id=? ORDER BY ar.submitted_at DESC");
$recStmt->execute([$sessionId]);
$records = $recStmt->fetchAll();

// Total students in group
$totStmt = $pdo->prepare("SELECT COUNT(*) FROM group_students WHERE group_id=? AND status='active'");
$totStmt->execute([$session['group_id']]);
$total = (int)$totStmt->fetchColumn();

// Build rows HTML
$rows = '';
foreach ($records as $i => $rec) {
    $name = htmlspecialchars($rec['student_name'], ENT_QUOTES, 'UTF-8');
    $time = date('H:i:s', strtotime($rec['submitted_at']));
    $rows .= "<tr><td style='color:var(--text-muted)'>" . ($i + 1) . "</td><td class='cell-primary'>$name</td><td><span class='badge badge-success'>Present</span></td><td style='font-size:0.78rem;color:var(--text-muted);'>$time</td></tr>";
}

echo json_encode([
    'count'  => count($records),
    'total'  => $total,
    'rows'   => $rows,
    'status' => $session['status'],
    'expires'=> $session['code_expires_at'],
]);
