function shCsrf(){var m=document.querySelector('meta[name="csrf-token"]');return m?m.content:'';}
// ══════════════════════════════════════════════════════════
//  SmartHire v3 — main.js  (fixed & enhanced)
// ══════════════════════════════════════════════════════════

// ── Sidebar toggle ────────────────────────────────────────
function toggleSidebar() {
  document.getElementById('sidebar')?.classList.toggle('open');
}
document.addEventListener('click', (e) => {
  const sidebar = document.getElementById('sidebar');
  const toggle  = document.querySelector('.sidebar-toggle');
  if (sidebar && !sidebar.contains(e.target) && !toggle?.contains(e.target)) {
    sidebar.classList.remove('open');
  }
});

// ── Auto-dismiss flash messages ───────────────────────────
(function () {
  const flash = document.getElementById('flash-msg');
  if (flash) setTimeout(() => flash.remove(), 5000);
})();

// ── Modal helpers ─────────────────────────────────────────
function openModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.style.removeProperty('display');   // remove any inline display:none
  el.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal(id) {
  document.getElementById(id)?.classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.style.removeProperty('display');
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeModal(overlay.id);
    });
  });
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(m => closeModal(m.id));
    closeNotifDropdown();
  }
});

// ── Animated score bars ───────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.score-bar-fill').forEach(bar => {
    const pct = bar.dataset.pct || '0';
    bar.style.width = '0%';
    setTimeout(() => { bar.style.width = pct + '%'; }, 200);
  });
  document.querySelectorAll('.ai-score-ring').forEach(ring => {
    ring.style.setProperty('--pct', ring.dataset.pct || '0');
  });
});

// ── Confirm deletes ───────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', (e) => {
      if (!confirm(el.dataset.confirm)) e.preventDefault();
    });
  });
});

// ── Table search filter ───────────────────────────────────
const searchInput = document.getElementById('tableSearch');
if (searchInput) {
  searchInput.addEventListener('input', () => {
    const q = searchInput.value.toLowerCase();
    document.querySelectorAll('tbody tr').forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
}

// ══════════════════════════════════════════════════════════
//  NOTIFICATION BELL — Full working dropdown
// ══════════════════════════════════════════════════════════
let notifLoaded = false;

function toggleNotifDropdown(e) {
  e.stopPropagation();
  const dropdown = document.getElementById('notifDropdown');
  if (!dropdown) return;
  const isOpen = dropdown.classList.contains('open');
  if (isOpen) {
    closeNotifDropdown();
  } else {
    dropdown.classList.add('open');
    if (!notifLoaded) loadNotifications();
  }
}

function closeNotifDropdown() {
  document.getElementById('notifDropdown')?.classList.remove('open');
}

// Close when clicking outside
document.addEventListener('click', (e) => {
  const wrapper = document.getElementById('notifWrapper');
  if (wrapper && !wrapper.contains(e.target)) closeNotifDropdown();
});

// Type → icon + color mapping
const notifMeta = {
  test_submitted:       { icon: 'fa-paper-plane',      color: '#8b5cf6', bg: 'rgba(139,92,246,.15)' },
  interview_scheduled:  { icon: 'fa-calendar-check',   color: '#3b82f6', bg: 'rgba(59,130,246,.15)' },
  result_added:         { icon: 'fa-chart-bar',         color: '#10b981', bg: 'rgba(16,185,129,.15)' },
  ats_scanned:          { icon: 'fa-file-magnifying-glass', color: '#f59e0b', bg: 'rgba(245,158,11,.15)' },
  hired:                { icon: 'fa-handshake',         color: '#10b981', bg: 'rgba(16,185,129,.15)' },
  rejected:             { icon: 'fa-user-xmark',        color: '#f43f5e', bg: 'rgba(244,63,94,.15)' },
  default:              { icon: 'fa-bell',              color: '#3b82f6', bg: 'rgba(59,130,246,.15)' },
};

function timeAgo(dateStr) {
  const now  = new Date();
  const then = new Date(dateStr);
  const diff = Math.floor((now - then) / 1000);
  if (diff < 60)   return 'just now';
  if (diff < 3600) return Math.floor(diff/60) + 'm ago';
  if (diff < 86400)return Math.floor(diff/3600) + 'h ago';
  return Math.floor(diff/86400) + 'd ago';
}

async function loadNotifications() {
  const list = document.getElementById('notifList');
  if (!list) return;
  try {
    const res  = await fetch('notifications_api.php?action=list');
    const data = await res.json();
    notifLoaded = true;

    if (!data.notifications || data.notifications.length === 0) {
      list.innerHTML = `<div class="notif-empty"><i class="fa-solid fa-bell-slash"></i><p>No notifications yet</p></div>`;
      return;
    }

    list.innerHTML = data.notifications.map(n => {
      const meta = notifMeta[n.type] || notifMeta.default;
      return `
        <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}" onclick="markRead(${n.id}, this)">
          <div class="notif-dot ${n.is_read == 1 ? 'read' : ''}"></div>
          <div class="notif-icon-wrap" style="background:${meta.bg}">
            <i class="fa-solid ${meta.icon}" style="color:${meta.color}"></i>
          </div>
          <div class="notif-text">
            <div class="notif-msg">${escHtml(n.message)}</div>
            <div class="notif-time">${timeAgo(n.created_at)}</div>
          </div>
        </div>`;
    }).join('');

    // Update badge
    updateBadge(data.unread_count);
  } catch(err) {
    list.innerHTML = `<div class="notif-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>Could not load notifications</p></div>`;
  }
}

async function markRead(id, el) {
  el.classList.remove('unread');
  el.querySelector('.notif-dot')?.classList.add('read');
  await fetch('notifications_api.php?action=mark_read&id=' + id, {headers:{'X-CSRF-Token':shCsrf()}});
  const badge = document.getElementById('notifBadge');
  if (badge) {
    const cur = parseInt(badge.textContent) || 0;
    updateBadge(Math.max(0, cur - 1));
  }
}

async function markAllRead() {
  await fetch('notifications_api.php?action=mark_all', {headers:{'X-CSRF-Token':shCsrf()}});
  document.querySelectorAll('.notif-item.unread').forEach(el => {
    el.classList.remove('unread');
    el.querySelector('.notif-dot')?.classList.add('read');
  });
  updateBadge(0);
}

function updateBadge(count) {
  const badge = document.getElementById('notifBadge');
  if (!badge) return;
  if (count > 0) {
    badge.textContent = count;
    badge.style.display = '';
  } else {
    badge.style.display = 'none';
  }
}

function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Poll for new notifications every 60 seconds
setInterval(async () => {
  try {
    const res  = await fetch('notifications_api.php?action=count');
    const data = await res.json();
    updateBadge(data.count || 0);
    if (document.getElementById('notifDropdown')?.classList.contains('open')) {
      notifLoaded = false;
      loadNotifications();
    }
  } catch(e) {}
}, 60000);
