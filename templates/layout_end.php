    </main>

    <!-- PAGE FOOTER -->
    <footer class="page-footer">
      <div class="page-footer-inner">
        <div class="page-footer-left">
          <a href="/educore/dashboard.php" class="page-footer-brand">
            <span class="page-footer-brand-icon">&#127979;</span>
            <span>By<strong>Nadeem</strong></span>
          </a>
          <span class="page-footer-sep">&middot;</span>
          <span class="page-footer-copy">&copy; <?= date('Y') ?> By Nadeem. All rights reserved.</span>
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
        font-family:'Inter',sans-serif; font-size:1.05rem; font-weight:700;
        color:#0f172a; letter-spacing:-0.02em; margin-bottom:6px;
      "></div>
      <div id="ec-confirm-message" style="
        font-family:'Inter',sans-serif; font-size:0.875rem;
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
        font-family:'Inter',sans-serif; font-size:0.875rem; font-weight:600;
        color:#475569; cursor:pointer; transition:all 0.15s;
      " onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
        Cancel
      </button>
      <button id="ec-confirm-ok" onclick="ecConfirmResolve(true)" style="
        flex:1; padding:10px 20px;
        border:none; border-radius:8px;
        font-family:'Inter',sans-serif; font-size:0.875rem; font-weight:700;
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
  font-family: 'Inter', sans-serif;
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
  const STORAGE_KEY = 'ec_sidebar_collapsed';
  const sidebar   = document.getElementById('sidebar');
  const toggle    = document.getElementById('menuToggle');

  // Create overlay element for mobile
  const overlay = document.createElement('div');
  overlay.className = 'sidebar-overlay';
  overlay.id = 'sidebarOverlay';
  document.body.appendChild(overlay);
  overlay.addEventListener('click', closeMobileSidebar);

  // Restore desktop collapsed state on load
  if (window.innerWidth > 1024 && localStorage.getItem(STORAGE_KEY) === '1') {
    document.body.classList.add('sidebar-collapsed');
    updateToggleIcon(true);
  }

  window.toggleSidebar = function() {
    if (window.innerWidth <= 1024) {
      // Mobile: slide-in overlay mode
      const isOpen = sidebar.classList.contains('open');
      if (isOpen) {
        closeMobileSidebar();
      } else {
        sidebar.classList.add('open');
        overlay.style.display = 'block';
        requestAnimationFrame(() => overlay.classList.add('visible'));
      }
    } else {
      // Desktop: collapse/expand with body class
      const collapsed = document.body.classList.toggle('sidebar-collapsed');
      localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
      updateToggleIcon(collapsed);
    }
  };

  function closeMobileSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('visible');
    setTimeout(() => { overlay.style.display = 'none'; }, 250);
  }

  function updateToggleIcon(collapsed) {
    if (!toggle) return;
    toggle.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
  }

  // On resize: clean up mobile state when going to desktop
  window.addEventListener('resize', function() {
    if (window.innerWidth > 1024) {
      closeMobileSidebar();
    }
  });
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
// TOPBAR SEARCH
// ═══════════════════════════════════════════════
function topbarSearchOpen() {
  document.getElementById('topbarSearchDrop').style.display = 'block';
}

function topbarSearchQuery(val) {
  const drop = document.getElementById('topbarSearchDrop');
  drop.style.display = 'block';
  const items = drop.querySelectorAll('.tsd-item');
  items.forEach(el => {
    el.style.display = el.textContent.toLowerCase().includes(val.toLowerCase()) ? 'flex' : 'none';
  });
}

document.addEventListener('click', function(e) {
  const wrap = document.querySelector('.topbar-search-wrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('topbarSearchDrop').style.display = 'none';
  }
});

// ⌘K / Ctrl+K shortcut
document.addEventListener('keydown', function(e) {
  if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
    e.preventDefault();
    const input = document.getElementById('topbarSearchInput');
    if (input) { input.focus(); input.select(); topbarSearchOpen(); }
  }
  if (e.key === 'Escape') {
    document.getElementById('topbarSearchDrop').style.display = 'none';
    const input = document.getElementById('topbarSearchInput');
    if (input) input.blur();
  }
});

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
