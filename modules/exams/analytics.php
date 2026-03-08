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

$pageTitle  = 'Analytics — ' . sanitize($exam['title']);
$activePage = 'exams';
$breadcrumbs = [
    ['label'=>'Dashboard',              'href'=>'/educore/dashboard.php'],
    ['label'=>'Exams',                  'href'=>'/educore/modules/exams/'],
    ['label'=>sanitize($exam['title']), 'href'=>"view.php?id=$eid"],
    ['label'=>'Analytics'],
];

// ── Overview stats ─────────────────────────────────────────────
$s = $pdo->prepare("
    SELECT COUNT(*)                        AS total,
           SUM(passed=1)                   AS passed,
           ROUND(AVG(percentage),1)        AS avg_score,
           ROUND(MIN(percentage),1)        AS min_score,
           ROUND(MAX(percentage),1)        AS max_score,
           ROUND(AVG(time_spent_sec)/60,1) AS avg_minutes,
           ROUND(STDDEV(percentage),1)     AS std_dev
    FROM exam_submissions WHERE exam_id=? AND status IN('submitted','graded')");
$s->execute([$eid]);
$stats = $s->fetch();

// ── Score buckets 0-9 … 90-100 ─────────────────────────────────
$buckets = array_fill(0, 10, 0);
$dp = $pdo->prepare("SELECT percentage FROM exam_submissions WHERE exam_id=? AND status IN('submitted','graded')");
$dp->execute([$eid]);
foreach ($dp->fetchAll(PDO::FETCH_COLUMN) as $pct) {
    $buckets[min(9,(int)floor($pct/10))]++;
}

// ── Questions ──────────────────────────────────────────────────
$qStmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id=? ORDER BY sort_order,id");
$qStmt->execute([$eid]);
$questions = $qStmt->fetchAll();

// ── Per-question stats (join via submission, no direct exam_id on answers) ──
$aStmt = $pdo->prepare("
    SELECT ea.exam_question_id,
           ea.student_answer,
           ea.is_correct,
           eq.correct_answer,
           eq.type
    FROM exam_answers ea
    JOIN exam_submissions es ON es.id = ea.submission_id
    JOIN exam_questions   eq ON eq.id = ea.exam_question_id
    WHERE es.exam_id=? AND es.status IN('submitted','graded')");
$aStmt->execute([$eid]);

$qStats = [];
foreach ($aStmt->fetchAll() as $a) {
    $qid = $a['exam_question_id'];
    if (!isset($qStats[$qid])) $qStats[$qid] = ['total'=>0,'correct'=>0,'choices'=>[]];
    $qStats[$qid]['total']++;
    if ($a['is_correct']) $qStats[$qid]['correct']++;
    if ($a['type']==='mcq' && $a['student_answer'] !== null && $a['student_answer'] !== '') {
        $k = strtolower($a['student_answer']);
        $qStats[$qid]['choices'][$k] = ($qStats[$qid]['choices'][$k] ?? 0) + 1;
    }
}

// ── Timeline ───────────────────────────────────────────────────
$tl = $pdo->prepare("SELECT DATE(submitted_at) AS day, COUNT(*) AS cnt FROM exam_submissions WHERE exam_id=? AND status IN('submitted','graded') AND submitted_at IS NOT NULL GROUP BY DATE(submitted_at) ORDER BY day");
$tl->execute([$eid]);
$timeline = $tl->fetchAll();

// ── Top / bottom ───────────────────────────────────────────────
$top = $pdo->prepare("SELECT s.name, es.percentage, es.passed, es.time_spent_sec FROM exam_submissions es JOIN students s ON s.id=es.student_id WHERE es.exam_id=? AND es.status IN('submitted','graded') ORDER BY es.percentage DESC,es.time_spent_sec ASC LIMIT 5");
$top->execute([$eid]); $topStudents = $top->fetchAll();

$bot = $pdo->prepare("SELECT s.name, es.percentage, es.passed, es.time_spent_sec FROM exam_submissions es JOIN students s ON s.id=es.student_id WHERE es.exam_id=? AND es.status IN('submitted','graded') ORDER BY es.percentage ASC LIMIT 5");
$bot->execute([$eid]); $bottomStudents = $bot->fetchAll();

$passRate = $stats['total'] > 0 ? round($stats['passed'] / $stats['total'] * 100) : 0;

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Exam Analytics</h1>
    <p class="page-subtitle"><?=sanitize($exam['title'])?><?=$exam['group_name']?' &middot; '.sanitize($exam['group_name']):''?></p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="results.php?id=<?=$eid?>"        class="btn btn-ghost btn-sm">← Results</a>
    <a href="export_results.php?id=<?=$eid?>" class="btn btn-ghost btn-sm">↓ Export</a>
  </div>
</div>

<?php if (!$stats['total']): ?>
<div class="empty-state">
  <div class="empty-icon">📊</div>
  <div class="empty-title">No submissions yet</div>
  <div class="empty-desc">Analytics appear once students complete this exam.</div>
</div>
<?php else: ?>

<!-- KPIs -->
<div class="stats-grid" style="grid-template-columns:repeat(6,1fr);margin-bottom:24px;">
  <?php foreach ([
    ['Submissions',   $stats['total'],        '#3b82f6', 'rgba(59,130,246,0.1)'],
    ['Pass Rate',     $passRate.'%',           '#22c55e', 'rgba(34,197,94,0.1)'],
    ['Average',       $stats['avg_score'].'%','var(--accent)','var(--accent-subtle)'],
    ['Lowest',        $stats['min_score'].'%','#8b5cf6', 'rgba(139,92,246,0.1)'],
    ['Highest',       $stats['max_score'].'%','#f59e0b', 'rgba(245,158,11,0.1)'],
    ['Avg Time',      $stats['avg_minutes'].'m','#ec4899','rgba(236,72,153,0.1)'],
  ] as [$lbl,$val,$col,$bg]): ?>
  <div class="stat-card" style="--stat-color:<?=$col?>;--stat-bg:<?=$bg?>;">
    <div class="stat-value"><?=$val?></div><div class="stat-label"><?=$lbl?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Charts -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

  <div class="card">
    <div class="card-header">
      <span class="card-title">Score Distribution</span>
      <span style="font-size:0.75rem;color:var(--text-muted);">σ = <?=$stats['std_dev']?>%</span>
    </div>
    <div class="card-body" style="padding:20px;"><canvas id="distChart" height="160"></canvas></div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">Pass / Fail</span></div>
    <div class="card-body" style="display:flex;align-items:center;gap:24px;padding:20px;">
      <div style="position:relative;width:150px;height:150px;flex-shrink:0;">
        <canvas id="pfChart" width="150" height="150"></canvas>
        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
          <div style="font-family:var(--font-display);font-size:1.8rem;font-weight:800;color:var(--text-primary);"><?=$passRate?>%</div>
          <div style="font-size:0.7rem;color:var(--text-muted);">Pass Rate</div>
        </div>
      </div>
      <div style="flex:1;">
        <?php foreach ([
          ['Passed', (int)$stats['passed'],                           $passRate,     'var(--success)'],
          ['Failed', (int)$stats['total']-(int)$stats['passed'], 100-$passRate, 'var(--danger)'],
        ] as [$lbl,$n,$pct,$col]): ?>
        <div style="margin-bottom:12px;">
          <div style="display:flex;justify-content:space-between;font-size:0.82rem;margin-bottom:4px;">
            <span style="color:<?=$col?>"><?=$lbl?></span><strong><?=$n?></strong>
          </div>
          <div style="height:7px;background:var(--bg-elevated);border-radius:4px;">
            <div style="width:<?=$pct?>%;height:100%;background:<?=$col?>;border-radius:4px;"></div>
          </div>
        </div>
        <?php endforeach; ?>
        <div style="padding:8px 12px;background:var(--bg-elevated);border-radius:var(--radius-sm);font-size:0.78rem;color:var(--text-muted);">
          Pass threshold: <strong style="color:var(--text-primary);"><?=$exam['passing_score']?>%</strong>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Per-question table -->
<?php if ($questions): ?>
<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <span class="card-title">Per-Question Breakdown</span>
    <span style="font-size:0.75rem;color:var(--text-muted);"><?=count($questions)?> questions</span>
  </div>
  <div class="card-body no-padding">
    <table class="data-table">
      <thead><tr><th>#</th><th>Question</th><th>Type</th><th>Pts</th><th>Answered</th><th>Correct</th><th>Pass Rate</th><th>Difficulty</th></tr></thead>
      <tbody>
      <?php foreach ($questions as $i=>$q):
        $qs   = $qStats[$q['id']] ?? ['total'=>0,'correct'=>0,'choices'=>[]];
        $qPct = $qs['total']>0 ? round($qs['correct']/$qs['total']*100) : 0;
        [$diff,$dbadge] = $qPct>=80 ? ['Easy','badge-success'] : ($qPct>=50 ? ['Medium','badge-warning'] : ['Hard','badge-danger']);
      ?>
      <tr>
        <td style="font-weight:600;color:var(--text-muted);">Q<?=$i+1?></td>
        <td>
          <div style="max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:0.84rem;">
            <?=sanitize($q['question'])?>
          </div>
          <?php if ($q['type']==='mcq' && $qs['total']>0): ?>
          <div style="display:flex;gap:3px;flex-wrap:wrap;margin-top:4px;">
            <?php foreach (['a','b','c','d'] as $ltr):
              $opt = $q['option_'.$ltr]; if (!$opt) continue;
              $cnt  = $qs['choices'][$ltr] ?? 0;
              $pct2 = $qs['total']>0 ? round($cnt/$qs['total']*100) : 0;
              $ok   = strtolower($q['correct_answer'])===$ltr;
            ?>
            <span style="font-size:0.67rem;padding:2px 7px;border-radius:4px;background:<?=$ok?'rgba(34,197,94,0.15)':'var(--bg-elevated)'?>;color:<?=$ok?'var(--success)':'var(--text-muted)'?>;">
              <?=strtoupper($ltr)?>: <?=$pct2?>%
            </span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </td>
        <td><span class="badge badge-muted" style="font-size:0.66rem;"><?=strtoupper(str_replace('_',' ',$q['type']))?></span></td>
        <td style="font-weight:600;"><?=$q['points']?></td>
        <td><?=$qs['total']?></td>
        <td style="color:var(--success);"><?=$qs['correct']?></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <div style="width:56px;height:5px;background:var(--bg-elevated);border-radius:3px;">
              <div style="width:<?=$qPct?>%;height:100%;border-radius:3px;background:<?=$qPct>=80?'var(--success)':($qPct>=50?'var(--warning)':'var(--danger)')?>"></div>
            </div>
            <span style="font-size:0.78rem;font-weight:600;"><?=$qPct?>%</span>
          </div>
        </td>
        <td><span class="badge <?=$dbadge?>" style="font-size:0.66rem;"><?=$diff?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Top / bottom performers -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
  <?php
  $sets = [
    ['🏆 Top Performers', $topStudents,    ['#f5a623','#8892a4','#cd7f32','var(--bg-elevated)','var(--bg-elevated)'], 'var(--success)'],
    ['📉 Need Attention',  $bottomStudents, array_fill(0,5,'rgba(239,68,68,0.15)'), 'var(--danger)'],
  ];
  foreach ($sets as [$title,$list,$colors,$scoreColor]): ?>
  <div class="card">
    <div class="card-header"><span class="card-title"><?=$title?></span></div>
    <div class="card-body no-padding">
      <?php if ($list): foreach ($list as $i=>$st): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:10px 18px;border-bottom:1px solid var(--border);">
        <div style="width:24px;height:24px;border-radius:50%;background:<?=$colors[$i]?>;display:flex;align-items:center;justify-content:center;font-size:0.68rem;font-weight:700;color:#fff;flex-shrink:0;"><?=$i+1?></div>
        <div style="flex:1;font-size:0.84rem;font-weight:500;"><?=sanitize($st['name'])?></div>
        <span style="font-family:var(--font-display);font-weight:700;color:<?=$scoreColor?>;"><?=round($st['percentage'])?>%</span>
        <span style="font-size:0.7rem;color:var(--text-muted);"><?=$st['time_spent_sec']?round($st['time_spent_sec']/60).'m':'—'?></span>
      </div>
      <?php endforeach; else: ?>
      <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:0.82rem;">No data</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($timeline): ?>
<div class="card">
  <div class="card-header"><span class="card-title">Submission Timeline</span></div>
  <div class="card-body" style="padding:20px;"><canvas id="timelineChart" height="80"></canvas></div>
</div>
<?php endif; ?>

<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
Chart.defaults.color = '#8892a4';
Chart.defaults.borderColor = 'rgba(255,255,255,0.05)';

const passing = <?=(int)$exam['passing_score']?>;
const bLabels = ['0–9','10–19','20–29','30–39','40–49','50–59','60–69','70–79','80–89','90–100'];
const bColors = bLabels.map((_,i)=> i*10 >= passing ? 'rgba(34,197,94,0.7)' : 'rgba(239,68,68,0.6)');

new Chart(document.getElementById('distChart'), {
  type:'bar',
  data:{ labels:bLabels, datasets:[{ data:<?=json_encode($buckets)?>, backgroundColor:bColors, borderRadius:4 }] },
  options:{ plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true,ticks:{stepSize:1}}, x:{grid:{display:false}} } }
});

new Chart(document.getElementById('pfChart'), {
  type:'doughnut',
  data:{ datasets:[{ data:[<?=(int)$stats['passed']?>,<?=(int)$stats['total']-(int)$stats['passed']?>], backgroundColor:['rgba(34,197,94,0.8)','rgba(239,68,68,0.6)'], borderWidth:0 }] },
  options:{ cutout:'72%', plugins:{legend:{display:false}} }
});

<?php if ($timeline): ?>
new Chart(document.getElementById('timelineChart'), {
  type:'bar',
  data:{ labels:<?=json_encode(array_column($timeline,'day'))?>, datasets:[{ data:<?=json_encode(array_column($timeline,'cnt'))?>, backgroundColor:'rgba(59,130,246,0.6)', borderRadius:4 }] },
  options:{ plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true,ticks:{stepSize:1}}, x:{grid:{display:false}} } }
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
