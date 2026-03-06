<?php
require_once __DIR__ . '/includes/helpers.php';
$pageTitle  = 'Dashboard';
$activePage = 'dashboard';

requireAuth();

$pdo = getDB();
$cid = centerId();

// ─── STATS ────────────────────────────────────────────────
$stats = [];

if ($cid) {
    // Total students
    $s = $pdo->prepare("SELECT COUNT(*) FROM students WHERE center_id = ? AND is_active = 1");
    $s->execute([$cid]);
    $stats['students'] = $s->fetchColumn();

    // Total groups
    $s = $pdo->prepare("SELECT COUNT(*) FROM `groups` WHERE center_id = ? AND is_active = 1");
    $s->execute([$cid]);
    $stats['groups'] = $s->fetchColumn();

    // Attendance sessions this month
    $s = $pdo->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE center_id = ? AND MONTH(session_date)=MONTH(NOW()) AND YEAR(session_date)=YEAR(NOW())");
    $s->execute([$cid]);
    $stats['sessions'] = $s->fetchColumn();

    // Monthly income
    $s = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM payments WHERE center_id = ? AND MONTH(paid_date)=MONTH(NOW()) AND status IN('paid','partial')");
    $s->execute([$cid]);
    $stats['income'] = $s->fetchColumn();

    // Unpaid count
    $s = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE center_id = ? AND status IN('unpaid','overdue')");
    $s->execute([$cid]);
    $stats['unpaid'] = $s->fetchColumn();

    // Recent students
    $s = $pdo->prepare("SELECT * FROM students WHERE center_id = ? ORDER BY created_at DESC LIMIT 6");
    $s->execute([$cid]);
    $recentStudents = $s->fetchAll();

    // Recent payments
    $s = $pdo->prepare("SELECT p.*, s.name as student_name FROM payments p
                         JOIN students s ON s.id = p.student_id
                         WHERE p.center_id = ? ORDER BY p.created_at DESC LIMIT 5");
    $s->execute([$cid]);
    $recentPayments = $s->fetchAll();

    // Attendance sessions this week
    $s = $pdo->prepare("SELECT a.*, g.name as group_name, u.name as teacher_name
                         FROM attendance_sessions a
                         JOIN `groups` g ON g.id = a.group_id
                         JOIN users u ON u.id = a.teacher_id
                         WHERE a.center_id = ? AND a.session_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                         ORDER BY a.created_at DESC LIMIT 5");
    $s->execute([$cid]);
    $recentSessions = $s->fetchAll();
}

include __DIR__ . '/templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">
      Good <?= (date('H') < 12 ? 'morning' : (date('H') < 18 ? 'afternoon' : 'evening')) ?>,
      <?= sanitize(explode(' ', auth()['name'])[0]) ?> 👋
    </h1>
    <p class="page-subtitle"><?= date('l, F j, Y') ?> · Here's what's happening at your center today</p>
  </div>
  <?php if (isRole('owner', 'teacher')): ?>
  <a href="/educore/modules/attendance/create.php" class="btn btn-primary">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New Session
  </a>
  <?php endif; ?>
</div>

<!-- STATS -->
<div class="stats-grid">
  <div class="stat-card" style="--stat-color: #3b82f6; --stat-bg: rgba(59,130,246,0.1)">
    <div class="stat-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    </div>
    <div class="stat-value"><?= number_format($stats['students'] ?? 0) ?></div>
    <div class="stat-label">Total Students</div>
    <span class="stat-change up">↑ Active</span>
  </div>

  <div class="stat-card" style="--stat-color: #8b5cf6; --stat-bg: rgba(139,92,246,0.1)">
    <div class="stat-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
    </div>
    <div class="stat-value"><?= number_format($stats['groups'] ?? 0) ?></div>
    <div class="stat-label">Active Groups</div>
    <span class="stat-change up">↑ Running</span>
  </div>

  <div class="stat-card" style="--stat-color: var(--accent); --stat-bg: var(--accent-subtle)">
    <div class="stat-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    </div>
    <div class="stat-value"><?= number_format($stats['sessions'] ?? 0) ?></div>
    <div class="stat-label">Sessions This Month</div>
    <span class="stat-change up">↑ This month</span>
  </div>

  <div class="stat-card" style="--stat-color: #22c55e; --stat-bg: rgba(34,197,94,0.1)">
    <div class="stat-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
    </div>
    <div class="stat-value"><?= number_format($stats['income'] ?? 0) ?> <small style="font-size:0.9rem;opacity:0.6">EGP</small></div>
    <div class="stat-label">Income This Month</div>
    <?php if (($stats['unpaid'] ?? 0) > 0): ?>
    <span class="stat-change down">↓ <?= $stats['unpaid'] ?> unpaid</span>
    <?php else: ?>
    <span class="stat-change up">↑ All collected</span>
    <?php endif; ?>
  </div>
</div>

<!-- BOTTOM GRID -->
<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:20px;">

  <!-- Recent Sessions -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Recent Attendance Sessions</span>
      <a href="/educore/modules/attendance/" class="btn btn-ghost btn-sm">View all</a>
    </div>
    <div class="card-body no-padding">
      <?php if (!empty($recentSessions)): ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>Group</th>
            <th>Teacher</th>
            <th>Date</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentSessions as $sess): ?>
          <tr>
            <td class="cell-primary"><?= sanitize($sess['group_name']) ?></td>
            <td><?= sanitize($sess['teacher_name']) ?></td>
            <td><?= date('M j', strtotime($sess['session_date'])) ?></td>
            <td>
              <?php if ($sess['status'] === 'open'): ?>
                <span class="badge badge-success">● Open</span>
              <?php else: ?>
                <span class="badge badge-muted">Closed</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon">📋</div>
        <div class="empty-title">No sessions yet</div>
        <div class="empty-desc">Start your first attendance session</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent Payments -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Recent Payments</span>
      <a href="/educore/modules/payments/" class="btn btn-ghost btn-sm">View all</a>
    </div>
    <div class="card-body no-padding">
      <?php if (!empty($recentPayments)): ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>Student</th>
            <th>Amount</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentPayments as $pay): ?>
          <tr>
            <td class="cell-primary"><?= sanitize($pay['student_name']) ?></td>
            <td><?= number_format($pay['paid_amount']) ?> EGP</td>
            <td>
              <?php
              $badgeMap = ['paid'=>'badge-success','partial'=>'badge-warning','unpaid'=>'badge-danger','overdue'=>'badge-danger'];
              $badge = $badgeMap[$pay['status']] ?? 'badge-muted';
              ?>
              <span class="badge <?= $badge ?>"><?= ucfirst($pay['status']) ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon">💳</div>
        <div class="empty-title">No payments recorded</div>
        <div class="empty-desc">Payments will appear here</div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Recent Students -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Recently Added Students</span>
    <a href="/educore/modules/students/" class="btn btn-ghost btn-sm">View all</a>
  </div>
  <div class="card-body no-padding">
    <?php if (!empty($recentStudents)): ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Student</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Status</th>
          <th>Joined</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentStudents as $student): ?>
        <tr>
          <td>
            <div style="display:flex; align-items:center; gap:10px;">
              <div class="avatar avatar-sm" style="background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff;">
                <?= strtoupper(substr($student['name'],0,2)) ?>
              </div>
              <span class="cell-primary"><?= sanitize($student['name']) ?></span>
            </div>
          </td>
          <td><?= sanitize($student['email'] ?: '—') ?></td>
          <td><?= sanitize($student['phone'] ?: '—') ?></td>
          <td>
            <?php if ($student['is_active']): ?>
              <span class="badge badge-success">Active</span>
            <?php else: ?>
              <span class="badge badge-muted">Inactive</span>
            <?php endif; ?>
          </td>
          <td><?= date('M j, Y', strtotime($student['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">👥</div>
      <div class="empty-title">No students yet</div>
      <div class="empty-desc">
        <a href="/educore/modules/students/create.php" class="btn btn-primary btn-sm" style="margin-top:12px;">Add First Student</a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/templates/layout_end.php'; ?>
