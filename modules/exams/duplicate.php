<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher');

$pdo = getDB();
$cid = centerId();
$eid = (int)($_GET['id'] ?? 0);
if (!$eid) redirect('/educore/modules/exams/');

// Only accept confirmed action
if (!isset($_GET['confirm'])) {
    // Show confirmation page rather than doing it silently
    $eStmt = $pdo->prepare("SELECT title FROM exams WHERE id=? AND center_id=?");
    $eStmt->execute([$eid,$cid]);
    $exam = $eStmt->fetch();
    if (!$exam) { flash('error','Exam not found.'); redirect('/educore/modules/exams/'); }
    ?>
    <!DOCTYPE html><html><head><meta charset="UTF-8"><title>Duplicate Exam</title></head>
    <body style="font-family:sans-serif;padding:40px;text-align:center;">
    <p style="font-size:1.1rem;">Duplicate <strong><?=sanitize($exam['title'])?></strong>?</p>
    <p style="color:#666;margin:10px 0 24px;">A full copy will be created in Draft status.</p>
    <a href="?id=<?=$eid?>&confirm=1" style="background:#f5a623;color:#000;padding:10px 28px;border-radius:6px;text-decoration:none;font-weight:700;margin-right:10px;">Yes, Duplicate</a>
    <a href="view.php?id=<?=$eid?>"   style="background:#333;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;">Cancel</a>
    </body></html>
    <?php exit;
}

$eStmt = $pdo->prepare("SELECT * FROM exams WHERE id=? AND center_id=?");
$eStmt->execute([$eid,$cid]);
$exam = $eStmt->fetch();
if (!$exam) { flash('error','Exam not found.'); redirect('/educore/modules/exams/'); }

$qStmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id=? ORDER BY sort_order,id");
$qStmt->execute([$eid]);
$questions = $qStmt->fetchAll();

try {
    $pdo->beginTransaction();

    $pdo->prepare("
        INSERT INTO exams
          (center_id,created_by,group_id,title,description,instructions,type,status,
           duration_minutes,passing_score,total_points,
           shuffle_questions,shuffle_options,allow_review,max_attempts,show_results,
           starts_at,ends_at)
        VALUES (?,?,?,?,?,?,?,'draft',?,?,?,?,?,?,?,?,NULL,NULL)")
    ->execute([
        $cid, authId(), $exam['group_id'],
        'Copy of '.$exam['title'],
        $exam['description'], $exam['instructions'], $exam['type'],
        $exam['duration_minutes'], $exam['passing_score'], $exam['total_points'],
        $exam['shuffle_questions'], $exam['shuffle_options'],
        $exam['allow_review'], $exam['max_attempts'], $exam['show_results'],
    ]);
    $newEid = $pdo->lastInsertId();

    foreach ($questions as $q) {
        $pdo->prepare("
            INSERT INTO exam_questions
              (exam_id,question_id,type,question,
               option_a,option_b,option_c,option_d,
               correct_answer,points,explanation,sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $newEid, $q['question_id'], $q['type'], $q['question'],
            $q['option_a'], $q['option_b'], $q['option_c'], $q['option_d'],
            $q['correct_answer'], $q['points'], $q['explanation'], $q['sort_order'],
        ]);
    }

    auditLog('exam.duplicated','exam',$newEid,['original_id'=>$eid]);
    $pdo->commit();
    flash('success','Exam duplicated — it\'s in Draft. Edit before activating.');
    redirect("view.php?id=$newEid");
} catch (Exception $e) {
    $pdo->rollBack();
    flash('error','Failed to duplicate exam.');
    redirect("view.php?id=$eid");
}
