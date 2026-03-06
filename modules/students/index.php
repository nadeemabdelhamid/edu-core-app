<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pageTitle  = 'Students';
$activePage = 'students';
$breadcrumbs = [['label' => 'Dashboard', 'href' => '/educore/dashboard.php'], ['label' => 'Students']];

$pdo = getDB();
$cid = centerId();

// Search & filters
$search  = trim($_GET['q'] ?? '');
$status  = $_GET['status'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));

$where  = "WHERE s.center_id = ?";
$params = [$cid];

if ($search) {
    $where .= " AND (s.name LIKE ? OR s.email LIKE ? OR s.phone LIKE ? OR s.student_code LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

if ($status === 'active')   { $where .= " AND s.is_active = 1"; }
if ($status === 'inactive') { $where .= " AND s.is_active = 0"; }

$query = "SELECT s.*, (SELECT COUNT(*) FROM group_students gs WHERE gs.student_id = s.id AND gs.status='active') as group_count FROM students s $where ORDER BY s.created_at DESC";
$result = paginate($query, $params, $page, 20);
$students = $result['data'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Students</h1>
    <p class="page-subtitle"><?= number_format($result['total']) ?> total students enrolled</p>
  </div>
  <div style="display:flex; gap:10px; align-items:center;">
    <a href="import.php" class="btn btn-ghost">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Import CSV
    </a>
    <a href="create.php" class="btn btn-primary">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Student
    </a>
  </div>
</div>

<!-- FILTERS -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:16px 20px;">
    <form method="GET" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
      <div class="search-bar">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="q" value="<?= sanitize($search) ?>" placeholder="Search students...">
      </div>

      <select name="status" class="form-control" style="width:140px;" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>

      <?php if ($search || $status): ?>
      <a href="index.php" class="btn btn-ghost btn-sm">Clear</a>
      <?php endif; ?>

      <div style="margin-left:auto; font-size:0.8rem; color:var(--text-muted);">
        Showing <?= count($students) ?> of <?= $result['total'] ?>
      </div>
    </form>
  </div>
</div>

<!-- TABLE -->
<div class="card">
  <div class="card-body no-padding">
    <?php if ($students): ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Student</th>
            <th>Contact</th>
            <th>Parent Phone</th>
            <th>Groups</th>
            <th>Status</th>
            <th>Joined</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $s): ?>
          <?php
            $colors = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4'];
            $color = $colors[crc32($s['name']) % count($colors)];
          ?>
          <tr>
            <td>
              <div style="display:flex; align-items:center; gap:11px;">
                <div class="avatar avatar-sm" style="background:linear-gradient(135deg,<?=$color?>,<?=$color?>99); color:#fff;">
                  <?= strtoupper(substr($s['name'], 0, 2)) ?>
                </div>
                <div>
                  <div class="cell-primary"><?= sanitize($s['name']) ?></div>
                  <?php if ($s['student_code']): ?>
                  <div style="font-size:0.68rem; color:var(--text-muted);">#<?= sanitize($s['student_code']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <?php if ($s['email']): ?>
                <div style="font-size:0.82rem;"><?= sanitize($s['email']) ?></div>
              <?php endif; ?>
              <?php if ($s['phone']): ?>
                <div style="font-size:0.78rem; color:var(--text-muted);"><?= sanitize($s['phone']) ?></div>
              <?php endif; ?>
              <?php if (!$s['email'] && !$s['phone']): ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
            </td>
            <td><?= sanitize($s['parent_phone'] ?: '—') ?></td>
            <td>
              <?php if ($s['group_count'] > 0): ?>
                <span class="badge badge-info"><?= $s['group_count'] ?> group<?= $s['group_count'] > 1 ? 's' : '' ?></span>
              <?php else: ?>
                <span style="color:var(--text-muted); font-size:0.8rem;">None</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($s['is_active']): ?>
                <span class="badge badge-success">Active</span>
              <?php else: ?>
                <span class="badge badge-muted">Inactive</span>
              <?php endif; ?>
            </td>
            <td style="font-size:0.8rem; color:var(--text-muted);">
              <?= date('M j, Y', strtotime($s['created_at'])) ?>
            </td>
            <td>
              <div style="display:flex; gap:6px; justify-content:flex-end;">
                <a href="view.php?id=<?= $s['id'] ?>" class="btn btn-ghost btn-icon" title="View">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </a>
                <a href="edit.php?id=<?= $s['id'] ?>" class="btn btn-ghost btn-icon" title="Edit">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </a>
                <button class="btn btn-danger btn-icon" title="Delete"
                        onclick="confirmDelete(<?= $s['id'] ?>, '<?= sanitize(addslashes($s['name'])) ?>')">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($result['last_page'] > 1): ?>
    <div class="pagination">
      <span>Page <?= $result['current_page'] ?> of <?= $result['last_page'] ?></span>
      <div class="page-btns">
        <?php for ($i = 1; $i <= $result['last_page']; $i++): ?>
          <a href="?page=<?=$i?>&q=<?=urlencode($search)?>&status=<?=$status?>"
             class="page-btn <?= $i === $result['current_page'] ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">👥</div>
      <div class="empty-title"><?= $search ? 'No students found' : 'No students yet' ?></div>
      <div class="empty-desc">
        <?php if ($search): ?>
          Try a different search term
        <?php else: ?>
          <a href="create.php" class="btn btn-primary btn-sm" style="margin-top:14px;">Add Your First Student</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Delete form (hidden) -->
<form id="deleteForm" method="POST" action="delete.php" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="id" id="deleteId">
</form>

<?php
$extraScripts = <<<JS
<script>
function confirmDelete(id, name) {
  if (confirm('Delete student "' + name + '"? This cannot be undone.')) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteForm').submit();
  }
}
</script>
JS;
include __DIR__ . '/../../templates/layout_end.php';
?>
