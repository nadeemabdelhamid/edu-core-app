<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','accountant');

$pdo = getDB();
$cid = centerId();

$stmt = $pdo->prepare("
    SELECT s.name, s.student_code, s.phone, s.email, s.gender,
           s.is_active, s.created_at,
           (s.user_id IS NOT NULL) AS has_login,
           (SELECT COUNT(*) FROM group_students gs WHERE gs.student_id=s.id AND gs.status='active') AS group_count,
           (SELECT ROUND(100.0*SUM(ar.status IN('present','late'))/NULLIF(COUNT(*),0),1)
            FROM attendance_records ar JOIN attendance_sessions ass ON ass.id=ar.session_id
            WHERE ar.student_id=s.id AND ass.session_date>=DATE_SUB(NOW(),INTERVAL 90 DAY)) AS att_rate_90d,
           (SELECT COALESCE(SUM(paid_amount),0) FROM payments WHERE student_id=s.id AND center_id=s.center_id) AS total_paid,
           (SELECT COALESCE(SUM(amount-COALESCE(discount,0)-paid_amount),0) FROM payments WHERE student_id=s.id AND center_id=s.center_id AND status IN('unpaid','partial','overdue')) AS outstanding
    FROM students s WHERE s.center_id=? ORDER BY s.name");
$stmt->execute([$cid]);
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="students_report_'.date('Ymd').'.csv"');
$out = fopen('php://output','w');
fputcsv($out,['Name','Code','Phone','Email','Gender','Active','Has Login','Groups','Attendance % (90d)','Total Paid (EGP)','Outstanding (EGP)','Joined']);
foreach($rows as $r) fputcsv($out,[$r['name'],$r['student_code'],$r['phone'],$r['email'],ucfirst($r['gender']??''),$r['is_active']?'Yes':'No',$r['has_login']?'Yes':'No',$r['group_count'],$r['att_rate_90d']??'—',$r['total_paid'],$r['outstanding'],date('Y-m-d',strtotime($r['created_at']))]);
fclose($out); exit;
