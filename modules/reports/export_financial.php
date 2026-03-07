<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','accountant');

$pdo  = getDB();
$cid  = centerId();
$year = (int)($_GET['year']  ?? date('Y'));
$month= $_GET['month'] ?? '';

$where  = 'AND p.center_id=?';
$params = [$cid];
if ($month) { $where .= ' AND p.month_year=?'; $params[]=$month; }
else { $where .= ' AND YEAR(STR_TO_DATE(CONCAT(COALESCE(p.month_year,DATE_FORMAT(p.created_at,"%Y-%m")),"-01"),"%Y-%m-%d"))=?'; $params[]=$year; }

$stmt = $pdo->prepare("SELECT p.*,s.name AS student_name,s.student_code,g.name AS group_name FROM payments p JOIN students s ON s.id=p.student_id LEFT JOIN `groups` g ON g.id=p.group_id WHERE 1=1 $where ORDER BY p.created_at DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="financial_report_'.($month?:$year).'.csv"');
$out = fopen('php://output','w');
fputcsv($out,['Receipt','Student','Code','Group','Month','Billed','Discount','Net Due','Paid','Balance','Method','Status','Due Date','Paid Date']);
foreach($rows as $r) {
    $net = $r['amount']-($r['discount']??0);
    fputcsv($out,[$r['receipt_number'],$r['student_name'],$r['student_code'],$r['group_name'],$r['month_year'],$r['amount'],$r['discount']??0,$net,$r['paid_amount'],$net-$r['paid_amount'],ucfirst(str_replace('_',' ',$r['payment_method'])),$r['status'],$r['due_date'],$r['paid_date']]);
}
fclose($out); exit;
