<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireAuth();

$pdo = getDB();
$cid = centerId();
$uid = authId();
$eid = (int)($_GET['exam'] ?? 0);
$stId= (int)($_GET['student'] ?? 0);

if (!$eid) redirect('/educore/dashboard.php');

// Determine student ID
if (isRole('student')) {
    $stStmt = $pdo->prepare("SELECT * FROM students WHERE user_id=? AND center_id=?");
    $stStmt->execute([$uid,$cid]);
    $student = $stStmt->fetch();
    if (!$student) redirect('/educore/dashboard.php');
    $stId = $student['id'];
} else {
    requireRole('owner','teacher','assistant');
    if (!$stId) redirect("/educore/modules/exams/results.php?id=$eid");
    $stStmt = $pdo->prepare("SELECT * FROM students WHERE id=? AND center_id=?");
    $stStmt->execute([$stId,$cid]);
    $student = $stStmt->fetch();
    if (!$student) redirect("/educore/modules/exams/results.php?id=$eid");
}

// Load exam
$eStmt = $pdo->prepare("SELECT e.*,g.name AS group_name FROM exams e LEFT JOIN `groups` g ON g.id=e.group_id WHERE e.id=? AND e.center_id=?");
$eStmt->execute([$eid,$cid]);
$exam = $eStmt->fetch();
if (!$exam) redirect('/educore/dashboard.php');

// Check result visibility for students
if (isRole('student')) {
    $sub = $pdo->prepare("SELECT * FROM exam_submissions WHERE exam_id=? AND student_id=? AND status IN('submitted','graded') ORDER BY attempt_number DESC LIMIT 1");
    $sub->execute([$eid,$stId]);
    $submission = $sub->fetch();
    if (!$submission) redirect("take.php?id=$eid");

    if ($exam['show_results']==='after_close' && $exam['status']!=='closed') {
        $pageTitle = 'Exam Submitted';
        $activePage= 'exams';
        $breadcrumbs = [['label'=>'Exams','href'=>'/educore/modules/exams/student.php'],['label'=>$pageTitle]];
        include __DIR__ . '/../../templates/layout.php';
        echo '<div style="max-width:500px;margin:60px auto;text-align:center;">
          <div style="font-size:3rem;margin-bottom:16px;">✅</div>
          <h2 style="font-family:var(--font-display);color:var(--text-primary);margin-bottom:8px;">Exam Submitted!</h2>
          <p style="color:var(--text-muted);">Results will be available once the exam closes.</p>
          <a href="/educore/modules/exams/student.php" class="btn btn-primary" style="margin-top:20px;">← Back to Exams</a>
        </div>';
        include __DIR__ . '/../../templates/layout_end.php';
        exit;
    }
    if ($exam['show_results']==='manual' && !$submission['graded_at']) {
        // Same "waiting" page
        $pageTitle = 'Results Pending';
        $activePage= 'exams';
        $breadcrumbs = [['label'=>'Exams','href'=>'/educore/modules/exams/student.php'],['label'=>$pageTitle]];
        include __DIR__ . '/../../templates/layout.php';
        echo '<div style="max-width:500px;margin:60px auto;text-align:center;">
          <div style="font-size:3rem;margin-bottom:16px;">⏳</div>
          <h2 style="font-family:var(--font-display);color:var(--text-primary);margin-bottom:8px;">Results Pending</h2>
          <p style="color:var(--text-muted);">Your teacher hasn\'t released the results yet. Check back later.</p>
          <a href="/educore/modules/exams/student.php" class="btn btn-primary" style="margin-top:20px;">← Back to Exams</a>
        </div>';
        include __DIR__ . '/../../templates/layout_end.php';
        exit;
    }
} else {
    $sub = $pdo->prepare("SELECT * FROM exam_submissions WHERE exam_id=? AND student_id=? ORDER BY attempt_number DESC LIMIT 1");
    $sub->execute([$eid,$stId]);
    $submission = $sub->fetch();
    if (!$submission) { flash('error','No submission found.'); redirect("results.php?id=$eid"); }
}

// Load questions + answers
$qStmt = $pdo->prepare("SELECT eq.*,ea.student_answer,ea.is_correct,ea.score_awarded,ea.teacher_comment FROM exam_questions eq LEFT JOIN exam_answers ea ON ea.exam_question_id=eq.id AND ea.submission_id=? WHERE eq.exam_id=? ORDER BY eq.sort_order,eq.id");
$qStmt->execute([$submission['id'],$eid]);
$questions = $qStmt->fetchAll();

// Load focus violations for this submission (saved permanently in exam_focus_log)
$focusEvents   = [];
$focusSummary  = ['violations'=>0,'away_seconds'=>0];
if (!isRole('student')) {
    try {
        $feStmt = $pdo->prepare("
            SELECT event, created_at
            FROM exam_focus_log
            WHERE submission_id=? AND exam_id=?
            ORDER BY created_at ASC");
        $feStmt->execute([$submission['id'],$eid]);
        $rawEvents = $feStmt->fetchAll(PDO::FETCH_ASSOC);

        // Build violation pairs (blur→focus)
        $blurTime = null;
        foreach ($rawEvents as $ev) {
            if ($ev['event']==='blur') {
                $blurTime = $ev['created_at'];
            } elseif ($ev['event']==='focus' && $blurTime) {
                $gap = strtotime($ev['created_at']) - strtotime($blurTime);
                $focusEvents[] = [
                    'left_at'     => $blurTime,
                    'returned_at' => $ev['created_at'],
                    'seconds'     => $gap,
                ];
                $focusSummary['violations']++;
                $focusSummary['away_seconds'] += $gap;
                $blurTime = null;
            }
        }
        // Still-open blur (student never returned before exam ended)
        if ($blurTime) {
            $focusEvents[] = [
                'left_at'     => $blurTime,
                'returned_at' => null,
                'seconds'     => null,
            ];
            $focusSummary['violations']++;
        }
    } catch (Exception $e) { /* table not created yet */ }
}

// Handle teacher grading POST
if ($_SERVER['REQUEST_METHOD']==='POST' && isRole('owner','teacher','assistant')) {
    csrf_verify();
    if ($_POST['action']==='grade_short') {
        $manualScore = 0;
        foreach ($questions as $q) {
            if ($q['type']!=='short_answer') continue;
            $score   = max(0, min($q['points'], (float)($_POST['score_'.$q['id']] ?? 0)));
            $comment = trim($_POST['comment_'.$q['id']] ?? '');
            $correct = $score > 0 ? 1 : 0;
            $pdo->prepare("UPDATE exam_answers SET score_awarded=?,is_correct=?,teacher_comment=? WHERE submission_id=? AND exam_question_id=?")
                ->execute([$score,$correct,$comment,$submission['id'],$q['id']]);
            $manualScore += $score;
        }
        // Recalc total
        $autoScore = $submission['auto_score'];
        $total     = $autoScore + $manualScore;
        $pct       = round(($total / max(1,$exam['total_points'])) * 100, 2);
        $passed    = $pct >= $exam['passing_score'] ? 1 : 0;
        $pdo->prepare("UPDATE exam_submissions SET manual_score=?,total_score=?,percentage=?,passed=?,status='graded',graded_at=NOW(),graded_by=? WHERE id=?")
            ->execute([$manualScore,$total,$pct,$passed,$uid,$submission['id']]);

        // Notify student
        $stuUser = $pdo->prepare("SELECT s.user_id FROM students s WHERE s.id=?");
        $stuUser->execute([$stId]);
        $stuUserId = $stuUser->fetchColumn();
        if ($stuUserId) {
            $resultLabel = $passed ? '✓ Passed' : '✗ Failed';
            if ($exam['show_results']==='immediately') {
                pushNotification($stuUserId,'grade','Your exam has been graded',
                    '"'.$exam['title'].'" — Score: '.round($pct).'% ('.$resultLabel.')',
                    '/educore/modules/exams/result.php?exam='.$eid.'&student='.$stId, $cid);
            } else {
                pushNotification($stuUserId,'grade','Your exam has been graded',
                    '"'.$exam['title'].'" has been graded. Results will be visible once released.','', $cid);
            }
        }
        flash('success','Grading saved.');
        redirect("result.php?exam=$eid&student=$stId");
    }
}

$pageTitle  = sanitize($exam['title']) . ' — Result';
$activePage = 'exams';
$breadcrumbs = isRole('student')
    ? [['label'=>'Exams','href'=>'/educore/modules/exams/student.php'],['label'=>$pageTitle]]
    : [['label'=>'Exams','href'=>'/educore/modules/exams/'],['label'=>sanitize($exam['title']),'href'=>"results.php?id=$eid"],['label'=>sanitize($student['name'])]];

$colors  = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4'];
$stuColor= $colors[abs(crc32($student['name'])) % count($colors)];
$pct     = (float)$submission['percentage'];
$passed  = (bool)$submission['passed'];
$pctColor= $pct >= $exam['passing_score'] ? 'var(--success)' : 'var(--danger)';

$hasUngraded = false;
foreach($questions as $q) { if($q['type']==='short_answer' && $q['is_correct']===null && $q['student_answer']) { $hasUngraded=true; break; } }

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:12px;">
    <div class="avatar" style="background:linear-gradient(135deg,<?=$stuColor?>,<?=$stuColor?>99);color:#fff;font-weight:700;border-radius:var(--radius-md);width:44px;height:44px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?=strtoupper(substr($student['name'],0,2))?></div>
    <div>
      <h1 class="page-title"><?=sanitize($student['name'])?></h1>
      <p class="page-subtitle"><?=sanitize($exam['title'])?></p>
    </div>
  </div>
  <?php if(!isRole('student')): ?>
  <a href="results.php?id=<?=$eid?>" class="btn btn-ghost">← All Results</a>
  <?php endif; ?>
</div>

<!-- Score card -->
<div style="display:grid;grid-template-columns:220px 1fr;gap:20px;margin-bottom:24px;">
  <div class="card" style="text-align:center;<?=$passed?'border-color:rgba(34,197,94,0.3)':'border-color:rgba(239,68,68,0.3)'?>">
    <div class="card-body" style="padding:28px 20px;">
      <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:8px;">Final Score</div>
      <?php
        $circ = 2*M_PI*15.9;
        $dash = round(($pct/100)*$circ,2);
        $rgb  = $passed ? '34,197,94' : '239,68,68';
      ?>
      <div style="position:relative;width:90px;height:90px;margin:0 auto 12px;">
        <svg viewBox="0 0 36 36" style="width:90px;height:90px;transform:rotate(-90deg);">
          <circle cx="18" cy="18" r="15.9" fill="none" stroke="var(--bg-elevated)" stroke-width="3"/>
          <circle cx="18" cy="18" r="15.9" fill="none" stroke="rgba(<?=$rgb?>,0.9)" stroke-width="3" stroke-dasharray="<?=$dash?> <?=$circ?>" stroke-linecap="round"/>
        </svg>
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-family:var(--font-display);font-size:1.3rem;font-weight:800;color:var(--text-primary);"><?=round($pct)?>%</div>
      </div>
      <div style="font-size:1.2rem;font-weight:700;color:<?=$pctColor?>;"><?=$passed?'✓ Passed':'✗ Failed'?></div>
      <div style="font-size:0.78rem;color:var(--text-muted);margin-top:4px;"><?=number_format($submission['total_score'],1)?> / <?=number_format($exam['total_points'],1)?> pts</div>
      <?php if($hasUngraded && isRole('student')): ?>
      <div style="margin-top:8px;font-size:0.72rem;color:var(--warning);">⏳ Pending manual grading</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-body" style="display:grid;grid-template-columns:repeat(3,1fr);gap:0;">
      <?php
      $autoQ  = array_filter($questions, fn($q)=>in_array($q['type'],['mcq','true_false']));
      $correct= array_filter($autoQ, fn($q)=>$q['is_correct']===1||$q['is_correct']==='1');
      $wrong  = array_filter($autoQ, fn($q)=>$q['is_correct']===0||$q['is_correct']==='0');
      $unanswered = array_filter($questions, fn($q)=>$q['student_answer']===null||$q['student_answer']==='');
      $timeStr = gmdate('i:s', (int)$submission['time_spent_sec']);
      $items = [
        ['✓ Correct',   count($correct),  'var(--success)'],
        ['✗ Wrong',     count($wrong),    'var(--danger)'],
        ['— Unanswered',count($unanswered),'var(--text-muted)'],
        ['Passing',     $exam['passing_score'].'%','var(--text-muted)'],
        ['Time Spent',  $timeStr,         'var(--text-muted)'],
        ['Attempt',     '#'.$submission['attempt_number'], 'var(--text-muted)'],
      ];
      foreach($items as [$label,$val,$color]):
      ?>
      <div style="padding:16px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);">
        <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:4px;"><?=$label?></div>
        <div style="font-size:1.3rem;font-weight:700;color:<?=$color?>;font-family:var(--font-display);"><?=$val?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Grading form for short answers (teacher only) -->
<?php if(!isRole('student') && $hasUngraded): ?>
<div class="card" style="margin-bottom:20px;border-color:rgba(245,158,11,0.3);">
  <div class="card-header" style="background:rgba(245,158,11,0.05);">
    <span class="card-title" style="color:var(--warning);">⚠ Short Answers Need Grading</span>
  </div>
  <div class="card-body" style="padding:14px 20px;font-size:0.84rem;color:var(--text-secondary);">
    Scroll down to the short answer questions and assign scores. Click "Save Grading" when done.
  </div>
</div>
<?php endif; ?>

<!-- Question review -->
<?php if($exam['allow_review'] || !isRole('student')): ?>
<form method="POST" id="gradeForm">
<?=csrf_field()?><input type="hidden" name="action" value="grade_short">
<div class="card">
  <div class="card-header">
    <span class="card-title">Answer Review</span>
    <?php if(!isRole('student') && $hasUngraded): ?>
    <button type="submit" class="btn btn-primary btn-sm">Save Grading</button>
    <?php endif; ?>
  </div>
  <div class="card-body no-padding">
    <?php foreach($questions as $i => $q):
      $opts   = ['a'=>$q['option_a'],'b'=>$q['option_b'],'c'=>$q['option_c'],'d'=>$q['option_d']];
      $ans    = $q['student_answer'] ?? null;
      $isCorrect = $q['is_correct'];
      $rowBg  = '';
      if ($q['type']!=='short_answer') {
        if ($isCorrect===1||$isCorrect==='1') $rowBg='rgba(34,197,94,0.04)';
        elseif ($isCorrect===0||$isCorrect==='0') $rowBg='rgba(239,68,68,0.04)';
      }
    ?>
    <div style="padding:18px 24px;border-bottom:1px solid var(--border);background:<?=$rowBg?>;">
      <div style="display:flex;gap:10px;align-items:flex-start;">
        <div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.72rem;font-weight:700;flex-shrink:0;
          <?php if($q['type']==='short_answer'&&$isCorrect===null): ?>background:var(--warning-dim);color:var(--warning);
          <?php elseif($isCorrect===1||$isCorrect==='1'): ?>background:var(--success-dim);color:var(--success);
          <?php elseif($isCorrect===0||$isCorrect==='0'): ?>background:var(--danger-dim);color:var(--danger);
          <?php else: ?>background:var(--bg-elevated);color:var(--text-muted);<?php endif; ?>">
          <?php if($q['type']==='short_answer'&&$isCorrect===null): ?>?<?php
          elseif($isCorrect===1||$isCorrect==='1'): ?>✓<?php
          elseif($isCorrect===0||$isCorrect==='0'): ?>✗<?php
          else: ?><?=$i+1?><?php endif; ?>
        </div>
        <div style="flex:1;">
          <div style="font-weight:500;color:var(--text-primary);margin-bottom:8px;"><?=sanitize($q['question'])?></div>

          <?php if($q['type']==='mcq'): ?>
          <div style="display:flex;flex-direction:column;gap:4px;">
            <?php foreach($opts as $k=>$v): if(!$v) continue; ?>
            <div style="font-size:0.82rem;padding:5px 10px;border-radius:4px;
              <?php if($k===$q['correct_answer']): ?>background:rgba(34,197,94,0.1);color:var(--success);font-weight:600;
              <?php elseif($k===$ans && $ans!==$q['correct_answer']): ?>background:rgba(239,68,68,0.1);color:var(--danger);
              <?php else: ?>color:var(--text-muted);<?php endif; ?>">
              <?=strtoupper($k)?>) <?=sanitize($v)?>
              <?php if($k===$q['correct_answer']): ?> ✓<?php endif; ?>
              <?php if($k===$ans && $ans!==$q['correct_answer']): ?> ← Your answer<?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>

          <?php elseif($q['type']==='true_false'): ?>
          <div style="font-size:0.84rem;">
            <span style="color:var(--text-muted);">Your answer: </span>
            <strong style="color:<?=$ans===$q['correct_answer']?'var(--success)':'var(--danger)'?>;"><?=ucfirst($ans??'—')?></strong>
            <span style="color:var(--text-muted);margin-left:12px;">Correct: </span>
            <strong style="color:var(--success);"><?=ucfirst($q['correct_answer']??'')?></strong>
          </div>

          <?php else: // short answer ?>
          <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);padding:10px 14px;font-size:0.85rem;color:var(--text-secondary);margin-bottom:8px;white-space:pre-wrap;"><?=sanitize($ans??'(no answer)')?></div>
          <?php if(!isRole('student')): ?>
          <div style="display:grid;grid-template-columns:120px 1fr;gap:10px;align-items:start;">
            <div>
              <label style="font-size:0.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Score (max <?=number_format($q['points'],1)?>)</label>
              <input type="number" name="score_<?=$q['id']?>" class="form-control" style="margin-top:4px;"
                     value="<?=number_format($q['score_awarded']??0,1)?>" min="0" max="<?=$q['points']?>" step="0.5">
            </div>
            <div>
              <label style="font-size:0.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Comment</label>
              <input type="text" name="comment_<?=$q['id']?>" class="form-control" style="margin-top:4px;"
                     value="<?=sanitize($q['teacher_comment']??'')?>" placeholder="Optional feedback…">
            </div>
          </div>
          <?php elseif($q['score_awarded']>0): ?>
          <div style="font-size:0.78rem;color:var(--success);">Score: <?=number_format($q['score_awarded'],1)?>/<?=number_format($q['points'],1)?></div>
          <?php endif; ?>
          <?php if($q['teacher_comment'] && isRole('student')): ?>
          <div style="margin-top:6px;font-size:0.78rem;color:var(--info);background:var(--info-dim);padding:6px 10px;border-radius:4px;">💬 <?=sanitize($q['teacher_comment'])?></div>
          <?php endif; ?>
          <?php endif; ?>

          <?php if($q['explanation'] && ($isCorrect===0||$isCorrect==='0')): ?>
          <div style="margin-top:6px;font-size:0.75rem;color:var(--info);background:var(--info-dim);padding:4px 8px;border-radius:4px;">💡 <?=sanitize($q['explanation'])?></div>
          <?php endif; ?>
        </div>
        <div style="font-size:0.72rem;color:var(--text-muted);flex-shrink:0;"><?=number_format($q['score_awarded']??0,1)?>/<?=number_format($q['points'],1)?>pt</div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php if(!isRole('student') && $hasUngraded): ?>
<div style="text-align:right;margin-top:16px;">
  <button type="submit" class="btn btn-primary">Save Grading</button>
</div>
<?php endif; ?>
</form>
<?php else: ?>
<div class="card"><div class="card-body" style="text-align:center;color:var(--text-muted);padding:40px;">Review not available until results are released.</div></div>
<?php endif; ?>

<?php if(!isRole('student') && ($focusSummary['violations'] > 0 || count($focusEvents) > 0)): ?>
<div class="card" style="margin-top:20px;border-color:rgba(239,68,68,.3);">
  <div class="card-header" style="border-color:rgba(239,68,68,.2);">
    <span class="card-title" style="color:var(--danger);">
      ⚠️ Focus Violations — <?=sanitize($student['name'])?>
    </span>
    <div style="display:flex;gap:12px;align-items:center;">
      <span style="font-size:.75rem;color:var(--text-muted);">
        <?=$focusSummary['violations']?> violation<?=$focusSummary['violations']!=1?'s':''?>
      </span>
      <?php if($focusSummary['away_seconds']>0):
        $aw = $focusSummary['away_seconds'];
        $awStr = ($aw>=60 ? floor($aw/60).'m ' : '').($aw%60).'s';
      ?>
      <span style="font-size:.75rem;color:var(--danger);font-weight:600;">
        Total away: <?=$awStr?>
      </span>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body no-padding">
    <table class="data-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Left Exam At</th>
          <th>Returned At</th>
          <th>Time Away</th>
          <th>Risk</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($focusEvents as $i=>$fe):
          $secs = $fe['seconds'];
          $risk = $secs === null ? 'high'
                : ($secs > 60 ? 'high' : ($secs > 15 ? 'medium' : 'low'));
          $riskColor = ['high'=>'var(--danger)','medium'=>'var(--warning)','low'=>'var(--text-muted)'][$risk];
          $riskLabel = ['high'=>'⚠️ High','medium'=>'⚡ Medium','low'=>'✓ Low'][$risk];
          $awayStr   = $secs === null ? 'Did not return'
                     : (($secs>=60 ? floor($secs/60).'m ' : '').($secs%60).'s');
        ?>
        <tr>
          <td style="color:var(--text-muted);font-size:.8rem;"><?=$i+1?></td>
          <td style="font-size:.82rem;font-weight:600;color:var(--danger);">
            <?=date('H:i:s',strtotime($fe['left_at']))?>
            <div style="font-size:.68rem;color:var(--text-muted);font-weight:400;">
              <?=date('M j, Y',strtotime($fe['left_at']))?>
            </div>
          </td>
          <td style="font-size:.82rem;color:<?=$fe['returned_at']?'var(--success)':'var(--danger)'?>;">
            <?=$fe['returned_at'] ? date('H:i:s',strtotime($fe['returned_at'])) : '— Never returned'?>
          </td>
          <td style="font-weight:700;font-size:.85rem;color:<?=$riskColor?>;">
            <?=$awayStr?>
          </td>
          <td>
            <span style="font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:10px;
                         background:<?=$risk==='high'?'rgba(239,68,68,.12)':($risk==='medium'?'rgba(245,158,11,.12)':'rgba(100,116,139,.1)')?>; 
                         color:<?=$riskColor?>;">
              <?=$riskLabel?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="padding:10px 20px;font-size:.72rem;color:var(--text-muted);border-top:1px solid var(--border);">
    📌 Violations are permanently recorded from the live exam session. Data is stored in <code>exam_focus_log</code>.
  </div>
</div>
<?php elseif(!isRole('student') && count($focusEvents)===0): ?>
<div style="margin-top:16px;padding:12px 18px;background:var(--bg-elevated);border-radius:var(--radius-sm);
            font-size:.78rem;color:var(--text-muted);display:flex;align-items:center;gap:8px;">
  <span style="color:var(--success);">✓</span> No focus violations recorded for this student.
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
