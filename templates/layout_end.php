    </main>

    <!-- PAGE FOOTER -->
    <footer class="page-footer">
      <div class="page-footer-inner">
        <div class="page-footer-left">
          <a href="/educore/dashboard.php" class="page-footer-brand">
            <span class="page-footer-brand-icon">&#127979;</span>
            <span>Edu<strong>Core</strong></span>
          </a>
          <span class="page-footer-sep">&middot;</span>
          <span class="page-footer-copy">&copy; <?= date('Y') ?> EduCore. All rights reserved.</span>
        </div>
        <nav class="page-footer-links">
          <a href="/educore/modules/settings/" class="page-footer-link">Settings</a>
          <a href="/educore/modules/reports/" class="page-footer-link">Reports</a>
          <a href="/educore/modules/settings/security.php" class="page-footer-link">Security</a>
          <a href="/educore/logout.php" class="page-footer-link page-footer-link-muted">Sign out</a>
        </nav>
      </div>
    </footer>

  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     GLOBAL POPUP SYSTEM — Confirm Dialog + Toast Notifications
     ═══════════════════════════════════════════════════════ -->

<!-- Confirm Dialog -->
<div id="ec-confirm-backdrop" style="
  display:none; position:fixed; inset:0; z-index:9999;
  background:rgba(15,23,42,0.5); backdrop-filter:blur(6px);
  -webkit-backdrop-filter:blur(6px);
  align-items:center; justify-content:center; padding:20px;
">
  <div id="ec-confirm-box" style="
    background:#fff; border-radius:16px; width:100%; max-width:420px;
    box-shadow:0 20px 60px rgba(0,0,0,0.15), 0 0 0 1px rgba(0,0,0,0.05);
    overflow:hidden;
    transform:translateY(12px) scale(0.97);
    transition:transform 0.22s cubic-bezier(0.34,1.56,0.64,1), opacity 0.18s ease;
    opacity:0;
  ">
    <!-- Icon row -->
    <div id="ec-confirm-icon-row" style="padding:28px 28px 0; text-align:center;">
      <div id="ec-confirm-icon" style="
        display:inline-flex; align-items:center; justify-content:center;
        width:52px; height:52px; border-radius:14px; font-size:22px;
        margin-bottom:4px;
      "></div>
    </div>
    <!-- Text -->
    <div style="padding:16px 28px 8px; text-align:center;">
      <div id="ec-confirm-title" style="
        font-family:'Geist',sans-serif; font-size:1.05rem; font-weight:700;
        color:#0f172a; letter-spacing:-0.02em; margin-bottom:6px;
      "></div>
      <div id="ec-confirm-message" style="
        font-family:'Geist',sans-serif; font-size:0.875rem;
        color:#64748b; line-height:1.6;
      "></div>
    </div>
    <!-- Buttons -->
    <div style="
      display:flex; gap:10px; padding:20px 28px 24px;
      justify-content:center;
    ">
      <button id="ec-confirm-cancel" onclick="ecConfirmResolve(false)" style="
        flex:1; padding:10px 20px;
        background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px;
        font-family:'Geist',sans-serif; font-size:0.875rem; font-weight:600;
        color:#475569; cursor:pointer; transition:all 0.15s;
      " onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
        Cancel
      </button>
      <button id="ec-confirm-ok" onclick="ecConfirmResolve(true)" style="
        flex:1; padding:10px 20px;
        border:none; border-radius:8px;
        font-family:'Geist',sans-serif; font-size:0.875rem; font-weight:700;
        cursor:pointer; transition:all 0.15s; color:#fff;
      ">
        Confirm
      </button>
    </div>
  </div>
</div>

<!-- Toast Container -->
<div id="ec-toast-container" style="
  position:fixed; bottom:24px; right:24px; z-index:9998;
  display:flex; flex-direction:column-reverse; gap:10px;
  max-width:380px; width:calc(100% - 48px);
  pointer-events:none;
"></div>

<style>
/* ── Confirm dialog animation ─────────────────── */
#ec-confirm-backdrop.ec-open {
  display: flex !important;
}
#ec-confirm-backdrop.ec-open #ec-confirm-box {
  transform: translateY(0) scale(1);
  opacity: 1;
}

/* ── Toast ────────────────────────────────────── */
.ec-toast {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 16px;
  border-radius: 12px;
  background: #fff;
  border: 1px solid #e2e8f0;
  box-shadow: 0 8px 24px rgba(0,0,0,0.10), 0 2px 6px rgba(0,0,0,0.06);
  font-family: 'Geist', sans-serif;
  font-size: 0.875rem;
  font-weight: 500;
  color: #0f172a;
  pointer-events: all;
  cursor: pointer;
  animation: ecToastIn 0.35s cubic-bezier(0.34,1.56,0.64,1) both;
  overflow: hidden;
  position: relative;
}
.ec-toast-icon {
  width: 32px; height: 32px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; flex-shrink: 0;
}
.ec-toast-body { flex: 1; min-width: 0; }
.ec-toast-title { font-weight: 700; font-size: 0.875rem; margin-bottom: 2px; }
.ec-toast-desc  { font-size: 0.8rem; color: #64748b; }
.ec-toast-close {
  width: 22px; height: 22px; border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  color: #94a3b8; flex-shrink: 0; font-size: 14px;
  transition: background 0.15s, color 0.15s;
  margin-top:1px;
}
.ec-toast:hover .ec-toast-close { background:#f1f5f9; color:#475569; }

/* progress bar */
.ec-toast-progress {
  position: absolute; bottom: 0; left: 0; height: 3px;
  border-radius: 0 0 0 12px;
  animation: ecProgress linear both;
}

/* type variants */
.ec-toast-success { border-color: #bbf7d0; }
.ec-toast-success .ec-toast-icon  { background: #dcfce7; color: #16a34a; }
.ec-toast-success .ec-toast-progress { background: #22c55e; }

.ec-toast-error   { border-color: #fecaca; }
.ec-toast-error   .ec-toast-icon  { background: #fee2e2; color: #dc2626; }
.ec-toast-error   .ec-toast-progress { background: #ef4444; }

.ec-toast-warning { border-color: #fde68a; }
.ec-toast-warning .ec-toast-icon  { background: #fef9c3; color: #ca8a04; }
.ec-toast-warning .ec-toast-progress { background: #f59e0b; }

.ec-toast-info    { border-color: #bfdbfe; }
.ec-toast-info    .ec-toast-icon  { background: #dbeafe; color: #2563eb; }
.ec-toast-info    .ec-toast-progress { background: #3b82f6; }

@keyframes ecToastIn {
  from { transform: translateX(110%) scale(0.95); opacity: 0; }
  to   { transform: translateX(0)    scale(1);    opacity: 1; }
}
@keyframes ecToastOut {
  to { transform: translateX(110%) scale(0.95); opacity: 0; max-height: 0; margin: 0; padding: 0; }
}
@keyframes ecProgress {
  from { width: 100%; }
  to   { width: 0%; }
}
</style>

<script>
// ═══════════════════════════════════════════════
// CONFIRM DIALOG
// ═══════════════════════════════════════════════
let _ecResolve = null;

const EC_TYPES = {
  danger: {
    icon: '🗑️', bg: '#fee2e2', color: '#dc2626',
    okBg: '#ef4444', okHover: '#dc2626',
    title: 'Are you sure?'
  },
  warning: {
    icon: '⚠️', bg: '#fef9c3', color: '#ca8a04',
    okBg: '#f59e0b', okHover: '#d97706',
    title: 'Confirm Action'
  },
  info: {
    icon: 'ℹ️', bg: '#dbeafe', color: '#2563eb',
    okBg: '#3b82f6', okHover: '#2563eb',
    title: 'Confirm'
  },
  primary: {
    icon: '✓', bg: '#ede9fe', color: '#7c3aed',
    okBg: '#5247e6', okHover: '#3d34c9',
    title: 'Confirm'
  }
};

function ecShowConfirm(message, type = 'danger', customTitle = null, okLabel = null, cancelLabel = null) {
  return new Promise(resolve => {
    _ecResolve = resolve;
    const t = EC_TYPES[type] || EC_TYPES.danger;
    const backdrop = document.getElementById('ec-confirm-backdrop');
    const box = document.getElementById('ec-confirm-box');

    document.getElementById('ec-confirm-icon').textContent = t.icon;
    document.getElementById('ec-confirm-icon').style.background = t.bg;
    document.getElementById('ec-confirm-icon').style.color = t.color;
    document.getElementById('ec-confirm-title').textContent = customTitle || t.title;
    document.getElementById('ec-confirm-message').textContent = message;

    const okBtn = document.getElementById('ec-confirm-ok');
    okBtn.textContent = okLabel || 'Confirm';
    okBtn.style.background = t.okBg;
    okBtn.onmouseover = () => okBtn.style.background = t.okHover;
    okBtn.onmouseout  = () => okBtn.style.background = t.okBg;

    const cancelBtn = document.getElementById('ec-confirm-cancel');
    cancelBtn.textContent = cancelLabel || 'Cancel';

    backdrop.style.display = 'flex';
    // Force reflow then animate
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        backdrop.classList.add('ec-open');
        box.style.transform = 'translateY(0) scale(1)';
        box.style.opacity = '1';
      });
    });

    document.body.style.overflow = 'hidden';

    // Keyboard: Enter = confirm, Escape = cancel
    backdrop._keyHandler = (e) => {
      if (e.key === 'Enter') ecConfirmResolve(true);
      if (e.key === 'Escape') ecConfirmResolve(false);
    };
    document.addEventListener('keydown', backdrop._keyHandler);
  });
}

function ecConfirmResolve(result) {
  const backdrop = document.getElementById('ec-confirm-backdrop');
  const box = document.getElementById('ec-confirm-box');
  box.style.transform = 'translateY(8px) scale(0.97)';
  box.style.opacity = '0';
  backdrop.classList.remove('ec-open');
  document.removeEventListener('keydown', backdrop._keyHandler);
  setTimeout(() => {
    backdrop.style.display = 'none';
    document.body.style.overflow = '';
    box.style.transform = 'translateY(12px) scale(0.97)';
  }, 200);
  if (_ecResolve) { _ecResolve(result); _ecResolve = null; }
}

// Close on backdrop click
document.getElementById('ec-confirm-backdrop').addEventListener('click', function(e) {
  if (e.target === this) ecConfirmResolve(false);
});

// Override native confirm() globally
window.confirm = function(message) {
  // Detect type from message content
  let type = 'danger';
  const lower = message.toLowerCase();
  if (lower.includes('cannot be undone') || lower.includes('delete') || lower.includes('remove')) type = 'danger';
  else if (lower.includes('close') || lower.includes('generate') || lower.includes('apply') || lower.includes('submit')) type = 'warning';
  else if (lower.includes('duplicate') || lower.includes('activate')) type = 'primary';

  // For synchronous contexts (inline onclick="return confirm(...)"), we need a sync fallback
  // We'll intercept the form/button and convert to async
  return true; // default pass — actual interception happens via data-confirm below
};

// ── Intercept all onclick="return confirm(...)" elements ──
document.addEventListener('DOMContentLoaded', function() {
  // Handle elements with onclick containing confirm()
  document.querySelectorAll('[onclick*="confirm("]').forEach(el => {
    const original = el.getAttribute('onclick');
    const msgMatch = original.match(/confirm\(['"](.+?)['"]\)/s);
    if (!msgMatch) return;
    const msg = msgMatch[1].replace(/\\n/g, '\n');

    el.removeAttribute('onclick');
    el.setAttribute('data-ec-confirm', msg);
  });

  // Handle onsubmit="return confirm(...)"
  document.querySelectorAll('[onsubmit*="confirm("]').forEach(form => {
    const original = form.getAttribute('onsubmit');
    const msgMatch = original.match(/confirm\(['"](.+?)['"]\)/s);
    if (!msgMatch) return;
    const msg = msgMatch[1].replace(/\\n/g, '\n');

    form.removeAttribute('onsubmit');
    form.setAttribute('data-ec-confirm-submit', msg);
  });

  // Delegate click handler
  document.addEventListener('click', async function(e) {
    const btn = e.target.closest('[data-ec-confirm]');
    if (!btn) return;
    e.preventDefault(); e.stopPropagation();

    const msg = btn.getAttribute('data-ec-confirm');
    const type = btn.getAttribute('data-ec-type') || detectType(msg);
    const ok = await ecShowConfirm(msg, type);
    if (!ok) return;

    // If inside a form, submit it
    const form = btn.closest('form');
    if (form) { form.submit(); return; }
    // If it's a link
    if (btn.href) { window.location.href = btn.href; return; }
    // Trigger original action
    btn.removeAttribute('data-ec-confirm');
    btn.click();
  });

  // Delegate form submit handler
  document.addEventListener('submit', async function(e) {
    const form = e.target;
    if (!form.hasAttribute('data-ec-confirm-submit')) return;
    e.preventDefault();

    const msg = form.getAttribute('data-ec-confirm-submit');
    const type = form.getAttribute('data-ec-type') || detectType(msg);
    const ok = await ecShowConfirm(msg, type);
    if (!ok) return;

    form.removeAttribute('data-ec-confirm-submit');
    form.submit();
  });
});

function detectType(msg) {
  const l = msg.toLowerCase();
  if (l.includes('delete') || l.includes('remove') || l.includes('cannot be undone')) return 'danger';
  if (l.includes('submit') || l.includes('close') || l.includes('apply') || l.includes('generate')) return 'warning';
  if (l.includes('duplicate') || l.includes('activate')) return 'primary';
  return 'warning';
}

// ═══════════════════════════════════════════════
// TOAST NOTIFICATIONS
// ═══════════════════════════════════════════════
const TOAST_ICONS = {
  success: '✓',
  error:   '✕',
  warning: '⚠',
  info:    'ℹ'
};
const TOAST_TITLES = {
  success: 'Success',
  error:   'Error',
  warning: 'Warning',
  info:    'Info'
};
const TOAST_DURATION = { success: 4000, error: 6000, warning: 5000, info: 4500 };

function ecToast(type, message, title, duration) {
  const container = document.getElementById('ec-toast-container');
  const dur = duration || TOAST_DURATION[type] || 4000;

  const toast = document.createElement('div');
  toast.className = `ec-toast ec-toast-${type}`;
  toast.innerHTML = `
    <div class="ec-toast-icon">${TOAST_ICONS[type] || 'ℹ'}</div>
    <div class="ec-toast-body">
      <div class="ec-toast-title">${title || TOAST_TITLES[type]}</div>
      <div class="ec-toast-desc">${message}</div>
    </div>
    <div class="ec-toast-close" title="Dismiss">✕</div>
    <div class="ec-toast-progress" style="animation-duration:${dur}ms"></div>
  `;

  toast.querySelector('.ec-toast-close').addEventListener('click', () => dismissToast(toast));
  toast.addEventListener('click', () => dismissToast(toast));
  container.prepend(toast);

  const timer = setTimeout(() => dismissToast(toast), dur);
  toast._timer = timer;
}

function dismissToast(toast) {
  if (toast._dismissed) return;
  toast._dismissed = true;
  clearTimeout(toast._timer);
  toast.style.animation = 'ecToastOut 0.3s ease forwards';
  setTimeout(() => toast.remove(), 320);
}

// ── Convert existing PHP flash messages into toasts ──
document.addEventListener('DOMContentLoaded', function() {
  const container = document.getElementById('ec-flash-source');
  if (!container) return;
  container.querySelectorAll('[data-flash-type]').forEach(el => {
    const type = el.getAttribute('data-flash-type');
    const msg  = el.getAttribute('data-flash-msg');
    ecToast(type, msg);
    el.remove();
  });
});

// ═══════════════════════════════════════════════
// SIDEBAR TOGGLE
// ═══════════════════════════════════════════════

(function() {
  var STORAGE_KEY = 'ec_sidebar_collapsed';
  var sidebar = document.getElementById('sidebar');
  var toggle  = document.getElementById('menuToggle');

  // Overlay backdrop
  var overlay = document.createElement('div');
  overlay.className = 'sidebar-overlay';
  overlay.id = 'sidebarOverlay';
  document.body.appendChild(overlay);
  overlay.addEventListener('click', closeMobileSidebar);

  // Restore desktop collapsed state
  if (window.innerWidth > 1024 && localStorage.getItem(STORAGE_KEY) === '1') {
    document.documentElement.classList.add('sidebar-collapsed');
  }

  window.toggleSidebar = function() {
    if (window.innerWidth <= 1024) {
      var isOpen = sidebar.classList.contains('open');
      if (isOpen) { closeMobileSidebar(); }
      else {
        sidebar.classList.add('open');
        overlay.style.display = 'block';
        requestAnimationFrame(function() { overlay.classList.add('visible'); });
        document.body.style.overflow = 'hidden';
      }
    } else {
      var collapsed = document.body.classList.toggle('sidebar-collapsed');
      localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
    }
  };

  function closeMobileSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('visible');
    document.body.style.overflow = '';
    setTimeout(function() { overlay.style.display = 'none'; }, 250);
  }

  // Close sidebar when a nav link is tapped on mobile
  if (sidebar) {
    sidebar.querySelectorAll('.nav-item').forEach(function(link) {
      link.addEventListener('click', function() {
        if (window.innerWidth <= 1024) closeMobileSidebar();
      });
    });
  }

  // On resize: clean up
  window.addEventListener('resize', function() {
    if (window.innerWidth > 1024) {
      closeMobileSidebar();
      document.body.style.overflow = '';
    }
  });

  window.closeMobileSidebar = closeMobileSidebar;
})();

function toggleUserMenu() {
  const m = document.getElementById('userMenu');
  const isOpen = m.style.display !== 'none';
  document.getElementById('topbarSearchDrop').style.display = 'none';
  document.getElementById('notifPanel') && (document.getElementById('notifPanel').style.display = 'none');
  m.style.display = isOpen ? 'none' : 'block';
}

// Close user menu when clicking outside
document.addEventListener('click', function(e) {
  const menu = document.getElementById('userMenu');
  const chip = document.querySelector('.topbar-user-chip');
  if (menu && chip && !chip.contains(e.target) && !menu.contains(e.target)) {
    menu.style.display = 'none';
  }
});

// ═══════════════════════════════════════════════
// NOTIFICATION PANEL
// ═══════════════════════════════════════════════
function toggleNotifPanel() {
  const p = document.getElementById('notifPanel');
  if (!p) return;
  const opening = p.style.display === 'none';
  p.style.display = opening ? 'block' : 'none';
  if (opening) {
    const badge = document.getElementById('notifBadge');
    if (badge) badge.remove();
    fetch('/educore/modules/settings/mark_read.php', {method:'POST', headers:{'X-CSRF-TOKEN': csrfToken}}).catch(()=>{});
  }
}
function markAllRead() {
  fetch('/educore/modules/settings/mark_read.php', {method:'POST', headers:{'X-CSRF-TOKEN': csrfToken}});
  document.getElementById('notifBadge') && document.getElementById('notifBadge').remove();
  document.getElementById('notifPanel').querySelectorAll('[style*="border-bottom"]').forEach(el => el.remove());
  const list = document.getElementById('notifPanel').querySelector('[style*="max-height"]');
  if(list) list.innerHTML = '<div style="padding:24px 16px;text-align:center;color:var(--text-muted);font-size:0.8rem;">All caught up ✓</div>';
}
document.addEventListener('click', function(e) {
  const panel = document.getElementById('notifPanel');
  const btn   = document.getElementById('notifBtn');
  if(panel && btn && !panel.contains(e.target) && !btn.contains(e.target)) panel.style.display='none';
});

// ═══════════════════════════════════════════════
// CSRF + MODAL HELPERS
// ═══════════════════════════════════════════════
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

function openModal(id) {
  document.getElementById(id).classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow = '';
}
document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
  backdrop.addEventListener('click', function(e) {
    if (e.target === backdrop) closeModal(backdrop.id);
  });
});

// Legacy confirmAction() support
function confirmAction(message, callback) {
  ecShowConfirm(message).then(ok => { if (ok) callback(); });
}


// ═══════════════════════════════════════════════
// TOPBAR SEARCH — delegates to command palette
// ═══════════════════════════════════════════════
// The search bar is now a button that opens the full command palette.
// ⌘K / Ctrl+K is handled inside the command palette IIFE below.

// ═══════════════════════════════════════════════
// 1. DARK MODE
// ═══════════════════════════════════════════════
(function() {
  const DARK_KEY = 'ec_dark_mode';
  const html = document.documentElement;

  // html.dark is already set by the inline <head> script — no need to re-apply.
  // This IIFE only needs to expose the toggle function.
  function applyDark(on) {
    html.classList.toggle('dark', on);
    localStorage.setItem(DARK_KEY, on ? '1' : '0');
  }

  window.toggleDarkMode = function() {
    // Add transition class so the switch animates smoothly
    html.classList.add('dark-transitioning');
    applyDark(!html.classList.contains('dark'));
    // Remove after transition completes
    setTimeout(function() { html.classList.remove('dark-transitioning'); }, 300);
  };

  // Listen for OS theme changes when no saved preference
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
    if (localStorage.getItem(DARK_KEY) === null) applyDark(e.matches);
  });
})();

// ═══════════════════════════════════════════════
// 2. COMMAND PALETTE (Cmd+K)
// ═══════════════════════════════════════════════
(function() {
  const PAGES = [
    { label: 'Dashboard',          icon: 'grid',         href: '/educore/dashboard.php' },
    { label: 'Students',           icon: 'users',        href: '/educore/modules/students/' },
    { label: 'Add Student',        icon: 'users',        href: '/educore/modules/students/create.php' },
    { label: 'Groups',             icon: 'layers',       href: '/educore/modules/groups/' },
    { label: 'Create Group',       icon: 'layers',       href: '/educore/modules/groups/create.php' },
    { label: 'Attendance',         icon: 'check',        href: '/educore/modules/attendance/' },
    { label: 'Take Attendance',    icon: 'check',        href: '/educore/modules/attendance/create.php' },
    { label: 'Exams',              icon: 'file',         href: '/educore/modules/exams/' },
    { label: 'Create Exam',        icon: 'file',         href: '/educore/modules/exams/create.php' },
    { label: 'Question Bank',      icon: 'file',         href: '/educore/modules/exams/question_bank.php' },
    { label: 'Payments',           icon: 'dollar',       href: '/educore/modules/payments/' },
    { label: 'Record Payment',     icon: 'dollar',       href: '/educore/modules/payments/record.php' },
    { label: 'Reports',            icon: 'chart',        href: '/educore/modules/reports/' },
    { label: 'Attendance Reports', icon: 'chart',        href: '/educore/modules/reports/attendance.php' },
    { label: 'Videos / Lectures',  icon: 'play',         href: '/educore/modules/videos/' },
    { label: 'Settings',           icon: 'settings',     href: '/educore/modules/settings/' },
    { label: 'Staff Management',   icon: 'settings',     href: '/educore/modules/settings/staff.php' },
    { label: 'Security',           icon: 'settings',     href: '/educore/modules/settings/security.php' },
    { label: 'Sign Out',           icon: 'logout',       href: '/educore/logout.php', danger: true },
  ];

  const ICONS = {
    grid:     '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
    users:    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    layers:   '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
    check:    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
    file:     '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
    dollar:   '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
    chart:    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
    play:     '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>',
    settings: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
    logout:   '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
  };

  // Inject palette HTML
  const paletteEl = document.createElement('div');
  paletteEl.id = 'cmdPalette';
  paletteEl.innerHTML = `
    <div id="cmdBackdrop" style="position:fixed;inset:0;background:rgba(15,23,42,0.55);backdrop-filter:blur(4px);z-index:8000;display:none;align-items:flex-start;justify-content:center;padding-top:15vh;">
      <div id="cmdBox" style="width:100%;max-width:560px;background:var(--bg-surface);border:1px solid var(--border);border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,0.22);overflow:hidden;transform:translateY(-12px) scale(0.97);opacity:0;transition:transform 0.2s cubic-bezier(0.34,1.56,0.64,1),opacity 0.18s ease;">
        <div style="display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--border);">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
          <input id="cmdInput" type="text" placeholder="Search pages, actions…" autocomplete="off" style="flex:1;background:none;border:none;outline:none;font-family:var(--font-body);font-size:0.95rem;color:var(--text-primary);"/>
          <kbd style="font-size:0.65rem;font-weight:600;color:var(--text-muted);background:var(--bg-elevated);border:1px solid var(--border);border-radius:5px;padding:2px 6px;">ESC</kbd>
        </div>
        <div id="cmdResults" style="max-height:340px;overflow-y:auto;padding:6px;"></div>
        <div style="padding:10px 16px;border-top:1px solid var(--border);display:flex;align-items:center;gap:16px;background:var(--bg-elevated);">
          <span style="font-size:0.7rem;color:var(--text-muted);display:flex;align-items:center;gap:4px;"><kbd style="background:var(--bg-surface);border:1px solid var(--border);border-radius:4px;padding:1px 5px;font-size:0.68rem;">↑↓</kbd> navigate</span>
          <span style="font-size:0.7rem;color:var(--text-muted);display:flex;align-items:center;gap:4px;"><kbd style="background:var(--bg-surface);border:1px solid var(--border);border-radius:4px;padding:1px 5px;font-size:0.68rem;">↵</kbd> open</span>
          <span style="font-size:0.7rem;color:var(--text-muted);display:flex;align-items:center;gap:4px;"><kbd style="background:var(--bg-surface);border:1px solid var(--border);border-radius:4px;padding:1px 5px;font-size:0.68rem;">⌘K</kbd> toggle</span>
        </div>
      </div>
    </div>`;
  document.body.appendChild(paletteEl);

  let selectedIdx = 0;
  let filteredPages = [];

  function renderResults(query) {
    filteredPages = query
      ? PAGES.filter(p => p.label.toLowerCase().includes(query.toLowerCase()))
      : PAGES;
    selectedIdx = 0;
    const container = document.getElementById('cmdResults');
    if (!filteredPages.length) {
      container.innerHTML = `<div style="padding:24px;text-align:center;color:var(--text-muted);font-size:0.84rem;">No results for "${query}"</div>`;
      return;
    }
    container.innerHTML = filteredPages.map((p, i) => `
      <a href="${p.href}" class="cmd-item" data-idx="${i}" style="display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:8px;text-decoration:none;color:${p.danger ? 'var(--danger)' : 'var(--text-secondary)'};font-size:0.875rem;font-weight:500;transition:background 0.1s,color 0.1s;${i===0?'background:var(--accent-subtle);color:var(--accent);':''}">
        <span style="width:28px;height:28px;border-radius:7px;background:${p.danger?'var(--danger-dim)':'var(--bg-elevated)'};display:flex;align-items:center;justify-content:center;flex-shrink:0;color:${p.danger?'var(--danger)':'var(--text-muted)'};">${ICONS[p.icon]||''}</span>
        ${p.label}
        <svg style="margin-left:auto;opacity:0.3" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
      </a>`).join('');
    // Re-attach hover
    container.querySelectorAll('.cmd-item').forEach(el => {
      el.addEventListener('mouseenter', () => { selectedIdx = +el.dataset.idx; highlightItem(); });
    });
  }

  function highlightItem() {
    document.querySelectorAll('.cmd-item').forEach((el, i) => {
      const active = i === selectedIdx;
      const p = filteredPages[i];
      el.style.background = active ? 'var(--accent-subtle)' : '';
      el.style.color = active ? 'var(--accent)' : (p && p.danger ? 'var(--danger)' : 'var(--text-secondary)');
    });
    const active = document.querySelector(`.cmd-item[data-idx="${selectedIdx}"]`);
    if (active) active.scrollIntoView({ block: 'nearest' });
  }

  function openPalette() {
    const backdrop = document.getElementById('cmdBackdrop');
    const box = document.getElementById('cmdBox');
    backdrop.style.display = 'flex';
    renderResults('');
    requestAnimationFrame(() => requestAnimationFrame(() => {
      box.style.transform = 'translateY(0) scale(1)';
      box.style.opacity = '1';
    }));
    setTimeout(() => document.getElementById('cmdInput').focus(), 50);
    document.body.style.overflow = 'hidden';
  }

  function closePalette() {
    const backdrop = document.getElementById('cmdBackdrop');
    const box = document.getElementById('cmdBox');
    box.style.transform = 'translateY(-12px) scale(0.97)';
    box.style.opacity = '0';
    setTimeout(() => { backdrop.style.display = 'none'; document.body.style.overflow = ''; }, 180);
    document.getElementById('cmdInput').value = '';
  }

  document.getElementById('cmdInput').addEventListener('input', e => renderResults(e.target.value));
  document.getElementById('cmdBackdrop').addEventListener('click', e => { if (e.target === document.getElementById('cmdBackdrop')) closePalette(); });

  document.addEventListener('keydown', e => {
    const open = document.getElementById('cmdBackdrop').style.display === 'flex';
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
      e.preventDefault();
      // Override topbar search shortcut for full palette
      open ? closePalette() : openPalette();
      return;
    }
    if (!open) return;
    if (e.key === 'Escape')    { closePalette(); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); selectedIdx = Math.min(selectedIdx+1, filteredPages.length-1); highlightItem(); }
    if (e.key === 'ArrowUp')   { e.preventDefault(); selectedIdx = Math.max(selectedIdx-1, 0); highlightItem(); }
    if (e.key === 'Enter')     { e.preventDefault(); const item = filteredPages[selectedIdx]; if (item) window.location.href = item.href; }
  });

  window.openCommandPalette = openPalette;
})();

// ═══════════════════════════════════════════════
// 3. MOBILE BOTTOM NAVIGATION
// ═══════════════════════════════════════════════
(function() {
  // Only render on phones
  if (window.innerWidth > 768) return;

  const currentPath = window.location.pathname;
  const mobileNav = document.createElement('nav');
  mobileNav.id = 'mobileBottomNav';
  mobileNav.innerHTML = `
    <a href="/educore/dashboard.php" class="mbn-item ${currentPath.includes('dashboard') ? 'mbn-active' : ''}">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      <span>Home</span>
    </a>
    <a href="/educore/modules/students/" class="mbn-item ${currentPath.includes('students') ? 'mbn-active' : ''}">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span>Students</span>
    </a>
    <a href="/educore/modules/exams/" class="mbn-item ${currentPath.includes('exams') ? 'mbn-active' : ''}">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      <span>Exams</span>
    </a>
    <a href="/educore/modules/payments/" class="mbn-item ${currentPath.includes('payments') ? 'mbn-active' : ''}">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      <span>Payments</span>
    </a>
    <button class="mbn-item" onclick="openCommandPalette()">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <span>Search</span>
    </button>`;
  document.body.appendChild(mobileNav);

  // Add padding so content isn't hidden behind bottom nav
  const mainContent = document.querySelector('.main-content');
  if (mainContent) mainContent.style.paddingBottom = '68px';
})();

// ═══════════════════════════════════════════════
// 4. ONBOARDING FLOW (first-time owner setup)
// ═══════════════════════════════════════════════
(function() {
  // Server-side flag: only show for new owner accounts (created within 48 hours)
  // Once dismissed it sets a localStorage key so it doesn't re-show on the same browser
  const ONBOARD_KEY = 'ec_onboarded_' + (document.body.dataset.role || '');
  const shouldShow  = <?= json_encode($showOnboarding ?? false) ?>;
  const dismissed   = localStorage.getItem(ONBOARD_KEY) === '1';
  if (!shouldShow || dismissed) return;

  const STEPS = [
    {
      icon: '🎓',
      title: 'Welcome to EduCore!',
      desc: "You're all set up. Let's take 30 seconds to get your center ready.",
      cta: 'Get started',
      skip: 'Skip tour',
    },
    {
      icon: '👥',
      title: 'Create your first group',
      desc: 'Groups are the core of EduCore — add a class, assign a teacher, and enroll students.',
      cta: 'Create a group →',
      href: '/educore/modules/groups/create.php',
      skip: 'Do this later',
    },
    {
      icon: '🎒',
      title: 'Add your students',
      desc: 'Import students one by one or in bulk. Each gets a unique login to track their progress.',
      cta: 'Add students →',
      href: '/educore/modules/students/create.php',
      skip: 'Do this later',
    },
    {
      icon: '📝',
      title: 'Build your first exam',
      desc: 'Create a quiz or full exam. Add multiple-choice and short-answer questions from your question bank.',
      cta: 'Create an exam →',
      href: '/educore/modules/exams/create.php',
      skip: 'Do this later',
    },
    {
      icon: '🚀',
      title: "You're ready!",
      desc: 'Your EduCore center is configured. Explore the dashboard to manage attendance, payments, and reports.',
      cta: 'Go to Dashboard',
      href: '/educore/dashboard.php',
      finish: true,
    },
  ];

  let step = 0;

  const el = document.createElement('div');
  el.id = 'onboardingModal';
  el.innerHTML = `
    <div id="onboardBackdrop" style="position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(6px);z-index:9000;display:flex;align-items:center;justify-content:center;padding:20px;">
      <div id="onboardBox" style="width:100%;max-width:440px;background:var(--bg-surface);border:1px solid var(--border);border-radius:20px;box-shadow:0 24px 64px rgba(0,0,0,0.22);overflow:hidden;">
        <!-- Progress bar -->
        <div style="height:4px;background:var(--bg-elevated);">
          <div id="onboardProgress" style="height:100%;background:var(--accent);border-radius:4px;transition:width 0.4s ease;width:20%;"></div>
        </div>
        <!-- Content -->
        <div style="padding:36px 32px 28px;text-align:center;">
          <div id="onboardIcon" style="font-size:2.8rem;margin-bottom:16px;line-height:1;">🎓</div>
          <h2 id="onboardTitle" style="font-family:var(--font-display);font-size:1.25rem;font-weight:800;color:var(--text-primary);letter-spacing:-0.03em;margin-bottom:10px;"></h2>
          <p id="onboardDesc" style="font-size:0.875rem;color:var(--text-secondary);line-height:1.7;margin-bottom:28px;"></p>
          <button id="onboardCta" style="display:block;width:100%;padding:12px 20px;background:var(--accent);color:#fff;border:none;border-radius:10px;font-family:var(--font-body);font-size:0.9rem;font-weight:700;cursor:pointer;margin-bottom:10px;transition:background 0.15s;"></button>
          <button id="onboardSkip" style="display:block;width:100%;padding:10px;background:none;border:none;font-family:var(--font-body);font-size:0.82rem;color:var(--text-muted);cursor:pointer;transition:color 0.15s;"></button>
        </div>
        <!-- Step dots -->
        <div id="onboardDots" style="display:flex;justify-content:center;gap:6px;padding:0 32px 24px;"></div>
      </div>
    </div>`;
  document.body.appendChild(el);

  function renderStep() {
    const s = STEPS[step];
    document.getElementById('onboardIcon').textContent = s.icon;
    document.getElementById('onboardTitle').textContent = s.title;
    document.getElementById('onboardDesc').textContent = s.desc;
    document.getElementById('onboardCta').textContent = s.cta;
    document.getElementById('onboardSkip').textContent = s.skip || 'Skip';
    document.getElementById('onboardProgress').style.width = ((step + 1) / STEPS.length * 100) + '%';

    // Dots
    document.getElementById('onboardDots').innerHTML = STEPS.map((_, i) =>
      `<div style="width:${i===step?'20px':'8px'};height:8px;border-radius:99px;background:${i===step?'var(--accent)':'var(--border-light)'};transition:all 0.3s;"></div>`
    ).join('');

    document.getElementById('onboardCta').onclick = function() {
      if (s.finish) { finish(); return; }
      if (s.href) { finish(); window.location.href = s.href; return; }
      step++;
      if (step < STEPS.length) renderStep();
      else finish();
    };
    document.getElementById('onboardSkip').onclick = function() {
      step++;
      if (step < STEPS.length) renderStep();
      else finish();
    };
  }

  function finish() {
    localStorage.setItem(ONBOARD_KEY, '1');
    const backdrop = document.getElementById('onboardBackdrop');
    const box = document.getElementById('onboardBox');
    box.style.transform = 'scale(0.95)';
    box.style.opacity = '0';
    box.style.transition = 'all 0.2s ease';
    setTimeout(() => backdrop.remove(), 200);
  }

  // Slight delay so page renders first
  setTimeout(() => {
    renderStep();
    document.getElementById('onboardBackdrop').style.display = 'flex';
  }, 800);
})();


// ═══════════════════════════════════════════════
// SEARCH DEBOUNCE
// ═══════════════════════════════════════════════
function debounce(fn, delay) {
  let t;
  return function(...args) {
    clearTimeout(t);
    t = setTimeout(() => fn.apply(this, args), delay);
  };
}
</script>

<?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html>
