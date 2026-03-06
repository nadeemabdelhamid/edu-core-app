<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pageTitle  = 'Attendance';
$activePage = 'attendance';
$breadcrumbs = [
    ['label' => 'Dashboard',  'href' => '/educore/dashboard.php'],
    ['label' => 'Attendance'],
];

$pdo    = getDB();
$cid    = centerId();
$uid    = authId();
$page   = max(1, (int)($_GET['page'] ?? 1));
$gid    = (int)($_GET['group'] ?? 0);
$status = $_GET['status'] ?? '';
$from   = $_GET['from'] ?? '';
$to     = $_GET['to'] ?? '';

$where  = "WHERE s.center_id = ?";
$params = [$cid];

if (!isRole('owner')) {
    $where  .= " AND s.teacher_id = ?";
    $params[] = $uid;
}
if ($gid)    { $where .= " AND s.group_id = ?";    $params[] = $gid; }
if ($status) { $where .= " AND s.status = ?";      $params[] = $status; }
if ($from)   { $where .= " AND s.session_date >= ?"; $params[] = $from; }
if ($to)     { $where .= " AND s.session_date <= ?"; $params[] = $to; }

$query = "SELECT s.*, g.name AS group_name, u.name AS teacher_name,
                 COUNT(ar.id) AS present_count,
                 (SELECT COUNT(*) FROM group_students gs WHERE gs.group_id=s.group_id AND gs.status='active') AS total_students
          FROM attendance_sessions s
          JOIN `groups` g ON g.id = s.group_id
          JOIN users u ON u.id = s.teacher_id
          LEFT JOIN attendance_records ar ON ar.session_id = s.id
          $where
          GROUP BY s.id
          ORDER BY s.session_date DESC, s.created_at DESC";

$result   = paginate($query, $params, $page, 20);
$sessions = $result['data'];

// Groups for filter
$gStmt = $pdo->prepare("SELECT id, name FROM `groups` WHERE center_id=? AND is_active=1 ORDER BY name");
$gStmt->execute([$cid]);
$groups = $gStmt->fetchAll();

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Attendance</h1>
    <p class="page-subtitle"><?= number_format($result['total']) ?> total sessions</p>
  </div>
  <a href="create.php" class="btn btn-primary">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New Session
  </a>
</div>

<!-- FILTERS -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:16px 20px;">
    <form method="GET" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">

      <select name="group" class="form-control" style="width:180px;" onchange="this.form.submit()">
        <option value="">All Groups</option>
        <?php foreach ($groups as $g): ?>
        <option value="<?= $g['id'] ?>" <?= $gid==$g['id']?'selected':'' ?>><?= sanitize($g['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="status" class="form-control" style="width:130px;" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="open"   <?= $status==='open'   ?'selected':'' ?>>Open</option>
        <option value="closed" <?= $status==='closed' ?'selected':'' ?>>Closed</option>
      </select>

      <input type="date" name="from" class="form-control" style="width:150px;" value="<?= sanitize($from) ?>" placeholder="From">
      <input type="date" name="to"   class="form-control" style="width:150px;" value="<?= sanitize($to) ?>"   placeholder="To">

      <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
      <?php if ($gid||$status||$from||$to): ?>
        <a href="index.php" class="btn btn-ghost btn-sm">Clear</a>
      <?php endif; ?>

      <span style="margin-left:auto; font-size:0.8rem; color:var(--text-muted);">
        <?= count($sessions) ?> of <?= $result['total'] ?> sessions
      </span>
    </form>
  </div>
</div>

<!-- TABLE -->
<div class="card">
  <div class="card-body no-padding">
    <?php if ($sessions): ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Group</th>
            <th>Teacher</th>
            <th>Topic</th>
            <th>Attendance</th>
            <th>Rate</th>
            <th>Status</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sessions as $sess): ?>
          <?php
            $present = (int)$sess['present_count'];
            $total   = (int)$sess['total_students'];
            $rate    = $total > 0 ? round(($present / $total) * 100) : 0;
            $rateColor = $rate >= 80 ? 'var(--success)' : ($rate >= 60 ? 'var(--warning)' : 'var(--danger)');
          ?>
          <tr>
            <td class="cell-primary"><?= date('M j, Y', strtotime($sess['session_date'])) ?></td>
            <td>
              <a href="/educore/modules/groups/view.php?id=<?= $sess['group_id'] ?>" style="color:var(--text-primary); text-decoration:none; font-weight:500;">
                <?= sanitize($sess['group_name']) ?>
              </a>
            </td>
            <td><?= sanitize($sess['teacher_name']) ?></td>
            <td style="color:var(--text-muted);"><?= sanitize($sess['topic'] ?: '—') ?></td>
            <td>
              <span style="color:var(--success); font-weight:600;"><?= $present ?></span>
              <span style="color:var(--text-muted); font-size:0.78rem;"> / <?= $total ?></span>
            </td>
            <td>
              <div style="display:flex; align-items:center; gap:8px;">
                <div style="width:50px; height:5px; background:var(--bg-elevated); border-radius:3px; overflow:hidden;">
                  <div style="height:100%; width:<?= $rate ?>%; background:<?= $rateColor ?>; border-radius:3px;"></div>
                </div>
                <span style="font-size:0.78rem; font-weight:600; color:<?= $rateColor ?>;"><?= $rate ?>%</span>
              </div>
            </td>
            <td>
              <?php if ($sess['status']==='open'): ?>
                <span class="badge badge-success">● Live</span>
              <?php else: ?>
                <span class="badge badge-muted">Closed</span>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex; gap:6px; justify-content:flex-end;">
                <a href="view.php?id=<?= $sess['id'] ?>" class="btn btn-ghost btn-icon" title="View">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </a>
                <?php if ($sess['status']==='open'): ?>
                <a href="create.php?session=<?= $sess['id'] ?>" class="btn btn-success btn-sm">Resume</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($result['last_page'] > 1): ?>
    <div class="pagination">
      <span>Page <?= $result['current_page'] ?> of <?= $result['last_page'] ?></span>
      <div class="page-btns">
        <?php for ($i=1;$i<=$result['last_page'];$i++): ?>
          <a href="?page=<?=$i?>&group=<?=$gid?>&status=<?=$status?>&from=<?=$from?>&to=<?=$to?>"
             class="page-btn <?= $i===$result['current_page']?'active':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">📋</div>
      <div class="empty-title">No sessions found</div>
      <div class="empty-desc">
        <a href="create.php" class="btn btn-primary btn-sm" style="margin-top:14px;">Start First Session</a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
