<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','assistant');

$pdo = getDB();
$cid = centerId();
$eid = (int)($_GET['id'] ?? 0);
if (!$eid) redirect('/educore/modules/exams/');

$eStmt = $pdo->prepare("SELECT e.*,g.name AS group_name FROM exams e LEFT JOIN `groups` g ON g.id=e.group_id WHERE e.id=? AND e.center_id=?");
$eStmt->execute([$eid,$cid]);
$exam = $eStmt->fetch();
if (!$exam) { flash('error','Exam not found.'); redirect('/educore/modules/exams/'); }

$pageTitle  = sanitize($exam['title']) . ' — Results';
$activePage = 'exams';
$breadcrumbs = [
    ['label'=>'Dashboard','href'=>'/educore/dashboard.php'],
    ['label'=>'Exams','href'=>'/educore/modules/exams/'],
    ['label'=>sanitize($exam['title']),'href'=>"view.php?id=$eid"],
    ['label'=>'Results'],
];

// Handle actions
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action==='activate') {
        $pdo->prepare("UPDATE exams SET status='active',starts_at=COALESCE(starts_at,NOW()) WHERE id=? AND center_id=?")->execute([$eid,$cid]);
        flash('success','Exam activated.');
    }
    if ($action==='close') {
        $pdo->prepare("UPDATE exams SET status='closed',ends_at=COALESCE(ends_at,NOW()) WHERE id=? AND center_id=?")->execute([$eid,$cid]);
        flash('success','Exam closed.');
    }
    if ($action==='release_results') {
        $pdo->prepare("UPDATE exams SET show_results='immediately' WHERE id=? AND center_id=?")->execute([$eid,$cid]);
        flash('success','Results released to students.');
    }
    redirect("results.php?id=$eid");
}

// Load all submissions with student info
$subStmt = $pdo->prepare("
    SELECT es.*,st.name AS student_name,st.student_code,st.id AS student_id
    FROM exam_submissions es
    JOIN students st ON st.id=es.student_id
    WHERE es.exam_id=? AND es.center_id=? AND es.status IN('submitted','graded','in_progress')
    ORDER BY es.percentage DESC, es.submitted_at ASC");
$subStmt->execute([$eid,$cid]);
$submissions = $subStmt->fetchAll();

// Stats
$graded  = array_filter($submissions,fn($s)=>in_array($s['status'],['submitted','graded']));
$scores  = array_column(array_filter($graded,fn($s)=>$s['percentage']>0),'percentage');
$avgScore= count($scores) ? round(array_sum($scores)/count($scores),1) : 0;
$highest = count($scores) ? round(max($scores),1) : 0;
$lowest  = count($scores) ? round(min($scores),1) : 0;
$passed  = count(array_filter($graded,fn($s)=>$s['passed']));

// Score distribution buckets
$dist = ['0-49'=>0,'50-59'=>0,'60-69'=>0,'70-79'=>0,'80-89'=>0,'90-100'=>0];
foreach ($graded as $s) {
    $p = (float)$s['percentage'];
    if ($p<50) $dist['0-49']++;
    elseif($p<60) $dist['50-59']++;
    elseif($p<70) $dist['60-69']++;
    elseif($p<80) $dist['70-79']++;
    elseif($p<90) $dist['80-89']++;
    else $dist['90-100']++;
}
$maxDist = max(1,...array_values($dist));

// Enrolled students (if group assigned)
$enrolled = 0;
if ($exam['group_id']) {
    $enrStmt = $pdo->prepare("SELECT COUNT(*) FROM group_students WHERE group_id=? AND status='active'");
    $enrStmt->execute([$exam['group_id']]);
    $enrolled = (int)$enrStmt->fetchColumn();
}

// CSV export
if (($_GET['export']??'')==='csv' && $submissions) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="exam_results_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out,['Student','Code','Status','Score','Percentage','Passed','Submitted At','Time Spent']);
    foreach ($submissions as $s) {
        fputcsv($out,[$s['student_name'],$s['student_code'],$s['status'],
            number_format($s['total_score'],1),number_format($s['percentage'],1).'%',
            $s['passed']?'Yes':'No',$s['submitted_at'],gmdate('i:s',(int)$s['time_spent_sec'])]);
    }
    fclose($out); exit;
}

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?=sanitize($exam['title'])?></h1>
    <p class="page-subtitle"><?=count($graded)?> submissions · avg <?=$avgScore?>%</p>
  </div>
  <div style="display:flex;gap:8px;">
    <?php if($submissions): ?>
    <a href="?id=<?=$eid?>&export=csv" class="btn btn-ghost btn-sm" style="color:var(--success);">↓ Export CSV</a>
    <a href="export_results.php?id=<?=$eid?>" class="btn btn-ghost btn-sm">↓ Full Export</a>
    <a href="analytics.php?id=<?=$eid?>" class="btn btn-ghost btn-sm">📊 Analytics</a>
    <?php endif; ?>
    <?php if($exam['status']==='active'): ?>
    <form method="POST" style="display:inline;">
      <?=csrf_field()?><input type="hidden" name="action" value="close">
      <button type="submit" class="btn btn-ghost btn-sm" onclick="return confirm('Close exam?')">Close Exam</button>
    </form>
    <?php endif; ?>
    <?php if($exam['status']==='closed' && $exam['show_results']!=='immediately'): ?>
    <form method="POST" style="display:inline;">
      <?=csrf_field()?><input type="hidden" name="action" value="release_results">
      <button type="submit" class="btn btn-primary btn-sm">Release Results</button>
    </form>
    <?php endif; ?>
    <a href="view.php?id=<?=$eid?>" class="btn btn-ghost btn-sm">← Back</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-bottom:24px;">
  <?php foreach([
    ['Submitted',count($graded),'var(--accent)','var(--accent-subtle)'],
    ['Avg Score',$avgScore.'%','#3b82f6','rgba(59,130,246,0.1)'],
    ['Highest',$highest.'%','#22c55e','rgba(34,197,94,0.1)'],
    ['Lowest',$lowest.'%','#ef4444','rgba(239,68,68,0.1)'],
    ['Passed',$passed,'#8b5cf6','rgba(139,92,246,0.1)'],
  ] as [$l,$v,$c,$bg]): ?>
  <div class="stat-card" style="--stat-color:<?=$c?>;--stat-bg:<?=$bg?>">
    <div class="stat-value"><?=$v?></div>
    <div class="stat-label"><?=$l?></div>
  </div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 280px;gap:20px;margin-bottom:20px;align-items:start;">

<!-- Results table -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Student Results</span>
    <input type="text" placeholder="Filter…" class="form-control" style="width:180px;font-size:0.82rem;" oninput="filterTable(this.value)">
  </div>
  <div class="card-body no-padding">
    <?php if($submissions): ?>
    <table class="data-table" id="resultsTable">
      <thead>
        <tr><th>#</th><th>Student</th><th>Score</th><th>%</th><th>Result</th><th>Status</th><th>Time</th><th style="text-align:right;">Detail</th></tr>
      </thead>
      <tbody>
        <?php foreach($submissions as $rank => $s):
          $pctColor = (float)$s['percentage'] >= $exam['passing_score'] ? 'var(--success)' : 'var(--danger)';
          $colors = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4'];
          $sc = $colors[abs(crc32($s['student_name']))%count($colors)];
        ?>
        <tr data-name="<?=strtolower(sanitize($s['student_name']))?>">
          <td style="color:var(--text-muted);font-size:0.8rem;"><?=$rank+1?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;">
              <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,<?=$sc?>,<?=$sc?>99);color:#fff;font-size:0.68rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?=strtoupper(substr($s['student_name'],0,2))?></div>
              <div>
                <div class="cell-primary"><?=sanitize($s['student_name'])?></div>
                <?php if($s['student_code']): ?><div style="font-size:0.7rem;color:var(--text-muted);"><?=sanitize($s['student_code'])?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td style="font-weight:600;"><?=number_format($s['total_score'],1)?><span style="color:var(--text-muted);font-weight:400;font-size:0.78rem;"> /<?=number_format($exam['total_points'],1)?></span></td>
          <td><span style="font-weight:700;color:<?=$pctColor?>;"><?=number_format($s['percentage'],1)?>%</span></td>
          <td>
            <?php if(in_array($s['status'],['submitted','graded'])): ?>
            <span class="badge <?=$s['passed']?'badge-success':'badge-danger'?>"><?=$s['passed']?'Passed':'Failed'?></span>
            <?php else: ?>
            <span class="badge badge-warning">In Progress</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if($s['status']==='graded'): ?>
            <span class="badge badge-success" style="font-size:0.65rem;">Graded</span>
            <?php elseif($s['status']==='submitted'): ?>
            <span class="badge badge-warning" style="font-size:0.65rem;">Needs Grading</span>
            <?php else: ?>
            <span class="badge badge-muted" style="font-size:0.65rem;">In Progress</span>
            <?php endif; ?>
          </td>
          <td style="font-size:0.78rem;color:var(--text-muted);"><?=gmdate('i:s',(int)$s['time_spent_sec'])?></td>
          <td style="text-align:right;">
            <a href="result.php?exam=<?=$eid?>&student=<?=$s['student_id']?>" class="btn btn-ghost btn-icon btn-sm">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">📊</div>
      <div class="empty-title">No submissions yet</div>
      <div class="empty-desc">Students will appear here once they submit.</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Score distribution chart -->
<div>
  <div class="card">
    <div class="card-header"><span class="card-title">Score Distribution</span></div>
    <div class="card-body" style="padding:16px 20px;">
      <?php foreach($dist as $range=>$count): ?>
      <div style="margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">
          <span><?=$range?>%</span>
          <span><?=$count?> student<?=$count!=1?'s':''?></span>
        </div>
        <?php
          $pct2 = $maxDist > 0 ? round(($count/$maxDist)*100) : 0;
          $rangeStart = (int)explode('-',$range)[0];
          $barColor = $rangeStart >= $exam['passing_score'] ? 'var(--success)' : 'var(--danger)';
        ?>
        <div style="height:6px;background:var(--bg-elevated);border-radius:3px;overflow:hidden;">
          <div style="height:100%;width:<?=$pct2?>%;background:<?=$barColor?>;border-radius:3px;transition:width 0.5s;"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</div>

<script>
function filterTable(q) {
  q = q.toLowerCase();
  document.querySelectorAll('#resultsTable tbody tr').forEach(row => {
    row.style.display = row.dataset.name?.includes(q) ? '' : 'none';
  });
}
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
