<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pageTitle  = 'Groups';
$activePage = 'groups';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Groups'],
];

$pdo    = getDB();
$cid    = centerId();
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));

$where  = "WHERE g.center_id = ?";
$params = [$cid];

if ($search) {
    $where .= " AND (g.name LIKE ? OR g.subject LIKE ?)";
    $like   = "%$search%";
    $params = array_merge($params, [$like, $like]);
}
if ($status === 'active')   { $where .= " AND g.is_active = 1"; }
if ($status === 'inactive') { $where .= " AND g.is_active = 0"; }

$query  = "SELECT g.*,
                  u.name AS teacher_name,
                  COUNT(DISTINCT gs.student_id) AS student_count,
                  COUNT(DISTINCT ats.id) AS session_count
           FROM `groups` g
           LEFT JOIN users u ON u.id = g.teacher_id
           LEFT JOIN group_students gs ON gs.group_id = g.id AND gs.status = 'active'
           LEFT JOIN attendance_sessions ats ON ats.group_id = g.id
           $where
           GROUP BY g.id
           ORDER BY g.created_at DESC";

$result   = paginate($query, $params, $page, 15);
$groups   = $result['data'];

// Load teachers for filter
$tStmt = $pdo->prepare("SELECT id, name FROM users WHERE center_id = ? AND role IN ('owner','teacher') AND is_active = 1 ORDER BY name");
$tStmt->execute([$cid]);
$teachers = $tStmt->fetchAll();

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Groups</h1>
    <p class="page-subtitle"><?= number_format($result['total']) ?> total groups</p>
  </div>
  <?php if (isRole('owner', 'teacher')): ?>
  <a href="create.php" class="btn btn-primary">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New Group
  </a>
  <?php endif; ?>
</div>

<!-- FILTERS -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:16px 20px;">
    <form method="GET" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
      <div class="search-bar">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="q" value="<?= sanitize($search) ?>" placeholder="Search groups...">
      </div>
      <select name="status" class="form-control" style="width:140px;" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="active"   <?= $status==='active'   ? 'selected':'' ?>>Active</option>
        <option value="inactive" <?= $status==='inactive' ? 'selected':'' ?>>Inactive</option>
      </select>
      <?php if ($search || $status): ?>
        <a href="index.php" class="btn btn-ghost btn-sm">Clear</a>
      <?php endif; ?>
      <span style="margin-left:auto; font-size:0.8rem; color:var(--text-muted);">
        Showing <?= count($groups) ?> of <?= $result['total'] ?>
      </span>
    </form>
  </div>
</div>

<!-- GROUPS GRID -->
<?php if ($groups): ?>
<div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:16px; margin-bottom:20px;">
  <?php
  $subjectColors = [
    'math'    => ['#3b82f6','#1d4ed8'],
    'science' => ['#22c55e','#15803d'],
    'english' => ['#8b5cf6','#6d28d9'],
    'arabic'  => ['#f59e0b','#b45309'],
    'physics' => ['#06b6d4','#0e7490'],
    'history' => ['#ec4899','#be185d'],
    'default' => ['#f5a623','#b87b1a'],
  ];
  foreach ($groups as $g):
    $subjectKey = strtolower(explode(' ', $g['subject'] ?? '')[0]);
    $colors     = $subjectColors[$subjectKey] ?? $subjectColors['default'];
    $initials   = strtoupper(substr($g['name'], 0, 2));

    // Parse schedule — supports new multi-slot [{day,time}] and old {days:[],time:''}
    $schedRaw = $g['schedule'] ? (json_decode($g['schedule'], true) ?? []) : [];
    $scheduleSlots = [];
    if (!empty($schedRaw)) {
        if (isset($schedRaw[0]) && is_array($schedRaw[0])) {
            $scheduleSlots = $schedRaw;
        } elseif (isset($schedRaw['days']) || isset($schedRaw['time'])) {
            foreach (($schedRaw['days'] ?? []) as $d) {
                $scheduleSlots[] = ['day' => $d, 'time' => $schedRaw['time'] ?? ''];
            }
        }
    }
  ?>
  <div class="card group-card" style="position:relative; overflow:hidden; transition:transform 0.2s, border-color 0.2s;"
       onmouseover="this.style.transform='translateY(-2px)';this.style.borderColor='var(--border-light)'"
       onmouseout="this.style.transform='';this.style.borderColor=''">

    <!-- Top color bar -->
    <div style="height:3px; background:linear-gradient(90deg,<?= $colors[0] ?>,<?= $colors[1] ?>);"></div>

    <div class="card-body" style="padding:20px;">
      <div style="display:flex; align-items:flex-start; gap:14px; margin-bottom:16px;">
        <!-- Icon -->
        <div style="width:44px; height:44px; border-radius:10px; background:linear-gradient(135deg,<?= $colors[0] ?>22,<?= $colors[1] ?>22); border:1px solid <?= $colors[0] ?>33; display:flex; align-items:center; justify-content:center; font-family:var(--font-display); font-weight:800; font-size:0.85rem; color:<?= $colors[0] ?>; flex-shrink:0;">
          <?= $initials ?>
        </div>

        <div style="flex:1; min-width:0;">
          <div style="font-family:var(--font-display); font-weight:700; font-size:0.95rem; color:var(--text-primary); margin-bottom:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
            <?= sanitize($g['name']) ?>
          </div>
          <?php if ($g['subject']): ?>
          <div style="font-size:0.75rem; color:<?= $colors[0] ?>; font-weight:600;">
            <?= sanitize($g['subject']) ?>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($g['is_active']): ?>
          <span class="badge badge-success">Active</span>
        <?php else: ?>
          <span class="badge badge-muted">Inactive</span>
        <?php endif; ?>
      </div>

      <!-- Stats row -->
      <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-bottom:16px;">
        <div style="text-align:center; padding:10px 6px; background:var(--bg-elevated); border-radius:var(--radius-sm);">
          <div style="font-family:var(--font-display); font-size:1.3rem; font-weight:700; color:var(--text-primary); line-height:1;"><?= $g['student_count'] ?></div>
          <div style="font-size:0.65rem; color:var(--text-muted); margin-top:3px; text-transform:uppercase; letter-spacing:0.05em;">Students</div>
        </div>
        <div style="text-align:center; padding:10px 6px; background:var(--bg-elevated); border-radius:var(--radius-sm);">
          <div style="font-family:var(--font-display); font-size:1.3rem; font-weight:700; color:var(--text-primary); line-height:1;"><?= $g['session_count'] ?></div>
          <div style="font-size:0.65rem; color:var(--text-muted); margin-top:3px; text-transform:uppercase; letter-spacing:0.05em;">Sessions</div>
        </div>
        <div style="text-align:center; padding:10px 6px; background:var(--bg-elevated); border-radius:var(--radius-sm);">
          <div style="font-family:var(--font-display); font-size:1.3rem; font-weight:700; color:var(--text-primary); line-height:1;"><?= $g['capacity'] ?></div>
          <div style="font-size:0.65rem; color:var(--text-muted); margin-top:3px; text-transform:uppercase; letter-spacing:0.05em;">Capacity</div>
        </div>
      </div>

      <!-- Teacher + fee -->
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <div style="display:flex; align-items:center; gap:8px;">
          <div style="width:24px; height:24px; border-radius:50%; background:linear-gradient(135deg,<?= $colors[0] ?>,<?= $colors[1] ?>); display:flex; align-items:center; justify-content:center; font-size:0.6rem; font-weight:700; color:#fff;">
            <?= strtoupper(substr($g['teacher_name'] ?? 'N', 0, 1)) ?>
          </div>
          <span style="font-size:0.78rem; color:var(--text-secondary);"><?= sanitize($g['teacher_name'] ?? 'No teacher') ?></span>
        </div>
        <?php if ($g['monthly_fee'] > 0): ?>
        <span style="font-size:0.78rem; font-weight:700; color:var(--success);">
          <?= number_format($g['monthly_fee']) ?> EGP/mo
        </span>
        <?php endif; ?>
      </div>

      <!-- Schedule pills -->
      <?php if (!empty($schedule['days'])): ?>
      <div style="display:flex; flex-wrap:wrap; gap:5px; margin-bottom:14px;">
        <?php foreach ($schedule['days'] as $day): ?>
        <span style="font-size:0.68rem; padding:3px 8px; background:var(--bg-elevated); border:1px solid var(--border); border-radius:20px; color:var(--text-secondary);">
          <?= sanitize($day) ?>
          <?php if (!empty($schedule['time'])): ?> <?= sanitize($schedule['time']) ?><?php endif; ?>
        </span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Actions -->
      <div style="display:flex; gap:8px;">
        <a href="/educore/modules/groups/view.php?id=<?= $g['id'] ?>" class="btn btn-ghost btn-sm" style="flex:1; justify-content:center;">
  View
</a>
        <a href="/educore/modules/groups/enroll.php?id=<?= $g['id'] ?>" class="btn btn-sm" ...>
  Enroll
</a>
        <?php if (isRole('owner', 'teacher')): ?>
        <a href="edit.php?id=<?= $g['id'] ?>" class="btn btn-ghost btn-icon" title="Edit">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </a>
        <button class="btn btn-danger btn-icon" title="Delete"
                onclick="confirmDelete(<?= $g['id'] ?>, '<?= sanitize(addslashes($g['name'])) ?>')">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
        </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($result['last_page'] > 1): ?>
<div class="card">
  <div class="pagination">
    <span>Page <?= $result['current_page'] ?> of <?= $result['last_page'] ?></span>
    <div class="page-btns">
      <?php for ($i = 1; $i <= $result['last_page']; $i++): ?>
        <a href="?page=<?=$i?>&q=<?=urlencode($search)?>&status=<?=$status?>"
           class="page-btn <?= $i===$result['current_page']?'active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="card">
  <div class="empty-state">
    <div class="empty-icon">📚</div>
    <div class="empty-title"><?= $search ? 'No groups found' : 'No groups yet' ?></div>
    <div class="empty-desc">
      <?php if (!$search): ?>
        <a href="create.php" class="btn btn-primary btn-sm" style="margin-top:14px;">Create First Group</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<form id="deleteForm" method="POST" action="delete.php" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="id" id="deleteId">
</form>

<?php
$extraScripts = <<<JS
<script>
function confirmDelete(id, name) {
  if (confirm('Delete group "' + name + '"? All enrollments will be removed.')) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteForm').submit();
  }
}
</script>
JS;
include __DIR__ . '/../../templates/layout_end.php';
?>
