<?php
requireAuth();
$user          = auth();
$initials      = strtoupper(substr($user['name'], 0, 2));
$flashMessages = getFlash();

// Nav items with Material Icon names
$navSections = [
    'NAVIGATION' => [
        ['href' => '/educore/dashboard.php',             'icon' => 'dashboard',              'label' => 'Dashboard',   'key' => 'dashboard'],
        ['href' => '/educore/modules/students/',         'icon' => 'group',                  'label' => 'Students',    'key' => 'students'],
        ['href' => '/educore/modules/groups/',           'icon' => 'hub',                    'label' => 'Groups',      'key' => 'groups'],
        ['href' => '/educore/modules/attendance/',       'icon' => 'calendar_today',         'label' => 'Attendance',  'key' => 'attendance'],
    ],
    'ACADEMIC' => [
        ['href' => '/educore/modules/exams/',            'icon' => 'description',            'label' => 'Exams',       'key' => 'exams'],
        ['href' => '/educore/modules/payments/',         'icon' => 'account_balance_wallet', 'label' => 'Finances',    'key' => 'payments'],
        ['href' => '/educore/modules/reports/',          'icon' => 'bar_chart',              'label' => 'Reports',     'key' => 'reports'],
    ],
    'SYSTEM' => [
        ['href' => '/educore/modules/settings/',         'icon' => 'settings',               'label' => 'Settings',    'key' => 'settings'],
    ],
];

if ($user['role'] === 'super_admin') {
    $navSections['PLATFORM'] = [
        ['href' => '/educore/modules/admin/centers.php', 'icon' => 'corporate_fare',         'label' => 'Tenants',     'key' => 'admin_centers'],
        ['href' => '/educore/modules/admin/plans.php',   'icon' => 'credit_card',            'label' => 'Subscriptions','key' => 'admin_plans'],
        ['href' => '/educore/modules/admin/billing.php', 'icon' => 'receipt_long',           'label' => 'Billing',     'key' => 'admin_billing'],
    ];
}

// Get subscription plan
$planName = $planSlug = '';
if (!empty($user['center_id'])) {
    try {
        $pdo2 = getDB();
        $ps   = $pdo2->prepare("SELECT p.name, p.slug FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.center_id=? AND s.status IN('active','trial') ORDER BY s.id DESC LIMIT 1");
        $ps->execute([$user['center_id']]);
        $plan = $ps->fetch();
        if ($plan) { $planName = $plan['name']; $planSlug = $plan['slug']; }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= sanitize($pageTitle ?? 'Dashboard') ?> — EduCore</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
  <link rel="stylesheet" href="/educore/assets/css/app.css">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
</head>
<body>

<div class="app-layout">

  <!-- ═══════════════════════════════════════════════════════
       SIDEBAR
  ════════════════════════════════════════════════════════════ -->
  <aside class="sidebar" id="sidebar">

    <!-- Brand -->
    <a href="/educore/dashboard.php" class="sidebar-brand">
      <div class="brand-logo">
        <span class="material-symbols-outlined">school</span>
      </div>
      <div>
        <div class="brand-name">EduCenter</div>
        <div class="brand-sub">Management</div>
      </div>
    </a>

    <!-- Nav -->
    <nav class="sidebar-nav">
      <?php foreach ($navSections as $label => $items): ?>
        <div class="nav-section-label"><?= $label ?></div>
        <?php foreach ($items as $item): ?>
          <a href="<?= $item['href'] ?>"
             class="nav-item <?= ($activePage ?? '') === $item['key'] ? 'active' : '' ?>">
            <span class="material-symbols-outlined"><?= $item['icon'] ?></span>
            <?= $item['label'] ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>

    <!-- User footer -->
    <div class="sidebar-footer">
      <div class="user-card" onclick="toggleUserMenu()">
        <div class="user-avatar"><?= $initials ?></div>
        <div style="flex:1; min-width:0;">
          <div class="user-name"><?= sanitize($user['name']) ?></div>
          <div class="user-role"><?= str_replace('_', ' ', $user['role']) ?></div>
        </div>
        <span class="material-symbols-outlined" style="font-size:18px; color:var(--text-muted);">unfold_more</span>
      </div>
      <div id="userMenu" style="display:none; margin-top:2px;">
        <div class="divider"></div>
        <a href="/educore/logout.php" class="nav-item" style="color:var(--danger);">
          <span class="material-symbols-outlined">logout</span>
          Sign out
        </a>
      </div>
    </div>

  </aside>

  <!-- ═══════════════════════════════════════════════════════
       MAIN
  ════════════════════════════════════════════════════════════ -->
  <div class="main-content">

    <!-- Topbar -->
    <header class="topbar">

      <!-- Mobile hamburger -->
      <button class="topbar-icon-btn" id="menuToggle" onclick="toggleSidebar()" style="display:none;">
        <span class="material-symbols-outlined">menu</span>
      </button>

      <!-- Search -->
      <div class="topbar-search">
        <span class="material-symbols-outlined">search</span>
        <input type="text" placeholder="Search students, groups, invoices...">
      </div>

      <div class="topbar-spacer"></div>

      <div class="topbar-actions">

        <!-- Notifications -->
        <button class="topbar-icon-btn" title="Notifications">
          <span class="material-symbols-outlined">notifications</span>
          <span class="notif-dot"></span>
        </button>

        <?php if ($planName): ?>
        <span class="plan-badge plan-<?= sanitize($planSlug) ?>"><?= sanitize($planName) ?></span>
        <?php endif; ?>

        <!-- Primary CTA -->
        <?php if (isRole('owner', 'teacher', 'super_admin')): ?>
        <a href="/educore/modules/attendance/create.php" style="
          display:inline-flex; align-items:center; gap:6px;
          padding:8px 18px; background:var(--primary); color:white;
          border:none; border-radius:var(--radius-md);
          font-family:var(--font); font-size:0.875rem; font-weight:600;
          cursor:pointer; text-decoration:none;
          box-shadow:var(--shadow-primary); transition:background 0.15s;
        " onmouseover="this.style.background='var(--primary-hover)'"
           onmouseout="this.style.background='var(--primary)'">
          <span class="material-symbols-outlined" style="font-size:18px;">add</span>
          Quick Post
        </a>
        <?php endif; ?>

      </div>
    </header>

    <!-- Flash messages -->
    <?php if ($flashMessages): ?>
    <div class="flash-container" id="flashContainer">
      <?php
      $flashIcons = [
        'success' => 'check_circle',
        'error'   => 'error',
        'warning' => 'warning',
        'info'    => 'info',
      ];
      foreach ($flashMessages as $msg):
        $icon = $flashIcons[$msg['type']] ?? 'info';
      ?>
      <div class="flash-msg flash-<?= $msg['type'] ?>" onclick="this.remove()">
        <span class="material-symbols-outlined"><?= $icon ?></span>
        <span><?= sanitize($msg['message']) ?></span>
        <span class="material-symbols-outlined" style="margin-left:auto; font-size:16px; opacity:0.5;">close</span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Page body -->
    <main class="page-content">
