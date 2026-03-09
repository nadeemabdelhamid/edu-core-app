<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher');

$pdo = getDB();
$cid = centerId();
$uid = authId();
$eid = (int)($_GET['id'] ?? 0);
if (!$eid) redirect('/educore/modules/exams/');

// Load exam — owner sees all, teacher sees own
$eStmt = $pdo->prepare("SELECT e.*,g.name AS group_name
    FROM exams e
    LEFT JOIN `groups` g ON g.id=e.group_id
    WHERE e.id=? AND e.center_id=?");
$eStmt->execute([$eid,$cid]);
$exam = $eStmt->fetch();
if (!$exam) { flash('error','Exam not found.'); redirect('/educore/modules/exams/'); }
if (!isRole('owner') && $exam['created_by'] !== $uid) {
    flash('error','You can only edit your own exams.');
    redirect('/educore/modules/exams/');
}

$isActive  = $exam['status'] === 'active';
$isClosed  = in_array($exam['status'], ['closed','archived']);
// Active/closed exams: only allow changing non-structural settings
$limitedEdit = $isActive || $isClosed;

$pageTitle  = 'Edit — '.sanitize($exam['title']);
$activePage = 'exams';
$breadcrumbs = [
    ['label'=>'Dashboard','href'=>'/educore/dashboard.php'],
    ['label'=>'Exams','href'=>'/educore/modules/exams/'],
    ['label'=>sanitize($exam['title']),'href'=>"view.php?id=$eid"],
    ['label'=>'Edit'],
];

$groups = $pdo->prepare("SELECT id,name FROM `groups` WHERE center_id=? AND is_active=1 ORDER BY name");
$groups->execute([$cid]);
$groups = $groups->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $instructions= trim($_POST['instructions'] ?? '');
    $type        = in_array($_POST['type']??'',['exam','quiz','practice']) ? $_POST['type'] : $exam['type'];
    $groupId     = (int)($_POST['group_id'] ?? 0) ?: null;
    $duration    = max(5, min(480, (int)($_POST['duration_minutes'] ?? 60)));
    $passing     = max(0, min(100, (float)($_POST['passing_score'] ?? 50)));
    $startsAt    = !empty($_POST['starts_at']) ? date('Y-m-d H:i:s', strtotime($_POST['starts_at'])) : null;
    $endsAt      = !empty($_POST['ends_at'])   ? date('Y-m-d H:i:s', strtotime($_POST['ends_at']))   : null;
    $showResults = in_array($_POST['show_results']??'',['immediately','after_close','manual'])
                    ? $_POST['show_results'] : $exam['show_results'];
    $shuffle_q   = isset($_POST['shuffle_questions']) ? 1 : 0;
    $shuffle_o   = isset($_POST['shuffle_options'])   ? 1 : 0;
    $allow_review= isset($_POST['allow_review'])      ? 1 : 0;
    $max_attempts= max(1, min(10, (int)($_POST['max_attempts'] ?? 1)));

    if (!$title) $errors[] = 'Title is required.';

    if (!$errors) {
        if ($limitedEdit) {
            // Active/closed: only safe fields
            $pdo->prepare("UPDATE exams SET
                title=?, description=?, instructions=?,
                ends_at=?, passing_score=?, show_results=?,
                allow_review=?, max_attempts=?
                WHERE id=? AND center_id=?")
                ->execute([$title,$description,$instructions,
                           $endsAt,$passing,$showResults,
                           $allow_review,$max_attempts,
                           $eid,$cid]);
        } else {
            // Draft/scheduled: full edit
            $pdo->prepare("UPDATE exams SET
                title=?, description=?, instructions=?, type=?, group_id=?,
                duration_minutes=?, passing_score=?, starts_at=?, ends_at=?,
                show_results=?, shuffle_questions=?, shuffle_options=?,
                allow_review=?, max_attempts=?
                WHERE id=? AND center_id=?")
                ->execute([$title,$description,$instructions,$type,$groupId,
                           $duration,$passing,$startsAt,$endsAt,
                           $showResults,$shuffle_q,$shuffle_o,
                           $allow_review,$max_attempts,
                           $eid,$cid]);
        }
        auditLog('exam.updated','exam',$eid,['title'=>$title]);
        flash('success','Exam settings updated.');
        redirect("view.php?id=$eid");
    }
}

// Prefill from DB or POST
$v = fn($f) => sanitize($_POST[$f] ?? $exam[$f] ?? '');
$checked = fn($f) => (isset($_POST[$f]) || (!isset($_POST['_submitted']) && $exam[$f])) ? 'checked' : '';

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Edit Exam</h1>
    <p class="page-subtitle"><?=sanitize($exam['title'])?>
      <?php if($limitedEdit): ?>
      <span style="font-size:.75rem;padding:2px 8px;border-radius:10px;background:rgba(245,158,11,.12);
                   color:var(--warning);margin-left:8px;">
        <?=$isActive?'Active':'Closed'?> — structural fields locked
      </span>
      <?php endif; ?>
    </p>
  </div>
  <a href="view.php?id=<?=$eid?>" class="btn btn-ghost">← Back</a>
</div>

<?php if($limitedEdit): ?>
<div style="margin-bottom:20px;padding:12px 18px;background:rgba(245,158,11,.08);
            border:1px solid rgba(245,158,11,.25);border-radius:var(--radius-md);
            font-size:.83rem;color:var(--warning);">
  ⚠️ This exam is <strong><?=ucfirst($exam['status'])?></strong>.
  <?php if($isActive): ?>
    You can update title, description, instructions, end time, passing score, and result settings.
    To edit questions, <a href="builder.php?id=<?=$eid?>" style="color:var(--accent);">close the exam first</a>.
  <?php else: ?>
    You can update title, description, instructions, and result settings only.
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if($errors): ?>
<div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,.25);border-radius:var(--radius-md);
            padding:14px 18px;font-size:.84rem;color:var(--danger);margin-bottom:20px;">
  <?php foreach($errors as $e): ?><div>• <?=sanitize($e)?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" id="editForm">
  <?=csrf_field()?>
  <input type="hidden" name="_submitted" value="1">

  <div style="display:grid;grid-template-columns:1fr 340px;gap:24px;">
  <div>

    <!-- Basic Info -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><span class="card-title">Basic Info</span></div>
      <div class="card-body">

        <div class="form-group">
          <label class="form-label required">Exam Title</label>
          <input type="text" name="title" class="form-control"
                 value="<?=$v('title')?>" required autofocus>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group">
            <label class="form-label required">Type</label>
            <select name="type" class="form-control" <?=$limitedEdit?'disabled':''?>>
              <?php foreach(['exam'=>'Exam','quiz'=>'Quiz','practice'=>'Practice Test'] as $val=>$lbl): ?>
              <option value="<?=$val?>" <?=($exam['type']===$val)?'selected':''?>><?=$lbl?></option>
              <?php endforeach; ?>
            </select>
            <?php if($limitedEdit): ?><span class="form-hint">Locked while exam is <?=$exam['status']?></span><?php endif; ?>
          </div>
          <div class="form-group">
            <label class="form-label">Assigned Group</label>
            <select name="group_id" class="form-control" <?=$limitedEdit?'disabled':''?>>
              <option value="">No group (open to all)</option>
              <?php foreach($groups as $g): ?>
              <option value="<?=$g['id']?>" <?=$exam['group_id']==$g['id']?'selected':''?>>
                <?=sanitize($g['name'])?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2"
                    placeholder="Brief description shown to students"><?=$v('description')?></textarea>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Instructions</label>
          <textarea name="instructions" class="form-control" rows="3"
                    placeholder="Instructions shown before exam starts"><?=$v('instructions')?></textarea>
        </div>
      </div>
    </div>

    <!-- Timing -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><span class="card-title">Timing</span></div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group">
            <label class="form-label">Start Date &amp; Time</label>
            <input type="datetime-local" name="starts_at" class="form-control"
                   value="<?=sanitize($exam['starts_at']?date('Y-m-d\TH:i',strtotime($exam['starts_at'])):'');?>"
                   <?=$limitedEdit?'disabled':''?>>
            <?php if($limitedEdit): ?>
            <span class="form-hint">Locked while <?=$exam['status']?></span>
            <?php else: ?>
            <span class="form-hint">Leave blank to start manually</span>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label class="form-label">End Date &amp; Time</label>
            <input type="datetime-local" name="ends_at" class="form-control"
                   value="<?=sanitize($exam['ends_at']?date('Y-m-d\TH:i',strtotime($exam['ends_at'])):'');?>">
            <span class="form-hint">Leave blank for no auto-close</span>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label required">Time Limit</label>
          <div style="display:flex;align-items:center;gap:12px;">
            <input type="number" name="duration_minutes" class="form-control" style="width:120px;"
                   value="<?=(int)$exam['duration_minutes']?>" min="5" max="480"
                   <?=$limitedEdit?'disabled':''?>>
            <span style="color:var(--text-muted);font-size:.84rem;">minutes (5–480)</span>
          </div>
          <?php if($limitedEdit): ?><span class="form-hint">Locked — would affect active submissions</span><?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Scoring -->
    <div class="card">
      <div class="card-header"><span class="card-title">Scoring &amp; Behaviour</span></div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group">
            <label class="form-label">Passing Score (%)</label>
            <input type="number" name="passing_score" class="form-control"
                   value="<?=(float)$exam['passing_score']?>" min="0" max="100" step="0.5">
          </div>
          <div class="form-group">
            <label class="form-label">Max Attempts</label>
            <input type="number" name="max_attempts" class="form-control"
                   value="<?=(int)$exam['max_attempts']?>" min="1" max="10">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Show Results To Students</label>
          <select name="show_results" class="form-control">
            <option value="immediately" <?=$exam['show_results']==='immediately'?'selected':''?>>Immediately after submit</option>
            <option value="after_close" <?=$exam['show_results']==='after_close'?'selected':''?>>After exam closes</option>
            <option value="manual"      <?=$exam['show_results']==='manual'?'selected':''?>>Manual release</option>
          </select>
        </div>

        <div style="display:flex;flex-direction:column;gap:10px;">
          <?php foreach([
            ['shuffle_questions','Shuffle question order for each student'],
            ['shuffle_options',  'Shuffle MCQ answer options'],
            ['allow_review',     'Allow students to review answers after submission'],
          ] as [$name,$label]): ?>
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:.85rem;
                        <?=$limitedEdit&&$name!=='allow_review'?'opacity:.5;pointer-events:none;':''?>">
            <input type="checkbox" name="<?=$name?>"
                   <?=$exam[$name]?'checked':''?>
                   <?=$limitedEdit&&$name!=='allow_review'?'disabled':''?>
                   style="width:16px;height:16px;accent-color:var(--accent);">
            <?=$label?>
          </label>
          <?php endforeach; ?>
        </div>

        <div style="display:flex;gap:10px;margin-top:24px;">
          <button type="submit" class="btn btn-primary btn-lg" style="flex:1;">
            💾 Save Changes
          </button>
          <a href="view.php?id=<?=$eid?>" class="btn btn-ghost btn-lg">Cancel</a>
        </div>
      </div>
    </div>

  </div>

  <!-- Right sidebar: summary -->
  <div>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><span class="card-title">Current Status</span></div>
      <div class="card-body" style="font-size:.82rem;">
        <?php
        $statusColors = ['draft'=>'var(--text-muted)','scheduled'=>'var(--info)',
                         'active'=>'var(--success)','closed'=>'var(--warning)','archived'=>'var(--text-muted)'];
        $rows = [
          'Status'    => '<span style="font-weight:700;color:'.($statusColors[$exam['status']]??'var(--text-muted)').';">'.ucfirst($exam['status']).'</span>',
          'Type'      => ucfirst($exam['type']),
          'Group'     => sanitize($exam['group_name'] ?? 'Open / No group'),
          'Questions' => (function() use($pdo,$eid) {
              $c=$pdo->prepare("SELECT COUNT(*) FROM exam_questions WHERE exam_id=?");
              $c->execute([$eid]); return $c->fetchColumn().' questions';
          })(),
          'Submissions'=> (function() use($pdo,$eid) {
              $c=$pdo->prepare("SELECT COUNT(*) FROM exam_submissions WHERE exam_id=? AND status IN('submitted','graded')");
              $c->execute([$eid]); return $c->fetchColumn().' submitted';
          })(),
        ];
        foreach($rows as $k=>$val): ?>
        <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);">
          <span style="color:var(--text-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;"><?=$k?></span>
          <span style="font-weight:500;"><?=$val?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if(!$limitedEdit): ?>
    <div class="card" style="border-color:rgba(239,68,68,.2);">
      <div class="card-header"><span class="card-title" style="color:var(--danger);">Danger Zone</span></div>
      <div class="card-body">
        <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:12px;">
          Permanently delete this exam and all its questions. Submissions are preserved for records.
        </p>
        <button type="button" onclick="confirmDelete()"
                class="btn btn-danger w-full" style="font-size:.82rem;">
          🗑 Delete Exam
        </button>
      </div>
    </div>
    <?php endif; ?>
  </div>
  </div>
</form>

<!-- Hidden delete form -->
<form id="deleteForm" method="POST" action="view.php?id=<?=$eid?>">
  <?=csrf_field()?>
  <input type="hidden" name="action" value="delete">
</form>

<script>
function confirmDelete() {
  if (confirm('Delete "<?=addslashes(sanitize($exam['title']))?>"?\n\nThis will permanently remove the exam and all questions. Submissions will be kept for records.\n\nThis cannot be undone.')) {
    document.getElementById('deleteForm').submit();
  }
}
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
