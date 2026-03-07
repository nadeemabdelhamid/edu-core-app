<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher');

$pdo = getDB();
$cid = centerId();
$uid = authId();

$pageTitle  = 'Question Bank';
$activePage = 'exams';
$breadcrumbs = [
    ['label'=>'Dashboard','href'=>'/educore/dashboard.php'],
    ['label'=>'Exams','href'=>'/educore/modules/exams/'],
    ['label'=>'Question Bank'],
];

$subject = trim($_GET['subject'] ?? '');
$type    = $_GET['type'] ?? '';
$search  = trim($_GET['q'] ?? '');
$page    = max(1,(int)($_GET['page']??1));

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if (in_array($action,['add','edit'])) {
        $qid   = (int)($_POST['qid'] ?? 0);
        $qtype = in_array($_POST['q_type']??'',['mcq','true_false','short_answer']) ? $_POST['q_type'] : 'mcq';
        $qtext = trim($_POST['q_text'] ?? '');
        $oa    = trim($_POST['opt_a'] ?? '');
        $ob    = trim($_POST['opt_b'] ?? '');
        $oc    = trim($_POST['opt_c'] ?? '');
        $od    = trim($_POST['opt_d'] ?? '');
        $ca    = trim($_POST['correct'] ?? '');
        $pts   = max(0.5,(float)($_POST['points']??1));
        $subj  = trim($_POST['subject'] ?? '');
        $topic = trim($_POST['topic'] ?? '');
        $expl  = trim($_POST['explanation'] ?? '');

        if ($qtext) {
            if ($action==='add') {
                $pdo->prepare("INSERT INTO question_bank (center_id,created_by,subject,topic,type,question,option_a,option_b,option_c,option_d,correct_answer,points,explanation)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$cid,$uid,$subj,$topic,$qtype,$qtext,$oa,$ob,$oc,$od,$ca,$pts,$expl]);
                flash('success','Question added to bank.');
            } else {
                $pdo->prepare("UPDATE question_bank SET subject=?,topic=?,type=?,question=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_answer=?,points=?,explanation=? WHERE id=? AND center_id=?")
                    ->execute([$subj,$topic,$qtype,$qtext,$oa,$ob,$oc,$od,$ca,$pts,$expl,$qid,$cid]);
                flash('success','Question updated.');
            }
        }
    }

    if ($action==='delete') {
        $qid = (int)($_POST['qid'] ?? 0);
        $pdo->prepare("UPDATE question_bank SET is_active=0 WHERE id=? AND center_id=?")->execute([$qid,$cid]);
        flash('success','Question removed from bank.');
    }

    redirect("question_bank.php?subject=".urlencode($subject)."&type=$type&q=".urlencode($search));
}

// Load questions
$where  = "WHERE qb.center_id=? AND qb.is_active=1";
$params = [$cid];
if (!isRole('owner')) { $where .= " AND qb.created_by=?"; $params[] = $uid; }
if ($subject) { $where .= " AND qb.subject=?"; $params[] = $subject; }
if ($type)    { $where .= " AND qb.type=?";    $params[] = $type; }
if ($search)  { $where .= " AND qb.question LIKE ?"; $params[] = "%$search%"; }

$query = "SELECT qb.*, u.name AS creator_name FROM question_bank qb JOIN users u ON u.id=qb.created_by $where ORDER BY qb.subject,qb.created_at DESC";
$result = paginate($query,$params,$page,25);
$questions = $result['data'];

// Subject list
$subjStmt = $pdo->prepare("SELECT DISTINCT subject FROM question_bank WHERE center_id=? AND is_active=1 AND subject IS NOT NULL AND subject!='' ORDER BY subject");
$subjStmt->execute([$cid]); $subjects = $subjStmt->fetchAll(PDO::FETCH_COLUMN);

$typeLabels = ['mcq'=>'MCQ','true_false'=>'True/False','short_answer'=>'Short Answer'];
$typeBadges = ['mcq'=>'badge-info','true_false'=>'badge-warning','short_answer'=>'badge-accent'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">Question Bank</h1><p class="page-subtitle"><?=number_format($result['total'])?> reusable questions</p></div>
  <button onclick="openModal('addModal')" class="btn btn-primary">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Question
  </button>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:13px 20px;">
    <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <input type="text" name="q" class="form-control" style="width:220px;" value="<?=sanitize($search)?>" placeholder="Search questions…">
      <select name="subject" class="form-control" style="width:160px;" onchange="this.form.submit()">
        <option value="">All Subjects</option>
        <?php foreach($subjects as $s): ?>
        <option value="<?=sanitize($s)?>" <?=$subject===$s?'selected':''?>><?=sanitize($s)?></option>
        <?php endforeach; ?>
      </select>
      <select name="type" class="form-control" style="width:150px;" onchange="this.form.submit()">
        <option value="">All Types</option>
        <?php foreach($typeLabels as $v=>$l): ?>
        <option value="<?=$v?>" <?=$type===$v?'selected':''?>><?=$l?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-ghost btn-sm">Search</button>
      <?php if($search||$subject||$type): ?><a href="question_bank.php" class="btn btn-ghost btn-sm" style="color:var(--danger);">✕ Clear</a><?php endif; ?>
      <span style="margin-left:auto;font-size:0.78rem;color:var(--text-muted);"><?=$result['total']?> questions</span>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body no-padding">
    <?php if($questions): ?>
    <?php foreach($questions as $q):
      $opts=['a'=>$q['option_a'],'b'=>$q['option_b'],'c'=>$q['option_c'],'d'=>$q['option_d']];
    ?>
    <div style="padding:14px 20px;border-bottom:1px solid var(--border);" class="bank-row">
      <div style="display:flex;gap:10px;align-items:flex-start;">
        <div style="flex:1;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
            <span class="badge <?=$typeBadges[$q['type']]?>" style="font-size:0.62rem;"><?=$typeLabels[$q['type']]?></span>
            <?php if($q['subject']): ?><span style="font-size:0.7rem;color:var(--text-muted);">📚 <?=sanitize($q['subject'])?></span><?php endif; ?>
            <?php if($q['topic']): ?><span style="font-size:0.7rem;color:var(--text-muted);">· <?=sanitize($q['topic'])?></span><?php endif; ?>
            <span style="font-size:0.7rem;color:var(--text-muted);margin-left:auto;"><?=number_format($q['points'],1)?> pt</span>
          </div>
          <div style="font-weight:500;color:var(--text-primary);font-size:0.88rem;margin-bottom:6px;"><?=sanitize($q['question'])?></div>
          <?php if($q['type']==='mcq'): ?>
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <?php foreach($opts as $k=>$v): if(!$v) continue; ?>
            <span style="font-size:0.75rem;<?=$q['correct_answer']===$k?'color:var(--success);font-weight:600;':'color:var(--text-muted);'?>"><?=strtoupper($k?>) <?=sanitize($v)?><?=$q['correct_answer']===$k?' ✓':''?></span>
            <?php endforeach; ?>
          </div>
          <?php elseif($q['type']==='true_false'): ?>
          <span style="font-size:0.75rem;color:var(--success);">Answer: <?=ucfirst($q['correct_answer']??'')?></span>
          <?php else: ?>
          <span style="font-size:0.72rem;color:var(--text-muted);font-style:italic;">Open-ended</span>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:4px;flex-shrink:0;">
          <button onclick="editBankQ(<?=$q['id']?>,<?=htmlspecialchars(json_encode($q),ENT_QUOTES)?>)" class="btn btn-ghost btn-icon btn-sm" title="Edit">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </button>
          <form method="POST" onsubmit="return confirm('Remove from bank?')">
            <?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="qid" value="<?=$q['id']?>">
            <button type="submit" class="btn btn-ghost btn-icon btn-sm" style="color:var(--danger);">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if($result['last_page']>1): ?>
    <div class="pagination">
      <span>Page <?=$result['current_page']?> of <?=$result['last_page']?></span>
      <div class="page-btns">
        <?php for($i=1;$i<=$result['last_page'];$i++): ?>
        <a href="?page=<?=$i?>&subject=<?=urlencode($subject)?>&type=<?=$type?>&q=<?=urlencode($search)?>" class="page-btn <?=$i===$result['current_page']?'active':''?>"><?=$i?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon">🗃</div><div class="empty-title">No questions yet</div><div class="empty-desc"><button onclick="openModal('addModal')" class="btn btn-primary btn-sm" style="margin-top:14px;">Add First Question</button></div></div>
    <?php endif; ?>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal-backdrop" id="addModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title" id="modalTitle">Add to Question Bank</span>
      <button class="modal-close" onclick="closeModal('addModal')">✕</button>
    </div>
    <div style="padding:20px 24px;overflow-y:auto;max-height:70vh;">
      <form method="POST" id="bankForm">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="add" id="bankFormAction">
        <input type="hidden" name="qid" id="bankQid" value="">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div class="form-group">
            <label class="form-label">Subject</label>
            <input type="text" name="subject" id="bSubject" class="form-control" placeholder="e.g. Mathematics" list="subjectList">
            <datalist id="subjectList">
              <?php foreach($subjects as $s): ?><option value="<?=sanitize($s)?>"><?php endforeach; ?>
            </datalist>
          </div>
          <div class="form-group">
            <label class="form-label">Topic</label>
            <input type="text" name="topic" id="bTopic" class="form-control" placeholder="e.g. Chapter 3">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label required">Type</label>
          <select name="q_type" id="bType" class="form-control" onchange="updateBankUI(this.value)">
            <option value="mcq">Multiple Choice</option>
            <option value="true_false">True / False</option>
            <option value="short_answer">Short Answer</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label required">Question</label>
          <textarea name="q_text" id="bText" class="form-control" rows="3" required placeholder="Enter the question…"></textarea>
        </div>

        <div id="bMcqOpts">
          <?php foreach(['a'=>'A','b'=>'B','c'=>'C','d'=>'D'] as $k=>$l): ?>
          <div class="form-group" style="margin-bottom:10px;">
            <label class="form-label" style="font-size:0.72rem;">Option <?=$l?></label>
            <input type="text" name="opt_<?=$k?>" id="bOpt_<?=$k?>" class="form-control" style="font-size:0.84rem;">
          </div>
          <?php endforeach; ?>
          <div class="form-group">
            <label class="form-label required">Correct Answer</label>
            <select name="correct" id="bCorrectMcq" class="form-control"><option value="a">A</option><option value="b">B</option><option value="c">C</option><option value="d">D</option></select>
          </div>
        </div>

        <div id="bTfOpts" style="display:none;">
          <div class="form-group">
            <label class="form-label required">Correct Answer</label>
            <select name="correct" id="bCorrectTf" class="form-control"><option value="true">True</option><option value="false">False</option></select>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div class="form-group">
            <label class="form-label">Points</label>
            <input type="number" name="points" id="bPoints" class="form-control" value="1" min="0.5" max="100" step="0.5">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Explanation</label>
          <input type="text" name="explanation" id="bExpl" class="form-control" placeholder="Shown after grading…">
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px;">
          <button type="button" onclick="closeModal('addModal')" class="btn btn-ghost">Cancel</button>
          <button type="submit" class="btn btn-primary" id="bankSubmitBtn">Add Question</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function updateBankUI(t) {
  document.getElementById('bMcqOpts').style.display = t==='mcq' ? '' : 'none';
  document.getElementById('bTfOpts').style.display  = t==='true_false' ? '' : 'none';
}
function editBankQ(id, data) {
  document.getElementById('bankFormAction').value = 'edit';
  document.getElementById('bankQid').value        = id;
  document.getElementById('modalTitle').textContent = 'Edit Question';
  document.getElementById('bankSubmitBtn').textContent = 'Save Changes';
  document.getElementById('bSubject').value = data.subject||'';
  document.getElementById('bTopic').value   = data.topic||'';
  document.getElementById('bType').value    = data.type;
  document.getElementById('bText').value    = data.question;
  document.getElementById('bPoints').value  = data.points;
  document.getElementById('bExpl').value    = data.explanation||'';
  ['a','b','c','d'].forEach(k => { const el=document.getElementById('bOpt_'+k); if(el) el.value=data['option_'+k]||''; });
  if (data.correct_answer) {
    if (data.type==='mcq')        document.getElementById('bCorrectMcq').value = data.correct_answer;
    if (data.type==='true_false') document.getElementById('bCorrectTf').value  = data.correct_answer;
  }
  updateBankUI(data.type);
  openModal('addModal');
}
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
