<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','assistant');

$pdo = getDB();
$cid = centerId();
$eid = (int)($_GET['id'] ?? 0);
if (!$eid) redirect('/educore/modules/exams/');

$eStmt = $pdo->prepare("SELECT e.*,g.name AS group_name FROM exams e LEFT JOIN `groups` g ON g.id=e.group_id WHERE e.id=? AND e.center_id=?");
$eStmt->execute([$eid,$cid]);
$exam = $eStmt->fetch();
if (!$exam) redirect('/educore/modules/exams/');

// All submissions
$rows = $pdo->prepare("
    SELECT s.name AS student_name, s.student_code, s.email AS student_email,
           g.name  AS group_name,
           es.attempt_number, es.status,
           es.started_at, es.submitted_at,
           es.auto_score, es.total_score, es.percentage,
           es.passed, es.time_spent_sec
    FROM exam_submissions es
    JOIN students s ON s.id = es.student_id
    LEFT JOIN group_students gs ON gs.student_id=es.student_id AND gs.center_id=es.center_id
    LEFT JOIN `groups` g ON g.id=gs.group_id
    WHERE es.exam_id=? AND es.status IN('submitted','graded')
    ORDER BY s.name");
$rows->execute([$eid]);
$data = $rows->fetchAll();

$slug     = preg_replace('/[^a-z0-9]+/','-',strtolower($exam['title']));
$filename = 'results_'.$slug.'_'.date('Y-m-d').'.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: no-cache,no-store,must-revalidate');
echo "\xEF\xBB\xBF"; // BOM for Excel

$out = fopen('php://output','w');

// Metadata block
fputcsv($out, ['Exam:',         $exam['title']]);
fputcsv($out, ['Group:',        $exam['group_name'] ?? 'All']);
fputcsv($out, ['Total Points:', $exam['total_points']]);
fputcsv($out, ['Pass at:',      $exam['passing_score'].'%']);
fputcsv($out, ['Exported:',     date('Y-m-d H:i')]);
fputcsv($out, []);

// Column headers
fputcsv($out, [
    'Student Name','Student Code','Email','Group',
    'Status','Attempt','Started At','Submitted At',
    'Time (min)','Auto Score','Total Score',
    'Percentage (%)','Passed','Grade',
]);

foreach ($data as $r) {
    $grade = $r['percentage']>=90?'A':($r['percentage']>=80?'B':($r['percentage']>=70?'C':($r['percentage']>=60?'D':'F')));
    fputcsv($out, [
        $r['student_name'],
        $r['student_code'] ?? '',
        $r['student_email'] ?? '',
        $r['group_name'] ?? '',
        ucfirst($r['status']),
        $r['attempt_number'],
        $r['started_at']   ? date('Y-m-d H:i', strtotime($r['started_at']))   : '',
        $r['submitted_at'] ? date('Y-m-d H:i', strtotime($r['submitted_at'])) : '',
        $r['time_spent_sec'] ? round($r['time_spent_sec']/60, 1) : '',
        $r['auto_score'],
        $r['total_score'],
        $r['percentage'],
        $r['passed'] ? 'Yes' : 'No',
        $grade,
    ]);
}
fclose($out);
exit;
