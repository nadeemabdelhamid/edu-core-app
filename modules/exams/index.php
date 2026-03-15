<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pdo = getDB();
$cid = centerId();
$uid = authId();

$pageTitle  = 'Exams';
$activePage = 'exams';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Exams'],
];

$status = $_GET['status'] ?? '';
$gid    = (int)($_GET['group'] ?? 0);
$type   = $_GET['type'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));

$where  = "WHERE e.center_id=?";
$params = [$cid];
if (!isRole('owner','assistant')) { $where .= " AND e.created_by=?"; $params[] = $uid; }
if ($status) { $where .= " AND e.status=?";   $params[] = $status; }
if ($gid)    { $where .= " AND e.group_id=?"; $params[] = $gid; }
if ($type)   { $where .= " AND e.type=?";     $params[] = $type; }

$query = "SELECT e.*,
                 g.name AS group_name,
                 u.name AS creator_name,
                 COUNT(DISTINCT eq.id)  AS question_count,
                 COUNT(DISTINCT es.id)  AS submission_count,
                 ROUND(AVG(es.percentage),1) AS avg_score
          FROM exams e
          LEFT JOIN `groups` g ON g.id=e.group_id
          LEFT JOIN users u ON u.id=e.created_by
          LEFT JOIN exam_questions eq ON eq.exam_id=e.id
          LEFT JOIN exam_submissions es ON es.exam_id=e.id AND es.status IN('submitted','graded')
          $where
          GROUP BY e.id
          ORDER BY e.created_at DESC";

$result = paginate($query, $params, $page, 20);
$exams  = $result['data'];

// Auto-activate scheduled exams / auto-close expired
foreach ($exams as $ex) {
    if ($ex['status']==='scheduled' && $ex['starts_at'] && strtotime($ex['starts_at']) <= time()) {
        $pdo->prepare("UPDATE exams SET status='active' WHERE id=?")->execute([$ex['id']]);
    }
    if ($ex['status']==='active' && $ex['ends_at'] && strtotime($ex['ends_at']) < time()) {
        $pdo->prepare("UPDATE exams SET status='closed' WHERE id=?")->execute([$ex['id']]);
    }
}

// Stats cards
$statsStmt = $pdo->prepare("SELECT
    COUNT(*) AS total,
    SUM(status='active') AS active,
    SUM(status='draft') AS drafts,
    SUM(status='closed') AS closed
    FROM exams WHERE center_id=?");
$statsStmt->execute([$cid]);
$stats = $statsStmt->fetch();

// Scope group dropdown for academy teachers
if (isRole('teacher') && isAcademyPlan()) {
    $gStmt = $pdo->prepare("SELECT id,name FROM `groups` WHERE center_id=? AND teacher_id=? AND is_active=1 ORDER BY name");
    $gStmt->execute([$cid, $uid]);
} else {
    $gStmt = $pdo->prepare("SELECT id,name FROM `groups` WHERE center_id=? AND is_active=1 ORDER BY name");
    $gStmt->execute([$cid]);
}
$groups = $gStmt->fetchAll();

// Handle quick delete from index
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    if ($_POST['action']==='delete') {
        $delId = (int)($_POST['exam_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id,title,status FROM exams WHERE id=? AND center_id=?");
        $chk->execute([$delId,$cid]);
        $delExam = $chk->fetch();
        if ($delExam && $delExam['status']!=='active') {
            if (!isRole('owner')) {
                // teachers can only delete own
                $own = $pdo->prepare("SELECT id FROM exams WHERE id=? AND created_by=?");
                $own->execute([$delId,$uid]);
                if (!$own->fetch()) { flash('error','Not authorized.'); redirect('index.php'); }
            }
            $pdo->prepare("DELETE FROM exam_questions WHERE exam_id=?")->execute([$delId]);
            $pdo->prepare("DELETE FROM exams WHERE id=? AND center_id=?")->execute([$delId,$cid]);
            auditLog('exam.deleted','exam',$delId,['title'=>$delExam['title']]);
            flash('success','"'.sanitize($delExam['title']).'" deleted.');
        } elseif ($delExam && $delExam['status']==='active') {
            flash('error','Close the exam before deleting it.');
        }
    }
    redirect('index.php?status='.$status.'&type='.$type.'&group='.$gid);
}

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Exams &amp; Quizzes</h1>
    <p class="page-subtitle"><?= number_format($result['total']) ?> total exams</p>
  </div>
  <div style="display:flex;gap:10px;">
    <a href="question_bank.php" class="btn btn-ghost">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
      Question Bank
    </a>
    <a href="paper_studio.php" class="btn btn-ghost">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      Paper Studio
    </a>
    <a href="create.php" class="btn btn-primary">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      New Exam
    </a>
  </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-value"><?= (int)$stats['total'] ?></div>
    <div class="stat-label">Total Exams</div>
  </div>
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)">
    <div class="stat-value"><?= (int)$stats['active'] ?></div>
    <div class="stat-label">Active Now</div>
  </div>
  <div class="stat-card" style="--stat-color:#f59e0b;--stat-bg:rgba(245,158,11,0.1)">
    <div class="stat-value"><?= (int)$stats['drafts'] ?></div>
    <div class="stat-label">Drafts</div>
  </div>
  <div class="stat-card" style="--stat-color:#8b5cf6;--stat-bg:rgba(139,92,246,0.1)">
    <div class="stat-value"><?= (int)$stats['closed'] ?></div>
    <div class="stat-label">Completed</div>
  </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:14px 20px;">
    <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <select name="status" class="form-control" style="width:140px;" onchange="this.form.submit()">
        <option value="">All Status</option>
        <?php foreach(['draft','scheduled','active','closed','archived'] as $s): ?>
        <option value="<?=$s?>" <?=$status===$s?'selected':''?>><?=ucfirst($s)?></option>
        <?php endforeach; ?>
      </select>
      <select name="type" class="form-control" style="width:120px;" onchange="this.form.submit()">
        <option value="">All Types</option>
        <option value="exam"     <?=$type==='exam'    ?'selected':''?>>Exam</option>
        <option value="quiz"     <?=$type==='quiz'    ?'selected':''?>>Quiz</option>
        <option value="practice" <?=$type==='practice'?'selected':''?>>Practice</option>
      </select>
      <select name="group" class="form-control" style="width:180px;" onchange="this.form.submit()">
        <option value="">All Groups</option>
        <?php foreach($groups as $g): ?>
        <option value="<?=$g['id']?>" <?=$gid==$g['id']?'selected':''?>><?=sanitize($g['name'])?></option>
        <?php endforeach; ?>
      </select>
      <?php if($status||$type||$gid): ?>
      <a href="index.php" class="btn btn-ghost btn-sm" style="color:var(--danger);">✕ Clear</a>
      <?php endif; ?>
      <span style="margin-left:auto;font-size:0.78rem;color:var(--text-muted);"><?=$result['total']?> exams</span>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body no-padding">
    <?php if ($exams): ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Title</th><th>Type</th><th>Group</th><th>Questions</th>
          <th>Submissions</th><th>Avg Score</th><th>Status</th><th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($exams as $ex):
          $statusBadge = [
            'draft'     => 'badge-muted',
            'scheduled' => 'badge-info',
            'active'    => 'badge-success',
            'closed'    => 'badge-warning',
            'archived'  => 'badge-muted',
          ][$ex['status']] ?? 'badge-muted';
          $typeBadge = ['exam'=>'badge-danger','quiz'=>'badge-warning','practice'=>'badge-info'][$ex['type']] ?? 'badge-muted';
          $avgColor  = $ex['avg_score'] >= $ex['passing_score'] ? 'var(--success)' : ($ex['avg_score'] > 0 ? 'var(--danger)' : 'var(--text-muted)');
        ?>
        <tr>
          <td>
            <div class="cell-primary"><?=sanitize($ex['title'])?></div>
            <div style="font-size:0.72rem;color:var(--text-muted);">
              <?=sanitize($ex['creator_name'])?>
              <?php if($ex['starts_at']): ?> · <?=date('M j, H:i',strtotime($ex['starts_at']))?><?php endif; ?>
            </div>
          </td>
          <td><span class="badge <?=$typeBadge?>"><?=ucfirst($ex['type'])?></span></td>
          <td style="color:var(--text-secondary);font-size:0.84rem;"><?=sanitize($ex['group_name']??'—')?></td>
          <td><?=(int)$ex['question_count']?></td>
          <td><?=(int)$ex['submission_count']?></td>
          <td style="font-weight:600;color:<?=$avgColor?>;"><?=$ex['avg_score']??'—'?><?=$ex['avg_score']?'%':''?></td>
          <td><span class="badge <?=$statusBadge?>"><?=ucfirst($ex['status'])?></span></td>
          <td>
            <div style="display:flex;gap:5px;justify-content:flex-end;align-items:center;">
              <a href="view.php?id=<?=$ex['id']?>" class="btn btn-ghost btn-icon" title="View">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </a>
              <a href="edit.php?id=<?=$ex['id']?>" class="btn btn-ghost btn-icon" title="Edit Settings"
                 style="color:var(--accent);">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </a>
              <?php if(in_array($ex['status'],['draft','scheduled'])): ?>
              <a href="builder.php?id=<?=$ex['id']?>" class="btn btn-ghost btn-sm">Questions</a>
              <?php endif; ?>
              <?php if(in_array($ex['status'],['active','closed'])): ?>
              <a href="results.php?id=<?=$ex['id']?>" class="btn btn-ghost btn-sm"
                 style="<?=$ex['status']==='active'?'color:var(--success);':''?>">Results</a>
              <?php endif; ?>
              <?php if($ex['status']!=='active'): ?>
              <form method="POST" style="display:inline;"
                    onsubmit="return confirm('Delete '<?=addslashes(sanitize($ex['title']))?>' and all its questions?\n\nThis cannot be undone.')">
                <?=csrf_field()?>
                <input type="hidden" name="action"   value="delete">
                <input type="hidden" name="exam_id"  value="<?=$ex['id']?>">
                <button type="submit" class="btn btn-ghost btn-icon" title="Delete"
                        style="color:var(--danger);">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if($result['last_page']>1): ?>
    <div class="pagination">
      <span>Page <?=$result['current_page']?> of <?=$result['last_page']?></span>
      <div class="page-btns">
        <?php for($i=1;$i<=$result['last_page'];$i++): ?>
        <a href="?page=<?=$i?>&status=<?=$status?>&type=<?=$type?>&group=<?=$gid?>"
           class="page-btn <?=$i===$result['current_page']?'active':''?>"><?=$i?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">📝</div>
      <div class="empty-title">No exams found</div>
      <div class="empty-desc">
        <a href="create.php" class="btn btn-primary btn-sm" style="margin-top:14px;">Create First Exam</a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
