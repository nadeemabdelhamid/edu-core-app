<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('student');

$pdo = getDB();
$uid = authId();
$cid = centerId();

$pageTitle  = 'Mark Attendance';
$activePage = 'attendance';

$stStmt = $pdo->prepare("SELECT * FROM students WHERE user_id=? AND center_id=? AND is_active=1");
$stStmt->execute([$uid, $cid]);
$student = $stStmt->fetch();
if (!$student) { flash('error','Student profile not found. Contact your teacher.'); redirect('/educore/modules/students/home.php'); }

$errors = [];
$success = false;
$successSession = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $code = strtoupper(trim($_POST['code'] ?? ''));

    if (!$code) {
        $errors[] = 'Please enter the attendance code.';
    } elseif (!preg_match('/^[A-Z0-9]{6}$/', $code)) {
        $errors[] = 'Code must be exactly 6 characters.';
    } else {
        $sessStmt = $pdo->prepare("
            SELECT s.*, g.name AS group_name, g.subject
            FROM attendance_sessions s
            JOIN `groups` g ON g.id=s.group_id
            WHERE s.session_code=? AND s.center_id=? AND s.status='open' AND s.code_expires_at > NOW()");
        $sessStmt->execute([$code, $cid]);
        $session = $sessStmt->fetch();

        if (!$session) {
            $errors[] = 'Code is invalid or has expired. Ask your teacher for the current code.';
        } else {
            $enrollStmt = $pdo->prepare("SELECT id FROM group_students WHERE group_id=? AND student_id=? AND status='active'");
            $enrollStmt->execute([$session['group_id'], $student['id']]);
            if (!$enrollStmt->fetch()) {
                $errors[] = 'You are not enrolled in this group.';
            } else {
                $dupStmt = $pdo->prepare("SELECT id FROM attendance_records WHERE session_id=? AND student_id=?");
                $dupStmt->execute([$session['id'], $student['id']]);
                if ($dupStmt->fetch()) {
                    $errors[] = 'You have already submitted attendance for this session.';
                } else {
                    $pdo->prepare("INSERT INTO attendance_records (session_id,student_id,center_id,status,submitted_at,ip_address) VALUES (?,?,?,'present',NOW(),?)")
                        ->execute([$session['id'], $student['id'], $cid, $_SERVER['REMOTE_ADDR']??null]);
                    $success = true;
                    $successSession = $session;
                    auditLog('attendance.submitted','attendance_record',$session['id'],['student_id'=>$student['id'],'group'=>$session['group_name']]);
                }
            }
        }
    }
}

// Full attendance history with pagination
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page-1)*$perPage;

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_records ar JOIN attendance_sessions a ON a.id=ar.session_id WHERE ar.student_id=? AND ar.center_id=?");
$totalStmt->execute([$student['id'],$cid]);
$totalRecs = (int)$totalStmt->fetchColumn();
$lastPage  = (int)ceil($totalRecs/$perPage);

$histStmt = $pdo->prepare("
    SELECT ar.status, ar.submitted_at, a.topic, a.session_date, g.name AS group_name
    FROM attendance_records ar
    JOIN attendance_sessions a ON a.id=ar.session_id
    JOIN `groups` g ON g.id=a.group_id
    WHERE ar.student_id=? AND ar.center_id=?
    ORDER BY a.session_date DESC, ar.submitted_at DESC
    LIMIT $perPage OFFSET $offset");
$histStmt->execute([$student['id'],$cid]);
$history = $histStmt->fetchAll();

// Per-group rates
$rateStmt = $pdo->prepare("
    SELECT g.name AS group_name, g.subject,
           COUNT(s.id) AS total,
           SUM(CASE WHEN ar.status IN('present','late') THEN 1 ELSE 0 END) AS present_count,
           ROUND(100.0 * SUM(CASE WHEN ar.status IN('present','late') THEN 1 ELSE 0 END) / NULLIF(COUNT(s.id),0),1) AS rate
    FROM group_students gs
    JOIN `groups` g ON g.id=gs.group_id
    JOIN attendance_sessions s ON s.group_id=gs.group_id AND s.center_id=? AND s.status='closed'
    LEFT JOIN attendance_records ar ON ar.session_id=s.id AND ar.student_id=gs.student_id
    WHERE gs.student_id=? AND gs.status='active'
    GROUP BY g.id ORDER BY g.name");
$rateStmt->execute([$cid, $student['id']]);
$groupRates = $rateStmt->fetchAll();

// Overall stats
$overallStmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status='present') AS present, SUM(status='late') AS late, SUM(status='absent') AS absent, SUM(status='excused') AS excused FROM attendance_records WHERE student_id=? AND center_id=?");
$overallStmt->execute([$student['id'],$cid]);
$overall = $overallStmt->fetch();
$totalO  = (int)$overall['total'];
$rateO   = $totalO > 0 ? round((($overall['present']+$overall['late'])/$totalO)*100) : 0;

$badgeMap = ['present'=>'badge-success','absent'=>'badge-danger','late'=>'badge-warning','excused'=>'badge-info'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">Attendance</h1><p class="page-subtitle">Mark your attendance and view your history</p></div>
  <a href="/educore/modules/students/home.php" class="btn btn-ghost">&larr; Home</a>
</div>

<div style="display:grid;grid-template-columns:380px 1fr;gap:24px;align-items:start;">

<!-- Left: code input -->
<div>
  <?php if($success && $successSession): ?>
  <div class="card" style="border-color:rgba(34,197,94,0.35);margin-bottom:16px;">
    <div class="card-body" style="text-align:center;padding:36px 24px;">
      <div style="width:64px;height:64px;background:rgba(34,197,94,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:28px;">&#x2705;</div>
      <div style="font-family:var(--font-display);font-size:1.3rem;font-weight:800;color:var(--success);margin-bottom:6px;">Attendance Recorded!</div>
      <div style="font-size:0.88rem;color:var(--text-secondary);margin-bottom:4px;"><strong><?=sanitize($successSession['group_name'])?></strong><?php if($successSession['topic']): ?> &mdash; <?=sanitize($successSession['topic'])?><?php endif; ?></div>
      <div style="font-size:0.75rem;color:var(--text-muted);"><?=date('l, M j, Y \&middot; H:i')?></div>
      <a href="submit.php" class="btn btn-ghost btn-sm" style="margin-top:16px;">Mark Another &rarr;</a>
    </div>
  </div>
  <?php else: ?>
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header"><span class="card-title">Enter Session Code</span></div>
    <div class="card-body">
      <?php if($errors): ?>
      <div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-sm);padding:12px 14px;font-size:0.83rem;color:var(--danger);margin-bottom:16px;">
        <?php foreach($errors as $e): ?><div>&bull; <?=sanitize($e)?></div><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <form method="POST" autocomplete="off">
        <?=csrf_field()?>
        <div class="form-group" style="margin-bottom:20px;">
          <input type="text" name="code" id="codeInput"
                 class="form-control"
                 placeholder="------"
                 maxlength="6"
                 style="text-align:center;font-family:var(--font-display);font-size:2.6rem;font-weight:800;letter-spacing:0.5em;text-transform:uppercase;padding:20px 10px;border-width:2px;"
                 oninput="formatCode(this)" autofocus required>
          <div style="text-align:center;margin-top:8px;font-size:0.76rem;color:var(--text-muted);">6-character code from your teacher</div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-full" id="submitBtn" disabled>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          Submit Attendance
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Group rates -->
  <?php if($groupRates): ?>
  <div class="card">
    <div class="card-header"><span class="card-title">My Rates by Group</span></div>
    <div class="card-body no-padding">
      <?php foreach($groupRates as $gr):
        $rate=(float)$gr['rate'];
        $rc=$rate>=80?'var(--success)':($rate>=60?'var(--warning)':'var(--danger)');
      ?>
      <div style="padding:12px 20px;border-bottom:1px solid var(--border);">
        <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
          <div>
            <div style="font-size:0.84rem;font-weight:500;color:var(--text-primary);"><?=sanitize($gr['group_name'])?></div>
            <div style="font-size:0.7rem;color:var(--text-muted);"><?=(int)$gr['present_count']?>/<?=(int)$gr['total']?> sessions<?=$gr['subject']?' &middot; '.sanitize($gr['subject']):''?></div>
          </div>
          <div style="font-size:1rem;font-weight:700;color:<?=$rc?>;"><?=$rate>0?$rate.'%':'—'?></div>
        </div>
        <?php if($rate>0): ?>
        <div style="height:5px;background:var(--bg-elevated);border-radius:3px;overflow:hidden;">
          <div style="height:100%;width:<?=$rate?>%;background:<?=$rc?>;border-radius:3px;transition:width 0.6s ease;"></div>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Right: history -->
<div>
  <!-- Overall stats mini row -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">
    <?php
    $statItems=[
      ['Overall Rate',$rateO.'%',$rateO>=80?'var(--success)':($rateO>=60?'var(--warning)':'var(--danger)'),'rgba(34,197,94,0.08)'],
      ['Present',(int)$overall['present']+(int)$overall['late'],'var(--success)','rgba(34,197,94,0.08)'],
      ['Absent',(int)$overall['absent'],'var(--danger)','rgba(239,68,68,0.08)'],
      ['Excused',(int)$overall['excused'],'#06b6d4','rgba(6,182,212,0.08)'],
    ];
    foreach($statItems as [$l,$v,$c,$bg]):
    ?>
    <div class="stat-card" style="--stat-color:<?=$c?>;--stat-bg:<?=$bg?>">
      <div class="stat-value" style="font-size:1.3rem;"><?=$v?></div>
      <div class="stat-label"><?=$l?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="card-title">Attendance History</span>
      <span style="font-size:0.75rem;color:var(--text-muted);"><?=$totalRecs?> total records</span>
    </div>
    <div class="card-body no-padding">
      <?php if($history): ?>
      <table class="data-table">
        <thead><tr><th>Date</th><th>Group</th><th>Topic</th><th>Status</th><th>Time</th></tr></thead>
        <tbody>
          <?php foreach($history as $rec): ?>
          <tr>
            <td style="font-size:0.8rem;color:var(--text-muted);white-space:nowrap;"><?=date('M j, Y',strtotime($rec['session_date']))?></td>
            <td class="cell-primary" style="font-size:0.84rem;"><?=sanitize($rec['group_name'])?></td>
            <td style="font-size:0.78rem;color:var(--text-muted);"><?=sanitize($rec['topic']?:'—')?></td>
            <td><span class="badge <?=$badgeMap[$rec['status']]?>"><?=ucfirst($rec['status'])?></span></td>
            <td style="font-size:0.75rem;color:var(--text-muted);"><?=$rec['submitted_at']?date('H:i',strtotime($rec['submitted_at'])):'Auto'?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if($lastPage > 1): ?>
      <div class="pagination">
        <span>Page <?=$page?> of <?=$lastPage?></span>
        <div class="page-btns">
          <?php for($i=1;$i<=$lastPage;$i++): ?>
          <a href="?page=<?=$i?>" class="page-btn <?=$i===$page?'active':''?>"><?=$i?></a>
          <?php endfor; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div class="empty-state" style="padding:40px;">
        <div class="empty-icon">&#x1F4CB;</div>
        <div class="empty-title">No attendance records yet</div>
        <div class="empty-desc">Enter a session code from your teacher to mark your first attendance.</div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

</div><!-- grid -->

<script>
function formatCode(input) {
    input.value = input.value.toUpperCase().replace(/[^A-Z0-9]/g,'');
    const btn = document.getElementById('submitBtn');
    if (btn) {
        btn.disabled = input.value.length !== 6;
        if (input.value.length === 6) {
            input.style.borderColor = 'var(--accent)';
            input.style.boxShadow   = '0 0 0 3px var(--accent-glow)';
        } else {
            input.style.borderColor = '';
            input.style.boxShadow   = '';
        }
    }
}
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
