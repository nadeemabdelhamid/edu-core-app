<?php
/**
 * take.php — Student exam-taking interface
 * Features: 1=password, 2=fullscreen, 3=nav panel, 4=bookmarks,
 *           7=auto-save, 12=multi-select, 20=reopen, 28=copy-block, 30=one-session
 */
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('student');

$pdo = getDB();
$uid = authId();
$cid = centerId();
$eid = (int)($_GET['id'] ?? 0);
if (!$eid) redirect('/educore/dashboard.php');

// ── Feature 30: Session check endpoint ───────────────────────────────────
if (isset($_GET['check_session'])) {
    $subId  = (int)($_GET['sub'] ?? 0);
    $token  = $_GET['token'] ?? '';
    $dbToken = '';
    try {
        // Look up student id for this user
        $stLookup = $pdo->prepare("SELECT id FROM students WHERE user_id=? AND center_id=? AND is_active=1");
        $stLookup->execute([$uid, $cid]);
        $stRow = $stLookup->fetch();
        $stId  = $stRow ? (int)$stRow['id'] : 0;
        if ($subId && $stId) {
            $t = $pdo->prepare("SELECT session_token FROM exam_submissions WHERE id=? AND student_id=?");
            $t->execute([$subId, $stId]);
            $dbToken = (string)($t->fetchColumn() ?: '');
        }
    } catch(Exception $e) {}
    jsonResponse(['kicked' => ($dbToken !== '' && $token !== '' && $dbToken !== $token)]);
}

$stStmt = $pdo->prepare("SELECT * FROM students WHERE user_id=? AND center_id=? AND is_active=1");
$stStmt->execute([$uid,$cid]);
$student = $stStmt->fetch();
if (!$student) { flash('error','Student profile not found.'); redirect('/educore/dashboard.php'); }

$eStmt = $pdo->prepare("SELECT e.*,g.name AS group_name FROM exams e LEFT JOIN `groups` g ON g.id=e.group_id WHERE e.id=? AND e.center_id=?");
$eStmt->execute([$eid,$cid]);
$exam = $eStmt->fetch();
if (!$exam) { flash('error','Exam not found.'); redirect('/educore/dashboard.php'); }

// Status gate
if ($exam['status']==='draft')    { flash('error','This exam is not yet available.'); redirect('/educore/dashboard.php'); }
if ($exam['status']==='archived') { flash('error','This exam is archived.');           redirect('/educore/dashboard.php'); }
if ($exam['status']==='scheduled' && $exam['starts_at'] && strtotime($exam['starts_at']) <= time()) {
    $pdo->prepare("UPDATE exams SET status='active' WHERE id=?")->execute([$eid]); $exam['status']='active';
}
if ($exam['status']==='active' && $exam['ends_at'] && strtotime($exam['ends_at']) < time()) {
    $pdo->prepare("UPDATE exams SET status='closed' WHERE id=?")->execute([$eid]); $exam['status']='closed';
}
if ($exam['status']==='closed') { redirect("result.php?exam=$eid&student={$student['id']}"); }
if ($exam['status']==='scheduled' && $exam['starts_at'] && strtotime($exam['starts_at']) > time()) {
    flash('error','This exam has not started yet.'); redirect('/educore/dashboard.php');
}

$isHidden = (int)($exam['is_hidden'] ?? 0);
$isOpen   = isset($exam['is_open']) ? (int)$exam['is_open'] : 1;
if ($isHidden) { flash('error','This exam is not currently available.'); redirect('/educore/dashboard.php'); }
if (!$isOpen)  { flash('error','The teacher has temporarily locked entry to this exam.'); redirect('/educore/dashboard.php'); }

if ($exam['group_id']) {
    $enrStmt = $pdo->prepare("SELECT id FROM group_students WHERE group_id=? AND student_id=? AND status='active'");
    $enrStmt->execute([$exam['group_id'],$student['id']]);
    if (!$enrStmt->fetch()) { flash('error','You are not enrolled in the group for this exam.'); redirect('/educore/dashboard.php'); }
}

// ── Feature 1: Password gate ──────────────────────────────────────────────
$examPassword  = $exam['exam_password'] ?? null;
$passwordPassed = !$examPassword;
$pwError = '';
if ($examPassword && !empty($_SESSION['exam_pw_'.$eid.'_'.$student['id']])) $passwordPassed = true;

// ── Feature 20: Reopen grant ──────────────────────────────────────────────
$reopenRow = null;
try {
    $rpStmt = $pdo->prepare("SELECT * FROM exam_reopens WHERE exam_id=? AND student_id=? AND used=0 LIMIT 1");
    $rpStmt->execute([$eid,$student['id']]);
    $reopenRow = $rpStmt->fetch() ?: null;
} catch(Exception $e) {}

// ── Existing submission ───────────────────────────────────────────────────
$subStmt = $pdo->prepare("SELECT * FROM exam_submissions WHERE exam_id=? AND student_id=? ORDER BY attempt_number DESC LIMIT 1");
$subStmt->execute([$eid,$student['id']]);
$submission = $subStmt->fetch();

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'submit';

    // Password verify
    if ($action==='verify_password') {
        if (trim($_POST['exam_password']??'') === $examPassword) {
            $_SESSION['exam_pw_'.$eid.'_'.$student['id']] = true;
            redirect("take.php?id=$eid");
        }
        $pwError = 'Incorrect password. Please try again.';
        $passwordPassed = false;
    }

    // Feature 7: Auto-save single answer
    if ($action==='autosave') {
        $subId = (int)($_POST['submission_id']??0);
        $qid   = (int)($_POST['qid']??0);
        $ans   = $_POST['answer'] ?? '';
        if ($subId && $qid) {
            $pdo->prepare("INSERT INTO exam_answers (submission_id,exam_question_id,student_answer,is_correct,score_awarded)
                            VALUES (?,?,?,NULL,0)
                            ON DUPLICATE KEY UPDATE student_answer=VALUES(student_answer)")
                ->execute([$subId,$qid,$ans]);
        }
        jsonResponse(['ok'=>true]);
    }

    // Feature 4: Bookmark toggle
    if ($action==='toggle_bookmark') {
        $subId = (int)($_POST['submission_id']??0);
        $qid   = (int)($_POST['qid']??0);
        if ($subId && $qid) {
            try {
                $ex = $pdo->prepare("SELECT id FROM exam_bookmarks WHERE submission_id=? AND question_id=?");
                $ex->execute([$subId,$qid]);
                if ($ex->fetch()) {
                    $pdo->prepare("DELETE FROM exam_bookmarks WHERE submission_id=? AND question_id=?")->execute([$subId,$qid]);
                    jsonResponse(['bookmarked'=>false]);
                } else {
                    $pdo->prepare("INSERT INTO exam_bookmarks (submission_id,question_id) VALUES (?,?)")->execute([$subId,$qid]);
                    jsonResponse(['bookmarked'=>true]);
                }
            } catch(Exception $e) { jsonResponse(['ok'=>false]); }
        }
    }

    // Submit / auto-submit
    $subId = (int)($_POST['submission_id']??0);
    if (in_array($action,['submit','auto_submit']) && $subId) {
        $sSt = $pdo->prepare("SELECT * FROM exam_submissions WHERE id=? AND student_id=? AND status='in_progress'");
        $sSt->execute([$subId,$student['id']]);
        $sub = $sSt->fetch();
        if ($sub) {
            $qStmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id=? ORDER BY sort_order,id");
            $qStmt->execute([$eid]);
            $questions = $qStmt->fetchAll();
            $autoScore = 0; $hasManual = false;
            foreach ($questions as $q) {
                $isMulti = !empty($q['is_multi_select']);
                if ($isMulti) {
                    $ansArr = array_filter(array_map('trim',(array)($_POST['q_'.$q['id']]??[])));
                    $ans = implode(',',$ansArr);
                } else {
                    $ans = trim($_POST['q_'.$q['id']]??'');
                }
                if ($ans==='') continue;
                $isCorrect=null; $scoreAwarded=0;
                if (in_array($q['type'],['mcq','true_false'])) {
                    if ($isMulti && !empty($q['correct_answers_multi'])) {
                        $correct=array_map('trim',explode(',',$q['correct_answers_multi']));
                        $given=array_map('trim',explode(',',$ans));
                        sort($correct); sort($given);
                        $isCorrect=($correct===$given)?1:0;
                    } else {
                        $isCorrect=(strtolower($ans)===strtolower($q['correct_answer']))?1:0;
                    }
                    $scoreAwarded=$isCorrect?$q['points']:0;
                    $autoScore+=$scoreAwarded;
                } else { $hasManual=true; }
                $pdo->prepare("INSERT INTO exam_answers (submission_id,exam_question_id,student_answer,is_correct,score_awarded)
                                VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE student_answer=VALUES(student_answer),is_correct=VALUES(is_correct),score_awarded=VALUES(score_awarded)")
                    ->execute([$subId,$q['id'],$ans,$isCorrect,$scoreAwarded]);
            }
            $tp=$exam['total_points']?:1;
            $pct=round($autoScore/$tp*100,2);
            $passed=$pct>=$exam['passing_score']?1:0;
            $newStatus=$hasManual?'submitted':'graded';
            $timeSpent=time()-strtotime($sub['started_at']);
            $pdo->prepare("UPDATE exam_submissions SET status=?,submitted_at=NOW(),auto_score=?,total_score=?,percentage=?,passed=?,time_spent_sec=?,session_token=NULL WHERE id=?")
                ->execute([$newStatus,$autoScore,$autoScore,$pct,$passed,$timeSpent,$subId]);
            auditLog('exam.submitted','exam_submission',$subId,['exam_id'=>$eid,'pct'=>$pct]);
            redirect("result.php?exam=$eid&student={$student['id']}");
        }
    }
    redirect("take.php?id=$eid");
}

// ── Start new attempt or resume ───────────────────────────────────────────
if (!$submission || in_array($submission['status'],['submitted','graded'])) {
    $attCount = $pdo->prepare("SELECT COUNT(*) FROM exam_submissions WHERE exam_id=? AND student_id=? AND status IN('submitted','graded')");
    $attCount->execute([$eid,$student['id']]);
    if ($attCount->fetchColumn() >= $exam['max_attempts'] && !$reopenRow) {
        flash('info','You have reached the maximum number of attempts.');
        redirect("result.php?exam=$eid&student={$student['id']}");
    }
    if ($reopenRow) $pdo->prepare("UPDATE exam_reopens SET used=1 WHERE id=?")->execute([$reopenRow['id']]);
    $attempt = $submission ? $submission['attempt_number']+1 : 1;
    // Feature 30: session token
    $sessionToken = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO exam_submissions (exam_id,student_id,center_id,attempt_number,status,started_at,ip_address,session_token) VALUES (?,?,?,?,'in_progress',NOW(),?,?)")
        ->execute([$eid,$student['id'],$cid,$attempt,$_SERVER['REMOTE_ADDR']??null,$sessionToken]);
    $_SESSION['exam_session_'.$eid] = $sessionToken;
    redirect("take.php?id=$eid");
}

// ── Feature 30: Enforce single session ───────────────────────────────────
if ($submission['status']==='in_progress') {
    $myToken = $_SESSION['exam_session_'.$eid] ?? null;
    $dbToken = $submission['session_token'] ?? null;
    if ($dbToken && $myToken && $myToken !== $dbToken) {
        flash('error','This exam was opened on another device. Only one active session is allowed.');
        redirect('/educore/modules/exams/student.php');
    }
    if ($dbToken && !$myToken) {
        $newToken = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE exam_submissions SET session_token=? WHERE id=?")->execute([$newToken,$submission['id']]);
        $_SESSION['exam_session_'.$eid] = $newToken;
        $submission['session_token'] = $newToken;
    }
    $elapsed = time()-strtotime($submission['started_at']);
    $limit   = $exam['duration_minutes']*60;
    if ($elapsed >= $limit) {
        $pdo->prepare("UPDATE exam_submissions SET status='submitted',submitted_at=NOW(),time_spent_sec=?,session_token=NULL WHERE id=?")->execute([$limit,$submission['id']]);
        flash('info','Time ran out — your exam was auto-submitted.');
        redirect("result.php?exam=$eid&student={$student['id']}");
    }
    $timeRemaining = $limit-$elapsed;
}

// Load questions + answers + bookmarks
$qStmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id=? ORDER BY sort_order,id");
$qStmt->execute([$eid]);
$questions = $qStmt->fetchAll();
if ($exam['shuffle_questions']) shuffle($questions);

$savedStmt = $pdo->prepare("SELECT exam_question_id,student_answer FROM exam_answers WHERE submission_id=?");
$savedStmt->execute([$submission['id']]);
$savedAnswers = [];
foreach($savedStmt->fetchAll() as $sa) $savedAnswers[$sa['exam_question_id']] = $sa['student_answer'];

$bookmarks = [];
try {
    $bStmt = $pdo->prepare("SELECT question_id FROM exam_bookmarks WHERE submission_id=?");
    $bStmt->execute([$submission['id']]);
    foreach($bStmt->fetchAll() as $b) $bookmarks[$b['question_id']] = true;
} catch(Exception $e) {}

$pageTitle    = sanitize($exam['title']);
$activePage   = 'exams';
$breadcrumbs  = [['label'=>'Exams','href'=>'/educore/modules/exams/student.php'],['label'=>$pageTitle]];
$timeRemaining = $timeRemaining ?? ($exam['duration_minutes']*60);
$forceFullscreen = (int)($exam['force_fullscreen'] ?? 0);
$blockCopyPaste  = (int)($exam['block_copy_paste'] ?? 0);
$_csrfToken = $_SESSION['csrf_token'] ?? '';
$totalQ = count($questions);
$answeredCount = count(array_filter($savedAnswers,fn($v)=>$v!==null&&$v!==''));
$bookmarkCount = count($bookmarks);
$dbSessionToken = $submission['session_token'] ?? '';

// ── Password gate page ────────────────────────────────────────────────────
if (!$passwordPassed) {
    include __DIR__ . '/../../templates/layout.php'; ?>
<div style="max-width:460px;margin:60px auto;padding:0 24px;">
  <div class="card">
    <div class="card-body" style="padding:36px 32px;text-align:center;">
      <div style="font-size:2.5rem;margin-bottom:16px;">🔐</div>
      <h2 style="font-family:var(--font-display);font-weight:700;margin-bottom:6px;"><?=sanitize($exam['title'])?></h2>
      <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:24px;">This exam is password-protected. Ask your teacher for the password.</p>
      <?php if($pwError): ?><div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,.25);border-radius:var(--radius-sm);padding:10px 14px;font-size:.82rem;color:var(--danger);margin-bottom:16px;"><?=sanitize($pwError)?></div><?php endif; ?>
      <form method="POST">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="verify_password">
        <div class="form-group" style="margin-bottom:16px;text-align:left;">
          <label class="form-label">Exam Password</label>
          <input type="password" name="exam_password" class="form-control" autofocus placeholder="Enter password…" required>
        </div>
        <button type="submit" class="btn btn-primary w-full btn-lg">Unlock Exam →</button>
      </form>
      <div style="margin-top:14px;"><a href="/educore/modules/exams/student.php" style="font-size:.78rem;color:var(--text-muted);">← Back to exams</a></div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../templates/layout_end.php'; exit; }

include __DIR__ . '/../../templates/layout.php'; ?>

<!-- Feature 2: Fullscreen warning -->
<?php if($forceFullscreen): ?>
<div id="fsWarning" style="display:none;position:fixed;top:0;left:0;right:0;z-index:1000;background:var(--danger);color:#fff;padding:14px 24px;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;">
  <span>⚠️ <strong>Fullscreen required.</strong> Exiting fullscreen is recorded as a violation.</span>
  <button onclick="enterFS();document.getElementById('fsWarning').style.display='none';" style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);color:#fff;padding:6px 16px;border-radius:6px;cursor:pointer;font-weight:600;">Return to Fullscreen</button>
</div>
<?php endif; ?>

<!-- Feature 28: Copy/paste warning toast -->
<?php if($blockCopyPaste): ?>
<div id="copyWarn" style="display:none;position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:var(--danger);color:#fff;padding:10px 20px;border-radius:var(--radius-md);font-size:.84rem;font-weight:600;z-index:500;align-items:center;gap:8px;box-shadow:var(--shadow-lg);">
  🚫 Copy/paste is disabled during this exam
</div>
<?php endif; ?>

<!-- Sticky header -->
<div id="examHeader" style="position:sticky;top:0;z-index:50;background:var(--bg-surface);border-bottom:1px solid var(--border);padding:12px 0;margin-bottom:24px;">
  <div style="max-width:860px;margin:0 auto;padding:0 24px;display:flex;justify-content:space-between;align-items:center;gap:12px;">
    <div style="min-width:0;">
      <div style="font-family:var(--font-display);font-weight:700;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=sanitize($exam['title'])?></div>
      <div style="font-size:.72rem;color:var(--text-muted);"><?=$totalQ?> questions · <?=number_format($exam['total_points'],1)?> pts</div>
    </div>
    <div style="text-align:center;flex-shrink:0;">
      <div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:1px;">Time Left</div>
      <div id="timerDisplay" style="font-family:var(--font-display);font-size:1.5rem;font-weight:800;color:var(--accent);">--:--</div>
    </div>
    <div style="display:flex;gap:8px;flex-shrink:0;align-items:center;">
      <?php if($forceFullscreen): ?>
      <button onclick="toggleFS()" class="btn btn-ghost btn-sm" title="Fullscreen" style="color:var(--warning);">⛶</button>
      <?php endif; ?>
      <button onclick="toggleNav()" class="btn btn-ghost btn-sm" title="Question navigator">
        ☰ <span id="hdrAnswered"><?=$answeredCount?></span>/<?=$totalQ?>
      </button>
      <button form="examForm" type="submit" name="action" value="submit" class="btn btn-primary"
              onclick="return confirm('Submit exam? You cannot change answers after submitting.')">Submit</button>
    </div>
  </div>
</div>

<!-- Feature 3: Navigation Panel -->
<div id="navPanel" style="position:fixed;top:0;right:-320px;width:300px;height:100vh;background:var(--bg-card);border-left:1px solid var(--border);z-index:200;overflow-y:auto;transition:right .25s;box-shadow:var(--shadow-lg);padding:20px;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
    <span style="font-weight:700;font-family:var(--font-display);">Question Navigator</span>
    <button onclick="toggleNav()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:1.1rem;">✕</button>
  </div>
  <div style="display:flex;gap:10px;font-size:.68rem;color:var(--text-muted);margin-bottom:12px;flex-wrap:wrap;">
    <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--success);margin-right:3px;vertical-align:middle;"></span>Answered</span>
    <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--warning);margin-right:3px;vertical-align:middle;"></span>Bookmarked</span>
    <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--bg-elevated);border:1px solid var(--border);margin-right:3px;vertical-align:middle;"></span>Unanswered</span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:5px;" id="navGrid">
    <?php foreach($questions as $i => $q):
      $isAns = isset($savedAnswers[$q['id']]) && $savedAnswers[$q['id']] !== '';
      $isBm  = isset($bookmarks[$q['id']]);
      $bg    = $isBm ? 'var(--warning)' : ($isAns ? 'var(--success)' : 'var(--bg-elevated)');
      $col   = ($isBm||$isAns) ? '#fff' : 'var(--text-muted)';
    ?>
    <a href="#qCard_<?=$q['id']?>" onclick="toggleNav()"
       id="nav_<?=$q['id']?>"
       data-bookmarked="<?=$isBm?'1':''?>"
       style="aspect-ratio:1;border-radius:5px;background:<?=$bg?>;color:<?=$col?>;font-size:.72rem;font-weight:700;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:all .15s;border:1px solid <?=$isBm?'var(--warning)':($isAns?'var(--success)':'var(--border)')?>;">
      <?=$i+1?>
    </a>
    <?php endforeach; ?>
  </div>
  <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border);font-size:.75rem;color:var(--text-muted);">
    <div>✅ <span id="navAns"><?=$answeredCount?></span> answered</div>
    <div style="margin:3px 0;">🔖 <span id="navBm"><?=$bookmarkCount?></span> bookmarked</div>
    <div>⬜ <span id="navUnans"><?=$totalQ-$answeredCount?></span> unanswered</div>
    <button form="examForm" type="submit" name="action" value="submit"
            onclick="return confirm('Submit exam?')"
            class="btn btn-primary w-full" style="margin-top:12px;font-size:.82rem;">Submit Exam →</button>
  </div>
</div>
<div id="navOverlay" onclick="toggleNav()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:199;"></div>

<!-- Main content -->
<div style="max-width:860px;margin:0 auto;padding:0 24px;">
  <?php if($exam['instructions']): ?>
  <div class="card" style="margin-bottom:20px;border-color:rgba(245,166,35,.25);">
    <div class="card-body" style="padding:14px 20px;font-size:.84rem;color:var(--text-secondary);">
      <strong style="color:var(--accent);">📋 Instructions:</strong> <?=sanitize($exam['instructions'])?>
    </div>
  </div>
  <?php endif; ?>

  <form method="POST" id="examForm">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="submit">
    <input type="hidden" name="submission_id" value="<?=$submission['id']?>">

    <?php foreach($questions as $i => $q):
      $saved     = $savedAnswers[$q['id']] ?? null;
      $opts      = ['a'=>$q['option_a'],'b'=>$q['option_b'],'c'=>$q['option_c'],'d'=>$q['option_d']];
      $isMulti   = !empty($q['is_multi_select']);
      $isBm      = isset($bookmarks[$q['id']]);
      $savedMulti= $isMulti ? array_filter(explode(',',$saved??'')) : [];
      $isAnswered= $isMulti ? !empty($savedMulti) : ($saved!==null&&$saved!=='');
    ?>
    <div class="card" id="qCard_<?=$q['id']?>" style="margin-bottom:16px;transition:border-color .2s;<?=$isBm?'border-color:rgba(245,158,11,.4);':''?>">
      <div class="card-body" style="padding:20px 24px;">
        <div style="display:flex;gap:12px;align-items:flex-start;">
          <div style="width:32px;height:32px;background:var(--bg-elevated);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.82rem;color:var(--text-muted);flex-shrink:0;"><?=$i+1?></div>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:500;color:var(--text-primary);font-size:.95rem;line-height:1.5;margin-bottom:<?=empty($q['question_image'])?'14px':'8px'?>;"><?=sanitize($q['question'])?></div>
            <?php if(!empty($q['question_image'])): ?>
            <div style="margin-bottom:14px;">
              <img src="<?=sanitize($q['question_image'])?>" alt="Question image"
                   style="max-width:100%;max-height:300px;border-radius:var(--radius-sm);border:1px solid var(--border);object-fit:contain;cursor:pointer;display:block;"
                   onclick="window.open(this.src,'_blank')" title="Click to enlarge">
              <div style="font-size:.65rem;color:var(--text-muted);margin-top:3px;">🔍 Click to enlarge</div>
            </div>
            <?php endif; ?>

            <?php if($isMulti): ?>
            <div style="font-size:.72rem;color:#60a5fa;background:rgba(96,165,250,.08);padding:4px 10px;border-radius:var(--radius-sm);margin-bottom:10px;display:inline-block;">☑ Select ALL correct answers</div>
            <?php endif; ?>

            <?php if($q['type']==='mcq'): ?>
            <div style="display:flex;flex-direction:column;gap:8px;">
              <?php foreach($opts as $k=>$v): if(!$v) continue; ?>
              <?php if($isMulti): $chk=in_array($k,$savedMulti); ?>
              <label class="opt-label" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid <?=$chk?'var(--accent)':'var(--border)'?>;border-radius:var(--radius-sm);cursor:pointer;transition:all .15s;font-size:.88rem;background:<?=$chk?'var(--accent-subtle)':'transparent'?>;">
                <input type="checkbox" name="q_<?=$q['id']?>[]" value="<?=$k?>" <?=$chk?'checked':''?>
                       onchange="onMultiChange(<?=$q['id']?>)"
                       style="accent-color:var(--accent);width:16px;height:16px;flex-shrink:0;">
                <span style="width:20px;height:20px;background:var(--bg-elevated);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;color:var(--text-muted);flex-shrink:0;"><?=strtoupper($k)?></span>
                <?=sanitize($v)?>
              </label>
              <?php else: $sel=$saved===$k; ?>
              <label class="opt-label" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid <?=$sel?'var(--accent)':'var(--border)'?>;border-radius:var(--radius-sm);cursor:pointer;transition:all .15s;font-size:.88rem;background:<?=$sel?'var(--accent-subtle)':'transparent'?>;">
                <input type="radio" name="q_<?=$q['id']?>" value="<?=$k?>" <?=$sel?'checked':''?>
                       onchange="onRadioChange(<?=$q['id']?>,this)"
                       style="accent-color:var(--accent);width:16px;height:16px;flex-shrink:0;">
                <span style="width:20px;height:20px;background:var(--bg-elevated);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;color:var(--text-muted);flex-shrink:0;"><?=strtoupper($k)?></span>
                <?=sanitize($v)?>
              </label>
              <?php endif; ?>
              <?php endforeach; ?>
            </div>

            <?php elseif($q['type']==='true_false'): ?>
            <div style="display:flex;gap:12px;">
              <?php foreach(['true'=>'✓ True','false'=>'✗ False'] as $v=>$l): $sel=$saved===$v; ?>
              <label style="flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;border:1px solid <?=$sel?'var(--accent)':'var(--border)'?>;border-radius:var(--radius-sm);cursor:pointer;font-size:.88rem;font-weight:500;transition:all .15s;background:<?=$sel?'var(--accent-subtle)':'transparent'?>;">
                <input type="radio" name="q_<?=$q['id']?>" value="<?=$v?>" <?=$sel?'checked':''?>
                       onchange="onRadioChange(<?=$q['id']?>,this)"
                       style="accent-color:var(--accent);width:16px;height:16px;"><?=$l?>
              </label>
              <?php endforeach; ?>
            </div>

            <?php else: ?>
            <textarea name="q_<?=$q['id']?>" class="form-control" rows="3"
                      placeholder="Type your answer here…"
                      oninput="onTextChange(<?=$q['id']?>,this.value)"
                      style="font-size:.88rem;"><?=sanitize($saved??'')?></textarea>
            <?php endif; ?>
          </div>

          <div style="display:flex;flex-direction:column;align-items:center;gap:6px;flex-shrink:0;margin-top:4px;">
            <!-- Feature 4: Bookmark -->
            <button type="button" id="bmBtn_<?=$q['id']?>" title="<?=$isBm?'Remove bookmark':'Bookmark'?>"
                    onclick="toggleBookmark(<?=$q['id']?>,<?=$submission['id']?>)"
                    style="background:none;border:none;cursor:pointer;font-size:1rem;line-height:1;opacity:<?=$isBm?1:.3?>;transition:opacity .2s;">🔖</button>
            <div id="qDot_<?=$q['id']?>"
                 data-answered="<?=$isAnswered?1:0?>"
                 style="width:10px;height:10px;border-radius:50%;background:<?=$isAnswered?'var(--success)':'var(--bg-elevated)'?>;transition:background .2s;"
                 title="<?=$isAnswered?'Answered':'Unanswered'?>"></div>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <div style="display:flex;justify-content:flex-end;margin-bottom:40px;">
      <button type="submit" name="action" value="submit" class="btn btn-primary btn-lg"
              onclick="return confirm('Submit exam? You cannot change answers after submitting.')">Submit Exam →</button>
    </div>
  </form>
</div>

<?php $extraScripts = <<<JS
<script>
// ── Timer ─────────────────────────────────────────────────────────────────
const endTime = Date.now() + {$timeRemaining}*1000;
const timerEl = document.getElementById('timerDisplay');
function tick() {
  const rem = Math.max(0,Math.floor((endTime-Date.now())/1000));
  timerEl.textContent = Math.floor(rem/60)+':'+String(rem%60).padStart(2,'0');
  if(rem<120) timerEl.style.color='var(--danger)';
  else if(rem<300) timerEl.style.color='var(--warning)';
  if(rem<=0){document.getElementById('examForm').querySelector('[name="action"]').value='auto_submit';document.getElementById('examForm').submit();return;}
  setTimeout(tick,500);
}
tick();

// ── State ─────────────────────────────────────────────────────────────────
let _ans={$answeredCount}, _bm={$bookmarkCount}, _total={$totalQ};
function syncCounts(){
  document.getElementById('hdrAnswered').textContent=_ans;
  document.getElementById('navAns').textContent=_ans;
  document.getElementById('navBm').textContent=_bm;
  document.getElementById('navUnans').textContent=_total-_ans;
}

function setDot(qid,on){
  const d=document.getElementById('qDot_'+qid);
  const n=document.getElementById('nav_'+qid);
  if(d){d.style.background=on?'var(--success)':'var(--bg-elevated)';d.dataset.answered=on?'1':'0';}
  if(n&&!n.dataset.bookmarked){n.style.background=on?'var(--success)':'var(--bg-elevated)';n.style.color=on?'#fff':'var(--text-muted)';n.style.borderColor=on?'var(--success)':'var(--border)';}
}

function onRadioChange(qid,inp){
  const was=document.getElementById('qDot_'+qid)?.dataset.answered==='1';
  if(!was){_ans++;setDot(qid,true);}
  syncCounts();
  // Style labels
  document.querySelectorAll('input[name="q_'+qid+'"]').forEach(i=>{
    const l=i.closest('.opt-label')||i.closest('label');
    if(l){l.style.borderColor='var(--border)';l.style.background='transparent';}
  });
  const lbl=inp.closest('.opt-label')||inp.closest('label');
  if(lbl){lbl.style.borderColor='var(--accent)';lbl.style.background='var(--accent-subtle)';}
  doAutoSave(qid,inp.value);
}

function onMultiChange(qid){
  const checks=document.querySelectorAll('input[name="q_'+qid+'[]"]:checked');
  const was=document.getElementById('qDot_'+qid)?.dataset.answered==='1';
  const now=checks.length>0;
  if(now&&!was){_ans++;}else if(!now&&was){_ans--;}
  setDot(qid,now);
  syncCounts();
  document.querySelectorAll('input[name="q_'+qid+'[]"]').forEach(i=>{
    const l=i.closest('.opt-label');
    if(l){l.style.borderColor=i.checked?'var(--accent)':'var(--border)';l.style.background=i.checked?'var(--accent-subtle)':'transparent';}
  });
  doAutoSave(qid,Array.from(checks).map(c=>c.value).join(','));
}

let _txtTimers={};
function onTextChange(qid,val){
  const was=document.getElementById('qDot_'+qid)?.dataset.answered==='1';
  const now=val.trim()!=='';
  if(now&&!was){_ans++;setDot(qid,true);syncCounts();}
  else if(!now&&was){_ans--;setDot(qid,false);syncCounts();}
  clearTimeout(_txtTimers[qid]);
  _txtTimers[qid]=setTimeout(()=>doAutoSave(qid,val),800);
}

// Feature 7: Auto-save
function doAutoSave(qid,value){
  fetch('/educore/modules/exams/take.php?id={$eid}',{
    method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=autosave&submission_id={$submission['id']}&qid='+qid+'&answer='+encodeURIComponent(value)+'&csrf_token={$_csrfToken}',
    keepalive:true
  }).catch(()=>{});
}

// Feature 3: Nav panel
let navOpen=false;
function toggleNav(){
  navOpen=!navOpen;
  document.getElementById('navPanel').style.right=navOpen?'0':'-320px';
  document.getElementById('navOverlay').style.display=navOpen?'block':'none';
}

// Feature 4: Bookmark
function toggleBookmark(qid,subId){
  fetch('/educore/modules/exams/take.php?id={$eid}',{
    method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=toggle_bookmark&submission_id='+subId+'&qid='+qid+'&csrf_token={$_csrfToken}'
  }).then(r=>r.json()).then(data=>{
    const btn=document.getElementById('bmBtn_'+qid);
    const card=document.getElementById('qCard_'+qid);
    const nav=document.getElementById('nav_'+qid);
    const dot=document.getElementById('qDot_'+qid);
    const isAns=dot?.dataset.answered==='1';
    if(data.bookmarked){
      btn.style.opacity='1';btn.title='Remove bookmark';
      card.style.borderColor='rgba(245,158,11,.4)';
      if(nav){nav.style.background='var(--warning)';nav.style.color='#fff';nav.style.borderColor='var(--warning)';nav.dataset.bookmarked='1';}
      _bm++;
    } else {
      btn.style.opacity='0.3';btn.title='Bookmark';
      card.style.borderColor='';
      if(nav){nav.style.background=isAns?'var(--success)':'var(--bg-elevated)';nav.style.color=isAns?'#fff':'var(--text-muted)';nav.style.borderColor=isAns?'var(--success)':'var(--border)';nav.dataset.bookmarked='';}
      _bm--;
    }
    syncCounts();
  }).catch(()=>{});
}

// Feature 2: Fullscreen
const _forceFS={$forceFullscreen};
function enterFS(){const e=document.documentElement;if(e.requestFullscreen)e.requestFullscreen();else if(e.webkitRequestFullscreen)e.webkitRequestFullscreen();}
function exitFS(){if(document.exitFullscreen)document.exitFullscreen();}
function toggleFS(){if(!document.fullscreenElement)enterFS();else exitFS();}
if(_forceFS){
  setTimeout(()=>{if(!document.fullscreenElement)enterFS();},600);
  document.addEventListener('fullscreenchange',()=>{
    const warn=document.getElementById('fsWarning');
    if(!document.fullscreenElement){if(warn)warn.style.display='flex';logFocus('blur');}
    else{if(warn)warn.style.display='none';logFocus('focus');}
  });
}

// Feature 28: Block copy/paste
if({$blockCopyPaste}){
  const showWarn=()=>{const w=document.getElementById('copyWarn');if(w){w.style.display='flex';clearTimeout(w._t);w._t=setTimeout(()=>w.style.display='none',2500);}};
  document.addEventListener('copy',e=>{e.preventDefault();showWarn();});
  document.addEventListener('cut',e=>{e.preventDefault();showWarn();});
  document.addEventListener('contextmenu',e=>e.preventDefault());
  document.addEventListener('keydown',e=>{
    if((e.ctrlKey||e.metaKey)&&['c','x'].includes(e.key.toLowerCase())){e.preventDefault();showWarn();}
  });
}

// Feature 30: Session heartbeat
setInterval(()=>{
  fetch('/educore/modules/exams/take.php?id={$eid}&check_session=1&sub={$submission['id']}&token='+encodeURIComponent('{$dbSessionToken}'))
  .then(r=>r.json()).then(d=>{if(d.kicked){alert('⚠️ This exam was opened on another device. This session has ended.');window.location.href='/educore/modules/exams/student.php';}}).catch(()=>{});
},15000);

// Focus/blur tracking
function logFocus(ev){
  fetch('/educore/modules/exams/focus_log.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'exam_id={$eid}&submission_id={$submission['id']}&event='+encodeURIComponent(ev)+'&csrf_token={$_csrfToken}',keepalive:true}).catch(()=>{});
}
document.addEventListener('visibilitychange',()=>logFocus(document.hidden?'blur':'focus'));
window.addEventListener('blur',()=>logFocus('blur'));
window.addEventListener('focus',()=>logFocus('focus'));
</script>
JS;
include __DIR__ . '/../../templates/layout_end.php';
?>
