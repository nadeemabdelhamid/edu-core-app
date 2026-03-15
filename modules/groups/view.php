<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pdo = getDB();
$cid = centerId();
$gid = (int)($_GET['id'] ?? 0);

if (!$gid) redirect('/educore/modules/groups/');

$gStmt = $pdo->prepare("SELECT g.*, u.name AS teacher_name FROM `groups` g LEFT JOIN users u ON u.id=g.teacher_id WHERE g.id=? AND g.center_id=?");
$gStmt->execute([$gid, $cid]);
$group = $gStmt->fetch();
if (!$group) { flash('error', 'Group not found.'); redirect('/educore/modules/groups/'); }

// Academy teachers can only view their own groups
if (isRole('teacher') && isAcademyPlan() && $group['teacher_id'] != authId()) {
    flash('error', 'You can only view your own groups.');
    redirect('/educore/modules/groups/');
}

$pageTitle  = sanitize($group['name']);
$activePage = 'groups';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Groups',    'href' => '/educore/modules/groups/'],
    ['label' => $pageTitle],
];

// Parse schedule — supports new multi-slot format [{day,time}] and old {days:[],time:''}
$scheduleRaw = json_decode($group['schedule'] ?? '[]', true) ?? [];
$scheduleSlots = [];
if (!empty($scheduleRaw)) {
    if (isset($scheduleRaw[0]) && is_array($scheduleRaw[0])) {
        // New format: array of {day, time}
        $scheduleSlots = $scheduleRaw;
    } elseif (isset($scheduleRaw['days']) || isset($scheduleRaw['time'])) {
        // Old format: {days:[], time:''}
        foreach (($scheduleRaw['days'] ?? []) as $d) {
            $scheduleSlots[] = ['day' => $d, 'time' => $scheduleRaw['time'] ?? ''];
        }
    }
}

// Enrolled students
$stuStmt = $pdo->prepare("SELECT s.*, gs.enrolled_at FROM group_students gs JOIN students s ON s.id=gs.student_id WHERE gs.group_id=? AND gs.status='active' ORDER BY s.name");
$stuStmt->execute([$gid]);
$students = $stuStmt->fetchAll();

// Recent sessions
$sessStmt = $pdo->prepare("SELECT s.*, COUNT(ar.id) as present_count FROM attendance_sessions s LEFT JOIN attendance_records ar ON ar.session_id=s.id WHERE s.group_id=? ORDER BY s.session_date DESC LIMIT 10");
$sessStmt->execute([$gid]);
$sessions = $sessStmt->fetchAll();

// Attendance rate (last 30 days)
$rateStmt = $pdo->prepare("SELECT COUNT(ar.id) as present, COUNT(DISTINCT s.id) as sessions FROM attendance_sessions s LEFT JOIN attendance_records ar ON ar.session_id=s.id WHERE s.group_id=? AND s.session_date >= DATE_SUB(NOW(),INTERVAL 30 DAY)");
$rateStmt->execute([$gid]);
$rateData = $rateStmt->fetch();
$totalStudents = count($students);
$avgAttendance = ($rateData['sessions'] > 0 && $totalStudents > 0)
    ? round(($rateData['present'] / ($rateData['sessions'] * $totalStudents)) * 100)
    : 0;

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div style="display:flex; align-items:center; gap:16px;">
    <div style="width:52px; height:52px; border-radius:12px; background:var(--accent-subtle); border:1px solid rgba(245,166,35,0.25); display:flex; align-items:center; justify-content:center; font-family:var(--font-display); font-size:1rem; font-weight:800; color:var(--accent);">
      <?= strtoupper(substr($group['name'],0,2)) ?>
    </div>
    <div>
      <h1 class="page-title"><?= sanitize($group['name']) ?></h1>
      <p class="page-subtitle">
        <?= sanitize($group['subject'] ?? 'No subject') ?>
        <?php if ($group['teacher_name']): ?> · <?= sanitize($group['teacher_name']) ?><?php endif; ?>
      </p>
    </div>
  </div>
  <div style="display:flex; gap:10px;">
    <a href="enroll.php?id=<?= $gid ?>" class="btn btn-ghost">Manage Enrollment</a>
    <a href="/educore/modules/attendance/create.php?group=<?= $gid ?>" class="btn btn-primary">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      Start Session
    </a>
    <?php if (isRole('owner','teacher')): ?>
    <a href="edit.php?id=<?= $gid ?>" class="btn btn-ghost">Edit</a>
    <?php endif; ?>
  </div>
</div>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr); margin-bottom:20px;">
  <div class="stat-card" style="--stat-color:#3b82f6;--stat-bg:rgba(59,130,246,0.1)">
    <div class="stat-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
    <div class="stat-value"><?= $totalStudents ?></div>
    <div class="stat-label">Students</div>
  </div>
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div>
    <div class="stat-value"><?= count($sessions) ?></div>
    <div class="stat-label">Sessions</div>
  </div>
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)">
    <div class="stat-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
    <div class="stat-value"><?= $avgAttendance ?>%</div>
    <div class="stat-label">Avg Attendance</div>
  </div>
  <div class="stat-card" style="--stat-color:#8b5cf6;--stat-bg:rgba(139,92,246,0.1)">
    <div class="stat-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
    <div class="stat-value"><?= number_format($group['monthly_fee']) ?></div>
    <div class="stat-label">Monthly Fee (EGP)</div>
  </div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">

  <!-- Students list -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Enrolled Students (<?= $totalStudents ?>)</span>
      <a href="enroll.php?id=<?= $gid ?>" class="btn btn-ghost btn-sm">Manage</a>
    </div>
    <div class="card-body no-padding">
      <?php if ($students): ?>
      <?php foreach ($students as $i => $s): ?>
      <?php $colors2 = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4']; $c = $colors2[crc32($s['name']) % count($colors2)]; ?>
      <div style="display:flex; align-items:center; gap:12px; padding:12px 18px; border-bottom:1px solid var(--border); <?= $i===count($students)-1?'border:none':'' ?>">
        <div style="width:22px; text-align:right; font-size:0.72rem; color:var(--text-muted);"><?= $i+1 ?></div>
        <div class="avatar avatar-sm" style="background:linear-gradient(135deg,<?=$c?>,<?=$c?>99);color:#fff;"><?= strtoupper(substr($s['name'],0,2)) ?></div>
        <div style="flex:1; min-width:0;">
          <div style="font-size:0.85rem; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= sanitize($s['name']) ?></div>
          <?php if ($s['phone']): ?><div style="font-size:0.7rem; color:var(--text-muted);"><?= sanitize($s['phone']) ?></div><?php endif; ?>
        </div>
        <a href="/educore/modules/students/view.php?id=<?= $s['id'] ?>" class="btn btn-ghost btn-icon" title="View student">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </a>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="empty-state" style="padding:40px 20px;">
        <div class="empty-icon">👥</div>
        <div class="empty-title">No students enrolled</div>
        <div class="empty-desc"><a href="enroll.php?id=<?=$gid?>" style="color:var(--accent)">Enroll students →</a></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Sessions + info -->
  <div>

    <!-- Group info card -->
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><span class="card-title">Group Details</span></div>
      <div class="card-body" style="padding:18px 22px;">
        <div style="display:grid; gap:12px; font-size:0.84rem;">
          <?php if (!empty($scheduleSlots)): ?>
          <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <span style="color:var(--text-muted);">Schedule</span>
            <span style="color:var(--text-primary); font-weight:500; text-align:right;">
              <?php foreach ($scheduleSlots as $slot): ?>
              <span style="display:block;">
                <?= sanitize($slot['day']) ?>
                <?php if (!empty($slot['time'])): ?> at <?= sanitize($slot['time']) ?><?php endif; ?>
              </span>
              <?php endforeach; ?>
            </span>
          </div>
          <?php endif; ?>
          <div style="display:flex; justify-content:space-between;">
            <span style="color:var(--text-muted);">Capacity</span>
            <span style="color:var(--text-primary); font-weight:500;"><?= $totalStudents ?> / <?= $group['capacity'] ?></span>
          </div>
          <?php if ($group['start_date']): ?>
          <div style="display:flex; justify-content:space-between;">
            <span style="color:var(--text-muted);">Start Date</span>
            <span style="color:var(--text-primary); font-weight:500;"><?= date('M j, Y', strtotime($group['start_date'])) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($group['end_date']): ?>
          <div style="display:flex; justify-content:space-between;">
            <span style="color:var(--text-muted);">End Date</span>
            <span style="color:var(--text-primary); font-weight:500;"><?= date('M j, Y', strtotime($group['end_date'])) ?></span>
          </div>
          <?php endif; ?>
          <div style="display:flex; justify-content:space-between;">
            <span style="color:var(--text-muted);">Status</span>
            <?php if ($group['is_active']): ?>
              <span class="badge badge-success">Active</span>
            <?php else: ?>
              <span class="badge badge-muted">Inactive</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Recent sessions -->
    <div class="card">
      <div class="card-header">
        <span class="card-title">Recent Sessions</span>
        <a href="/educore/modules/attendance/create.php?group=<?= $gid ?>" class="btn btn-primary btn-sm">+ New</a>
      </div>
      <div class="card-body no-padding">
        <?php if ($sessions): ?>
        <table class="data-table">
          <thead><tr><th>Date</th><th>Topic</th><th>Present</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($sessions as $sess): ?>
            <tr>
              <td><?= date('M j', strtotime($sess['session_date'])) ?></td>
              <td><?= sanitize($sess['topic'] ?: '—') ?></td>
              <td>
                <span style="color:var(--success); font-weight:600;"><?= $sess['present_count'] ?></span>
                <span style="color:var(--text-muted); font-size:0.75rem;"> / <?= $totalStudents ?></span>
              </td>
              <td>
                <?php if ($sess['status']==='open'): ?>
                  <span class="badge badge-success">● Live</span>
                <?php else: ?>
                  <span class="badge badge-muted">Closed</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state" style="padding:30px 20px;">
          <div class="empty-icon">📋</div>
          <div class="empty-title">No sessions yet</div>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
