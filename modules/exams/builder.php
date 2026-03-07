<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher');

$pdo   = getDB();
$cid   = centerId();
$uid   = authId();
$eid   = (int)($_GET['id'] ?? 0);
if (!$eid) redirect('/educore/modules/exams/');

$eStmt = $pdo->prepare("SELECT * FROM exams WHERE id=? AND center_id=?");
$eStmt->execute([$eid, $cid]);
$exam  = $eStmt->fetch();
if (!$exam) { flash('error','Exam not found.'); redirect('/educore/modules/exams/'); }
if (!in_array($exam['status'],['draft','scheduled'])) { flash('error','Cannot edit an active or closed exam.'); redirect("view.php?id=$eid"); }

$pageTitle  = 'Builder — ' . sanitize($exam['title']);
$activePage = 'exams';
$breadcrumbs = [
    ['label'=>'Dashboard','href'=>'/educore/dashboard.php'],
    ['label'=>'Exams','href'=>'/educore/modules/exams/'],
    ['label'=>sanitize($exam['title']),'href'=>"view.php?id=$eid"],
    ['label'=>'Builder'],
];

// ── Handle question actions ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // Add / edit inline question
    if (in_array($action,['add_question','edit_question'])) {
        $qid   = (int)($_POST['question_id'] ?? 0);
        $type  = in_array($_POST['q_type']??'',['mcq','true_false','short_answer']) ? $_POST['q_type'] : 'mcq';
        $qtext = trim($_POST['q_text'] ?? '');
        $oa    = trim($_POST['opt_a'] ?? '');
        $ob    = trim($_POST['opt_b'] ?? '');
        $oc    = trim($_POST['opt_c'] ?? '');
        $od    = trim($_POST['opt_d'] ?? '');
        $ca    = trim($_POST['correct'] ?? '');
        $pts   = max(0.5, (float)($_POST['points'] ?? 1));
        $expl  = trim($_POST['explanation'] ?? '');

        if ($qtext) {
            if ($action==='add_question') {
                // Get next sort order
                $maxSort = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM exam_questions WHERE exam_id=?");
                $maxSort->execute([$eid]);
                $sort = $maxSort->fetchColumn();
                $pdo->prepare("INSERT INTO exam_questions (exam_id,type,question,option_a,option_b,option_c,option_d,correct_answer,points,explanation,sort_order)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$eid,$type,$qtext,$oa,$ob,$oc,$od,$ca,$pts,$expl,$sort]);
            } else {
                $pdo->prepare("UPDATE exam_questions SET type=?,question=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_answer=?,points=?,explanation=? WHERE id=? AND exam_id=?")
                    ->execute([$type,$qtext,$oa,$ob,$oc,$od,$ca,$pts,$expl,$qid,$eid]);
            }
            // Update total_points
            $pdo->prepare("UPDATE exams SET total_points=(SELECT COALESCE(SUM(points),0) FROM exam_questions WHERE exam_id=?) WHERE id=?")->execute([$eid,$eid]);
        }
    }

    // Delete question
    if ($action==='delete_question') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $pdo->prepare("DELETE FROM exam_questions WHERE id=? AND exam_id=?")->execute([$qid,$eid]);
        $pdo->prepare("UPDATE exams SET total_points=(SELECT COALESCE(SUM(points),0) FROM exam_questions WHERE exam_id=?) WHERE id=?")->execute([$eid,$eid]);
    }

    // Reorder (drag-drop saves array of ids)
    if ($action==='reorder') {
        $ids = $_POST['order'] ?? [];
        foreach ($ids as $i => $qid) {
            $pdo->prepare("UPDATE exam_questions SET sort_order=? WHERE id=? AND exam_id=?")->execute([$i,$qid,$eid]);
        }
        jsonResponse(['ok'=>true]);
    }

    // Import from question bank
    if ($action==='import_bank') {
        $bankIds = array_map('intval', $_POST['bank_ids'] ?? []);
        if ($bankIds) {
            $maxSort = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM exam_questions WHERE exam_id=?");
            $maxSort->execute([$eid]);
            $sort = (int)$maxSort->fetchColumn();
            foreach ($bankIds as $bid) {
                $bq = $pdo->prepare("SELECT * FROM question_bank WHERE id=? AND center_id=?");
                $bq->execute([$bid,$cid]);
                $bRow = $bq->fetch();
                if ($bRow) {
                    $sort++;
                    $pdo->prepare("INSERT INTO exam_questions (exam_id,question_id,type,question,option_a,option_b,option_c,option_d,correct_answer,points,explanation,sort_order)
                                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$eid,$bid,$bRow['type'],$bRow['question'],$bRow['option_a'],$bRow['option_b'],$bRow['option_c'],$bRow['option_d'],$bRow['correct_answer'],$bRow['points'],$bRow['explanation'],$sort]);
                }
            }
            $pdo->prepare("UPDATE exams SET total_points=(SELECT COALESCE(SUM(points),0) FROM exam_questions WHERE exam_id=?) WHERE id=?")->execute([$eid,$eid]);
        }
        flash('success', count($bankIds) . ' question(s) imported.');
    }

    // Activate exam
    if ($action==='activate') {
        $qCount = $pdo->prepare("SELECT COUNT(*) FROM exam_questions WHERE exam_id=?");
        $qCount->execute([$eid]);
        if ($qCount->fetchColumn() < 1) { flash('error','Add at least one question before activating.'); }
        else {
            $pdo->prepare("UPDATE exams SET status='active', starts_at=COALESCE(starts_at,NOW()) WHERE id=? AND center_id=?")->execute([$eid,$cid]);
            auditLog('exam.activated','exam',$eid);
            flash('success','Exam is now live!');
            redirect("view.php?id=$eid");
        }
    }

    redirect("builder.php?id=$eid");
}

// Load questions
$qStmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id=? ORDER BY sort_order,id");
$qStmt->execute([$eid]);
$questions = $qStmt->fetchAll();

// Load question bank for import modal
$bankStmt = $pdo->prepare("SELECT * FROM question_bank WHERE center_id=? AND is_active=1 ORDER BY subject,topic,id");
$bankStmt->execute([$cid]);
$bankQuestions = $bankStmt->fetchAll();

$typeLabels = ['mcq'=>'MCQ','true_false'=>'True/False','short_answer'=>'Short Answer'];
$typeBadges = ['mcq'=>'badge-info','true_false'=>'badge-warning','short_answer'=>'badge-accent'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?=sanitize($exam['title'])?></h1>
    <p class="page-subtitle">Question Builder · <?=count($questions)?> questions · <?=number_format($exam['total_points'],1)?> pts total</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="create.php?edit=<?=$eid?>" class="btn btn-ghost btn-sm">⚙ Settings</a>
    <button onclick="openModal('importModal')" class="btn btn-ghost btn-sm">↓ Import from Bank</button>
    <?php if(count($questions)>0): ?>
    <form method="POST" style="display:inline;" onsubmit="return confirm('Activate exam? Students can start taking it.')">
      <?=csrf_field()?><input type="hidden" name="action" value="activate">
      <button type="submit" class="btn btn-primary">🚀 Activate Exam</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;">

<!-- Questions list -->
<div>
  <?php if(!$questions): ?>
  <div class="card">
    <div class="empty-state" style="padding:50px 30px;">
      <div class="empty-icon">❓</div>
      <div class="empty-title">No questions yet</div>
      <div class="empty-desc">Use the form on the right to add your first question, or import from the question bank.</div>
    </div>
  </div>
  <?php else: ?>
  <div id="questionsList">
    <?php foreach($questions as $i => $q):
      $opts = ['a'=>$q['option_a'],'b'=>$q['option_b'],'c'=>$q['option_c'],'d'=>$q['option_d']];
    ?>
    <div class="card" style="margin-bottom:12px;" data-qid="<?=$q['id']?>">
      <div class="card-body" style="padding:16px 20px;">
        <div style="display:flex;gap:12px;align-items:flex-start;">
          <!-- Drag handle -->
          <div style="color:var(--text-muted);cursor:grab;padding-top:2px;flex-shrink:0;" title="Drag to reorder">⋮⋮</div>
          <!-- Number -->
          <div style="width:28px;height:28px;background:var(--accent-subtle);border:1px solid var(--accent);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:700;color:var(--accent);flex-shrink:0;"><?=$i+1?></div>
          <div style="flex:1;min-width:0;">
            <!-- Type + points -->
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
              <span class="badge <?=$typeBadges[$q['type']]?> " style="font-size:0.65rem;"><?=$typeLabels[$q['type']]?></span>
              <span style="font-size:0.72rem;color:var(--text-muted);"><?=number_format($q['points'],1)?> pt<?=$q['points']!=1?'s':''?></span>
              <?php if($q['question_id']): ?>
              <span style="font-size:0.65rem;color:var(--text-muted);background:var(--bg-elevated);padding:1px 6px;border-radius:10px;">From bank</span>
              <?php endif; ?>
            </div>
            <!-- Question text -->
            <div style="font-weight:500;color:var(--text-primary);margin-bottom:8px;line-height:1.5;"><?=sanitize($q['question'])?></div>
            <!-- Options -->
            <?php if($q['type']==='mcq'): ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;">
              <?php foreach($opts as $k=>$v): if(!$v) continue; ?>
              <div style="font-size:0.8rem;padding:4px 8px;border-radius:4px;<?=$q['correct_answer']===$k?'background:rgba(34,197,94,0.1);color:var(--success);font-weight:600;':'color:var(--text-secondary);'?>">
                <?=strtoupper($k)?>) <?=sanitize($v)?>
                <?php if($q['correct_answer']===$k): ?> ✓<?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
            <?php elseif($q['type']==='true_false'): ?>
            <div style="font-size:0.82rem;color:var(--text-muted);">Answer: <strong style="color:var(--success);"><?=ucfirst($q['correct_answer']??'')?></strong></div>
            <?php else: ?>
            <div style="font-size:0.78rem;color:var(--text-muted);font-style:italic;">Open-ended · manual grading required</div>
            <?php endif; ?>
            <?php if($q['explanation']): ?>
            <div style="margin-top:6px;font-size:0.75rem;color:var(--info);background:var(--info-dim);padding:4px 8px;border-radius:4px;">💡 <?=sanitize($q['explanation'])?></div>
            <?php endif; ?>
          </div>
          <!-- Actions -->
          <div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0;">
            <button onclick="editQuestion(<?=$q['id']?>,<?=htmlspecialchars(json_encode($q),ENT_QUOTES)?>)" class="btn btn-ghost btn-icon btn-sm" title="Edit">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>
            <form method="POST" onsubmit="return confirm('Delete this question?')">
              <?=csrf_field()?><input type="hidden" name="action" value="delete_question"><input type="hidden" name="question_id" value="<?=$q['id']?>">
              <button type="submit" class="btn btn-ghost btn-icon btn-sm" style="color:var(--danger);" title="Delete">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Add question panel -->
<div>
  <div class="card" id="questionFormCard" style="position:sticky;top:80px;">
    <div class="card-header">
      <span class="card-title" id="formTitle">Add Question</span>
      <button id="cancelEditBtn" onclick="cancelEdit()" class="btn btn-ghost btn-sm" style="display:none;">Cancel</button>
    </div>
    <div class="card-body">
      <form method="POST" id="questionForm">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="add_question" id="formAction">
        <input type="hidden" name="question_id" id="formQuestionId" value="">

        <div class="form-group">
          <label class="form-label required">Type</label>
          <select name="q_type" id="qType" class="form-control" onchange="updateTypeUI(this.value)">
            <option value="mcq">Multiple Choice (MCQ)</option>
            <option value="true_false">True / False</option>
            <option value="short_answer">Short Answer</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label required">Question</label>
          <textarea name="q_text" id="qText" class="form-control" rows="3" required placeholder="Enter the question…"></textarea>
        </div>

        <!-- MCQ options -->
        <div id="mcqOptions">
          <?php foreach(['a'=>'Option A','b'=>'Option B','c'=>'Option C','d'=>'Option D'] as $k=>$l): ?>
          <div class="form-group" style="margin-bottom:10px;">
            <label class="form-label" style="font-size:0.72rem;"><?=$l?></label>
            <input type="text" name="opt_<?=$k?>" id="opt_<?=$k?>" class="form-control" style="font-size:0.84rem;" placeholder="<?=$l?>…">
          </div>
          <?php endforeach; ?>
          <div class="form-group">
            <label class="form-label required">Correct Answer</label>
            <select name="correct" id="correctMcq" class="form-control">
              <option value="a">A</option><option value="b">B</option><option value="c">C</option><option value="d">D</option>
            </select>
          </div>
        </div>

        <!-- True/False -->
        <div id="tfOptions" style="display:none;">
          <div class="form-group">
            <label class="form-label required">Correct Answer</label>
            <select name="correct" id="correctTf" class="form-control">
              <option value="true">True</option><option value="false">False</option>
            </select>
          </div>
        </div>

        <!-- Short answer has no options -->

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Points</label>
            <input type="number" name="points" id="qPoints" class="form-control" value="1" min="0.5" max="100" step="0.5">
          </div>
        </div>

        <div class="form-group" style="margin-top:14px;">
          <label class="form-label">Explanation <span style="color:var(--text-muted);">(shown after grading)</span></label>
          <input type="text" name="explanation" id="qExplanation" class="form-control" placeholder="Optional explanation…">
        </div>

        <button type="submit" class="btn btn-primary w-full" id="submitQBtn" style="margin-top:4px;">Add Question</button>
      </form>
    </div>
  </div>
</div>
</div>

<!-- ── Import from bank modal ──────────────────────────────── -->
<div class="modal-backdrop" id="importModal">
  <div class="modal" style="max-width:680px;">
    <div class="modal-header">
      <span class="modal-title">Import from Question Bank</span>
      <button class="modal-close" onclick="closeModal('importModal')">✕</button>
    </div>
    <div style="padding:16px 24px;border-bottom:1px solid var(--border);">
      <input type="text" id="bankSearch" class="form-control" placeholder="Search questions…" oninput="filterBank(this.value)">
    </div>
    <form method="POST" id="importForm">
      <?=csrf_field()?><input type="hidden" name="action" value="import_bank">
      <div style="max-height:380px;overflow-y:auto;padding:8px 0;" id="bankList">
        <?php if($bankQuestions): ?>
        <?php foreach($bankQuestions as $bq): ?>
        <label class="bank-item" style="display:flex;align-items:flex-start;gap:10px;padding:10px 24px;cursor:pointer;border-bottom:1px solid var(--border);transition:background 0.1s;" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''" data-text="<?=strtolower(sanitize($bq['question']))?>">
          <input type="checkbox" name="bank_ids[]" value="<?=$bq['id']?>" style="margin-top:3px;accent-color:var(--accent);">
          <div>
            <div style="display:flex;gap:6px;margin-bottom:4px;">
              <span class="badge <?=$typeBadges[$bq['type']]?>" style="font-size:0.62rem;"><?=$typeLabels[$bq['type']]?></span>
              <?php if($bq['subject']): ?><span style="font-size:0.7rem;color:var(--text-muted);"><?=sanitize($bq['subject'])?></span><?php endif; ?>
              <span style="font-size:0.7rem;color:var(--text-muted);margin-left:auto;"><?=number_format($bq['points'],1)?> pt</span>
            </div>
            <div style="font-size:0.84rem;color:var(--text-primary);"><?=sanitize($bq['question'])?></div>
          </div>
        </label>
        <?php endforeach; ?>
        <?php else: ?>
        <div style="padding:30px;text-align:center;color:var(--text-muted);">No questions in bank yet. <a href="question_bank.php" style="color:var(--accent);">Add some first</a>.</div>
        <?php endif; ?>
      </div>
      <div style="padding:16px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeModal('importModal')" class="btn btn-ghost">Cancel</button>
        <button type="submit" class="btn btn-primary">Import Selected</button>
      </div>
    </form>
  </div>
</div>

<script>
function updateTypeUI(type) {
  document.getElementById('mcqOptions').style.display = type==='mcq' ? '' : 'none';
  document.getElementById('tfOptions').style.display  = type==='true_false' ? '' : 'none';
}

function editQuestion(id, data) {
  document.getElementById('formAction').value      = 'edit_question';
  document.getElementById('formQuestionId').value  = id;
  document.getElementById('formTitle').textContent = 'Edit Question';
  document.getElementById('submitQBtn').textContent= 'Save Changes';
  document.getElementById('cancelEditBtn').style.display = '';
  document.getElementById('questionFormCard').scrollIntoView({behavior:'smooth'});

  document.getElementById('qType').value        = data.type;
  document.getElementById('qText').value        = data.question;
  document.getElementById('qPoints').value      = data.points;
  document.getElementById('qExplanation').value = data.explanation||'';
  if (data.option_a) document.getElementById('opt_a').value = data.option_a;
  if (data.option_b) document.getElementById('opt_b').value = data.option_b;
  if (data.option_c) document.getElementById('opt_c').value = data.option_c;
  if (data.option_d) document.getElementById('opt_d').value = data.option_d;
  if (data.correct_answer) {
    if (data.type==='mcq')        document.getElementById('correctMcq').value = data.correct_answer;
    if (data.type==='true_false') document.getElementById('correctTf').value  = data.correct_answer;
  }
  updateTypeUI(data.type);
}

function cancelEdit() {
  document.getElementById('formAction').value      = 'add_question';
  document.getElementById('formQuestionId').value  = '';
  document.getElementById('formTitle').textContent = 'Add Question';
  document.getElementById('submitQBtn').textContent= 'Add Question';
  document.getElementById('cancelEditBtn').style.display = 'none';
  document.getElementById('questionForm').reset();
  updateTypeUI('mcq');
}

function filterBank(q) {
  q = q.toLowerCase();
  document.querySelectorAll('.bank-item').forEach(el => {
    el.style.display = el.dataset.text.includes(q) ? '' : 'none';
  });
}
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
