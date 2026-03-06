<?php
// templates/layout.php
// Usage: set $pageTitle, $activePage, $breadcrumbs before including
requireAuth();
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

$navItems = [
    'main' => [
        ['href' => '/educore/dashboard.php',          'icon' => 'grid',         'label' => 'Dashboard',   'key' => 'dashboard'],
        ['href' => '/educore/modules/students/',      'icon' => 'users',        'label' => 'Students',    'key' => 'students'],
        ['href' => '/educore/modules/groups/',        'icon' => 'layers',       'label' => 'Groups',      'key' => 'groups'],
        ['href' => '/educore/modules/attendance/',    'icon' => 'check-square', 'label' => 'Attendance',  'key' => 'attendance'],
    ],
    'academic' => [
        ['href' => '/educore/modules/exams/',         'icon' => 'file-text',    'label' => 'Exams',       'key' => 'exams'],
        ['href' => '/educore/modules/payments/',      'icon' => 'dollar-sign',  'label' => 'Payments',    'key' => 'payments'],
        ['href' => '/educore/modules/reports/',       'icon' => 'bar-chart-2',  'label' => 'Reports',     'key' => 'reports'],
    ],
    'settings' => [
        ['href' => '/educore/modules/settings/',      'icon' => 'settings',     'label' => 'Settings',    'key' => 'settings'],
    ],
];

// Super admin gets extra items
if ($user['role'] === 'super_admin') {
    $navItems['platform'] = [
        ['href' => '/educore/modules/admin/centers.php',  'icon' => 'briefcase', 'label' => 'Centers',  'key' => 'admin_centers'],
        ['href' => '/educore/modules/admin/plans.php',    'icon' => 'star',      'label' => 'Plans',    'key' => 'admin_plans'],
        ['href' => '/educore/modules/admin/users.php',    'icon' => 'user',      'label' => 'All Users','key' => 'admin_users'],
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
        'star' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        'user' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'bell' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
        'menu' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>',
        'log-out' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        'chevron-right' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>',
        'x' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
    ];
    return $icons[$name] ?? '';
}

$sectionLabels = [
    'main'     => 'Navigation',
    'academic' => 'Academic',
    'settings' => 'System',
    'platform' => 'Platform Admin',
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
</head>
<body>

<div class="app-layout">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <a href="/educore/dashboard.php" class="sidebar-brand">
      <div class="brand-icon">🎓</div>
      <span class="brand-text">Edu<span>Core</span></span>
    </a>

    <nav class="sidebar-nav">
      <?php foreach ($navItems as $section => $items): ?>
      <div class="nav-section">
        <div class="nav-section-label"><?= $sectionLabels[$section] ?></div>
        <?php foreach ($items as $item): ?>
          <a href="<?= $item['href'] ?>"
             class="nav-item <?= ($activePage ?? '') === $item['key'] ? 'active' : '' ?>">
            <span class="nav-icon"><?= navIcon($item['icon']) ?></span>
            <?= $item['label'] ?>
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

    <!-- TOPBAR -->
    <header class="topbar">
      <button class="topbar-btn" id="menuToggle" onclick="toggleSidebar()" style="display:none">
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

      <div class="topbar-spacer"></div>

      <div class="topbar-actions">
        <button class="topbar-btn" title="Notifications">
          <?= navIcon('bell') ?>
          <span class="notif-dot"></span>
        </button>
        <?php if ($user['center_id']): ?>
        <?php
          $pdo = getDB();
          $sub = $pdo->prepare("SELECT p.name, p.slug FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.center_id=? AND s.status IN('active','trial') ORDER BY s.id DESC LIMIT 1");
          $sub->execute([$user['center_id']]);
          $plan = $sub->fetch();
        ?>
        <?php if ($plan): ?>
          <span class="plan-badge plan-<?= $plan['slug'] ?>"><?= $plan['name'] ?></span>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </header>

    <!-- FLASH MESSAGES -->
    <?php if ($flashMessages): ?>
    <div class="flash-container" id="flashContainer">
      <?php foreach ($flashMessages as $msg): ?>
      <div class="flash-msg flash-<?= $msg['type'] ?>" onclick="this.remove()">
        <span><?= sanitize($msg['message']) ?></span>
        <span style="margin-left:auto; opacity:0.6"><?= navIcon('x') ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- PAGE CONTENT -->
    <main class="page-content">
      <?php // Content injected by child pages ?>
