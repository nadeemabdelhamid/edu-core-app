<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','assistant');

$pdo = getDB();
$cid = centerId();
$uid = authId();
$eid = (int)($_GET['id'] ?? 0);
if (!$eid) redirect('/educore/modules/exams/');

$eStmt = $pdo->prepare("SELECT e.*,g.name AS group_name,u.name AS creator_name FROM exams e LEFT JOIN `groups` g ON g.id=e.group_id LEFT JOIN users u ON u.id=e.created_by WHERE e.id=? AND e.center_id=?");
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
        $qC = $pdo->prepare("SELECT COUNT(*) FROM exam_questions WHERE exam_id=?"); $qC->execute([$eid]);
        if ($qC->fetchColumn()<1) { flash('error','Add at least one question first.'); }
        else { $pdo->prepare("UPDATE exams SET status='active',starts_at=COALESCE(starts_at,NOW()) WHERE id=? AND center_id=?")->execute([$eid,$cid]); flash('success','Exam is live!'); }
    }
    if ($action==='close')   { $pdo->prepare("UPDATE exams SET status='closed',ends_at=COALESCE(ends_at,NOW()) WHERE id=? AND center_id=?")->execute([$eid,$cid]); flash('success','Exam closed.'); }
    if ($action==='archive') { $pdo->prepare("UPDATE exams SET status='archived' WHERE id=? AND center_id=?")->execute([$eid,$cid]); flash('success','Exam archived.'); }
    if ($action==='delete')  { $pdo->prepare("DELETE FROM exams WHERE id=? AND center_id=? AND status IN('draft','archived')")->execute([$eid,$cid]); flash('success','Exam deleted.'); redirect('/educore/modules/exams/'); }
    redirect("view.php?id=$eid");
}

// Reload after possible status change
$eStmt->execute([$eid,$cid]); $exam = $eStmt->fetch();

$qStmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id=? ORDER BY sort_order,id"); $qStmt->execute([$eid]);
$questions = $qStmt->fetchAll();
$typeLabels = ['mcq'=>'MCQ','true_false'=>'True/False','short_answer'=>'Short Answer'];
$typeBadges = ['mcq'=>'badge-info','true_false'=>'badge-warning','short_answer'=>'badge-accent'];
$typeCount  = array_count_values(array_column($questions,'type'));

$subStmt = $pdo->prepare("SELECT COUNT(*) AS total,SUM(status='graded') AS graded,SUM(passed) AS passed,ROUND(AVG(percentage),1) AS avg FROM exam_submissions WHERE exam_id=? AND status IN('submitted','graded')");
$subStmt->execute([$eid]); $subStats = $subStmt->fetch();

$statusBadge=['draft'=>'badge-muted','scheduled'=>'badge-info','active'=>'badge-success','closed'=>'badge-warning','archived'=>'badge-muted'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?=sanitize($exam['title'])?></h1>
    <p class="page-subtitle">
      <?=sanitize($exam['group_name']??'No group')?> ·
      Created by <?=sanitize($exam['creator_name'])?>
    </p>
  </div>
  <div style="display:flex;gap:8px;">
    <?php if(in_array($exam['status'],['draft','scheduled'])): ?>
    <a href="builder.php?id=<?=$eid?>" class="btn btn-ghost">Edit Questions</a>
    <form method="POST" style="display:inline;">
      <?=csrf_field()?><input type="hidden" name="action" value="activate">
      <button type="submit" class="btn btn-primary" onclick="return confirm('Activate exam?')">🚀 Activate</button>
    </form>
    <?php endif; ?>
    <?php if($exam['status']==='active'): ?>
    <a href="results.php?id=<?=$eid?>" class="btn btn-ghost">Live Results</a>
    <form method="POST" style="display:inline;">
      <?=csrf_field()?><input type="hidden" name="action" value="close">
      <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Close exam?')">Close</button>
    </form>
    <?php endif; ?>
    <?php if($exam['status']==='closed'): ?>
    <a href="results.php?id=<?=$eid?>" class="btn btn-primary">View Results</a>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start;">
<div>

<!-- Exam stats -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px;">
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-value"><?=count($questions)?></div><div class="stat-label">Questions</div>
  </div>
  <div class="stat-card" style="--stat-color:#3b82f6;--stat-bg:rgba(59,130,246,0.1)">
    <div class="stat-value"><?=number_format($exam['total_points'],1)?></div><div class="stat-label">Total Points</div>
  </div>
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)">
    <div class="stat-value"><?=(int)$subStats['total']?></div><div class="stat-label">Submissions</div>
  </div>
  <div class="stat-card" style="--stat-color:#8b5cf6;--stat-bg:rgba(139,92,246,0.1)">
    <div class="stat-value"><?=$subStats['avg']??'—'?><?=$subStats['avg']?'%':''?></div><div class="stat-label">Avg Score</div>
  </div>
</div>

<!-- Question list preview -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Questions (<?=count($questions)?>)</span>
    <?php if(in_array($exam['status'],['draft','scheduled'])): ?>
    <a href="builder.php?id=<?=$eid?>" class="btn btn-ghost btn-sm">+ Add / Edit</a>
    <?php endif; ?>
  </div>
  <div class="card-body no-padding">
    <?php if($questions): ?>
    <?php foreach($questions as $i => $q): ?>
    <div style="display:flex;gap:12px;align-items:center;padding:12px 20px;border-bottom:1px solid var(--border);">
      <span style="color:var(--text-muted);font-size:0.78rem;width:20px;text-align:right;"><?=$i+1?></span>
      <span class="badge <?=$typeBadges[$q['type']]?>" style="font-size:0.62rem;flex-shrink:0;"><?=$typeLabels[$q['type']]?></span>
      <span style="flex:1;font-size:0.84rem;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=sanitize($q['question'])?></span>
      <span style="font-size:0.72rem;color:var(--text-muted);flex-shrink:0;"><?=number_format($q['points'],1)?> pt</span>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-state" style="padding:30px;"><div class="empty-icon">❓</div><div class="empty-title">No questions yet</div><div class="empty-desc"><a href="builder.php?id=<?=$eid?>" class="btn btn-primary btn-sm" style="margin-top:10px;">Open Builder</a></div></div>
    <?php endif; ?>
  </div>
</div>

</div>

<!-- Right: exam settings -->
<div>
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header"><span class="card-title">Exam Settings</span></div>
    <div class="card-body" style="font-size:0.82rem;">
      <?php
      $infos = [
        'Status'       => '<span class="badge '.$statusBadge[$exam['status']].'">'.ucfirst($exam['status']).'</span>',
        'Type'         => ucfirst($exam['type']),
        'Group'        => sanitize($exam['group_name']??'Any'),
        'Duration'     => $exam['duration_minutes'].' min',
        'Passing'      => $exam['passing_score'].'%',
        'Shuffle Q'    => $exam['shuffle_questions']?'Yes':'No',
        'Shuffle Opts' => $exam['shuffle_options']?'Yes':'No',
        'Show Results' => ucwords(str_replace('_',' ',$exam['show_results'])),
        'Max Attempts' => $exam['max_attempts'],
      ];
      if ($exam['starts_at']) $infos['Starts'] = date('M j, H:i',strtotime($exam['starts_at']));
      if ($exam['ends_at'])   $infos['Ends']   = date('M j, H:i',strtotime($exam['ends_at']));
      ?>
      <?php foreach($infos as $k=>$v): ?>
      <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);">
        <span style="color:var(--text-muted);font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;"><?=$k?></span>
        <span style="font-weight:500;color:var(--text-primary);"><?=$v?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Question type breakdown -->
  <?php if($questions): ?>
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header"><span class="card-title">Question Types</span></div>
    <div class="card-body" style="font-size:0.82rem;">
      <?php foreach($typeLabels as $type=>$label): ?>
      <?php $cnt = $typeCount[$type]??0; if(!$cnt) continue; ?>
      <div style="display:flex;justify-content:space-between;padding:6px 0;">
        <span class="badge <?=$typeBadges[$type]?>" style="font-size:0.68rem;"><?=$label?></span>
        <span style="font-weight:600;color:var(--text-primary);"><?=$cnt?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Danger zone -->
  <?php if(in_array($exam['status'],['draft','archived'])): ?>
  <div class="card" style="border-color:rgba(239,68,68,0.25);">
    <div class="card-header"><span class="card-title" style="color:var(--danger);">Danger Zone</span></div>
    <div class="card-body">
      <form method="POST" onsubmit="return confirm('Permanently delete this exam? This cannot be undone.')">
        <?=csrf_field()?><input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-danger w-full" style="font-size:0.82rem;">Delete Exam</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
