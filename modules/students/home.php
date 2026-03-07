<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('student');

$pdo = getDB();
$uid = authId();
$cid = centerId();

$pageTitle  = 'My Dashboard';
$activePage = 'student_home';

$stStmt = $pdo->prepare("SELECT s.*, c.name AS center_name FROM students s JOIN centers c ON c.id=s.center_id WHERE s.user_id=? AND s.center_id=? AND s.is_active=1");
$stStmt->execute([$uid, $cid]);
$student = $stStmt->fetch();
if (!$student) { flash('error','Student profile not found.'); redirect('/educore/login.php'); }

$groupStmt = $pdo->prepare("
    SELECT g.*, u.name AS teacher_name,
           COUNT(DISTINCT s.id) AS total_sessions,
           SUM(CASE WHEN ar.status IN('present','late') THEN 1 ELSE 0 END) AS attended,
           ROUND(100.0 * SUM(CASE WHEN ar.status IN('present','late') THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT s.id),0),1) AS att_rate
    FROM group_students gs
    JOIN `groups` g ON g.id = gs.group_id
    LEFT JOIN users u ON u.id = g.teacher_id
    LEFT JOIN attendance_sessions s ON s.group_id = g.id AND s.status='closed'
    LEFT JOIN attendance_records ar ON ar.session_id = s.id AND ar.student_id = gs.student_id
    WHERE gs.student_id=? AND gs.status='active'
    GROUP BY g.id ORDER BY g.name");
$groupStmt->execute([$student['id']]);
$groups = $groupStmt->fetchAll();

$attStmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status='present') AS present, SUM(status='late') AS late, SUM(status='absent') AS absent FROM attendance_records WHERE student_id=? AND center_id=?");
$attStmt->execute([$student['id'], $cid]);
$attStats = $attStmt->fetch();
$totalAtt = (int)$attStats['total'];
$attRate  = $totalAtt > 0 ? round((($attStats['present'] + $attStats['late']) / $totalAtt) * 100) : 0;

$examStmt = $pdo->prepare("
    SELECT e.*, g.name AS group_name,
           es.status AS my_status, es.percentage AS my_score
    FROM exams e
    LEFT JOIN `groups` g ON g.id=e.group_id
    LEFT JOIN exam_submissions es ON es.exam_id=e.id AND es.student_id=? AND es.status IN('submitted','graded','in_progress')
    WHERE e.center_id=? AND e.status='active'
      AND (e.group_id IS NULL OR EXISTS(SELECT 1 FROM group_students gs2 WHERE gs2.group_id=e.group_id AND gs2.student_id=? AND gs2.status='active'))
    ORDER BY e.starts_at DESC LIMIT 5");
$examStmt->execute([$student['id'], $cid, $student['id']]);
$activeExams = $examStmt->fetchAll();

$openStmt = $pdo->prepare("
    SELECT s.*, g.name AS group_name
    FROM attendance_sessions s
    JOIN `groups` g ON g.id=s.group_id
    JOIN group_students gs ON gs.group_id=s.group_id AND gs.student_id=? AND gs.status='active'
    LEFT JOIN attendance_records ar ON ar.session_id=s.id AND ar.student_id=?
    WHERE s.center_id=? AND s.status='open' AND s.code_expires_at > NOW() AND ar.id IS NULL");
$openStmt->execute([$student['id'], $student['id'], $cid]);
$openSessions = $openStmt->fetchAll();

$recentStmt = $pdo->prepare("
    SELECT ar.status, a.session_date, a.topic, g.name AS group_name
    FROM attendance_records ar
    JOIN attendance_sessions a ON a.id=ar.session_id
    JOIN `groups` g ON g.id=a.group_id
    WHERE ar.student_id=? AND ar.center_id=?
    ORDER BY a.session_date DESC LIMIT 8");
$recentStmt->execute([$student['id'], $cid]);
$recentAtt = $recentStmt->fetchAll();

$examStatStmt = $pdo->prepare("SELECT COUNT(*) AS total, ROUND(AVG(percentage),1) AS avg_pct, SUM(passed) AS passed FROM exam_submissions WHERE student_id=? AND center_id=? AND status IN('submitted','graded')");
$examStatStmt->execute([$student['id'], $cid]);
$examStats = $examStatStmt->fetch();

$colors   = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4'];
$stuColor = $colors[abs(crc32($student['name'])) % count($colors)];
$attColor = $attRate >= 80 ? 'var(--success)' : ($attRate >= 60 ? 'var(--warning)' : 'var(--danger)');
$hour     = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

include __DIR__ . '/../../templates/layout.php';
?>

<!-- Hero -->
<div style="background:linear-gradient(135deg,var(--bg-card),var(--bg-elevated));border:1px solid var(--border);border-radius:var(--radius-xl);padding:28px 32px;margin-bottom:24px;display:flex;align-items:center;gap:20px;">
  <div style="width:64px;height:64px;border-radius:var(--radius-lg);background:linear-gradient(135deg,<?=$stuColor?>,<?=$stuColor?>99);color:#fff;font-family:var(--font-display);font-size:1.5rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
    <?= strtoupper(substr($student['name'],0,2)) ?>
  </div>
  <div style="flex:1;">
    <div style="font-family:var(--font-display);font-size:1.4rem;font-weight:800;color:var(--text-primary);">
      <?= $greeting ?>, <?= sanitize(explode(' ',$student['name'])[0]) ?> 👋
    </div>
    <div style="color:var(--text-muted);font-size:0.84rem;margin-top:4px;">
      <?= sanitize($student['center_name']) ?> &middot; <?= date('l, F j') ?>
      <?php if($student['student_code']): ?>&nbsp;&middot;&nbsp;<span style="font-family:monospace;color:var(--accent);">#<?= sanitize($student['student_code']) ?></span><?php endif; ?>
    </div>
  </div>
  <?php
    $circ=2*M_PI*20; $dash=round(($attRate/100)*$circ,2);
    $rgb=$attRate>=80?'34,197,94':($attRate>=60?'245,158,11':'239,68,68');
  ?>
  <div style="text-align:center;flex-shrink:0;">
    <div style="position:relative;width:70px;height:70px;">
      <svg viewBox="0 0 44 44" style="width:70px;height:70px;transform:rotate(-90deg);">
        <circle cx="22" cy="22" r="20" fill="none" stroke="var(--bg-elevated)" stroke-width="3.5"/>
        <circle cx="22" cy="22" r="20" fill="none" stroke="rgba(<?=$rgb?>,0.9)" stroke-width="3.5" stroke-dasharray="<?=$dash?> <?=$circ?>" stroke-linecap="round"/>
      </svg>
      <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-family:var(--font-display);font-size:0.95rem;font-weight:800;color:var(--text-primary);"><?=$attRate?>%</div>
    </div>
    <div style="font-size:0.65rem;color:var(--text-muted);margin-top:4px;text-transform:uppercase;letter-spacing:0.06em;">Attendance</div>
  </div>
</div>

<?php if($openSessions): ?>
<div style="background:rgba(34,197,94,0.07);border:1px solid rgba(34,197,94,0.3);border-radius:var(--radius-md);padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
  <div style="width:10px;height:10px;border-radius:50%;background:var(--success);animation:pulse 1.5s infinite;flex-shrink:0;"></div>
  <div style="flex:1;font-size:0.85rem;color:var(--text-secondary);">
    <strong style="color:var(--success);">Session open!</strong>
    <?php foreach($openSessions as $os): ?><span style="margin-left:6px;">&#x2014; <?= sanitize($os['group_name']) ?></span><?php endforeach; ?>
  </div>
  <a href="/educore/modules/attendance/submit.php" class="btn btn-primary btn-sm">Mark Attendance &rarr;</a>
</div>
<?php endif; ?>

<?php if($activeExams): ?>
<div style="background:rgba(245,158,11,0.07);border:1px solid rgba(245,158,11,0.25);border-radius:var(--radius-md);padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--warning)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
  <span style="flex:1;font-size:0.85rem;color:var(--text-secondary);"><strong style="color:var(--warning);"><?= count($activeExams) ?> exam<?= count($activeExams)>1?'s are':' is'?> open.</strong></span>
  <a href="/educore/modules/exams/student.php" class="btn btn-sm" style="background:var(--warning-dim);color:var(--warning);border:1px solid rgba(245,158,11,0.3);">Go to Exams &rarr;</a>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px;">
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)"><div class="stat-value"><?=count($groups)?></div><div class="stat-label">My Groups</div></div>
  <div class="stat-card" style="--stat-color:<?=$attColor?>;--stat-bg:rgba(34,197,94,0.08)"><div class="stat-value"><?=$attRate?>%</div><div class="stat-label">Attendance Rate</div></div>
  <div class="stat-card" style="--stat-color:#8b5cf6;--stat-bg:rgba(139,92,246,0.1)"><div class="stat-value"><?=(int)$examStats['total']?></div><div class="stat-label">Exams Taken</div></div>
  <div class="stat-card" style="--stat-color:#3b82f6;--stat-bg:rgba(59,130,246,0.1)"><div class="stat-value"><?=$examStats['avg_pct']?$examStats['avg_pct'].'%':'&mdash;'?></div><div class="stat-label">Avg Exam Score</div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

<div class="card">
  <div class="card-header"><span class="card-title">My Groups</span></div>
  <div class="card-body no-padding">
    <?php if($groups): ?>
    <?php foreach($groups as $g):
      $rate=(float)($g['att_rate']??0);
      $rc=$rate>=80?'var(--success)':($rate>=60?'var(--warning)':($rate>0?'var(--danger)':'var(--text-muted)'));
      $gc=$colors[abs(crc32($g['name']))%count($colors)];
    ?>
    <div style="display:flex;align-items:center;gap:12px;padding:13px 20px;border-bottom:1px solid var(--border);">
      <div style="width:36px;height:36px;border-radius:var(--radius-sm);background:<?=$gc?>22;color:<?=$gc?>;font-size:0.7rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-family:var(--font-display);"><?=strtoupper(substr($g['name'],0,2))?></div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:600;color:var(--text-primary);font-size:0.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=sanitize($g['name'])?></div>
        <div style="font-size:0.72rem;color:var(--text-muted);"><?=sanitize($g['teacher_name']??'')?><?=$g['subject']?' &middot; '.sanitize($g['subject']):''?></div>
      </div>
      <?php if($g['total_sessions']>0): ?>
      <div style="text-align:right;">
        <div style="font-size:0.88rem;font-weight:700;color:<?=$rc?>;"><?=$rate?>%</div>
        <div style="width:50px;height:3px;background:var(--bg-elevated);border-radius:2px;margin-top:4px;"><div style="height:100%;width:<?=$rate?>%;background:<?=$rc?>;border-radius:2px;"></div></div>
      </div>
      <?php else: ?><span style="font-size:0.72rem;color:var(--text-muted);">No sessions</span><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-state" style="padding:28px;"><div class="empty-icon" style="font-size:1.4rem;">&#x1F4DA;</div><div class="empty-title" style="font-size:0.88rem;">Not enrolled in any groups yet</div></div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">Recent Attendance</span>
    <a href="/educore/modules/attendance/submit.php" class="btn btn-primary btn-sm">Mark Attendance</a>
  </div>
  <div class="card-body no-padding">
    <?php if($recentAtt):
      $bm=['present'=>'badge-success','absent'=>'badge-danger','late'=>'badge-warning','excused'=>'badge-info'];
      foreach($recentAtt as $r): ?>
    <div style="display:flex;align-items:center;gap:10px;padding:10px 20px;border-bottom:1px solid var(--border);">
      <span class="badge <?=$bm[$r['status']]?>" style="font-size:0.65rem;flex-shrink:0;"><?=ucfirst($r['status'])?></span>
      <div style="flex:1;min-width:0;">
        <div style="font-size:0.84rem;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=sanitize($r['group_name'])?></div>
        <?php if($r['topic']): ?><div style="font-size:0.7rem;color:var(--text-muted);"><?=sanitize($r['topic'])?></div><?php endif; ?>
      </div>
      <div style="font-size:0.72rem;color:var(--text-muted);flex-shrink:0;"><?=date('M j',strtotime($r['session_date']))?></div>
    </div>
    <?php endforeach; else: ?>
    <div class="empty-state" style="padding:28px;"><div class="empty-icon" style="font-size:1.4rem;">&#x1F4CB;</div><div class="empty-title" style="font-size:0.88rem;">No attendance records yet</div></div>
    <?php endif; ?>
  </div>
</div>

</div>

<?php if($activeExams): ?>
<div class="card">
  <div class="card-header"><span class="card-title" style="color:var(--warning);">&#x1F4DD; Open Exams</span><a href="/educore/modules/exams/student.php" class="btn btn-ghost btn-sm">All Exams</a></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;padding:16px;">
    <?php foreach($activeExams as $ex):
      $done=$ex['my_status']&&in_array($ex['my_status'],['submitted','graded']);
      $inProg=$ex['my_status']==='in_progress';
    ?>
    <div style="background:var(--bg-elevated);border:1px solid <?=$done?'var(--border)':($inProg?'rgba(245,158,11,0.3)':'rgba(245,158,11,0.3)')?>;border-radius:var(--radius-md);padding:14px;">
      <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:3px;"><?=ucfirst($ex['type'])?></div>
      <div style="font-weight:700;color:var(--text-primary);font-size:0.9rem;margin-bottom:8px;line-height:1.3;"><?=sanitize($ex['title'])?></div>
      <div style="font-size:0.73rem;color:var(--text-muted);margin-bottom:10px;">&#x23F1; <?=$ex['duration_minutes']?> min &nbsp;&middot;&nbsp; Pass: <?=$ex['passing_score']?>%</div>
      <?php if($done): ?>
      <span class="badge badge-success" style="font-size:0.68rem;">&#x2713; <?=round($ex['my_score'])?>%</span>
      <?php elseif($inProg): ?>
      <a href="/educore/modules/exams/take.php?id=<?=$ex['id']?>" class="btn btn-sm w-full" style="background:var(--warning-dim);color:var(--warning);border:1px solid rgba(245,158,11,0.3);font-size:0.8rem;">Resume &rarr;</a>
      <?php else: ?>
      <a href="/educore/modules/exams/take.php?id=<?=$ex['id']?>" class="btn btn-primary btn-sm w-full" style="font-size:0.8rem;">Start Exam &rarr;</a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<style>@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.6;transform:scale(1.3)}}</style>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
