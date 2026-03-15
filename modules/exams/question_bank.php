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
$type    = $_GET['type']    ?? '';
$search  = trim($_GET['q']  ?? '');
$page    = max(1,(int)($_GET['page']??1));

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ── Add / Edit ────────────────────────────────────────────────────────────
    if (in_array($action,['add','edit'])) {
        $qid   = (int)($_POST['qid'] ?? 0);
        $qtype = in_array($_POST['q_type']??'',['mcq','true_false','short_answer'])
                    ? $_POST['q_type'] : 'mcq';
        $qtext = trim($_POST['q_text'] ?? '');
        $oa    = trim($_POST['opt_a']  ?? '');
        $ob    = trim($_POST['opt_b']  ?? '');
        $oc    = trim($_POST['opt_c']  ?? '');
        $od    = trim($_POST['opt_d']  ?? '');
        $ca    = trim($_POST['correct']?? '');
        $pts   = max(0.5,(float)($_POST['points']??1));
        $subj  = trim($_POST['subject']     ?? '');
        $topic = trim($_POST['topic']       ?? '');
        $expl  = trim($_POST['explanation'] ?? '');
        $diff  = in_array($_POST['difficulty']??'',['easy','medium','hard'])
                    ? $_POST['difficulty'] : 'medium';
        $tags  = trim($_POST['tags'] ?? '');

        if ($qtext) {
            if ($action==='add') {
                $pdo->prepare("INSERT INTO question_bank
                    (center_id,created_by,subject,topic,type,question,
                     option_a,option_b,option_c,option_d,correct_answer,
                     points,explanation,difficulty,tags)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$cid,$uid,$subj,$topic,$qtype,$qtext,
                               $oa,$ob,$oc,$od,$ca,$pts,$expl,$diff,$tags]);
                flash('success','Question added to bank.');
            } else {
                // ownership check
                $own = $pdo->prepare("SELECT id FROM question_bank WHERE id=? AND center_id=? AND (created_by=? OR ?)");
                $own->execute([$qid,$cid,$uid,isRole('owner')?1:0]);
                if ($own->fetch()) {
                    $pdo->prepare("UPDATE question_bank
                        SET subject=?,topic=?,type=?,question=?,
                            option_a=?,option_b=?,option_c=?,option_d=?,
                            correct_answer=?,points=?,explanation=?,difficulty=?,tags=?
                        WHERE id=? AND center_id=?")
                        ->execute([$subj,$topic,$qtype,$qtext,
                                   $oa,$ob,$oc,$od,$ca,$pts,$expl,$diff,$tags,
                                   $qid,$cid]);
                    flash('success','Question updated.');
                }
            }
        }
    }

    // ── Duplicate ─────────────────────────────────────────────────────────────
    if ($action==='duplicate') {
        $qid = (int)($_POST['qid'] ?? 0);
        $src = $pdo->prepare("SELECT * FROM question_bank WHERE id=? AND center_id=?");
        $src->execute([$qid,$cid]); $q = $src->fetch();
        if ($q) {
            $pdo->prepare("INSERT INTO question_bank
                (center_id,created_by,subject,topic,type,question,
                 option_a,option_b,option_c,option_d,correct_answer,
                 points,explanation,difficulty,tags)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$cid,$uid,$q['subject'],$q['topic'],$q['type'],
                           'Copy of '.$q['question'],
                           $q['option_a'],$q['option_b'],$q['option_c'],$q['option_d'],
                           $q['correct_answer'],$q['points'],$q['explanation'],
                           $q['difficulty']??'medium',$q['tags']??'']);
            flash('success','Question duplicated.');
        }
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    if ($action==='delete') {
        $qid = (int)($_POST['qid'] ?? 0);
        $pdo->prepare("UPDATE question_bank SET is_active=0 WHERE id=? AND center_id=?")
            ->execute([$qid,$cid]);
        flash('success','Question removed from bank.');
    }

    // ── Bulk delete ───────────────────────────────────────────────────────────
    if ($action==='bulk_delete') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if ($ids) {
            $ph = implode(',', array_fill(0,count($ids),'?'));
            $params = array_merge($ids, [$cid]);
            $pdo->prepare("UPDATE question_bank SET is_active=0 WHERE id IN($ph) AND center_id=?")
                ->execute($params);
            flash('success',count($ids).' question(s) removed.');
        }
    }

    redirect("question_bank.php?subject=".urlencode($subject)."&type=$type&q=".urlencode($search)."&page=$page");
}

// ── Load data ─────────────────────────────────────────────────────────────────
$where  = "WHERE qb.center_id=? AND qb.is_active=1";
$params = [$cid];
if (!isRole('owner')) { $where .= " AND qb.created_by=?"; $params[] = $uid; }
if ($subject) { $where .= " AND qb.subject=?"; $params[] = $subject; }
if ($type)    { $where .= " AND qb.type=?";    $params[] = $type; }
if ($search)  { $where .= " AND (qb.question LIKE ? OR qb.tags LIKE ? OR qb.topic LIKE ?)";
                $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }

$query = "SELECT qb.*, u.name AS creator_name,
          (SELECT COUNT(*) FROM exam_questions eq WHERE eq.question_id=qb.id) AS used_count
          FROM question_bank qb
          JOIN users u ON u.id=qb.created_by
          $where
          ORDER BY qb.subject,qb.topic,qb.created_at DESC";

$result    = paginate($query,$params,$page,20);
$questions = $result['data'];

// Subject list for filter
$subjStmt = $pdo->prepare("SELECT DISTINCT subject FROM question_bank
    WHERE center_id=? AND is_active=1 AND subject IS NOT NULL AND subject!=''
    ORDER BY subject");
$subjStmt->execute([$cid]);
$subjects = $subjStmt->fetchAll(PDO::FETCH_COLUMN);

// Stats
$statsStmt = $pdo->prepare("SELECT
    COUNT(*) AS total,
    SUM(type='mcq') AS mcq_cnt,
    SUM(type='true_false') AS tf_cnt,
    SUM(type='short_answer') AS sa_cnt,
    COUNT(DISTINCT subject) AS subj_cnt,
    SUM((SELECT COUNT(*) FROM exam_questions eq WHERE eq.question_id=question_bank.id)>0) AS used_cnt
    FROM question_bank WHERE center_id=? AND is_active=1");
$statsStmt->execute([$cid]);
$stats = $statsStmt->fetch();

$typeLabels = ['mcq'=>'MCQ','true_false'=>'True/False','short_answer'=>'Short Answer'];
$typeBadges = ['mcq'=>'badge-info','true_false'=>'badge-warning','short_answer'=>'badge-accent'];
$diffColors = ['easy'=>'var(--success)','medium'=>'var(--warning)','hard'=>'var(--danger)'];
$diffLabels = ['easy'=>'Easy','medium'=>'Medium','hard'=>'Hard'];

include __DIR__ . '/../../templates/layout.php';
?>

<style>
.qb-stats-bar {
    display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:22px;
}
.qb-stat {
    background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-md);
    padding:14px 18px;transition:border-color .2s;
}
.qb-stat:hover { border-color:var(--border-light); }
.qb-stat-val { font-family:var(--font-display);font-size:1.6rem;font-weight:800;color:var(--text-primary);line-height:1; }
.qb-stat-lbl { font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin-top:4px; }
.qb-stat-accent { border-left:3px solid var(--accent); }

.qb-row {
    padding:14px 20px;border-bottom:1px solid var(--border);
    transition:background .15s;display:flex;gap:12px;align-items:flex-start;
}
.qb-row:hover { background:var(--bg-hover); }
.qb-row:last-child { border-bottom:none; }

.qb-check {
    width:16px;height:16px;margin-top:3px;flex-shrink:0;
    accent-color:var(--accent);cursor:pointer;
}

.qb-meta { display:flex;align-items:center;gap:6px;margin-bottom:5px;flex-wrap:wrap; }
.qb-question { font-weight:500;color:var(--text-primary);font-size:.88rem;margin-bottom:6px;line-height:1.4; }
.qb-options { display:flex;gap:10px;flex-wrap:wrap; }
.qb-opt { font-size:.75rem; }
.qb-opt.correct { color:var(--success);font-weight:600; }
.qb-opt.wrong   { color:var(--text-muted); }

.qb-actions { display:flex;gap:4px;flex-shrink:0;margin-top:2px; }

.diff-dot {
    width:7px;height:7px;border-radius:50%;display:inline-block;margin-right:2px;
}

.used-badge {
    font-size:.65rem;padding:2px 6px;border-radius:10px;
    background:var(--accent-subtle);color:var(--accent);font-weight:600;
}
.unused-badge {
    font-size:.65rem;padding:2px 6px;border-radius:10px;
    background:var(--bg-elevated);color:var(--text-muted);
}

.bulk-bar {
    position:fixed;bottom:24px;left:50%;transform:translateX(-50%);
    background:var(--bg-card);border:1px solid var(--border-light);
    border-radius:var(--radius-md);padding:12px 20px;
    display:flex;align-items:center;gap:14px;
    box-shadow:var(--shadow-lg);z-index:200;
    transition:opacity .2s,transform .2s;
}
.bulk-bar.hidden { opacity:0;pointer-events:none;transform:translateX(-50%) translateY(10px); }
.bulk-count { font-weight:700;color:var(--text-primary);font-size:.85rem; }

.tab-pills { display:flex;gap:6px;flex-wrap:wrap; }
.tab-pill {
    padding:5px 14px;border-radius:20px;font-size:.75rem;font-weight:600;
    border:1px solid var(--border);color:var(--text-muted);cursor:pointer;
    text-decoration:none;transition:all .15s;
}
.tab-pill:hover,.tab-pill.active {
    border-color:var(--accent);color:var(--accent);background:var(--accent-subtle);
}

.modal-backdrop { display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:300;
    align-items:center;justify-content:center; }
.modal-backdrop.open { display:flex; }
.modal { background:var(--bg-card);border:1px solid var(--border-light);border-radius:var(--radius-lg);
    width:min(600px,95vw);max-height:90vh;overflow:hidden;display:flex;flex-direction:column;
    box-shadow:var(--shadow-lg); }
.modal-header { display:flex;align-items:center;justify-content:space-between;
    padding:18px 24px;border-bottom:1px solid var(--border); }
.modal-title { font-family:var(--font-display);font-size:1rem;font-weight:700;color:var(--text-primary); }
.modal-close { background:none;border:none;color:var(--text-muted);cursor:pointer;
    font-size:1.2rem;line-height:1;padding:4px; }
.modal-close:hover { color:var(--text-primary); }
.modal-body { padding:20px 24px;overflow-y:auto;flex:1; }
.modal-footer { padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end; }

.opt-row { display:flex;align-items:center;gap:10px;margin-bottom:8px; }
.opt-row .form-control { flex:1;font-size:.84rem; }
.correct-mark { font-size:.75rem;padding:2px 8px;border-radius:var(--radius-sm);border:1px solid var(--border);
    cursor:pointer;color:var(--text-muted);white-space:nowrap; }
.correct-mark.on { border-color:var(--success);color:var(--success);background:rgba(34,197,94,.1); }

.section-divider {
    padding:8px 20px;background:var(--bg-elevated);
    border-bottom:1px solid var(--border);
    display:flex;align-items:center;gap:8px;
}
.section-label { font-size:.68rem;font-weight:700;text-transform:uppercase;
    letter-spacing:.1em;color:var(--accent); }
.section-count { font-size:.68rem;color:var(--text-muted); }
</style>

<div class="page-header">
  <div>
    <h1 class="page-title">Question Bank</h1>
    <p class="page-subtitle"><?=number_format($stats['total']??0)?> reusable questions across <?=intval($stats['subj_cnt']??0)?> subjects</p>
  </div>
  <div style="display:flex;gap:8px;">
    <button onclick="openAdd()" class="btn btn-primary">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Question
    </button>
  </div>
</div>

<!-- Stats bar -->
<div class="qb-stats-bar">
  <div class="qb-stat qb-stat-accent">
    <div class="qb-stat-val"><?=number_format($stats['total']??0)?></div>
    <div class="qb-stat-lbl">Total Questions</div>
  </div>
  <div class="qb-stat">
    <div class="qb-stat-val" style="color:var(--info);"><?=intval($stats['mcq_cnt']??0)?></div>
    <div class="qb-stat-lbl">MCQ</div>
  </div>
  <div class="qb-stat">
    <div class="qb-stat-val" style="color:var(--warning);"><?=intval($stats['tf_cnt']??0)?></div>
    <div class="qb-stat-lbl">True / False</div>
  </div>
  <div class="qb-stat">
    <div class="qb-stat-val" style="color:var(--accent);"><?=intval($stats['sa_cnt']??0)?></div>
    <div class="qb-stat-lbl">Short Answer</div>
  </div>
  <div class="qb-stat">
    <div class="qb-stat-val" style="color:var(--success);"><?=intval($stats['used_cnt']??0)?></div>
    <div class="qb-stat-lbl">Used in Exams</div>
  </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:12px 20px;">
    <form method="GET" id="filterForm" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <input type="text" name="q" class="form-control" style="width:220px;"
             value="<?=sanitize($search)?>" placeholder="Search questions, topics, tags…"
             oninput="debounceFilter()">
      <select name="subject" class="form-control" style="width:160px;" onchange="this.form.submit()">
        <option value="">All Subjects</option>
        <?php foreach($subjects as $s): ?>
        <option value="<?=sanitize($s)?>" <?=$subject===$s?'selected':''?>><?=sanitize($s)?></option>
        <?php endforeach; ?>
      </select>
      <div class="tab-pills">
        <a href="?<?=http_build_query(array_merge($_GET,['type'=>'','page'=>1]))?>"
           class="tab-pill <?=$type===''?'active':''?>">All</a>
        <a href="?<?=http_build_query(array_merge($_GET,['type'=>'mcq','page'=>1]))?>"
           class="tab-pill <?=$type==='mcq'?'active':''?>">MCQ</a>
        <a href="?<?=http_build_query(array_merge($_GET,['type'=>'true_false','page'=>1]))?>"
           class="tab-pill <?=$type==='true_false'?'active':''?>">T/F</a>
        <a href="?<?=http_build_query(array_merge($_GET,['type'=>'short_answer','page'=>1]))?>"
           class="tab-pill <?=$type==='short_answer'?'active':''?>">Open</a>
      </div>
      <?php if($search||$subject||$type): ?>
      <a href="question_bank.php" class="btn btn-ghost btn-sm" style="color:var(--danger);">✕ Clear</a>
      <?php endif; ?>
      <span style="margin-left:auto;font-size:.75rem;color:var(--text-muted);"><?=$result['total']?> found</span>
    </form>
  </div>
</div>

<!-- Question list -->
<form id="bulkForm" method="POST">
  <?=csrf_field()?>
  <input type="hidden" name="action" value="bulk_delete">

  <div class="card">
    <?php if($questions):

      // Group by subject for visual dividers
      $curSubj = null;
      foreach($questions as $q):
        $diff   = $q['difficulty'] ?? 'medium';
        $opts   = ['a'=>$q['option_a'],'b'=>$q['option_b'],'c'=>$q['option_c'],'d'=>$q['option_d']];
        $optLabels = ['a'=>optionLabel('a',$q['question']),'b'=>optionLabel('b',$q['question']),'c'=>optionLabel('c',$q['question']),'d'=>optionLabel('d',$q['question'])];
        $qSubj  = $q['subject'] ?: 'No Subject';

        if ($qSubj !== $curSubj):
          $curSubj = $qSubj;
          // Count questions in this group in current result
          $grpCnt = count(array_filter($questions, fn($x)=> ($x['subject']?:'No Subject') === $qSubj));
    ?>
    <div class="section-divider">
      <span class="section-label">📚 <?=sanitize($qSubj)?></span>
      <span class="section-count"><?=$grpCnt?> question<?=$grpCnt!==1?'s':''?></span>
    </div>
    <?php endif; ?>

    <div class="qb-row">
      <input type="checkbox" class="qb-check bulk-check" name="ids[]" value="<?=$q['id']?>"
             onchange="updateBulk()">

      <div style="flex:1;min-width:0;">
        <div class="qb-meta">
          <span class="badge <?=$typeBadges[$q['type']]?>" style="font-size:.62rem;"><?=$typeLabels[$q['type']]?></span>
          <?php if($q['topic']): ?>
          <span style="font-size:.7rem;color:var(--text-muted);">📌 <?=sanitize($q['topic'])?></span>
          <?php endif; ?>
          <span style="display:inline-flex;align-items:center;gap:3px;font-size:.7rem;color:<?=$diffColors[$diff]??'var(--text-muted)'?>;">
            <span class="diff-dot" style="background:<?=$diffColors[$diff]??'#888'?>;"></span>
            <?=$diffLabels[$diff]??ucfirst($diff)?>
          </span>
          <?php if($q['used_count']>0): ?>
          <span class="used-badge">Used <?=$q['used_count']?>×</span>
          <?php else: ?>
          <span class="unused-badge">Unused</span>
          <?php endif; ?>
          <span style="margin-left:auto;font-size:.7rem;color:var(--text-muted);"><?=number_format($q['points'],1)?> pt</span>
        </div>

        <div class="qb-question"><?=sanitize($q['question'])?></div>

        <?php if($q['type']==='mcq'): ?>
        <div class="qb-options">
          <?php foreach($opts as $k=>$v): if(!$v) continue; ?>
          <span class="qb-opt <?=$q['correct_answer']===$k?'correct':'wrong'?>">
            <?=$optLabels[$k]?>) <?=sanitize($v)?><?=$q['correct_answer']===$k?' ✓':''?>
          </span>
          <?php endforeach; ?>
        </div>
        <?php elseif($q['type']==='true_false'): ?>
        <span style="font-size:.75rem;color:var(--success);">Answer: <?=ucfirst($q['correct_answer']??'')?></span>
        <?php else: ?>
        <span style="font-size:.72rem;color:var(--text-muted);font-style:italic;">Open-ended response</span>
        <?php endif; ?>

        <?php if($q['explanation']): ?>
        <div style="font-size:.72rem;color:var(--text-muted);margin-top:4px;border-left:2px solid var(--border);padding-left:8px;">
          💡 <?=sanitize($q['explanation'])?>
        </div>
        <?php endif; ?>

        <?php if($q['tags']): ?>
        <div style="margin-top:5px;display:flex;gap:4px;flex-wrap:wrap;">
          <?php foreach(explode(',',sanitize($q['tags'])) as $tag): $tag=trim($tag); if(!$tag) continue; ?>
          <span style="font-size:.65rem;padding:2px 7px;border-radius:10px;background:var(--bg-elevated);color:var(--text-secondary);">#<?=$tag?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="qb-actions">
        <button type="button" onclick="openEdit(<?=$q['id']?>,<?=htmlspecialchars(json_encode($q),ENT_QUOTES)?>)"
                class="btn btn-ghost btn-icon btn-sm" title="Edit">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </button>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Duplicate this question?')">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="duplicate">
          <input type="hidden" name="qid"    value="<?=$q['id']?>">
          <button type="submit" class="btn btn-ghost btn-icon btn-sm" title="Duplicate">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
          </button>
        </form>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this question?')">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="qid"    value="<?=$q['id']?>">
          <button type="submit" class="btn btn-ghost btn-icon btn-sm" style="color:var(--danger);" title="Delete">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>
          </button>
        </form>
      </div>
    </div>

    <?php endforeach; ?>

    <!-- Pagination -->
    <?php if($result['last_page']>1): ?>
    <div class="pagination" style="padding:16px 20px;">
      <span style="font-size:.78rem;color:var(--text-muted);">Page <?=$result['current_page']?> of <?=$result['last_page']?></span>
      <div class="page-btns">
        <?php for($i=1;$i<=$result['last_page'];$i++): ?>
        <a href="?page=<?=$i?>&subject=<?=urlencode($subject)?>&type=<?=$type?>&q=<?=urlencode($search)?>"
           class="page-btn <?=$i===$result['current_page']?'active':''?>"><?=$i?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="empty-state" style="padding:60px 20px;">
      <div class="empty-icon">🗃️</div>
      <div class="empty-title">No questions yet</div>
      <div class="empty-desc">Build your reusable question library to quickly populate exams.</div>
      <button onclick="openAdd()" class="btn btn-primary btn-sm" style="margin-top:16px;">Add First Question</button>
    </div>
    <?php endif; ?>
  </div>

</form>

<!-- Bulk action bar -->
<div class="bulk-bar hidden" id="bulkBar">
  <span class="bulk-count" id="bulkCount">0 selected</span>
  <button type="button" onclick="bulkSelectAll()" class="btn btn-ghost btn-sm">Select All</button>
  <button type="button" onclick="bulkClear()" class="btn btn-ghost btn-sm">Clear</button>
  <button type="button" onclick="confirmBulkDelete()" class="btn btn-danger btn-sm">
    🗑 Delete Selected
  </button>
</div>

<!-- ── Add / Edit Modal ──────────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="bankModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title" id="modalTitle">Add to Question Bank</span>
      <button class="modal-close" type="button" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <form method="POST" id="bankForm">
        <?=csrf_field()?>
        <input type="hidden" name="action" id="bankAction" value="add">
        <input type="hidden" name="qid"    id="bankQid"    value="">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
          <div class="form-group">
            <label class="form-label">Subject</label>
            <input type="text" name="subject" id="bSubject" class="form-control"
                   placeholder="e.g. Mathematics" list="subjectList" autocomplete="off">
            <datalist id="subjectList">
              <?php foreach($subjects as $s): ?><option value="<?=sanitize($s)?>"><?php endforeach; ?>
            </datalist>
          </div>
          <div class="form-group">
            <label class="form-label">Topic / Chapter</label>
            <input type="text" name="topic" id="bTopic" class="form-control" placeholder="e.g. Algebra">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
          <div class="form-group" style="margin:0;">
            <label class="form-label required">Type</label>
            <select name="q_type" id="bType" class="form-control" onchange="updateTypeUI(this.value)">
              <option value="mcq">Multiple Choice</option>
              <option value="true_false">True / False</option>
              <option value="short_answer">Short Answer</option>
            </select>
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Difficulty</label>
            <select name="difficulty" id="bDiff" class="form-control">
              <option value="easy">Easy</option>
              <option value="medium" selected>Medium</option>
              <option value="hard">Hard</option>
            </select>
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Points</label>
            <input type="number" name="points" id="bPoints" class="form-control"
                   value="1" min="0.5" max="100" step="0.5">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label required">Question Text</label>
          <textarea name="q_text" id="bText" class="form-control" rows="3" required
                    placeholder="Enter the question…"></textarea>
        </div>

        <!-- MCQ options -->
        <div id="bMcqOpts">
          <div style="font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;
                      color:var(--text-muted);margin-bottom:10px;">Answer Options</div>
          <?php foreach(['a'=>'A / ا','b'=>'B / ب','c'=>'C / ج','d'=>'D / د'] as $k=>$l): ?>
          <div class="opt-row">
            <span style="font-size:.8rem;font-weight:700;color:var(--text-muted);width:16px;"><?=$l?></span>
            <input type="text" name="opt_<?=$k?>" id="bOpt_<?=$k?>" class="form-control"
                   placeholder="Option <?=$l?>…" style="flex:1;">
            <button type="button" class="correct-mark" id="bMark_<?=$k?>"
                    onclick="setCorrect('<?=$k?>')" data-opt="<?=$k?>">✓ Correct</button>
          </div>
          <?php endforeach; ?>
          <input type="hidden" name="correct" id="bCorrectMcq" value="a">
        </div>

        <!-- T/F options -->
        <div id="bTfOpts" style="display:none;">
          <div class="form-group">
            <label class="form-label required">Correct Answer</label>
            <div style="display:flex;gap:10px;">
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                <input type="radio" name="correct" id="bTfTrue"  value="true"  checked> True
              </label>
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                <input type="radio" name="correct" id="bTfFalse" value="false"> False
              </label>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Tags <span style="color:var(--text-muted);font-weight:400;">(comma-separated)</span></label>
          <input type="text" name="tags" id="bTags" class="form-control"
                 placeholder="e.g. fractions, chapter-3, midterm">
        </div>

        <div class="form-group">
          <label class="form-label">Explanation <span style="color:var(--text-muted);font-weight:400;">(shown after grading)</span></label>
          <textarea name="explanation" id="bExpl" class="form-control" rows="2"
                    placeholder="Optional explanation for the correct answer…"></textarea>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="closeModal()" class="btn btn-ghost">Cancel</button>
      <button type="button" onclick="document.getElementById('bankForm').submit()"
              class="btn btn-primary" id="bankSubmitBtn">Add Question</button>
    </div>
  </div>
</div>

<script>
// ── Modal ────────────────────────────────────────────────────────────────────
function openAdd() {
  document.getElementById('bankAction').value    = 'add';
  document.getElementById('bankQid').value       = '';
  document.getElementById('modalTitle').textContent = 'Add to Question Bank';
  document.getElementById('bankSubmitBtn').textContent = 'Add Question';
  document.getElementById('bankForm').reset();
  setCorrect('a');
  updateTypeUI('mcq');
  document.getElementById('bankModal').classList.add('open');
}

function openEdit(id, data) {
  document.getElementById('bankAction').value    = 'edit';
  document.getElementById('bankQid').value       = id;
  document.getElementById('modalTitle').textContent = 'Edit Question';
  document.getElementById('bankSubmitBtn').textContent = 'Save Changes';

  document.getElementById('bSubject').value = data.subject || '';
  document.getElementById('bTopic').value   = data.topic   || '';
  document.getElementById('bType').value    = data.type;
  document.getElementById('bDiff').value    = data.difficulty || 'medium';
  document.getElementById('bPoints').value  = data.points;
  document.getElementById('bText').value    = data.question;
  document.getElementById('bTags').value    = data.tags || '';
  document.getElementById('bExpl').value    = data.explanation || '';

  ['a','b','c','d'].forEach(k => {
    const el = document.getElementById('bOpt_'+k);
    if (el) el.value = data['option_'+k] || '';
  });

  updateTypeUI(data.type);

  if (data.type === 'mcq') {
    setCorrect(data.correct_answer || 'a');
  } else if (data.type === 'true_false') {
    document.getElementById(data.correct_answer==='true' ? 'bTfTrue' : 'bTfFalse').checked = true;
  }

  document.getElementById('bankModal').classList.add('open');
}

function closeModal() {
  document.getElementById('bankModal').classList.remove('open');
}

document.getElementById('bankModal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

// ── Type UI ───────────────────────────────────────────────────────────────────
function updateTypeUI(t) {
  document.getElementById('bMcqOpts').style.display = t === 'mcq'        ? '' : 'none';
  document.getElementById('bTfOpts').style.display  = t === 'true_false' ? '' : 'none';
}

// ── Correct option ─────────────────────────────────────────────────────────
function setCorrect(k) {
  document.getElementById('bCorrectMcq').value = k;
  ['a','b','c','d'].forEach(x => {
    const btn = document.getElementById('bMark_'+x);
    if (btn) btn.classList.toggle('on', x === k);
  });
}

// ── Bulk selection ────────────────────────────────────────────────────────
function updateBulk() {
  const checked = document.querySelectorAll('.bulk-check:checked');
  const bar     = document.getElementById('bulkBar');
  const count   = document.getElementById('bulkCount');
  count.textContent = checked.length + ' selected';
  bar.classList.toggle('hidden', checked.length === 0);
}

function bulkSelectAll() {
  document.querySelectorAll('.bulk-check').forEach(c => c.checked = true);
  updateBulk();
}

function bulkClear() {
  document.querySelectorAll('.bulk-check').forEach(c => c.checked = false);
  updateBulk();
}

function confirmBulkDelete() {
  const n = document.querySelectorAll('.bulk-check:checked').length;
  if (confirm('Delete ' + n + ' question(s)? This cannot be undone.')) {
    document.getElementById('bulkForm').submit();
  }
}

// ── Debounced search ───────────────────────────────────────────────────────
let searchTimer;
function debounceFilter() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => document.getElementById('filterForm').submit(), 500);
}
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
