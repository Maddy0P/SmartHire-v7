/* ═══════════════════════════════════════════════════════════════════════════
   SmartHire v7 — assets/js/v7.js   (Build 2)
   Progressive enhancement: mobile sidebar drawer, toasts, AJAX pipeline moves,
   drag-and-drop between stages. Loads AFTER main.js and is fully optional —
   every action also works as a normal form/link if JS is disabled.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ── CSRF token (rendered into <meta> by pages) ── */
  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.content : '';
  }

  /* ── Toasts ── */
  function ensureToastHost() {
    var host = document.getElementById('sh-toasts');
    if (!host) { host = document.createElement('div'); host.id = 'sh-toasts'; document.body.appendChild(host); }
    return host;
  }
  window.shToast = function (msg, type) {
    var host = ensureToastHost();
    var t = document.createElement('div');
    t.className = 'sh-toast ' + (type === 'error' ? 'err' : type === 'success' ? 'ok' : '');
    var icon = type === 'error' ? 'fa-circle-exclamation' : type === 'success' ? 'fa-circle-check' : 'fa-circle-info';
    t.innerHTML = '<i class="fa-solid ' + icon + '"></i><span></span>';
    t.querySelector('span').textContent = msg;
    host.appendChild(t);
    setTimeout(function () { t.style.opacity = '0'; t.style.transform = 'translateX(30px)'; setTimeout(function () { t.remove(); }, 250); }, 3200);
  };

  /* ── Mobile off-canvas sidebar ── */
  function overlay() {
    var o = document.getElementById('sh-overlay');
    if (!o) { o = document.createElement('div'); o.id = 'sh-overlay'; document.body.appendChild(o); o.addEventListener('click', closeSidebar); }
    return o;
  }
  function openSidebar() { var s = document.getElementById('sidebar'); if (s) { s.classList.add('open'); overlay().classList.add('show'); } }
  function closeSidebar() { var s = document.getElementById('sidebar'); if (s) { s.classList.remove('open'); var o = document.getElementById('sh-overlay'); if (o) o.classList.remove('show'); } }
  // Override/augment the v6 toggleSidebar so it works as a drawer on mobile.
  window.toggleSidebar = function () {
    var s = document.getElementById('sidebar');
    if (!s) return;
    if (window.innerWidth <= 768) { s.classList.contains('open') ? closeSidebar() : openSidebar(); }
    else { s.classList.toggle('collapsed'); }
  };
  window.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSidebar(); });

  /* ── AJAX pipeline stage move ── */
  window.shMoveStage = function (appId, toStage, cb) {
    var body = new URLSearchParams();
    body.set('_csrf', csrf());
    body.set('action', 'move_stage');
    body.set('app_id', appId);
    body.set('to_stage', toStage);
    return fetch('applications.php?ajax=1', {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest',
                 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) { window.shToast(j.message || 'Stage updated', 'success'); if (cb) cb(true, j); }
        else { window.shToast((j && j.message) || 'Update failed', 'error'); if (cb) cb(false, j); }
      })
      .catch(function () { window.shToast('Network error', 'error'); if (cb) cb(false); });
  };

  /* ── Drag & drop between pipeline columns ── */
  function initPipeline() {
    var board = document.querySelector('.pipeline');
    if (!board) return;
    var dragged = null;

    board.querySelectorAll('.pipe-card[draggable="true"]').forEach(function (card) {
      card.addEventListener('dragstart', function (e) {
        dragged = card; card.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', card.dataset.appId);
      });
      card.addEventListener('dragend', function () { card.classList.remove('dragging'); dragged = null; });
    });

    board.querySelectorAll('.pipe-col').forEach(function (col) {
      col.addEventListener('dragover', function (e) { e.preventDefault(); col.classList.add('drop-hint'); });
      col.addEventListener('dragleave', function () { col.classList.remove('drop-hint'); });
      col.addEventListener('drop', function (e) {
        e.preventDefault(); col.classList.remove('drop-hint');
        if (!dragged) return;
        var toStage = col.dataset.stage;
        var fromStage = dragged.dataset.stage;
        if (toStage === fromStage) return;
        var appId = dragged.dataset.appId;
        var card = dragged;
        shMoveStage(appId, toStage, function (ok) {
          if (ok) {
            col.querySelector('.pipe-body').appendChild(card);
            card.dataset.stage = toStage;
            refreshCounts(board);
          }
        });
      });
    });
  }
  function refreshCounts(board) {
    board.querySelectorAll('.pipe-col').forEach(function (col) {
      var n = col.querySelectorAll('.pipe-card').length;
      var c = col.querySelector('.cnt'); if (c) c.textContent = n;
    });
  }

  /* ── Confirm-guard for destructive actions ── */
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); }
  });

  /* ── Client-side live filter for lists marked [data-filterable] ── */
  function initLiveFilter() {
    document.querySelectorAll('input[data-filter-target]').forEach(function (input) {
      input.addEventListener('input', function () {
        var q = input.value.toLowerCase().trim();
        document.querySelectorAll(input.dataset.filterTarget).forEach(function (row) {
          row.style.display = row.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
        });
      });
    });
  }

  /* ── Animated score counters ([data-count]) ── */
  function initCounters() {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    document.querySelectorAll('[data-count]').forEach(function (el) {
      var target = parseInt(el.getAttribute('data-count'), 10);
      if (isNaN(target)) return;
      var start = null, dur = 900;
      function step(ts) {
        if (!start) start = ts;
        var p = Math.min((ts - start) / dur, 1);
        el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3)));  // ease-out cubic
        if (p < 1) requestAnimationFrame(step);
      }
      el.textContent = '0';
      requestAnimationFrame(step);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initPipeline();
    initLiveFilter();
    initCounters();
    // surface a flash message from ?flash= as a toast (optional)
    var p = new URLSearchParams(location.search);
    if (p.get('toast')) window.shToast(p.get('toast'), p.get('toast_type') || 'success');
  });
})();
