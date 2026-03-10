<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('student');

$pdo = getDB();
$uid = authId();
$cid = centerId();

$pageTitle  = 'My Exams';
$activePage = 'exams';
$breadcrumbs = [['label'=>'Dashboard','href'=>'/educore/dashboard.php'],['label'=>'Exams']];

$stStmt = $pdo->prepare("SELECT * FROM students WHERE user_id=? AND center_id=? AND is_active=1");
$stStmt->execute([$uid,$cid]);
$student = $stStmt->fetch();
if (!$student) { flash('error','Student profile not found.'); redirect('/educore/dashboard.php'); }

// Safe column-existence checks (migrations may not have run yet)
$hasHiddenCol = false;
$hasOpenCol   = false;
try { $pdo->query("SELECT is_hidden FROM exams LIMIT 1"); $hasHiddenCol = true; } catch (Exception $e) {}
try { $pdo->query("SELECT is_open FROM exams LIMIT 1");   $hasOpenCol   = true; } catch (Exception $e) {}

$hiddenFilter = $hasHiddenCol ? "AND COALESCE(e.is_hidden,0) = 0" : "";
// Hide locked exams UNLESS the student already has a submission (so they can still see results)
$openFilter   = $hasOpenCol ? "AND (COALESCE(e.is_open,1) = 1 OR e.status = 'closed' OR es.id IS NOT NULL)" : "";

$examStmt = $pdo->prepare("
    SELECT e.*, g.name AS group_name,
           es.status AS my_status, es.percentage AS my_score, es.passed AS my_passed,
           es.submitted_at AS my_submitted, es.id AS my_sub_id
    FROM exams e
    LEFT JOIN `groups` g ON g.id=e.group_id
    LEFT JOIN exam_submissions es
           ON es.exam_id=e.id AND es.student_id=?
          AND es.id = (SELECT id FROM exam_submissions
                       WHERE exam_id=e.id AND student_id=?
                       ORDER BY attempt_number DESC LIMIT 1)
    WHERE e.center_id=?
      AND e.status IN('active','closed')
      $hiddenFilter
      $openFilter
      AND (e.group_id IS NULL
           OR EXISTS(SELECT 1 FROM group_students gs
                     WHERE gs.group_id=e.group_id AND gs.student_id=? AND gs.status='active'))
    ORDER BY FIELD(e.status,'active','closed'), e.starts_at DESC");
$examStmt->execute([$student['id'],$student['id'],$cid,$student['id']]);
$exams = $examStmt->fetchAll();

// Auto-sync statuses
foreach ($exams as &$ex) {
    if ($ex['status']==='scheduled' && $ex['starts_at'] && strtotime($ex['starts_at'])<=time()) {
        $pdo->prepare("UPDATE exams SET status='active' WHERE id=?")->execute([$ex['id']]);
        $ex['status']='active';
    }
    if ($ex['status']==='active' && $ex['ends_at'] && strtotime($ex['ends_at'])<time()) {
        $pdo->prepare("UPDATE exams SET status='closed',ends_at=COALESCE(ends_at,NOW()) WHERE id=?")->execute([$ex['id']]);
        $ex['status']='closed';
    }
}
unset($ex);

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">My Exams</h1><p class="page-subtitle"><?=count($exams)?> available</p></div>
</div>

<?php if($exams): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;">
  <?php foreach($exams as $ex):
    $isActive   = $ex['status']==='active';
    $isOpen     = isset($ex['is_open']) ? (bool)$ex['is_open'] : true;
    $submitted  = in_array($ex['my_status'],['submitted','graded']);
    $inProgress = $ex['my_status']==='in_progress';
    $pct        = (float)($ex['my_score']??0);
    $passed     = (bool)$ex['my_passed'];
    $pctColor   = $pct >= $ex['passing_score'] ? 'var(--success)' : 'var(--danger)';
  ?>
  <div class="card" style="<?=$isActive&&!$submitted&&$isOpen?'border-color:rgba(34,197,94,0.3);':''?>">
    <div class="card-body" style="padding:20px;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
        <span class="badge <?=$isActive?'badge-success':'badge-muted'?>"><?=$isActive?'● Active':'Closed'?></span>
        <span class="badge <?=match($ex['type']){'exam'=>'badge-danger','quiz'=>'badge-warning','practice'=>'badge-info',default=>'badge-muted'}?>"><?=ucfirst($ex['type'])?></span>
      </div>
      <div style="font-family:var(--font-display);font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:4px;"><?=sanitize($ex['title'])?></div>
      <?php if($ex['group_name']): ?>
      <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:12px;">📚 <?=sanitize($ex['group_name'])?></div>
      <?php endif; ?>

      <div style="display:flex;gap:12px;font-size:.78rem;color:var(--text-muted);margin-bottom:16px;flex-wrap:wrap;">
        <span>⏱ <?=$ex['duration_minutes']?> min</span>
        <span>📝 Pass: <?=$ex['passing_score']?>%</span>
        <?php if($ex['ends_at']&&$isActive): ?><span style="color:var(--warning);">⏰ Ends <?=date('M j H:i',strtotime($ex['ends_at']))?></span><?php endif; ?>
      </div>

      <?php if($submitted): ?>
        <div style="background:<?=$passed?'rgba(34,197,94,.08)':'rgba(239,68,68,.08)'?>;border-radius:var(--radius-sm);padding:10px;text-align:center;margin-bottom:12px;">
          <div style="font-size:1.4rem;font-weight:800;font-family:var(--font-display);color:<?=$pctColor?>;"><?=round($pct)?>%</div>
          <div style="font-size:.72rem;color:<?=$pctColor?>;"><?=$passed?'✓ Passed':'✗ Failed'?></div>
        </div>
        <?php if($ex['show_results']==='immediately'||($ex['show_results']==='after_close'&&$ex['status']==='closed')): ?>
        <a href="result.php?exam=<?=$ex['id']?>&student=<?=$student['id']?>" class="btn btn-ghost w-full" style="font-size:.84rem;">View Results</a>
        <?php else: ?>
        <div class="btn w-full" style="text-align:center;background:var(--bg-elevated);color:var(--text-muted);font-size:.82rem;cursor:default;">Results pending</div>
        <?php endif; ?>
      <?php elseif($inProgress): ?>
        <a href="take.php?id=<?=$ex['id']?>" class="btn btn-warning w-full">Resume Exam →</a>
      <?php elseif($isActive&&$isOpen): ?>
        <a href="take.php?id=<?=$ex['id']?>" class="btn btn-primary w-full">Start Exam →</a>
      <?php elseif($isActive&&!$isOpen): ?>
        <div class="btn w-full" style="text-align:center;background:var(--bg-elevated);color:var(--danger);font-size:.82rem;cursor:default;">🔒 Entry Locked</div>
      <?php else: ?>
        <div class="btn w-full" style="text-align:center;background:var(--bg-elevated);color:var(--text-muted);font-size:.82rem;cursor:default;">Not submitted</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card"><div class="empty-state"><div class="empty-icon">📝</div><div class="empty-title">No exams yet</div><div class="empty-desc">Your teacher hasn't published any exams yet.</div></div></div>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
