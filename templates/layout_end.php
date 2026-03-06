    </main>
  </div>
</div>

<script>
// Sidebar toggle (mobile)
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
}

// User menu
function toggleUserMenu() {
  const m = document.getElementById('userMenu');
  m.style.display = m.style.display === 'none' ? 'block' : 'none';
}

// Show hamburger on mobile
function checkMobile() {
  const btn = document.getElementById('menuToggle');
  if (!btn) return;
  btn.style.display = window.innerWidth <= 1024 ? 'flex' : 'none';
  if (window.innerWidth > 1024) {
    document.getElementById('sidebar').classList.remove('open');
  }
}
checkMobile();
window.addEventListener('resize', checkMobile);

// Close sidebar on outside click (mobile)
document.addEventListener('click', function(e) {
  const sb = document.getElementById('sidebar');
  const mb = document.getElementById('menuToggle');
  if (window.innerWidth <= 1024 && sb && !sb.contains(e.target) && e.target !== mb) {
    sb.classList.remove('open');
  }
});

// Auto-dismiss flash messages after 4s
document.querySelectorAll('.flash-msg').forEach(function(el) {
  setTimeout(function() {
    el.style.transition = 'all 0.3s ease';
    el.style.opacity = '0';
    el.style.transform = 'translateX(110%)';
    setTimeout(function() { el.remove(); }, 300);
  }, 4000);
});

// Global CSRF token for AJAX
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// Modal helpers
function openModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.add('open'); document.body.style.overflow = 'hidden'; }
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.remove('open'); document.body.style.overflow = ''; }
}

document.querySelectorAll('.modal-backdrop').forEach(function(b) {
  b.addEventListener('click', function(e) {
    if (e.target === b) closeModal(b.id);
  });
});

// Debounce utility
function debounce(fn, ms) {
  let t;
  return function(...a) { clearTimeout(t); t = setTimeout(() => fn.apply(this, a), ms); };
}
</script>

<?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>
