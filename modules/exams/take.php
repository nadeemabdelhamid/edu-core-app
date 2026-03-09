<?php
/**
 * take.php — Student exam-taking interface
 * Timer-based, question-by-question, auto-submits on expiry
 */
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('student');

$pdo = getDB();
$uid = authId();
$cid = centerId();
$eid = (int)($_GET['id'] ?? 0);
if (!$eid) redirect('/educore/dashboard.php');

// Get student record
$stStmt = $pdo->prepare("SELECT * FROM students WHERE user_id=? AND center_id=? AND is_active=1");
$stStmt->execute([$uid,$cid]);
$student = $stStmt->fetch();
if (!$student) { flash('error','Student profile not found.'); redirect('/educore/dashboard.php'); }

// Get exam
$eStmt = $pdo->prepare("SELECT e.*,g.name AS group_name FROM exams e LEFT JOIN `groups` g ON g.id=e.group_id WHERE e.id=? AND e.center_id=?");
$eStmt->execute([$eid,$cid]);
$exam = $eStmt->fetch();
if (!$exam) { flash('error','Exam not found.'); redirect('/educore/dashboard.php'); }

// Validate exam is accessible
if ($exam['status']==='draft') { flash('error','This exam is not yet available.'); redirect('/educore/dashboard.php'); }
if ($exam['status']==='archived') { flash('error','This exam is archived.'); redirect('/educore/dashboard.php'); }
// Auto-sync status from time-based rules before access check
if ($exam['status']==='scheduled' && $exam['starts_at'] && strtotime($exam['starts_at']) <= time()) {
    $pdo->prepare("UPDATE exams SET status='active' WHERE id=?")->execute([$eid]);
    $exam['status'] = 'active';
}
if ($exam['status']==='active' && $exam['ends_at'] && strtotime($exam['ends_at']) < time()) {
    $pdo->prepare("UPDATE exams SET status='closed' WHERE id=?")->execute([$eid]);
    $exam['status'] = 'closed';
}
if ($exam['status']==='closed') { redirect("result.php?exam=$eid&student={$student['id']}"); }
// Only block on starts_at if exam is still scheduled (not yet active)
// Once teacher manually activates, status='active' — starts_at check must NOT run
if ($exam['status']==='scheduled' && $exam['starts_at'] && strtotime($exam['starts_at']) > time()) {
    flash('error','This exam has not started yet.'); redirect('/educore/dashboard.php');
}

// ── Visibility & access gate ──────────────────────────────────────────────────
// Use COALESCE so missing columns (before migration) default safely
$isHidden = (int)($exam['is_hidden'] ?? 0);
$isOpen   = isset($exam['is_open']) ? (int)$exam['is_open'] : 1;
if ($isHidden) { flash('error','This exam is not currently available.'); redirect('/educore/dashboard.php'); }
if (!$isOpen)  { flash('error','The teacher has temporarily locked entry to this exam.'); redirect('/educore/dashboard.php'); }

// Check group enrollment if exam has a group
if ($exam['group_id']) {
    $enrStmt = $pdo->prepare("SELECT id FROM group_students WHERE group_id=? AND student_id=? AND status='active'");
    $enrStmt->execute([$exam['group_id'],$student['id']]);
    if (!$enrStmt->fetch()) { flash('error','You are not enrolled in the group for this exam.'); redirect('/educore/dashboard.php'); }
}

// Check existing submission
$subStmt = $pdo->prepare("SELECT * FROM exam_submissions WHERE exam_id=? AND student_id=? ORDER BY attempt_number DESC LIMIT 1");
$subStmt->execute([$eid,$student['id']]);
$submission = $subStmt->fetch();

// Handle auto-submit / form submit
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'submit';
    $subId  = (int)($_POST['submission_id'] ?? 0);

    if (in_array($action,['submit','auto_submit']) && $subId) {
        $sSt = $pdo->prepare("SELECT * FROM exam_submissions WHERE id=? AND student_id=? AND status='in_progress'");
        $sSt->execute([$subId,$student['id']]);
        $sub = $sSt->fetch();
        if ($sub) {
            // Save all answers
            $qStmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id=? ORDER BY sort_order,id");
            $qStmt->execute([$eid]);
            $questions = $qStmt->fetchAll();

            $autoScore = 0;
            $hasManual = false;

            foreach ($questions as $q) {
                $ans = trim($_POST['q_' . $q['id']] ?? '');
                if ($ans === '') continue;

                $isCorrect = null;
                $scoreAwarded = 0;

                if ($q['type']==='mcq' || $q['type']==='true_false') {
                    $isCorrect    = (strtolower($ans) === strtolower($q['correct_answer'])) ? 1 : 0;
                    $scoreAwarded = $isCorrect ? $q['points'] : 0;
                    $autoScore   += $scoreAwarded;
                } else {
                    $hasManual = true; // short answer needs grading
                }

                $pdo->prepare("INSERT INTO exam_answers (submission_id,exam_question_id,student_answer,is_correct,score_awarded)
                                VALUES (?,?,?,?,?)
                                ON DUPLICATE KEY UPDATE student_answer=VALUES(student_answer),is_correct=VALUES(is_correct),score_awarded=VALUES(score_awarded)")
                    ->execute([$subId,$q['id'],$ans,$isCorrect,$scoreAwarded]);
            }

            $totalPts  = $exam['total_points'] ?: 1;
            $pct       = round(($autoScore / $totalPts) * 100, 2);
            $passed    = $pct >= $exam['passing_score'] ? 1 : 0;
            $newStatus = $hasManual ? 'submitted' : 'graded';
            $timeSpent = time() - strtotime($sub['started_at']);

            $pdo->prepare("UPDATE exam_submissions SET status=?,submitted_at=NOW(),auto_score=?,total_score=?,percentage=?,passed=?,time_spent_sec=? WHERE id=?")
                ->execute([$newStatus,$autoScore,$autoScore,$pct,$passed,$timeSpent,$subId]);

            auditLog('exam.submitted','exam_submission',$subId,['exam_id'=>$eid,'score'=>$autoScore,'pct'=>$pct]);
            redirect("result.php?exam=$eid&student={$student['id']}");
        }
    }
    redirect("take.php?id=$eid");
}

// Start new attempt or resume
if (!$submission || $submission['status']==='submitted' || $submission['status']==='graded') {
    // Check max attempts
    $attCount = $pdo->prepare("SELECT COUNT(*) FROM exam_submissions WHERE exam_id=? AND student_id=? AND status IN('submitted','graded')");
    $attCount->execute([$eid,$student['id']]);
    if ($attCount->fetchColumn() >= $exam['max_attempts']) {
        flash('info','You have reached the maximum number of attempts.');
        redirect("result.php?exam=$eid&student={$student['id']}");
    }
    // Start fresh
    $attempt = $submission ? $submission['attempt_number']+1 : 1;
    $pdo->prepare("INSERT INTO exam_submissions (exam_id,student_id,center_id,attempt_number,status,started_at,ip_address) VALUES (?,?,?,?,'in_progress',NOW(),?)")
        ->execute([$eid,$student['id'],$cid,$attempt,$_SERVER['REMOTE_ADDR']??null]);
    $subId = $pdo->lastInsertId();
    redirect("take.php?id=$eid"); // reload to fetch submission
}

if ($submission['status']==='in_progress') {
    // Check time hasn't run out
    $elapsed = time() - strtotime($submission['started_at']);
    $limit   = $exam['duration_minutes'] * 60;
    if ($elapsed >= $limit) {
        // Auto-submit expired session
        $pdo->prepare("UPDATE exam_submissions SET status='submitted',submitted_at=NOW(),time_spent_sec=? WHERE id=?")->execute([$limit,$submission['id']]);
        flash('info','Time ran out — your exam was auto-submitted.');
        redirect("result.php?exam=$eid&student={$student['id']}");
    }
    $timeRemaining = $limit - $elapsed;
}

// Load questions
$qStmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id=? ORDER BY sort_order,id");
$qStmt->execute([$eid]);
$questions = $qStmt->fetchAll();
if ($exam['shuffle_questions']) shuffle($questions);

// Load already-saved answers for this submission
$savedStmt = $pdo->prepare("SELECT exam_question_id,student_answer FROM exam_answers WHERE submission_id=?");
$savedStmt->execute([$submission['id']]);
$savedAnswers = [];
foreach($savedStmt->fetchAll() as $sa) $savedAnswers[$sa['exam_question_id']] = $sa['student_answer'];

$pageTitle  = sanitize($exam['title']);
$activePage = 'exams';
$breadcrumbs = [['label'=>'Exams','href'=>'/educore/modules/exams/student.php'],['label'=>$pageTitle]];
$timeRemaining = isset($timeRemaining) ? $timeRemaining : ($exam['duration_minutes']*60);

include __DIR__ . '/../../templates/layout.php';
?>

<!-- Sticky header with timer -->
<div style="position:sticky;top:0;z-index:50;background:var(--bg-surface);border-bottom:1px solid var(--border);padding:12px 0;margin-bottom:24px;">
  <div style="max-width:800px;margin:0 auto;padding:0 24px;display:flex;justify-content:space-between;align-items:center;">
    <div>
      <div style="font-family:var(--font-display);font-weight:700;color:var(--text-primary);"><?=sanitize($exam['title'])?></div>
      <div style="font-size:0.75rem;color:var(--text-muted);"><?=count($questions)?> questions · <?=number_format($exam['total_points'],1)?> pts</div>
    </div>
    <div style="text-align:center;">
      <div style="font-size:0.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:2px;">Time Remaining</div>
      <div id="timerDisplay" style="font-family:var(--font-display);font-size:1.6rem;font-weight:800;color:var(--accent);">--:--</div>
    </div>
    <div>
      <button form="examForm" type="submit" name="action" value="submit" class="btn btn-primary" onclick="return confirm('Submit exam? You cannot change answers after submitting.')">
        Submit Exam
      </button>
    </div>
  </div>
</div>

<div style="max-width:800px;margin:0 auto;padding:0 24px;">

  <?php if($exam['instructions']): ?>
  <div class="card" style="margin-bottom:20px;border-color:rgba(245,166,35,0.25);">
    <div class="card-body" style="padding:14px 20px;font-size:0.84rem;color:var(--text-secondary);">
      <strong style="color:var(--accent);">📋 Instructions:</strong> <?=sanitize($exam['instructions'])?>
    </div>
  </div>
  <?php endif; ?>

  <form method="POST" id="examForm">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="submit">
    <input type="hidden" name="submission_id" value="<?=$submission['id']?>">

    <?php foreach($questions as $i => $q):
      $saved = $savedAnswers[$q['id']] ?? null;
      $opts  = ['a'=>$q['option_a'],'b'=>$q['option_b'],'c'=>$q['option_c'],'d'=>$q['option_d']];
    ?>
    <div class="card" style="margin-bottom:16px;" id="qCard_<?=$q['id']?>">
      <div class="card-body" style="padding:20px 24px;">
        <div style="display:flex;gap:12px;align-items:flex-start;">
          <div style="width:32px;height:32px;background:var(--bg-elevated);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.82rem;color:var(--text-muted);flex-shrink:0;"><?=$i+1?></div>
          <div style="flex:1;">
            <div style="font-weight:500;color:var(--text-primary);font-size:0.95rem;line-height:1.5;margin-bottom:14px;"><?=sanitize($q['question'])?></div>

            <?php if($q['type']==='mcq'): ?>
            <div style="display:flex;flex-direction:column;gap:8px;">
              <?php foreach($opts as $k=>$v): if(!$v) continue; ?>
              <label class="opt-label" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;transition:all 0.15s;font-size:0.88rem;">
                <input type="radio" name="q_<?=$q['id']?>" value="<?=$k?>" <?=$saved===$k?'checked':''?>
                       onchange="markAnswered(<?=$q['id']?>)"
                       style="accent-color:var(--accent);width:16px;height:16px;flex-shrink:0;">
                <span style="width:20px;height:20px;background:var(--bg-elevated);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:0.72rem;font-weight:700;color:var(--text-muted);flex-shrink:0;"><?=strtoupper($k)?></span>
                <?=sanitize($v)?>
              </label>
              <?php endforeach; ?>
            </div>

            <?php elseif($q['type']==='true_false'): ?>
            <div style="display:flex;gap:12px;">
              <?php foreach(['true'=>'✓ True','false'=>'✗ False'] as $v=>$l): ?>
              <label style="flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;font-size:0.88rem;font-weight:500;transition:all 0.15s;">
                <input type="radio" name="q_<?=$q['id']?>" value="<?=$v?>" <?=$saved===$v?'checked':''?>
                       onchange="markAnswered(<?=$q['id']?>)"
                       style="accent-color:var(--accent);width:16px;height:16px;">
                <?=$l?>
              </label>
              <?php endforeach; ?>
            </div>

            <?php else: // short answer ?>
            <textarea name="q_<?=$q['id']?>" class="form-control" rows="3"
                      placeholder="Type your answer here…"
                      onchange="markAnswered(<?=$q['id']?>)"
                      style="font-size:0.88rem;"><?=sanitize($saved??'')?></textarea>
            <?php endif; ?>
          </div>
          <div id="qDot_<?=$q['id']?>" style="width:10px;height:10px;border-radius:50%;background:<?=$saved?'var(--success)':'var(--bg-elevated)'?>;flex-shrink:0;margin-top:8px;transition:background 0.2s;" title="<?=$saved?'Answered':'Unanswered'?>"></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <div style="display:flex;justify-content:flex-end;margin-bottom:40px;">
      <button type="submit" name="action" value="submit" class="btn btn-primary btn-lg"
              onclick="return confirm('Submit exam? You cannot change answers after submitting.')">
        Submit Exam →
      </button>
    </div>
  </form>
</div>

<!-- Question progress nav -->
<div style="position:fixed;bottom:20px;right:20px;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-md);padding:12px;display:flex;flex-wrap:wrap;gap:4px;max-width:200px;z-index:40;box-shadow:var(--shadow-md);">
  <?php foreach($questions as $i => $q): ?>
  <a href="#qCard_<?=$q['id']?>" id="navDot_<?=$q['id']?>" style="width:26px;height:26px;border-radius:4px;background:<?=isset($savedAnswers[$q['id']])?'var(--success)':'var(--bg-elevated)'?>;color:<?=isset($savedAnswers[$q['id']])?'#fff':'var(--text-muted)'?>;font-size:0.68rem;font-weight:700;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:all 0.2s;"><?=$i+1?></a>
  <?php endforeach; ?>
</div>

<?php
$_csrfToken  = $_SESSION['csrf_token'] ?? '';
$extraScripts = <<<JS
<script>
// Timer
const endTime = Date.now() + {$timeRemaining}*1000;
const timerEl = document.getElementById('timerDisplay');

function updateTimer() {
  const rem = Math.max(0, Math.floor((endTime - Date.now())/1000));
  const m   = Math.floor(rem/60);
  const s   = rem%60;
  timerEl.textContent = m+':'+String(s).padStart(2,'0');
  if (rem < 120) timerEl.style.color = 'var(--danger)';
  else if (rem < 300) timerEl.style.color = 'var(--warning)';
  if (rem <= 0) {
    timerEl.textContent = '0:00';
    document.querySelector('[name="action"][value="submit"]')?.setAttribute('value','auto_submit');
    document.getElementById('examForm').submit();
    return;
  }
  setTimeout(updateTimer, 500);
}
updateTimer();

// Mark answered
function markAnswered(qid) {
  const dot    = document.getElementById('qDot_'+qid);
  const navDot = document.getElementById('navDot_'+qid);
  if (dot) { dot.style.background='var(--success)'; dot.title='Answered'; }
  if (navDot) { navDot.style.background='var(--success)'; navDot.style.color='#fff'; }
}

// Style radio options on click
document.querySelectorAll('.opt-label').forEach(label => {
  label.addEventListener('click', () => {
    const name = label.querySelector('input')?.name;
    if (!name) return;
    document.querySelectorAll('input[name="'+name+'"]').forEach(inp => {
      inp.closest('.opt-label')?.style.setProperty('border-color','var(--border)');
      inp.closest('.opt-label')?.style.setProperty('background','transparent');
    });
    label.style.borderColor = 'var(--accent)';
    label.style.background  = 'var(--accent-subtle)';
  });
});

// Pre-style already-checked options
document.querySelectorAll('.opt-label input:checked').forEach(inp => {
  inp.closest('.opt-label').style.borderColor = 'var(--accent)';
  inp.closest('.opt-label').style.background  = 'var(--accent-subtle)';
});

// ── Focus / blur tracking ─────────────────────────────────────────────────
const _focusData = {
  examId: {$eid},
  subId:  {$submission['id']},
  token:  '{$_csrfToken}'
};

function logFocusEvent(event) {
  fetch('/educore/modules/exams/focus_log.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'exam_id=' + _focusData.examId
        + '&submission_id=' + _focusData.subId
        + '&event=' + encodeURIComponent(event)
        + '&csrf_token=' + encodeURIComponent(_focusData.token),
    keepalive: true
  }).catch(()=>{});
}

// Detect tab switch / window minimize
document.addEventListener('visibilitychange', () => {
  logFocusEvent(document.hidden ? 'blur' : 'focus');
});
window.addEventListener('blur',  () => logFocusEvent('blur'));
window.addEventListener('focus', () => logFocusEvent('focus'));
</script>
JS;
include __DIR__ . '/../../templates/layout_end.php';
?>
