<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','assistant','accountant');

$pdo = getDB();
$cid = centerId();

$search = trim($_GET['q']      ?? '');
$status = $_GET['status']      ?? '';
$group  = (int)($_GET['group'] ?? 0);
$month  = $_GET['month']       ?? '';

$where  = 'WHERE p.center_id = ?';
$params = [$cid];
if ($search) { $where .= ' AND (s.name LIKE ? OR p.receipt_number LIKE ?)'; $like="%$search%"; $params=array_merge($params,[$like,$like]); }
if ($status) { $where .= ' AND p.status = ?'; $params[] = $status; }
if ($group)  { $where .= ' AND p.group_id = ?'; $params[] = $group; }
if ($month)  { $where .= ' AND p.month_year = ?'; $params[] = $month; }

$stmt = $pdo->prepare("SELECT p.*,s.name AS student_name,s.student_code,g.name AS group_name FROM payments p JOIN students s ON s.id=p.student_id LEFT JOIN `groups` g ON g.id=p.group_id $where ORDER BY p.created_at DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="payments_'.date('Ymd').'.csv"');

$out = fopen('php://output','w');
fputcsv($out, ['Receipt #','Student','Code','Group','Month','Fee Amount','Discount','Net Due','Paid','Balance','Method','Status','Due Date','Paid Date','Notes']);
foreach ($rows as $r) {
    $net = $r['amount'] - ($r['discount']??0);
    fputcsv($out, [
        $r['receipt_number'],
        $r['student_name'],
        $r['student_code'],
        $r['group_name'],
        $r['month_year'],
        $r['amount'],
        $r['discount']??0,
        $net,
        $r['paid_amount'],
        $net - $r['paid_amount'],
        $r['payment_method'],
        $r['status'],
        $r['due_date'],
        $r['paid_date'],
        $r['notes'],
    ]);
}
fclose($out);
exit;
