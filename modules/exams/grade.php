<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher');

$pdo = getDB();
$cid = centerId();
$eid = (int)($_GET['id'] ?? 0);
if (!$eid) redirect('/educore/modules/exams/');

$eStmt = $pdo->prepare("SELECT e.*,g.name AS group_name FROM exams e LEFT JOIN `groups` g ON g.id=e.group_id WHERE e.id=? AND e.center_id=?");
$eStmt->execute([$eid,$cid]);
$exam = $eStmt->fetch();
if (!$exam) { flash('error','Exam not found.'); redirect('/educore/modules/exams/'); }

$pageTitle  = 'Grade — ' . sanitize($exam['title']);
$activePage = 'exams';
$breadcrumbs = [
    ['label'=>'Dashboard',              'href'=>'/educore/dashboard.php'],
    ['label'=>'Exams',                  'href'=>'/educore/modules/exams/'],
    ['label'=>sanitize($exam['title']), 'href'=>"view.php?id=$eid"],
    ['label'=>'Manual Grading'],
];

// Short-answer questions only
$sqStmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id=? AND type='short_answer' ORDER BY sort_order,id");
$sqStmt->execute([$eid]);
$shortQs = $sqStmt->fetchAll();
if (!$shortQs) { flash('info','No short-answer questions to grade.'); redirect("results.php?id=$eid"); }

// Handle save
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();

    $grades   = $_POST['grade']    ?? []; // [submission_id_qid => score]
    $comments = $_POST['comment']  ?? []; // [submission_id_qid => text]

    $pdo->beginTransaction();
    try {
        foreach ($grades as $key => $pts) {
            [$subId,$qId] = explode('_', $key);
            $subId  = (int)$subId;
            $qId    = (int)$qId;
            $pts    = max(0, (float)$pts);
            $cmnt   = trim($comments[$key] ?? '');

            // Clamp to question max
            $maxStmt = $pdo->prepare("SELECT points FROM exam_questions WHERE id=? AND exam_id=?");
            $maxStmt->execute([$qId,$eid]);
            $maxPts = (float)($maxStmt->fetchColumn() ?: 0);
            $pts    = min($pts, $maxPts);
            $isCorr = ($pts >= $maxPts) ? 1 : 0;

            // Update answer row — correct column names: score_awarded, teacher_comment, is_correct
            $pdo->prepare("UPDATE exam_answers
                           SET score_awarded=?, is_correct=?, teacher_comment=?
                           WHERE submission_id=? AND exam_question_id=?")
                ->execute([$pts, $isCorr, $cmnt, $subId, $qId]);
        }

        // Recalculate each affected submission total
        $affectedSubs = array_unique(array_map(fn($k)=>(int)explode('_',$k)[0], array_keys($grades)));
        foreach ($affectedSubs as $subId) {
            $totalStmt = $pdo->prepare("SELECT SUM(score_awarded) FROM exam_answers WHERE submission_id=?");
            $totalStmt->execute([$subId]);
            $total  = (float)$totalStmt->fetchColumn();
            $tp     = $exam['total_points'] ?: 1;
            $pct    = round($total/$tp*100, 1);
            $passed = $pct >= $exam['passing_score'] ? 1 : 0;
            $pdo->prepare("UPDATE exam_submissions SET total_score=?,percentage=?,passed=?,status='graded' WHERE id=? AND exam_id=?")
                ->execute([$total,$pct,$passed,$subId,$eid]);

            // Notify the student
            $subInfo = $pdo->prepare("SELECT es.student_id, s.user_id FROM exam_submissions es JOIN students s ON s.id=es.student_id WHERE es.id=?");
            $subInfo->execute([$subId]);
            $subRow  = $subInfo->fetch();
            if ($subRow && $subRow['user_id']) {
                $resultLabel = $passed ? '✓ Passed' : '✗ Failed';
                $notifTitle  = 'Your exam has been graded';
                $notifBody   = '"'.$exam['title'].'" — Score: '.round($pct).'% ('.$resultLabel.')';
                $resultLink  = '/educore/modules/exams/result.php?exam='.$eid.'&student='.$subRow['student_id'];
                if ($exam['show_results']==='immediately') {
                    pushNotification($subRow['user_id'],'grade',$notifTitle,$notifBody,$resultLink,$cid);
                } else {
                    pushNotification($subRow['user_id'],'grade',$notifTitle,'"'.$exam['title'].'" has been graded. Results will be visible once released.','',$cid);
                }
            }
        }

        $pdo->commit();
        flash('success','Grades saved.');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('error','Failed to save grades. Please try again.');
    }
    redirect("grade.php?id=$eid&status=".($_GET['status']??'ungraded'));
}

$filter = $_GET['status'] ?? 'ungraded';
$statusWhere = $filter==='ungraded' ? "AND es.status='submitted'" : ($filter==='graded' ? "AND es.status='graded'" : "");

$subStmt = $pdo->prepare("
    SELECT es.id AS sub_id, es.attempt_number, es.submitted_at, es.status AS sub_status,
           es.percentage, es.passed,
           s.name AS student_name, s.id AS student_id, s.student_code
    FROM exam_submissions es
    JOIN students s ON s.id=es.student_id
    WHERE es.exam_id=? AND es.status IN('submitted','graded') $statusWhere
    ORDER BY es.status DESC, s.name");
$subStmt->execute([$eid]);
$submissions = $subStmt->fetchAll();

// Load all short-answer rows for all submissions at once
$ansMap = []; // [sub_id][question_id] = row
if ($submissions) {
    $subIds = implode(',', array_fill(0, count($submissions), '?'));
    $qIds   = implode(',', array_fill(0, count($shortQs), '?'));
    $params = array_merge(array_column($submissions,'sub_id'), array_column($shortQs,'id'));
    $aStmt  = $pdo->prepare("SELECT ea.* FROM exam_answers ea WHERE ea.submission_id IN($subIds) AND ea.exam_question_id IN($qIds)");
    $aStmt->execute($params);
    foreach ($aStmt->fetchAll() as $a) {
        $ansMap[$a['submission_id']][$a['exam_question_id']] = $a;
    }
}

$ungradedCount = (int)$pdo->prepare("SELECT COUNT(*) FROM exam_submissions WHERE exam_id=? AND status='submitted'")->execute([$eid]) ?
    $pdo->query("SELECT COUNT(*) FROM exam_submissions WHERE exam_id=$eid AND status='submitted'")->fetchColumn() : 0;
// Safer:
$ugStmt = $pdo->prepare("SELECT COUNT(*) FROM exam_submissions WHERE exam_id=? AND status='submitted'");
$ugStmt->execute([$eid]);
$ungradedCount = (int)$ugStmt->fetchColumn();

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Manual Grading</h1>
    <p class="page-subtitle">Short-answer responses · <?=sanitize($exam['title'])?></p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <?php if ($ungradedCount > 0): ?>
    <span class="badge badge-warning"><?=$ungradedCount?> ungraded</span>
    <?php else: ?>
    <span class="badge badge-success">All graded ✓</span>
    <?php endif; ?>
    <a href="results.php?id=<?=$eid?>" class="btn btn-ghost btn-sm">← Results</a>
  </div>
</div>

<!-- Filter tabs -->
<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:1px solid var(--border);">
  <?php foreach (['ungraded'=>'⏳ Ungraded','graded'=>'✓ Graded','all'=>'All'] as $f=>$lbl): ?>
  <a href="?id=<?=$eid?>&status=<?=$f?>"
     style="padding:9px 18px;font-size:0.84rem;font-weight:600;text-decoration:none;border-bottom:2px solid <?=$filter===$f?'var(--accent)':'transparent'?>;color:<?=$filter===$f?'var(--accent)':'var(--text-muted)'?>;margin-bottom:-1px;display:inline-block;">
    <?=$lbl?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Question reference -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:12px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:0.75rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;">Questions:</span>
    <?php foreach ($shortQs as $i=>$q): ?>
    <span style="font-size:0.78rem;padding:4px 10px;background:var(--bg-elevated);border-radius:20px;color:var(--text-secondary);">
      Q<?=$q['sort_order']??$i+1?> — <?=sanitize(substr($q['question'],0,45))?><?=strlen($q['question'])>45?'…':''?>
      <strong style="color:var(--accent);">(<?=$q['points']?> pts)</strong>
    </span>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!$submissions): ?>
<div class="empty-state">
  <div class="empty-icon">📝</div>
  <div class="empty-title">No <?=$filter==='ungraded'?'ungraded ':''?>submissions</div>
</div>
<?php else: ?>

<form method="POST">
  <?=csrf_field()?>

  <?php foreach ($submissions as $sub):
    $isGraded = $sub['sub_status']==='graded';
  ?>
  <div class="card" style="margin-bottom:16px;border-left:3px solid <?=$isGraded?'var(--success)':'var(--warning)'?>;">
    <div class="card-header" style="background:var(--bg-elevated);">
      <div style="display:flex;align-items:center;gap:12px;">
        <div style="width:34px;height:34px;border-radius:50%;background:var(--accent-subtle);color:var(--accent);font-size:0.75rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <?=strtoupper(substr($sub['student_name'],0,2))?>
        </div>
        <div>
          <div style="font-weight:600;color:var(--text-primary);"><?=sanitize($sub['student_name'])?></div>
          <div style="font-size:0.72rem;color:var(--text-muted);">
            <?=$sub['student_code']?'#'.$sub['student_code'].' · ':''?>
            Submitted <?=date('M j, g:i a', strtotime($sub['submitted_at']))?>
            <?= $isGraded ? ' · <strong style="color:var(--success);">'.round($sub['percentage']).'%</strong>' : '' ?>
          </div>
        </div>
        <span class="badge <?=$isGraded?'badge-success':'badge-warning'?>" style="margin-left:auto;">
          <?=$isGraded?'Graded':'Needs Grading'?>
        </span>
      </div>
    </div>

    <div class="card-body" style="padding:0;">
      <?php foreach ($shortQs as $idx=>$q):
        $ans     = $ansMap[$sub['sub_id']][$q['id']] ?? null;
        $rawAns  = $ans['student_answer'] ?? '';
        $scored  = $ans['score_awarded']  ?? 0;
        $comment = $ans['teacher_comment'] ?? '';
        $key     = $sub['sub_id'].'_'.$q['id'];
      ?>
      <div style="padding:18px 22px;border-bottom:1px solid var(--border);">
        <div style="display:grid;grid-template-columns:1fr 130px;gap:16px;align-items:start;">
          <div>
            <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:5px;">
              Q<?=$q['sort_order']??$idx+1?> · <?=$q['points']?> pts max
            </div>
            <div style="font-size:0.88rem;font-weight:500;color:var(--text-primary);margin-bottom:<?=empty($q['question_image'])?'10px':'6px'?>;">
              <?=sanitize($q['question'])?>
            </div>
            <?php if(!empty($q['question_image'])): ?>
            <div style="margin-bottom:10px;">
              <img src="<?=sanitize($q['question_image'])?>" alt="Question image"
                   style="max-width:100%;max-height:200px;border-radius:var(--radius-sm);border:1px solid var(--border);object-fit:contain;cursor:pointer;"
                   onclick="window.open(this.src,'_blank')" title="Click to enlarge">
            </div>
            <?php endif; ?>
            <?php if ($q['explanation']): ?>
            <div style="font-size:0.76rem;color:var(--info,#60a5fa);background:rgba(96,165,250,0.08);padding:6px 10px;border-radius:var(--radius-sm);margin-bottom:10px;">
              💡 <strong>Model answer:</strong> <?=sanitize($q['explanation'])?>
            </div>
            <?php endif; ?>
            <!-- Student's answer -->
            <div style="background:var(--bg-elevated);border-left:3px solid var(--border-light,var(--border));border-radius:0 var(--radius-sm) var(--radius-sm) 0;padding:10px 14px;font-size:0.86rem;min-height:42px;">
              <?php if ($rawAns !== '' && $rawAns !== null): ?>
              <?=nl2br(sanitize($rawAns))?>
              <?php else: ?>
              <span style="color:var(--text-muted);font-style:italic;">No answer given</span>
              <?php endif; ?>
            </div>
            <!-- Teacher comment -->
            <div style="margin-top:10px;">
              <label style="font-size:0.72rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:4px;">Feedback for student <span style="font-weight:400;">(optional)</span></label>
              <input type="text" name="comment[<?=$key?>]" class="form-control" style="font-size:0.82rem;"
                     value="<?=sanitize($comment)?>" placeholder="Leave a note…">
            </div>
          </div>

          <!-- Score input -->
          <div style="display:flex;flex-direction:column;align-items:center;gap:8px;padding-top:24px;">
            <label style="font-size:0.72rem;font-weight:600;color:var(--text-muted);">Score</label>
            <div style="display:flex;align-items:center;gap:6px;">
              <input type="number" name="grade[<?=$key?>]" class="form-control"
                     id="score_<?=$key?>"
                     style="width:68px;text-align:center;font-size:1.1rem;font-weight:700;font-family:var(--font-display);"
                     min="0" max="<?=$q['points']?>" step="0.5"
                     value="<?=$scored?>">
              <span style="font-size:0.8rem;color:var(--text-muted);">/ <?=$q['points']?></span>
            </div>
            <div style="display:flex;gap:4px;">
              <button type="button" class="btn btn-sm btn-danger"
                      style="font-size:0.7rem;padding:3px 8px;"
                      onclick="document.getElementById('score_<?=$key?>').value=0">0</button>
              <?php if ($q['points']>1): ?>
              <button type="button" class="btn btn-sm btn-ghost"
                      style="font-size:0.7rem;padding:3px 8px;"
                      onclick="document.getElementById('score_<?=$key?>').value=<?=$q['points']/2?>">½</button>
              <?php endif; ?>
              <button type="button" class="btn btn-sm btn-success"
                      style="font-size:0.7rem;padding:3px 8px;"
                      onclick="document.getElementById('score_<?=$key?>').value=<?=$q['points']?>">Full</button>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div style="position:sticky;bottom:20px;display:flex;justify-content:flex-end;z-index:10;">
    <button type="submit" class="btn btn-primary btn-lg" style="box-shadow:var(--shadow-lg);">
      💾 Save All Grades
    </button>
  </div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
