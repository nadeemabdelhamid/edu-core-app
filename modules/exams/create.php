<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher');

$pdo = getDB();
$cid = centerId();
$uid = authId();

$pageTitle  = 'Create Exam';
$activePage = 'exams';
$breadcrumbs = [
    ['label'=>'Dashboard','href'=>'/educore/dashboard.php'],
    ['label'=>'Exams','href'=>'/educore/modules/exams/'],
    ['label'=>'Create Exam'],
];

$gStmt = $pdo->prepare("SELECT id,name,subject FROM `groups` WHERE center_id=? AND is_active=1 ORDER BY name");
$gStmt->execute([$cid]);
$groups = $gStmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();

    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $instructions= trim($_POST['instructions'] ?? '');
    $type        = in_array($_POST['type']??'',['exam','quiz','practice']) ? $_POST['type'] : 'exam';
    $groupId     = (int)($_POST['group_id'] ?? 0) ?: null;
    $duration    = max(5, min(480, (int)($_POST['duration_minutes'] ?? 60)));
    $passing     = max(0, min(100, (float)($_POST['passing_score'] ?? 50)));
    $startsAt    = $_POST['starts_at'] ? date('Y-m-d H:i:s', strtotime($_POST['starts_at'])) : null;
    $endsAt      = $_POST['ends_at']   ? date('Y-m-d H:i:s', strtotime($_POST['ends_at']))   : null;
    $showResults = in_array($_POST['show_results']??'',['immediately','after_close','manual']) ? $_POST['show_results'] : 'after_close';
    $shuffle_q        = isset($_POST['shuffle_questions']) ? 1 : 0;
    $shuffle_o        = isset($_POST['shuffle_options'])   ? 1 : 0;
    $allow_review     = isset($_POST['allow_review'])      ? 1 : 0;
    $max_attempts     = max(1, min(10, (int)($_POST['max_attempts'] ?? 1)));
    $exam_password    = trim($_POST['exam_password'] ?? '') ?: null;
    $force_fullscreen = isset($_POST['force_fullscreen']) ? 1 : 0;
    $block_copy_paste = isset($_POST['block_copy_paste']) ? 1 : 0;

    if (!$title) $errors[] = 'Title is required.';
    if ($groupId) {
        $gChk = $pdo->prepare("SELECT id FROM `groups` WHERE id=? AND center_id=?");
        $gChk->execute([$groupId,$cid]);
        if (!$gChk->fetch()) { $errors[] = 'Invalid group.'; $groupId = null; }
    }

    if (!$errors) {
        $status = ($startsAt && strtotime($startsAt) > time()) ? 'scheduled' : 'draft';
        $pdo->prepare("INSERT INTO exams (center_id,group_id,created_by,title,description,instructions,type,status,starts_at,ends_at,duration_minutes,passing_score,shuffle_questions,shuffle_options,show_results,allow_review,max_attempts,exam_password,force_fullscreen,block_copy_paste)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$cid,$groupId,$uid,$title,$description,$instructions,$type,$status,$startsAt,$endsAt,$duration,$passing,$shuffle_q,$shuffle_o,$showResults,$allow_review,$max_attempts,$exam_password,$force_fullscreen,$block_copy_paste]);
        $examId = $pdo->lastInsertId();
        auditLog('exam.created','exam',$examId,['title'=>$title,'type'=>$type]);
        flash('success',"Exam created! Now add questions.");
        redirect("builder.php?id=$examId");
    }
}

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">Create Exam</h1><p class="page-subtitle">Set up exam details, then add questions in the builder</p></div>
  <a href="index.php" class="btn btn-ghost">← Back</a>
</div>

<?php if($errors): ?>
<div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-md);padding:14px 18px;font-size:0.84rem;color:var(--danger);margin-bottom:20px;">
  <?php foreach($errors as $e): ?><div>• <?=sanitize($e)?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:24px;">
<div>
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><span class="card-title">Basic Info</span></div>
    <div class="card-body">
      <form method="POST" id="examForm">
        <?=csrf_field()?>

        <div class="form-group">
          <label class="form-label required">Exam Title</label>
          <input type="text" name="title" class="form-control" value="<?=sanitize($_POST['title']??'')?>" placeholder="e.g. Midterm Exam — Algebra Chapter 1–4" required autofocus>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group">
            <label class="form-label required">Type</label>
            <select name="type" class="form-control">
              <?php foreach(['exam'=>'Exam','quiz'=>'Quiz','practice'=>'Practice Test'] as $v=>$l): ?>
              <option value="<?=$v?>" <?=($_POST['type']??'exam')===$v?'selected':''?>><?=$l?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Assigned Group</label>
            <select name="group_id" class="form-control">
              <option value="">No group (open)</option>
              <?php foreach($groups as $g): ?>
              <option value="<?=$g['id']?>" <?=($_POST['group_id']??'')==$g['id']?'selected':''?>><?=sanitize($g['name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2" placeholder="Brief description shown to students"><?=sanitize($_POST['description']??'')?></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Instructions</label>
          <textarea name="instructions" class="form-control" rows="3" placeholder="Instructions shown before the exam starts"><?=sanitize($_POST['instructions']??'')?></textarea>
        </div>
      </div>
    </div>

  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><span class="card-title">Timing</span></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group">
            <label class="form-label">Start Date &amp; Time</label>
            <input type="datetime-local" name="starts_at" class="form-control" value="<?=sanitize($_POST['starts_at']??'')?>">
            <span class="form-hint">Leave blank to start manually</span>
          </div>
          <div class="form-group">
            <label class="form-label">End Date &amp; Time</label>
            <input type="datetime-local" name="ends_at" class="form-control" value="<?=sanitize($_POST['ends_at']??'')?>">
            <span class="form-hint">Leave blank for no auto-close</span>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label required">Time Limit (per student)</label>
          <div style="display:flex;align-items:center;gap:12px;">
            <input type="number" name="duration_minutes" class="form-control" style="width:120px;" value="<?=(int)($_POST['duration_minutes']??60)?>" min="5" max="480">
            <span style="color:var(--text-muted);font-size:0.84rem;">minutes (5–480)</span>
          </div>
        </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">Scoring &amp; Behaviour</span></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group">
            <label class="form-label">Passing Score (%)</label>
            <input type="number" name="passing_score" class="form-control" value="<?=(float)($_POST['passing_score']??50)?>" min="0" max="100" step="0.5">
          </div>
          <div class="form-group">
            <label class="form-label">Max Attempts</label>
            <input type="number" name="max_attempts" class="form-control" value="<?=(int)($_POST['max_attempts']??1)?>" min="1" max="10">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Show Results To Students</label>
          <select name="show_results" class="form-control">
            <option value="immediately" <?=($_POST['show_results']??'after_close')==='immediately'?'selected':''?>>Immediately after submit</option>
            <option value="after_close" <?=($_POST['show_results']??'after_close')==='after_close'?'selected':''?>>After exam closes</option>
            <option value="manual"      <?=($_POST['show_results']??'')==='manual'?'selected':''?>>Manual release</option>
          </select>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;">
          <?php foreach([
            ['shuffle_questions','Shuffle question order for each student'],
            ['shuffle_options',  'Shuffle MCQ answer options'],
            ['allow_review',     'Allow students to review answers after submission'],
          ] as [$name,$label]):
            $checked = isset($_POST[$name]) || ($name==='allow_review' && !isset($_POST['_submitted']));
          ?>
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:0.85rem;">
            <input type="checkbox" name="<?=$name?>" <?=$checked?'checked':''?> style="width:16px;height:16px;accent-color:var(--accent);">
            <?=$label?>
          </label>
          <?php endforeach; ?>
        </div>
        <div class="form-group" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
          <label class="form-label">Security Settings</label>
          <div style="display:flex;flex-direction:column;gap:10px;">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:.85rem;">
              <input type="checkbox" name="force_fullscreen" <?=isset($_POST['force_fullscreen'])?'checked':''?> style="width:16px;height:16px;accent-color:var(--accent);">
              🖥 Force fullscreen mode (exiting counts as violation)
            </label>
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:.85rem;">
              <input type="checkbox" name="block_copy_paste" <?=isset($_POST['block_copy_paste'])?'checked':''?> style="width:16px;height:16px;accent-color:var(--accent);">
              🚫 Block copy/paste and right-click
            </label>
            <div class="form-group" style="margin-bottom:0;margin-top:4px;">
              <label class="form-label" style="font-size:.78rem;">Exam Password <span style="color:var(--text-muted);font-weight:400;">(leave blank for no password)</span></label>
              <input type="text" name="exam_password" class="form-control" style="font-size:.84rem;" placeholder="e.g. math2025" value="<?=sanitize($_POST['exam_password']??'')?>">
            </div>
          </div>
        </div>
        <input type="hidden" name="_submitted" value="1">
        <button type="submit" class="btn btn-primary btn-lg w-full" style="margin-top:20px;">
          Continue to Question Builder →
        </button>
      </form>
    </div>
  </div>
</div>

<!-- Right: how it works -->
<div>
  <div class="card">
    <div class="card-header"><span class="card-title">Workflow</span></div>
    <div class="card-body" style="font-size:0.83rem;">
      <?php foreach([
        ['1','Create exam','Set title, timing, group, and scoring settings.'],
        ['2','Build questions','Add MCQ, True/False, and short answer questions (or import from question bank).'],
        ['3','Activate','Publish the exam — students can open it from their portal.'],
        ['4','Auto-grade','MCQ + True/False graded instantly. Short answers flagged for manual review.'],
        ['5','Results','View per-student scores, export, and release results to students.'],
      ] as [$n,$t,$d]): ?>
      <div style="display:flex;gap:12px;margin-bottom:14px;">
        <div style="width:24px;height:24px;background:var(--accent-subtle);border:1px solid var(--accent);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:800;color:var(--accent);flex-shrink:0;"><?=$n?></div>
        <div><div style="font-weight:600;color:var(--text-primary);"><?=$t?></div><div style="color:var(--text-muted);font-size:0.78rem;margin-top:2px;"><?=$d?></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
