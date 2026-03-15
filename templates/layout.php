<?php
// templates/layout.php
// Usage: set $pageTitle, $activePage, $breadcrumbs before including
requireAuth();
blockIfExpired();
// Run auto-scheduler after auth is confirmed (throttled to once/min)
if (!isRole('student')) runScheduler();
$user = auth();
$initials = strtoupper(substr($user['name'], 0, 2));

// Colors per role
$roleColors = [
    'owner'       => '#f5a623',
    'teacher'     => '#3b82f6',
    'assistant'   => '#8b5cf6',
    'accountant'  => '#22c55e',
    'student'     => '#ec4899',
    'super_admin' => '#ef4444',
];
$userColor = $roleColors[$user['role']] ?? '#f5a623';
$flashMessages = getFlash();

// ── Build nav based on role ─────────────────────────────────

if ($user['role'] === 'student') {
    // ── Student portal: lean, focused nav ───────────────────
    $navItems = [
        'main' => [
            ['href' => '/educore/modules/students/home.php',         'icon' => 'grid',         'label' => 'My Dashboard', 'key' => 'student_home'],
            ['href' => '/educore/modules/attendance/submit.php',     'icon' => 'check-square', 'label' => 'Attendance',   'key' => 'attendance'],
            ['href' => '/educore/modules/exams/student.php',         'icon' => 'file-text',    'label' => 'My Exams',     'key' => 'exams'],
            ['href' => '/educore/modules/students/videos.php',       'icon' => 'play-circle',  'label' => 'Lectures',     'key' => 'videos', 'feature' => 'videos'],
        ],
        'account' => [
            ['href' => '/educore/modules/students/profile.php',      'icon' => 'user',         'label' => 'My Profile',   'key' => 'student_profile'],
        ],
    ];

} elseif ($user['role'] === 'super_admin') {
    // ── Super admin: platform-only nav, no center clutter ───
    try {
        $_sa_pdo      = getDB();
        $_sa_pending  = (int)$_sa_pdo->query("SELECT COUNT(*) FROM centers WHERE status='pending'")->fetchColumn();
        try { $_sa_resets = (int)$_sa_pdo->query("SELECT COUNT(*) FROM password_reset_requests WHERE status='pending'")->fetchColumn(); } catch(Exception $e){ $_sa_resets=0; }
        $_sa_expiring = (int)$_sa_pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 7 DAY)")->fetchColumn();
    } catch(Exception $e) { $_sa_pending=0; $_sa_resets=0; $_sa_expiring=0; }

    $navItems = [
        'platform' => [
            ['href' => '/educore/modules/admin/centers.php',
             'icon' => 'briefcase', 'label' => 'Centers',
             'key'  => 'admin_centers',
             'badge'=> $_sa_pending > 0 ? $_sa_pending : null,
             'badge_title' => $_sa_pending > 0 ? $_sa_pending.' pending approval' : null],
            ['href' => '/educore/modules/admin/centers.php?view=plans',
             'icon' => 'star',      'label' => 'Plans',
             'key'  => 'admin_plans'],
            ['href' => '/educore/modules/admin/centers.php?view=password_requests',
             'icon' => 'lock',      'label' => 'Password Requests',
             'key'  => 'admin_passwords',
             'badge'=> $_sa_resets > 0 ? $_sa_resets : null,
             'badge_title' => $_sa_resets > 0 ? $_sa_resets.' pending' : null],
            ['href' => '/educore/modules/admin/centers.php?view=notifications',
             'icon' => 'bell',      'label' => 'Notifications',
             'key'  => 'admin_notifications'],
        ],
        'admin_account' => [
            ['href' => '/educore/modules/settings/security.php',
             'icon' => 'shield',    'label' => 'Security',
             'key'  => 'security'],
        ],
    ];

} else {
    // ── Center staff (owner, teacher, assistant, accountant) ─
    $navItems = [
        'main' => [
            ['href' => '/educore/dashboard.php',          'icon' => 'grid',         'label' => 'Dashboard',  'key' => 'dashboard'],
            ['href' => '/educore/modules/students/',      'icon' => 'users',        'label' => 'Students',   'key' => 'students'],
            ['href' => '/educore/modules/groups/',        'icon' => 'layers',       'label' => 'Groups',     'key' => 'groups'],
            ['href' => '/educore/modules/attendance/',    'icon' => 'check-square', 'label' => 'Attendance', 'key' => 'attendance'],
        ],
        'academic' => [
            ['href' => '/educore/modules/exams/',         'icon' => 'file-text',    'label' => 'Exams',      'key' => 'exams'],
            ['href' => '/educore/modules/videos/',        'icon' => 'play-circle',  'label' => 'Videos',     'key' => 'videos', 'feature' => 'videos'],
            ['href' => '/educore/modules/payments/',      'icon' => 'dollar-sign',  'label' => 'Payments',   'key' => 'payments'],
            ['href' => '/educore/modules/reports/',       'icon' => 'bar-chart-2',  'label' => 'Reports',    'key' => 'reports'],
        ],
        'settings' => [
            ['href' => '/educore/modules/settings/',      'icon' => 'settings',     'label' => 'Settings',   'key' => 'settings'],
        ],
    ];
}

function navIcon($name) {
    $icons = [
        'grid' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
        'users' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'layers' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
        'check-square' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
        'file-text' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
        'dollar-sign' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        'bar-chart-2' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        'settings' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'briefcase' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
        'shield' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'star' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        'user' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'bell' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
        'lock' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        'menu' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>',
        'log-out' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        'chevron-right' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>',
        'x' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'play-circle' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>',
    ];
    return $icons[$name] ?? '';
}

$sectionLabels = [
    'main'          => 'Navigation',
    'account'       => 'Account',
    'academic'      => 'Academic',
    'settings'      => 'System',
    'platform'      => 'Platform',
    'admin_account' => 'Account',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= sanitize($pageTitle ?? 'Dashboard') ?> — EduCore</title>
  <link rel="stylesheet" href="/educore/assets/css/app.css">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <script>
  // Apply dark class IMMEDIATELY — only when user has explicitly chosen dark mode.
  // Default is always LIGHT (white theme).
  (function(){
    if (localStorage.getItem('ec_dark_mode') === '1') {
      document.documentElement.classList.add('dark');
    }
  })();
</script>
</head>
<body data-role="<?= $user['role'] ?>">

<div class="app-layout">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <a href="<?= isRole('super_admin') ? '/educore/modules/admin/centers.php' : '/educore/dashboard.php' ?>" class="sidebar-brand">
      <div class="brand-icon">🎓</div>
      <span class="brand-text">Edu<span>Core</span></span>
    </a>

    <nav class="sidebar-nav">
      <?php foreach ($navItems as $section => $items): ?>
      <div class="nav-section">
        <div class="nav-section-label"><?= $sectionLabels[$section] ?></div>
        <?php foreach ($items as $item): ?>
          <?php
            // Skip items that require a plan feature the current center doesn't have
            if (!empty($item['feature']) && !isRole('super_admin') && !hasFeature($item['feature'])) {
                continue;
            }
          ?>
          <?php
            // Determine active state — also match view-based URLs for super admin
            $isActive = ($activePage ?? '') === $item['key'];
            if (!$isActive && isset($item['href'])) {
                $currentUri = strtok($_SERVER['REQUEST_URI'] ?? '', '#');
                $itemPath   = parse_url($item['href'], PHP_URL_PATH);
                $itemQuery  = parse_url($item['href'], PHP_URL_QUERY) ?? '';
                if ($itemPath && str_contains($currentUri, $itemPath)) {
                    if (!$itemQuery || str_contains($_SERVER['QUERY_STRING'] ?? '', $itemQuery)) {
                        $isActive = true;
                    }
                }
            }
          ?>
          <a href="<?= $item['href'] ?>"
             class="nav-item <?= $isActive ? 'active' : '' ?>"
             <?= !empty($item['badge_title']) ? 'title="'.htmlspecialchars($item['badge_title']).'"' : '' ?>>
            <span class="nav-icon"><?= navIcon($item['icon']) ?></span>
            <span style="flex:1;"><?= $item['label'] ?></span>
            <?php if(!empty($item['badge'])): ?>
            <span style="min-width:18px;height:18px;border-radius:9px;background:var(--danger);color:#fff;font-size:0.6rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;padding:0 4px;flex-shrink:0;"><?= $item['badge'] ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <div class="user-card" onclick="toggleUserMenu()">
        <div class="user-avatar" style="background: linear-gradient(135deg, <?= $userColor ?>, <?= $userColor ?>99)">
          <?= $initials ?>
        </div>
        <div style="flex:1; min-width:0;">
          <div class="user-name truncate"><?= sanitize($user['name']) ?></div>
          <div class="user-role"><?= str_replace('_', ' ', $user['role']) ?></div>
        </div>
        <span style="color:var(--text-muted)"><?= navIcon('chevron-right') ?></span>
      </div>
      <div id="userMenu" style="display:none; margin-top:6px;">
        <a href="/educore/logout.php" class="nav-item" style="color:var(--danger)">
          <span class="nav-icon"><?= navIcon('log-out') ?></span>
          Sign out
        </a>
      </div>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="main-content">

    <!-- TOPBAR / NAVBAR -->
    <header class="topbar">

      <!-- Left: hamburger + breadcrumb / page title -->
      <div class="topbar-left">
        <button class="topbar-btn topbar-menu-btn" id="menuToggle" onclick="toggleSidebar()" title="Toggle menu" style="display:none">
          <?= navIcon('menu') ?>
        </button>
        <?php if (!empty($breadcrumbs)): ?>
        <nav class="breadcrumb">
          <?php foreach ($breadcrumbs as $i => $crumb): ?>
            <?php if ($i > 0): ?><span class="sep"><?= navIcon('chevron-right') ?></span><?php endif; ?>
            <?php if (isset($crumb['href']) && $i < count($breadcrumbs) - 1): ?>
              <a href="<?= $crumb['href'] ?>"><?= sanitize($crumb['label']) ?></a>
            <?php else: ?>
              <span class="current"><?= sanitize($crumb['label']) ?></span>
            <?php endif; ?>
          <?php endforeach; ?>
        </nav>
        <?php else: ?>
        <span class="topbar-title"><?= sanitize($pageTitle ?? 'Dashboard') ?></span>
        <?php endif; ?>
      </div>

      <!-- Centre: search bar -->
      <!-- Search bar — opens full command palette -->
      <div class="topbar-search-wrap">
        <button class="topbar-search topbar-search-btn" onclick="openCommandPalette()" type="button" title="Search (Ctrl+K)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;color:var(--text-muted)"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
          <span class="topbar-search-placeholder">Search anything…</span>
          <kbd class="topbar-search-kbd">⌘K</kbd>
        </button>
      </div>

      <!-- Right: actions -->
      <div class="topbar-actions">
        <?php
          $notifCount = unreadCount(authId());
          $trialLeft  = (!isRole('super_admin','student') && $user['center_id']) ? trialDaysLeft() : null;
          $subInfo    = (!isRole('super_admin','student') && $user['center_id']) ? getSubscription() : null;
        ?>

        <?php if($subInfo): ?>
          <span class="plan-badge plan-<?=$subInfo['plan_slug']?>"><?=sanitize($subInfo['plan_name'])?></span>
        <?php endif; ?>

        <!-- Notifications -->
        <div style="position:relative;">
          <button class="topbar-btn" title="Notifications" onclick="toggleNotifPanel()" id="notifBtn">
            <?= navIcon('bell') ?>
            <?php if($notifCount > 0): ?>
            <span id="notifBadge" class="topbar-notif-badge"><?= $notifCount > 99 ? '99+' : $notifCount ?></span>
            <?php endif; ?>
          </button>
          <div id="notifPanel" class="notif-panel" style="display:none;">
            <div class="notif-panel-head">
              <span style="font-size:0.88rem;font-weight:700;">Notifications</span>
              <?php if($notifCount>0): ?>
              <a href="#" onclick="markAllRead();return false;" class="notif-mark-read">Mark all read</a>
              <?php endif; ?>
            </div>
            <div class="notif-panel-body">
              <?php
              $notifs=getUnreadNotifications(authId(),8);
              $notifTypeIcons=['payment'=>'💳','attendance'=>'📋','exam'=>'📝','system'=>'⚙️','subscription'=>'💎','security'=>'🔐','info'=>'ℹ️','announcement'=>'📢','warning'=>'⚠️','success'=>'✅','billing'=>'💳','maintenance'=>'🔧','approval'=>'✅','rejection'=>'❌','password_reset'=>'🔐'];
              if($notifs): foreach($notifs as $n):
                $ico = $notifTypeIcons[$n['type']] ?? '🔔';
                $tag = !empty($n['link']) ? 'a href="'.sanitize($n['link']).'"' : 'div';
                $end = !empty($n['link']) ? 'a' : 'div';
              ?>
              <<?=$tag?> class="notif-item" style="text-decoration:none;display:flex;gap:10px;align-items:flex-start;">
                <span style="font-size:1rem;flex-shrink:0;margin-top:1px;"><?=$ico?></span>
                <div style="min-width:0;">
                  <div class="notif-item-title"><?=sanitize($n['title'])?></div>
                  <?php if($n['body']): ?><div class="notif-item-body" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=sanitize($n['body'])?></div><?php endif; ?>
                  <div class="notif-item-time"><?=date('M j, g:i a',strtotime($n['created_at']))?></div>
                </div>
              </<?=$end?>>
              <?php endforeach; else: ?>
              <div class="notif-empty">All caught up ✓</div>
              <?php endif; ?>
            </div>
            <div class="notif-panel-foot">
              <?php $notifAllUrl = isRole('super_admin') ? '/educore/modules/admin/centers.php?view=password_requests' : '/educore/modules/settings/?tab=notifications'; ?>
              <a href="<?=$notifAllUrl?>" onclick="document.getElementById('notifPanel').style.display='none';" style="font-size:0.78rem;color:var(--accent);text-decoration:none;font-weight:500;">View all notifications →</a>
            </div>
          </div>
        </div>

        <!-- Dark mode toggle -->
        <button class="dark-toggle" id="darkToggle" onclick="toggleDarkMode()" type="button" title="Toggle dark mode" aria-label="Toggle dark mode">
          <svg class="icon-sun" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
          <svg class="icon-moon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        </button>

        <?php if(isRole('owner') && $user['center_id']): ?>
        <a href="/educore/modules/settings/" class="topbar-btn" title="Settings"><?=navIcon('settings')?></a>
        <?php endif; ?>
        <?php if(isRole('super_admin')): ?>
        <a href="/educore/modules/settings/security.php" class="topbar-btn" title="Security"><?=navIcon('shield')?></a>
        <?php endif; ?>

        <!-- User avatar chip -->
        <div class="topbar-user-chip" onclick="toggleUserMenu()" title="Account">
          <div class="topbar-avatar" style="background:<?= $userColor ?>;"><?= $initials ?></div>
          <span class="topbar-user-name"><?= sanitize($user['name']) ?></span>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="opacity:0.5"><path d="M6 9l6 6 6-6"/></svg>
        </div>
        <div id="userMenu" class="topbar-user-menu" style="display:none;">
          <div class="tum-header">
            <div class="tum-avatar" style="background:<?= $userColor ?>;"><?= $initials ?></div>
            <div>
              <div class="tum-name"><?= sanitize($user['name']) ?></div>
              <div class="tum-role"><?= str_replace('_',' ',$user['role']) ?></div>
            </div>
          </div>
          <div class="tum-divider"></div>
          <?php if(isRole('owner') && $user['center_id']): ?>
          <a href="/educore/modules/settings/" class="tum-item"><?= navIcon('settings') ?> Settings</a>
          <?php endif; ?>
          <a href="/educore/modules/settings/security.php" class="tum-item"><?= navIcon('user') ?> Profile</a>
          <div class="tum-divider"></div>
          <a href="/educore/logout.php" class="tum-item tum-item-danger"><?= navIcon('log-out') ?> Sign out</a>
        </div>

      </div>
    </header>

    <!-- EXPIRED ACCOUNT BANNER -->
    <?php echo expiredBannerHtml(); ?>

    <!-- TRIAL BANNER -->
    <?php if($trialLeft !== null && $trialLeft <= 7 && !isRole('student')): ?>
    <div style="background:<?=$trialLeft<=2?'rgba(239,68,68,0.12)':'rgba(245,166,35,0.08)'?>;border-bottom:1px solid <?=$trialLeft<=2?'rgba(239,68,68,0.3)':'rgba(245,166,35,0.2)'?>;padding:10px 28px;display:flex;align-items:center;gap:12px;font-size:0.82rem;">
      <span><?=$trialLeft<=2?'⚠️':'⏰'?></span>
      <span style="color:var(--text-primary);">Your trial <?=$trialLeft>0?"expires in <strong>$trialLeft day".($trialLeft>1?'s':'')."</strong>":'<strong>expired today</strong>'?>.</span>
      <a href="/educore/modules/settings/subscription.php" style="margin-left:auto;background:var(--accent);color:var(--text-inverse);padding:5px 14px;border-radius:20px;font-size:0.75rem;font-weight:700;text-decoration:none;">Upgrade Now →</a>
    </div>
    <?php endif; ?>

    <!-- FLASH MESSAGES — picked up by toast system in layout_end.php -->
    <?php if ($flashMessages): ?>
    <div id="ec-flash-source" style="display:none">
      <?php foreach ($flashMessages as $msg): ?>
      <span data-flash-type="<?= $msg['type'] ?>" data-flash-msg="<?= htmlspecialchars(sanitize($msg['message']), ENT_QUOTES) ?>"></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- PAGE CONTENT -->
    <main class="page-content">
      <?php // Content injected by child pages ?>
