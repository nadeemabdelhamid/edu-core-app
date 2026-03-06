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
