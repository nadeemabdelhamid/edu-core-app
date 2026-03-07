<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','assistant','accountant');

$pdo     = getDB();
$cid     = centerId();
$year    = (int)($_GET['year']  ?? date('Y'));
$groupId = (int)($_GET['group'] ?? 0);

$extra  = $groupId ? ' AND s.group_id=?' : '';
$params = $groupId ? [$cid,$year,$groupId] : [$cid,$year];

$stmt = $pdo->prepare("
    SELECT st.name, st.student_code, g.name AS group_name,
           COUNT(ar.id) AS total, SUM(ar.status IN('present','late')) AS present,
           SUM(ar.status='absent') AS absent, SUM(ar.status='late') AS late, SUM(ar.status='excused') AS excused,
           ROUND(100.0*SUM(ar.status IN('present','late'))/NULLIF(COUNT(ar.id),0),1) AS rate
    FROM attendance_records ar
    JOIN students st ON st.id=ar.student_id
    JOIN attendance_sessions s ON s.id=ar.session_id
    JOIN `groups` g ON g.id=s.group_id
    WHERE ar.center_id=? AND YEAR(s.session_date)=? $extra
    GROUP BY st.id, g.id ORDER BY g.name, rate DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="attendance_report_'.$year.'.csv"');
$out = fopen('php://output','w');
fputcsv($out,['Student','Code','Group','Total Sessions','Present','Absent','Late','Excused','Rate %']);
foreach($rows as $r) fputcsv($out,[$r['name'],$r['student_code'],$r['group_name'],$r['total'],$r['present'],$r['absent'],$r['late'],$r['excused'],$r['rate']]);
fclose($out); exit;
