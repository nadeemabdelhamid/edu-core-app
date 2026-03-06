<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pdo = getDB();
$cid = centerId();
$sid = (int)($_GET['id'] ?? 0);

if (!$sid) redirect('/educore/modules/students/');

$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND center_id = ?");
$stmt->execute([$sid, $cid]);
$student = $stmt->fetch();
if (!$student) { flash('error', 'Student not found.'); redirect('/educore/modules/students/'); }

$pageTitle  = sanitize($student['name']);
$activePage = 'students';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Students',  'href' => '/educore/modules/students/'],
    ['label' => $pageTitle],
];

// Groups this student is in
$groupStmt = $pdo->prepare("SELECT g.*, gs.enrolled_at FROM group_students gs JOIN `groups` g ON g.id=gs.group_id WHERE gs.student_id=? AND gs.status='active' ORDER BY g.name");
$groupStmt->execute([$sid]);
$groups = $groupStmt->fetchAll();

// Attendance history (last 20)
$attStmt = $pdo->prepare("SELECT ar.*, s.session_date, s.topic, g.name AS group_name FROM attendance_records ar JOIN attendance_sessions s ON s.id=ar.session_id JOIN `groups` g ON g.id=s.group_id WHERE ar.student_id=? ORDER BY s.session_date DESC LIMIT 20");
$attStmt->execute([$sid]);
$attendance = $attStmt->fetchAll();

// Payment history
$payStmt = $pdo->prepare("SELECT p.*, g.name AS group_name FROM payments p LEFT JOIN `groups` g ON g.id=p.group_id WHERE p.student_id=? AND p.center_id=? ORDER BY p.created_at DESC LIMIT 10");
$payStmt->execute([$sid, $cid]);
$payments = $payStmt->fetchAll();

// Attendance rate
$totalSessions = count($attendance);
$presentCount  = count(array_filter($attendance, fn($a) => $a['status'] === 'present'));
$attendanceRate = $totalSessions > 0 ? round(($presentCount / $totalSessions) * 100) : 0;

// Colors
$colors = ['#3b82f6','#8b5cf6','#22c55e','#ec4899','#f59e0b','#06b6d4'];
$color  = $colors[crc32($student['name']) % count($colors)];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div style="display:flex; align-items:center; gap:16px;">
    <div class="avatar avatar-xl" style="background:linear-gradient(135deg,<?=$color?>,<?=$color?>99); color:#fff;">
      <?= strtoupper(substr($student['name'],0,2)) ?>
    </div>
    <div>
      <h1 class="page-title"><?= sanitize($student['name']) ?></h1>
      <p class="page-subtitle">
        <?php if ($student['student_code']): ?>#<?= sanitize($student['student_code']) ?> · <?php endif; ?>
        <?= sanitize($student['email'] ?: $student['phone'] ?: 'No contact info') ?>
      </p>
    </div>
  </div>
  <div style="display:flex; gap:10px;">
    <a href="edit.php?id=<?= $sid ?>" class="btn btn-ghost">Edit Student</a>
  </div>
</div>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr); margin-bottom:20px;">
  <div class="stat-card" style="--stat-color:#3b82f6;--stat-bg:rgba(59,130,246,0.1)">
    <div class="stat-value"><?= count($groups) ?></div>
    <div class="stat-label">Groups</div>
  </div>
  <div class="stat-card" style="--stat-color:var(--accent);--stat-bg:var(--accent-subtle)">
    <div class="stat-value"><?= $totalSessions ?></div>
    <div class="stat-label">Sessions</div>
  </div>
  <div class="stat-card" style="--stat-color:#22c55e;--stat-bg:rgba(34,197,94,0.1)">
    <div class="stat-value"><?= $attendanceRate ?>%</div>
    <div class="stat-label">Attendance Rate</div>
  </div>
  <div class="stat-card" style="--stat-color:#8b5cf6;--stat-bg:rgba(139,92,246,0.1)">
    <?php
      $totalDue  = array_sum(array_column($payments, 'amount'));
      $totalPaid = array_sum(array_column($payments, 'paid_amount'));
    ?>
    <div class="stat-value"><?= number_format($totalPaid) ?></div>
    <div class="stat-label">Total Paid (EGP)</div>
  </div>
</div>

<div style="display:grid; grid-template-columns:1fr 2fr; gap:20px;">

  <!-- Left: Info -->
  <div>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header"><span class="card-title">Personal Info</span></div>
      <div class="card-body" style="font-size:0.84rem;">
        <?php
        $fields = [
          'Email'          => $student['email'],
          'Phone'          => $student['phone'],
          'Parent Phone'   => $student['parent_phone'],
          'Date of Birth'  => $student['date_of_birth'] ? date('M j, Y', strtotime($student['date_of_birth'])) : null,
          'Gender'         => $student['gender'] ? ucfirst($student['gender']) : null,
          'Address'        => $student['address'],
          'Status'         => $student['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Inactive</span>',
          'Joined'         => date('M j, Y', strtotime($student['created_at'])),
        ];
        foreach ($fields as $label => $value):
          if (!$value) continue;
        ?>
        <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border);">
          <span style="color:var(--text-muted);"><?= $label ?></span>
          <span style="color:var(--text-primary); font-weight:500; text-align:right; max-width:60%;"><?= $value ?></span>
        </div>
        <?php endforeach; ?>
        <?php if ($student['notes']): ?>
        <div style="padding-top:12px;">
          <div style="color:var(--text-muted); font-size:0.75rem; margin-bottom:4px;">Notes</div>
          <div style="color:var(--text-secondary);"><?= sanitize($student['notes']) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Groups -->
    <div class="card">
      <div class="card-header"><span class="card-title">Groups (<?= count($groups) ?>)</span></div>
      <div class="card-body no-padding">
        <?php if ($groups): ?>
        <?php foreach ($groups as $g): ?>
        <a href="/educore/modules/groups/view.php?id=<?= $g['id'] ?>" style="display:flex; align-items:center; gap:10px; padding:12px 18px; border-bottom:1px solid var(--border); text-decoration:none; transition:background 0.1s;" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
          <div style="width:32px; height:32px; border-radius:8px; background:var(--accent-subtle); border:1px solid rgba(245,166,35,0.2); display:flex; align-items:center; justify-content:center; font-size:0.7rem; font-weight:800; color:var(--accent);">
            <?= strtoupper(substr($g['name'],0,2)) ?>
          </div>
          <div style="flex:1;">
            <div style="font-size:0.84rem; font-weight:600; color:var(--text-primary);"><?= sanitize($g['name']) ?></div>
            <?php if ($g['subject']): ?><div style="font-size:0.7rem; color:var(--text-muted);"><?= sanitize($g['subject']) ?></div><?php endif; ?>
          </div>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state" style="padding:30px;"><div class="empty-icon" style="font-size:1.5rem;">📚</div><div class="empty-title" style="font-size:0.85rem;">Not in any group</div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right: Attendance + Payments -->
  <div>

    <!-- Attendance -->
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header">
        <span class="card-title">Attendance History</span>
        <span class="badge <?= $attendanceRate>=80?'badge-success':($attendanceRate>=60?'badge-warning':'badge-danger') ?>"><?= $attendanceRate ?>% rate</span>
      </div>
      <div class="card-body no-padding">
        <?php if ($attendance): ?>
        <table class="data-table">
          <thead><tr><th>Date</th><th>Group</th><th>Topic</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($attendance as $att): ?>
            <tr>
              <td><?= date('M j, Y', strtotime($att['session_date'])) ?></td>
              <td class="cell-primary"><?= sanitize($att['group_name']) ?></td>
              <td><?= sanitize($att['topic'] ?: '—') ?></td>
              <td>
                <?php
                $bmap = ['present'=>'badge-success','absent'=>'badge-danger','late'=>'badge-warning','excused'=>'badge-info'];
                ?>
                <span class="badge <?= $bmap[$att['status']] ?? 'badge-muted' ?>"><?= ucfirst($att['status']) ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state" style="padding:30px;"><div class="empty-icon" style="font-size:1.5rem;">📋</div><div class="empty-title" style="font-size:0.85rem;">No attendance records</div></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Payments -->
    <div class="card">
      <div class="card-header">
        <span class="card-title">Payment History</span>
        <a href="/educore/modules/payments/?student=<?= $sid ?>" class="btn btn-ghost btn-sm">View All</a>
      </div>
      <div class="card-body no-padding">
        <?php if ($payments): ?>
        <table class="data-table">
          <thead><tr><th>Period</th><th>Group</th><th>Amount</th><th>Paid</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($payments as $pay): ?>
            <tr>
              <td><?= sanitize($pay['month_year'] ?: date('M Y', strtotime($pay['created_at']))) ?></td>
              <td><?= sanitize($pay['group_name'] ?: '—') ?></td>
              <td><?= number_format($pay['amount']) ?></td>
              <td style="color:var(--success); font-weight:600;"><?= number_format($pay['paid_amount']) ?></td>
              <td>
                <?php $bmap2=['paid'=>'badge-success','partial'=>'badge-warning','unpaid'=>'badge-danger','overdue'=>'badge-danger']; ?>
                <span class="badge <?= $bmap2[$pay['status']]??'badge-muted' ?>"><?= ucfirst($pay['status']) ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state" style="padding:30px;"><div class="empty-icon" style="font-size:1.5rem;">💳</div><div class="empty-title" style="font-size:0.85rem;">No payment records</div></div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
