<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pdo    = getDB();
$cid    = centerId();
$gid    = (int)($_GET['id'] ?? 0);
$isNew  = isset($_GET['new']);

if (!$gid) redirect('/educore/modules/groups/');

// Verify group belongs to center
$gStmt = $pdo->prepare("SELECT * FROM `groups` WHERE id = ? AND center_id = ?");
$gStmt->execute([$gid, $cid]);
$group = $gStmt->fetch();
if (!$group) { flash('error', 'Group not found.'); redirect('/educore/modules/groups/'); }

$pageTitle  = 'Enroll Students — ' . $group['name'];
$activePage = 'groups';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Groups',    'href' => '/educore/modules/groups/'],
    ['label' => sanitize($group['name']), 'href' => "view.php?id=$gid"],
    ['label' => 'Enroll Students'],
];

// Handle POST — enroll or unenroll
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action    = $_POST['action'] ?? '';
    $studentId = (int)($_POST['student_id'] ?? 0);

    if ($studentId) {
        // Verify student belongs to center
        $sCheck = $pdo->prepare("SELECT id FROM students WHERE id=? AND center_id=?");
        $sCheck->execute([$studentId, $cid]);

        if ($sCheck->fetch()) {
            if ($action === 'enroll') {
                // Check capacity
                $enrolled = $pdo->prepare("SELECT COUNT(*) FROM group_students WHERE group_id=? AND status='active'");
                $enrolled->execute([$gid]);
                $count = (int)$enrolled->fetchColumn();

                if ($count >= $group['capacity']) {
                    flash('error', 'Group is at full capacity (' . $group['capacity'] . ' students).');
                } else {
                    // Upsert enrollment
                    $pdo->prepare("INSERT INTO group_students (group_id, student_id, center_id, status)
                                    VALUES (?,?,?,'active')
                                    ON DUPLICATE KEY UPDATE status='active', enrolled_at=NOW()")
                        ->execute([$gid, $studentId, $cid]);
                    auditLog('group.student_enrolled', 'group_student', $gid, ['student_id' => $studentId]);
                    flash('success', 'Student enrolled successfully.');
                }
            } elseif ($action === 'unenroll') {
                $pdo->prepare("UPDATE group_students SET status='inactive' WHERE group_id=? AND student_id=?")
                    ->execute([$gid, $studentId]);
                auditLog('group.student_unenrolled', 'group_student', $gid, ['student_id' => $studentId]);
                flash('success', 'Student removed from group.');
            }
        }
    }
    redirect("enroll.php?id=$gid");
}

// Currently enrolled students
$enrolledStmt = $pdo->prepare("
    SELECT s.*, gs.enrolled_at
    FROM group_students gs
    JOIN students s ON s.id = gs.student_id
    WHERE gs.group_id = ? AND gs.status = 'active'
    ORDER BY s.name
");
$enrolledStmt->execute([$gid]);
$enrolled = $enrolledStmt->fetchAll();
$enrolledIds = array_column($enrolled, 'id');

// All students NOT in this group
$search = trim($_GET['q'] ?? '');
$availWhere = "WHERE s.center_id = ? AND s.is_active = 1";
$availParams = [$cid];

if ($enrolledIds) {
    $placeholders = implode(',', array_fill(0, count($enrolledIds), '?'));
    $availWhere  .= " AND s.id NOT IN ($placeholders)";
    $availParams  = array_merge($availParams, $enrolledIds);
}
if ($search) {
    $availWhere  .= " AND (s.name LIKE ? OR s.phone LIKE ?)";
    $availParams  = array_merge($availParams, ["%$search%", "%$search%"]);
}

$availStmt = $pdo->prepare("SELECT * FROM students s $availWhere ORDER BY s.name LIMIT 50");
$availStmt->execute($availParams);
$available = $availStmt->fetchAll();

// Capacity info
$enrolledCount = count($enrolled);
$capacityPct   = $group['capacity'] > 0 ? round(($enrolledCount / $group['capacity']) * 100) : 0;

include __DIR__ . '/../../templates/layout.php';
?>

<?php if ($isNew): ?>
<div style="background:var(--accent-subtle); border:1px solid rgba(245,166,35,0.25); border-radius:var(--radius-md); padding:14px 18px; font-size:0.84rem; color:var(--accent); margin-bottom:20px; display:flex; align-items:center; gap:10px;">
  <span style="font-size:1.2rem;">🎉</span>
  <div>
    <strong>Group created!</strong> Now enroll students into this group.
    <a href="/educore/modules/groups/" style="color:var(--accent); margin-left:8px; text-decoration:underline;">Skip for now →</a>
  </div>
</div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Enroll Students</h1>
    <p class="page-subtitle"><?= sanitize($group['name']) ?> · <?= $enrolledCount ?> / <?= $group['capacity'] ?> students</p>
  </div>
  <a href="view.php?id=<?= $gid ?>" class="btn btn-ghost">← Back to Group</a>
</div>

<!-- Capacity bar -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:16px 22px;">
    <div style="display:flex; justify-content:space-between; font-size:0.8rem; color:var(--text-muted); margin-bottom:8px;">
      <span>Group Capacity</span>
      <span style="font-weight:600; color:<?= $capacityPct >= 90 ? 'var(--danger)' : ($capacityPct >= 70 ? 'var(--warning)' : 'var(--success)') ?>">
        <?= $enrolledCount ?> / <?= $group['capacity'] ?> (<?= $capacityPct ?>%)
      </span>
    </div>
    <div style="height:8px; background:var(--bg-elevated); border-radius:4px; overflow:hidden;">
      <div style="height:100%; width:<?= $capacityPct ?>%; border-radius:4px; transition:width 0.5s ease;
                  background:<?= $capacityPct >= 90 ? 'var(--danger)' : ($capacityPct >= 70 ? 'var(--warning)' : 'var(--success)') ?>">
      </div>
    </div>
  </div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">

  <!-- ENROLLED -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Enrolled (<?= $enrolledCount ?>)</span>
    </div>
    <div class="card-body no-padding">
      <?php if ($enrolled): ?>
      <div style="max-height:500px; overflow-y:auto;">
        <?php foreach ($enrolled as $s): ?>
        <?php
          $colors = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4'];
          $color  = $colors[crc32($s['name']) % count($colors)];
        ?>
        <div style="display:flex; align-items:center; gap:12px; padding:12px 18px; border-bottom:1px solid var(--border);">
          <div class="avatar avatar-sm" style="background:linear-gradient(135deg,<?=$color?>,<?=$color?>99);color:#fff;">
            <?= strtoupper(substr($s['name'],0,2)) ?>
          </div>
          <div style="flex:1; min-width:0;">
            <div style="font-size:0.85rem; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
              <?= sanitize($s['name']) ?>
            </div>
            <div style="font-size:0.7rem; color:var(--text-muted);">
              Since <?= date('M j, Y', strtotime($s['enrolled_at'])) ?>
            </div>
          </div>
          <form method="POST" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="unenroll">
            <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm"
                    onclick="return confirm('Remove <?= sanitize(addslashes($s['name'])) ?> from this group?')"
                    title="Remove">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state" style="padding:40px 20px;">
        <div class="empty-icon">👥</div>
        <div class="empty-title">No students enrolled</div>
        <div class="empty-desc">Add students from the right panel</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- AVAILABLE -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Available Students</span>
    </div>

    <!-- Search -->
    <div style="padding:12px 18px; border-bottom:1px solid var(--border);">
      <form method="GET">
        <input type="hidden" name="id" value="<?= $gid ?>">
        <div class="search-bar" style="width:100%;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" name="q" value="<?= sanitize($search) ?>" placeholder="Search students..." style="width:100%;" oninput="debounce(() => this.form.submit(), 400)()">
        </div>
      </form>
    </div>

    <div class="card-body no-padding">
      <?php if ($available): ?>
      <div style="max-height:452px; overflow-y:auto;">
       <?php foreach ($available as $s): ?>
        <?php
          $colors = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4'];
          $color  = $colors[crc32($s['name']) % count($colors)];
        ?>
        <div style="display:flex; align-items:center; gap:12px; padding:12px 18px; border-bottom:1px solid var(--border); transition:background 0.1s;"
             onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
          <div class="avatar avatar-sm" style="background:linear-gradient(135deg,<?=$color?>,<?=$color?>99);color:#fff;">
            <?= strtoupper(substr($s['name'],0,2)) ?>
          </div>
          <div style="flex:1; min-width:0;">
            <div style="font-size:0.85rem; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
              <?= sanitize($s['name']) ?>
            </div>
            <?php if ($s['phone']): ?>
            <div style="font-size:0.7rem; color:var(--text-muted);"><?= sanitize($s['phone']) ?></div>
            <?php endif; ?>
          </div>
          <form method="POST" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="enroll">
            <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
            <button type="submit" class="btn btn-success btn-sm" title="Enroll">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Add
            </button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state" style="padding:40px 20px;">
        <div class="empty-icon">🔍</div>
        <div class="empty-title"><?= $search ? 'No students found' : 'All students enrolled' ?></div>
        <div class="empty-desc">
          <?php if (!$search): ?>
            <a href="/educore/modules/students/create.php" style="color:var(--accent);">Add new students →</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
