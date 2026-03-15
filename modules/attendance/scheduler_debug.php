<?php
/**
 * scheduler_debug.php — Shows what the scheduler WOULD create right now.
 * Only accessible to owners. Does NOT actually create sessions.
 * Use this to verify your group schedules are set up correctly.
 */
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner');

$pdo     = getDB();
$cid     = centerId();
$now     = time();
$today   = date('Y-m-d', $now);
$dayName = date('l', $now);
$nowTime = date('H:i', $now);

// Load all active groups with schedules for this center
$stmt = $pdo->prepare("
    SELECT g.id, g.name, g.schedule, g.teacher_id,
           COALESCE(g.session_duration_minutes, 90) AS duration_minutes,
           u.name AS teacher_name
    FROM `groups` g
    LEFT JOIN users u ON u.id = g.teacher_id
    WHERE g.center_id = ? AND g.is_active = 1
      AND g.schedule IS NOT NULL AND g.schedule NOT IN ('null','[]','{}','')
    ORDER BY g.name
");
$stmt->execute([$cid]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle  = 'Scheduler Debug';
$activePage = 'attendance';
$breadcrumbs = [
    ['label' => 'Dashboard',  'href' => '/educore/dashboard.php'],
    ['label' => 'Attendance', 'href' => '/educore/modules/attendance/'],
    ['label' => 'Scheduler Status'],
];
include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">⚙️ Scheduler Status</h1>
    <p class="page-subtitle">Today is <strong><?= $dayName ?></strong>, <?= date('M j, Y') ?> · Current server time: <strong><?= $nowTime ?></strong></p>
  </div>
  <a href="/educore/modules/attendance/cron.php?key=educore_cron_secret" 
     class="btn btn-primary" target="_blank">▶ Force Run Now</a>
</div>

<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <span class="card-title">Groups with Timetables</span>
    <span style="font-size:0.76rem;color:var(--text-muted);"><?= count($groups) ?> group(s) found</span>
  </div>
  <div class="card-body no-padding">
    <?php if (empty($groups)): ?>
    <div class="empty-state">
      <div class="empty-icon">📅</div>
      <div class="empty-title">No groups with schedules</div>
      <div class="empty-desc">Go to a group and set its weekly schedule to enable auto-sessions.</div>
    </div>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Group</th>
          <th>Teacher</th>
          <th>Schedule Slots</th>
          <th>Today's Slot</th>
          <th>Session Exists?</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($groups as $g):
          $slots = _parseSchedule($g['schedule']);
          $todaySlot = null;
          foreach ($slots as $s) {
              if (trim($s['day'] ?? '') === $dayName) { $todaySlot = $s; break; }
          }
          // Check if session already exists today
          $existCheck = $pdo->prepare("SELECT id, status FROM attendance_sessions WHERE group_id=? AND center_id=? AND session_date=? LIMIT 1");
          $existCheck->execute([$g['id'], $cid, $today]);
          $existingSession = $existCheck->fetch();
          
          // What would the scheduler do?
          $schedulerAction = '—';
          $statusBadge = '<span class="badge badge-muted">No slot today</span>';
          if ($todaySlot) {
              $slotTs = strtotime($today . ' ' . ($todaySlot['time'] ?? ''));
              $diffMin = $slotTs ? round(($now - $slotTs) / 60) : null;
              if ($existingSession) {
                  $schedulerAction = 'Session already exists (skipped)';
                  $statusBadge = $existingSession['status'] === 'open'
                    ? '<span class="badge badge-success">● Live</span>'
                    : '<span class="badge badge-muted">Done</span>';
              } elseif ($slotTs && $now >= $slotTs) {
                  $schedulerAction = '✓ WOULD CREATE SESSION NOW (overdue by ' . $diffMin . ' min)';
                  $statusBadge = '<span class="badge badge-warning">Pending creation</span>';
              } else {
                  $minsUntil = $slotTs ? round(($slotTs - $now) / 60) : '?';
                  $schedulerAction = 'Will create in ~' . $minsUntil . ' min';
                  $statusBadge = '<span class="badge badge-info">Scheduled</span>';
              }
          }
        ?>
        <tr>
          <td class="cell-primary"><?= sanitize($g['name']) ?></td>
          <td><?= sanitize($g['teacher_name'] ?? '—') ?></td>
          <td style="font-size:0.78rem;">
            <?php foreach ($slots as $sl): ?>
            <div><?= sanitize($sl['day'] ?? '') ?> <?= sanitize($sl['time'] ?? '') ?></div>
            <?php endforeach; ?>
            <?php if (empty($slots)): ?><span style="color:var(--text-muted);">No slots set</span><?php endif; ?>
          </td>
          <td>
            <?php if ($todaySlot): ?>
              <strong><?= sanitize($todaySlot['time'] ?? '') ?></strong>
              <div style="font-size:0.72rem;color:var(--text-muted);"><?= (int)$g['duration_minutes'] ?> min duration</div>
            <?php else: ?>
              <span style="color:var(--text-muted);">—</span>
            <?php endif; ?>
          </td>
          <td><?= $existingSession ? '<a href="/educore/modules/attendance/view.php?id='.$existingSession['id'].'" style="color:var(--accent);">View #'.$existingSession['id'].'</a>' : '<span style="color:var(--text-muted);">No</span>' ?></td>
          <td><?= $statusBadge ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">Setup Instructions</span></div>
  <div class="card-body" style="font-size:0.875rem; line-height:1.8; color:var(--text-secondary);">
    <p><strong style="color:var(--text-primary);">Option A — Server Cron Job (recommended, exact timing):</strong></p>
    <code style="display:block;background:var(--bg-elevated);padding:10px 14px;border-radius:8px;font-size:0.82rem;margin:8px 0 16px;">* * * * * php <?= $_SERVER['DOCUMENT_ROOT'] ?>/educore/modules/attendance/cron.php</code>
    <p><strong style="color:var(--text-primary);">Option B — Page-load trigger (no server access required):</strong></p>
    <p>The scheduler runs automatically whenever any non-student user loads any page. Sessions will be created within 1 minute of scheduled time as long as someone is using the app.</p>
    <p style="margin-top:12px;"><strong style="color:var(--text-primary);">Option C — External cron service (Render, Railway, cron-job.org):</strong></p>
    <code style="display:block;background:var(--bg-elevated);padding:10px 14px;border-radius:8px;font-size:0.82rem;margin:8px 0;">GET <?= (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] ?>/educore/modules/attendance/cron.php?key=educore_cron_secret</code>
  </div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
