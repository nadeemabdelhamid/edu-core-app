    </main>
  </div>
</div>

<script>
// ─── SIDEBAR TOGGLE (mobile) ─────────────────────────────
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
}

function toggleUserMenu() {
  const m = document.getElementById('userMenu');
  m.style.display = m.style.display === 'none' ? 'block' : 'none';
}

// Show menu toggle on mobile
function checkMobile() {
  const toggle = document.getElementById('menuToggle');
  if (window.innerWidth <= 1024) {
    toggle.style.display = 'flex';
  } else {
    toggle.style.display = 'none';
    document.getElementById('sidebar').classList.remove('open');
  }
}
checkMobile();
window.addEventListener('resize', checkMobile);

// Close sidebar when clicking outside (mobile)
document.addEventListener('click', function(e) {
  const sidebar = document.getElementById('sidebar');
  const toggle  = document.getElementById('menuToggle');
  if (window.innerWidth <= 1024 && !sidebar.contains(e.target) && e.target !== toggle) {
    sidebar.classList.remove('open');
  }
});

// ─── AUTO DISMISS FLASH ───────────────────────────────────
document.querySelectorAll('.flash-msg').forEach(function(el) {
  setTimeout(function() {
    el.style.opacity = '0';
    el.style.transform = 'translateX(100%)';
    el.style.transition = 'all 0.3s ease';
    setTimeout(function() { el.remove(); }, 300);
  }, 4000);
});


// ─── NOTIFICATION PANEL ──────────────────────────────────
function toggleNotifPanel() {
  const p = document.getElementById('notifPanel');
  p.style.display = p.style.display === 'none' ? 'block' : 'none';
}
function markAllRead() {
  fetch('/educore/modules/settings/mark_read.php', {method:'POST', headers:{'X-CSRF-TOKEN': csrfToken}});
  document.getElementById('notifDot') && document.getElementById('notifDot').remove();
  document.getElementById('notifPanel').querySelectorAll('[style*="border-bottom"]').forEach(el => el.remove());
  const list = document.getElementById('notifPanel').querySelector('[style*="max-height"]');
  if(list) list.innerHTML = '<div style="padding:24px 16px;text-align:center;color:var(--text-muted);font-size:0.8rem;">All caught up ✓</div>';
}
document.addEventListener('click', function(e) {
  const panel = document.getElementById('notifPanel');
  const btn   = document.getElementById('notifBtn');
  if(panel && btn && !panel.contains(e.target) && !btn.contains(e.target)) panel.style.display='none';
});

// ─── CSRF HEADER FOR AJAX ────────────────────────────────
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

// ─── MODAL HELPERS ───────────────────────────────────────
function openModal(id) {
  document.getElementById(id).classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow = '';
}

// Close modal on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
  backdrop.addEventListener('click', function(e) {
    if (e.target === backdrop) closeModal(backdrop.id);
  });
});

// ─── CONFIRM DIALOG ──────────────────────────────────────
function confirmAction(message, callback) {
  if (confirm(message)) callback();
}

// ─── SEARCH DEBOUNCE ─────────────────────────────────────
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
