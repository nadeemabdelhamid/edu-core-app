<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','assistant');

$pdo = getDB();
$cid = centerId();
$uid = authId();
$eid = (int)($_GET['id'] ?? 0);
if (!$eid) redirect('/educore/modules/exams/');

$eStmt = $pdo->prepare("SELECT e.*,g.name AS group_name,u.name AS creator_name
    FROM exams e
    LEFT JOIN `groups` g ON g.id=e.group_id
    LEFT JOIN users u ON u.id=e.created_by
    WHERE e.id=? AND e.center_id=?");
$eStmt->execute([$eid,$cid]);
$exam = $eStmt->fetch();
if (!$exam) { flash('error','Exam not found.'); redirect('/educore/modules/exams/'); }

$pageTitle  = sanitize($exam['title']);
$activePage = 'exams';
$breadcrumbs = [
    ['label'=>'Dashboard','href'=>'/educore/dashboard.php'],
    ['label'=>'Exams','href'=>'/educore/modules/exams/'],
    ['label'=>$pageTitle],
];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action==='activate' && in_array($exam['status'],['draft','scheduled'])) {
        $qC = $pdo->prepare("SELECT COUNT(*) FROM exam_questions WHERE exam_id=?");
        $qC->execute([$eid]);
        if ($qC->fetchColumn()<1) { flash('error','Add at least one question first.'); }
        else {
            $pdo->prepare("UPDATE exams SET status='active',starts_at=COALESCE(starts_at,NOW()) WHERE id=? AND center_id=?")
                ->execute([$eid,$cid]);
            flash('success','Exam is now live!');
        }
    }
    if ($action==='close')   { $pdo->prepare("UPDATE exams SET status='closed',ends_at=COALESCE(ends_at,NOW()) WHERE id=? AND center_id=?")->execute([$eid,$cid]); flash('success','Exam closed.'); }
    if ($action==='archive') { $pdo->prepare("UPDATE exams SET status='archived' WHERE id=? AND center_id=?")->execute([$eid,$cid]); flash('success','Exam archived.'); }
    if ($action==='delete') {
        // Prevent deleting active exams
        if ($exam['status']==='active') {
            flash('error','Close the exam before deleting it.');
        } else {
            $pdo->prepare("DELETE FROM exam_questions WHERE exam_id=?")->execute([$eid]);
            $pdo->prepare("DELETE FROM exams WHERE id=? AND center_id=?")->execute([$eid,$cid]);
            auditLog('exam.deleted','exam',$eid,['title'=>$exam['title']]);
            flash('success','Exam deleted.');
            redirect('/educore/modules/exams/');
        }
    }

    // Visibility & access toggles
    if ($action==='toggle_hidden') {
        $newVal = $exam['is_hidden'] ? 0 : 1;
        $pdo->prepare("UPDATE exams SET is_hidden=? WHERE id=? AND center_id=?")->execute([$newVal,$eid,$cid]);
        flash('success', $newVal ? 'Exam hidden from students.' : 'Exam visible to students.');
    }
    if ($action==='toggle_open') {
        $newVal = (isset($exam['is_open']) && $exam['is_open']) ? 0 : 1;
        $pdo->prepare("UPDATE exams SET is_open=? WHERE id=? AND center_id=?")->execute([$newVal,$eid,$cid]);
        flash('success', $newVal ? 'Exam opened — students can enter.' : 'Exam locked — students cannot enter.');
    }

    redirect("view.php?id=$eid");
}

// Reload
$eStmt->execute([$eid,$cid]); $exam = $eStmt->fetch();

// Guard against missing columns (before migration ran)
$isHidden = (int)($exam['is_hidden'] ?? 0);
$isOpen   = isset($exam['is_open']) ? (int)$exam['is_open'] : 1;

$qStmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id=? ORDER BY sort_order,id");
$qStmt->execute([$eid]); $questions = $qStmt->fetchAll();
$typeLabels = ['mcq'=>'MCQ','true_false'=>'True/False','short_answer'=>'Short Answer'];
$typeBadges = ['mcq'=>'badge-info','true_false'=>'badge-warning','short_answer'=>'badge-accent'];
$typeCount  = array_count_values(array_column($questions,'type'));

$subStmt = $pdo->prepare("SELECT COUNT(*) AS total,SUM(status='graded') AS graded,SUM(passed) AS passed,ROUND(AVG(percentage),1) AS avg
    FROM exam_submissions WHERE exam_id=? AND status IN('submitted','graded')");
$subStmt->execute([$eid]); $subStats = $subStmt->fetch();

$statusBadge=['draft'=>'badge-muted','scheduled'=>'badge-info','active'=>'badge-success','closed'=>'badge-warning','archived'=>'badge-muted'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?=sanitize($exam['title'])?></h1>
    <p class="page-subtitle"><?=sanitize($exam['group_name']??'No group')?> · Created by <?=sanitize($exam['creator_name'])?></p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <a href="print.php?id=<?=$eid?>" class="btn btn-ghost btn-sm" target="_blank">🖨 Print</a>
    <a href="paper_studio.php?exam=<?=$eid?>" class="btn btn-ghost btn-sm">📄 Studio</a>
    <a href="edit.php?id=<?=$eid?>" class="btn btn-ghost btn-sm">✏️ Edit</a>
    <a href="duplicate.php?id=<?=$eid?>" class="btn btn-ghost btn-sm" onclick="return confirm('Duplicate this exam?')">⧉ Duplicate</a>

    <?php if(in_array($exam['status'],['draft','scheduled'])): ?>
    <a href="builder.php?id=<?=$eid?>" class="btn btn-ghost">Edit Questions</a>
    <form method="POST" style="display:inline;">
      <?=csrf_field()?><input type="hidden" name="action" value="activate">
      <button type="submit" class="btn btn-primary" onclick="return confirm('Activate exam?')">🚀 Activate</button>
    </form>
    <?php endif; ?>

    <?php if($exam['status']==='active'): ?>
    <a href="monitor.php?id=<?=$eid?>" class="btn btn-ghost btn-sm" title="Focus Monitor" style="color:var(--warning);">👁 Monitor</a>
    <a href="results.php?id=<?=$eid?>" class="btn btn-ghost">Live Results</a>
    <form method="POST" style="display:inline;">
      <?=csrf_field()?><input type="hidden" name="action" value="close">
      <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Close exam for all students?')">Close Exam</button>
    </form>
    <?php endif; ?>

    <?php if(in_array($exam['status'],['closed','active'])): ?>
    <a href="results.php?id=<?=$eid?>" class="btn <?=$exam['status']==='closed'?'btn-primary':'btn-ghost'?>">Results</a>
    <a href="analytics.php?id=<?=$eid?>" class="btn btn-ghost btn-sm">📊 Analytics</a>
    <a href="export_results.php?id=<?=$eid?>" class="btn btn-ghost btn-sm">↓ Export</a>
    <?php endif; ?>

    <?php if($exam['status']==='closed'):
      $sqC = $pdo->prepare("SELECT COUNT(*) FROM exam_questions WHERE exam_id=? AND type='short_answer'");
      $sqC->execute([$eid]); $sqCount=$sqC->fetchColumn();
      $ngC = $pdo->prepare("SELECT COUNT(*) FROM exam_submissions WHERE exam_id=? AND status='submitted'");
      $ngC->execute([$eid]); $ngCount=$ngC->fetchColumn();
      if($sqCount>0): ?>
    <a href="grade.php?id=<?=$eid?>" class="btn <?=$ngCount>0?'btn-primary':'btn-ghost'?> btn-sm" style="position:relative;">
      ✏️ Grade
      <?php if($ngCount>0): ?><span style="position:absolute;top:-6px;right:-6px;background:var(--danger);color:#fff;font-size:.6rem;font-weight:700;width:16px;height:16px;border-radius:50%;display:flex;align-items:center;justify-content:center;"><?=$ngCount?></span><?php endif; ?>
    </a>
    <?php endif; endif; ?>
  </div>
</div>

<!-- ── Control strip (visibility + access) ── -->
<?php if($exam['status']==='active'): ?>
<div class="card" style="margin-bottom:18px;border-color:rgba(245,158,11,.3);">
  <div class="card-body" style="padding:14px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <span style="font-size:.8rem;font-weight:600;color:var(--text-secondary);">🎛 Live Controls</span>

    <!-- Hidden/Visible toggle -->
    <div style="display:flex;align-items:center;gap:8px;">
      <span style="font-size:.8rem;color:var(--text-muted);">Visibility:</span>
      <form method="POST" style="display:inline;">
        <?=csrf_field()?><input type="hidden" name="action" value="toggle_hidden">
        <button type="submit" class="btn btn-sm <?=$isHidden?'btn-warning':'btn-ghost'?>"
                style="font-size:.76rem;<?=$isHidden?'':'border-color:var(--success);color:var(--success);'?>">
          <?=$isHidden?'👁‍🗨 Hidden from Students':'👁 Visible to Students'?>
        </button>
      </form>
    </div>

    <!-- Open/Locked toggle -->
    <div style="display:flex;align-items:center;gap:8px;">
      <span style="font-size:.8rem;color:var(--text-muted);">Access:</span>
      <form method="POST" style="display:inline;">
        <?=csrf_field()?><input type="hidden" name="action" value="toggle_open">
        <button type="submit" class="btn btn-sm <?=$isOpen?'':'btn-danger'?>"
                style="font-size:.76rem;<?=$isOpen?'border-color:var(--success);color:var(--success);':''?>">
          <?=$isOpen?'🔓 Open — Students Can Enter':'🔒 Locked — Entry Blocked'?>
        </button>
      </form>
    </div>

    <span style="margin-left:auto;font-size:.75rem;color:var(--text-muted);">
      <?=(int)$subStats['total']?> submitted · <?=(int)($subStats['total']-$subStats['graded'])?> pending grading
    </span>
  </div>
</div>
<?php endif; ?>

<!-- ── Visibility info bar for non-active exams ── -->
<?php if(!in_array($exam['status'],['active']) && $exam['status']!=='draft'): ?>
<div style="margin-bottom:18px;padding:10px 16px;background:var(--bg-elevated);border-radius:var(--radius-sm);font-size:.8rem;color:var(--text-muted);display:flex;gap:16px;align-items:center;">
  <span>Visibility: <strong style="color:<?=$isHidden?'var(--warning)':'var(--success)'?>"><?=$isHidden?'Hidden':'Visible'?></strong></span>
  <span>Access: <strong style="color:<?=$isOpen?'var(--success)':'var(--danger)'?>"><?=$isOpen?'Open':'Locked'?></strong></span>
  <a href="view.php?id=<?=$eid?>" style="color:var(--accent);font-size:.75rem;">Change</a>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start;">
<div>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px;">
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-value"><?=count($questions)?></div><div class="stat-label">Questions</div>
  </div>
  <div class="stat-card" style="--stat-color:#3b82f6;--stat-bg:rgba(59,130,246,.1)">
    <div class="stat-value"><?=number_format($exam['total_points'],1)?></div><div class="stat-label">Total Points</div>
  </div>
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,.1)">
    <div class="stat-value"><?=(int)$subStats['total']?></div><div class="stat-label">Submissions</div>
  </div>
  <div class="stat-card" style="--stat-color:#8b5cf6;--stat-bg:rgba(139,92,246,.1)">
    <div class="stat-value"><?=$subStats['avg']??'—'?><?=$subStats['avg']?'%':''?></div><div class="stat-label">Avg Score</div>
  </div>
</div>

<!-- Question list -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Questions (<?=count($questions)?>)</span>
    <?php if(in_array($exam['status'],['draft','scheduled'])): ?>
    <a href="builder.php?id=<?=$eid?>" class="btn btn-ghost btn-sm">+ Add / Edit</a>
    <?php endif; ?>
  </div>
  <div class="card-body no-padding">
    <?php if($questions): ?>
    <?php foreach($questions as $i=>$q): ?>
    <div style="display:flex;gap:12px;align-items:center;padding:12px 20px;border-bottom:1px solid var(--border);">
      <span style="color:var(--text-muted);font-size:.78rem;width:20px;text-align:right;"><?=$i+1?></span>
      <span class="badge <?=$typeBadges[$q['type']]?>" style="font-size:.62rem;flex-shrink:0;"><?=$typeLabels[$q['type']]?></span>
      <span style="flex:1;font-size:.84rem;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=sanitize($q['question'])?></span>
      <span style="font-size:.72rem;color:var(--text-muted);flex-shrink:0;"><?=number_format($q['points'],1)?> pt</span>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-state" style="padding:30px;"><div class="empty-icon">❓</div><div class="empty-title">No questions yet</div><div class="empty-desc"><a href="builder.php?id=<?=$eid?>" class="btn btn-primary btn-sm" style="margin-top:10px;">Open Builder</a></div></div>
    <?php endif; ?>
  </div>
</div>

</div>

<!-- Right sidebar -->
<div>
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header"><span class="card-title">Exam Settings</span></div>
    <div class="card-body" style="font-size:.82rem;">
      <?php
      $infos = [
        'Status'       => '<span class="badge '.$statusBadge[$exam['status']].'">'.ucfirst($exam['status']).'</span>',
        'Visible'      => $isHidden ? '<span style="color:var(--warning);">Hidden</span>' : '<span style="color:var(--success);">Yes</span>',
        'Entry'        => $isOpen   ? '<span style="color:var(--success);">Open</span>' : '<span style="color:var(--danger);">Locked</span>',
        'Type'         => ucfirst($exam['type']),
        'Group'        => sanitize($exam['group_name']??'Any'),
        'Duration'     => $exam['duration_minutes'].' min',
        'Passing'      => $exam['passing_score'].'%',
        'Shuffle Q'    => $exam['shuffle_questions']?'Yes':'No',
        'Show Results' => ucwords(str_replace('_',' ',$exam['show_results'])),
        'Max Attempts' => $exam['max_attempts'],
      ];
      if($exam['starts_at']) $infos['Starts'] = date('M j, H:i',strtotime($exam['starts_at']));
      if($exam['ends_at'])   $infos['Ends']   = date('M j, H:i',strtotime($exam['ends_at']));
      ?>
      <?php foreach($infos as $k=>$v): ?>
      <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);">
        <span style="color:var(--text-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;"><?=$k?></span>
        <span style="font-weight:500;color:var(--text-primary);"><?=$v?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if($questions): ?>
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header"><span class="card-title">Question Types</span></div>
    <div class="card-body" style="font-size:.82rem;">
      <?php foreach($typeLabels as $t=>$l): $cnt=$typeCount[$t]??0; if(!$cnt) continue; ?>
      <div style="display:flex;justify-content:space-between;padding:6px 0;">
        <span class="badge <?=$typeBadges[$t]?>" style="font-size:.68rem;"><?=$l?></span>
        <span style="font-weight:600;color:var(--text-primary);"><?=$cnt?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if($exam['status']==='active'): ?>
  <div class="card" style="margin-bottom:16px;border-color:rgba(245,158,11,.3);">
    <div class="card-header"><span class="card-title" style="color:var(--warning);">Quick Controls</span></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:8px;">
      <form method="POST">
        <?=csrf_field()?><input type="hidden" name="action" value="toggle_hidden">
        <button type="submit" class="btn <?=$isHidden?'btn-warning':'btn-ghost'?> w-full" style="font-size:.8rem;text-align:left;">
          <?=$isHidden?'👁 Unhide from Students':'👁‍🗨 Hide from Students'?>
        </button>
      </form>
      <form method="POST">
        <?=csrf_field()?><input type="hidden" name="action" value="toggle_open">
        <button type="submit" class="btn <?=$isOpen?'btn-ghost':'btn-danger'?> w-full" style="font-size:.8rem;text-align:left;">
          <?=$isOpen?'🔒 Lock Entry':'🔓 Unlock Entry'?>
        </button>
      </form>
      <a href="monitor.php?id=<?=$eid?>" class="btn btn-ghost w-full" style="font-size:.8rem;text-align:left;">👁 Focus Monitor</a>
    </div>
  </div>
  <?php endif; ?>

  <?php if($exam['status']!=='active'): ?>
  <div class="card" style="border-color:rgba(239,68,68,.25);">
    <div class="card-header"><span class="card-title" style="color:var(--danger);">Danger Zone</span></div>
    <div class="card-body">
      <p style="font-size:.76rem;color:var(--text-muted);margin-bottom:10px;">
        Deletes the exam and all questions permanently.
        <?php if((int)($subStats['total']??0)>0): ?>
        <strong style="color:var(--warning);"><?=(int)$subStats['total']?> submission(s) will be kept.</strong>
        <?php endif; ?>
      </p>
      <form method="POST" onsubmit="return confirm('Delete this exam and all its questions?\n\nSubmissions will be kept for records.\n\nThis cannot be undone.')">
        <?=csrf_field()?><input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-danger w-full" style="font-size:.82rem;">🗑 Delete Exam</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
