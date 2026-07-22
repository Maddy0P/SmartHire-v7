/* ═══════════════════════════════════════════════════════════════════════════
 *  SmartHire Assessment Player (Module 8C)
 *  Server-authoritative timing + autosave-driven recovery. The client clock is
 *  display-only: it counts down locally but re-syncs to the server's authoritative
 *  `remaining` on every autosave/nav/ping response, so refresh, reconnect and
 *  crash all recover correctly. Autosaves queue when offline and flush on
 *  reconnect (last-write-wins resolved server-side). Anti-cheat signals are
 *  logged, never blocking.
 * ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var bootEl = document.getElementById('ap-boot');
  if (!bootEl) return;
  var B = JSON.parse(bootEl.textContent);
  var URL = 'take_test.php?token=' + encodeURIComponent(B.token);

  var state = {
    cur: B.startAt || 0,
    remaining: B.remaining,
    answered: new Set(B.answered || []),
    flagged: new Set(B.flagged || []),
    perQSpent: {},
    perQStart: {},
    qEnter: Date.now(),
    signalQueue: [],
    saveQueue: [],
    online: navigator.onLine,
    submitting: false,
    warned: {}
  };
  var TQ = B.totalQ;
  var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
  var qid = function (i) { return B.qids[i]; };

  // ── DOM refs ──────────────────────────────────────────────────────────────
  var $ = function (id) { return document.getElementById(id); };
  var timerVal = $('ap-timer-val'), timerBox = $('ap-timer'), counter = $('ap-counter'),
      saveEl = $('ap-save'), netEl = $('ap-net'), toast = $('ap-toast'),
      progFill = $('ap-progress-fill'), ansCount = $('ap-ans-count'), sbProg = $('ap-sb-prog');

  // ── Server communication ─────────────────────────────────────────────────
  function post(api, data) {
    var fd = new FormData();
    fd.append('api', api);
    fd.append('_csrf', B.csrf);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(URL, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': B.csrf } })
      .then(function (r) { return r.json(); });
  }

  function syncRemaining(resp) {
    if (resp && typeof resp.remaining === 'number') {
      state.remaining = resp.remaining;               // server is authoritative
    }
  }

  // ── Autosave (queued, offline-safe) ───────────────────────────────────────
  function setSave(status) {
    if (!saveEl) return;
    if (status === 'saving') saveEl.innerHTML = '<i class="fa-solid fa-rotate ap-spin"></i> Saving…';
    else if (status === 'saved') saveEl.innerHTML = '<i class="fa-solid fa-check"></i> Saved';
    else if (status === 'queued') saveEl.innerHTML = '<i class="fa-solid fa-clock"></i> Will save';
    else if (status === 'error') saveEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Retrying';
  }

  function enqueueSave(i) {
    var id = qid(i);
    var q = document.getElementById('ap-q-' + i);
    var payload = { qid: id, time_spent: spentOn(i), flag: state.flagged.has(i) ? 1 : 0, client_ts: new Date().toISOString() };
    var multi = q.querySelectorAll('[data-multi]');
    if (multi.length) {
      var picks = [];
      multi.forEach(function (cb) { if (cb.checked) picks.push(cb.value); });
      payload.response = JSON.stringify({ selected: picks });
      payload.answer = '';
    } else {
      var field = q.querySelector('[data-answer]');
      payload.answer = field ? field.value : '';
    }
    // de-dupe: replace any queued save for the same question
    state.saveQueue = state.saveQueue.filter(function (p) { return p.qid !== id; });
    state.saveQueue.push(payload);
    flushSaves();
  }

  function flushSaves() {
    if (!state.online || !state.saveQueue.length) { if (state.saveQueue.length) setSave('queued'); return; }
    var payload = state.saveQueue.shift();
    setSave('saving');
    post('autosave', payload).then(function (resp) {
      if (resp && resp.ok) { syncRemaining(resp); setSave(state.saveQueue.length ? 'saving' : 'saved'); }
      else { setSave('error'); state.saveQueue.unshift(payload); }
      if (state.saveQueue.length) setTimeout(flushSaves, 120);
    }).catch(function () {
      state.saveQueue.unshift(payload); state.online = false; updateNet(); setSave('queued');
    });
  }

  function spentOn(i) {
    var base = state.perQSpent[i] || 0;
    if (state.perQStart[i]) base += Math.round((Date.now() - state.perQStart[i]) / 1000);
    return base;
  }

  // ── Answer tracking ───────────────────────────────────────────────────────
  function markAnswered(i, has) {
    if (has) state.answered.add(i); else state.answered.delete(i);
    updateDot(i); updateProgress();
  }

  function isAnswered(i) {
    var q = document.getElementById('ap-q-' + i);
    var multi = q.querySelectorAll('[data-multi]');
    if (multi.length) { for (var m = 0; m < multi.length; m++) if (multi[m].checked) return true; return false; }
    var radios = q.querySelectorAll('input[type=radio]');
    if (radios.length) { for (var r = 0; r < radios.length; r++) if (radios[r].checked) return true; return false; }
    var field = q.querySelector('[data-answer]');
    return field ? field.value.trim().length > 0 : false;
  }

  // ── Navigation ────────────────────────────────────────────────────────────
  function goto(i) {
    if (i < 0 || i >= TQ || i === state.cur) return;
    leaveQuestion(state.cur);
    document.getElementById('ap-q-' + state.cur).hidden = true;
    document.getElementById('ap-q-' + state.cur).classList.remove('is-active');
    document.getElementById('ap-dot-' + state.cur).classList.remove('is-cur');
    state.cur = i;
    var q = document.getElementById('ap-q-' + i);
    q.hidden = false; q.classList.add('is-active');
    document.getElementById('ap-dot-' + i).classList.add('is-cur');
    counter.textContent = 'Q ' + (i + 1) + ' / ' + TQ;
    enterQuestion(i);
    var content = $('ap-content'); if (content) { content.scrollTop = 0; content.focus(); }
    saveNav();
  }

  function enterQuestion(i) { state.perQStart[i] = Date.now(); startPerQTimer(i); }
  function leaveQuestion(i) {
    if (state.perQStart[i]) { state.perQSpent[i] = (state.perQSpent[i] || 0) + Math.round((Date.now() - state.perQStart[i]) / 1000); state.perQStart[i] = 0; }
    var tf = $('ap-time-' + qid(i)); if (tf) tf.value = state.perQSpent[i] || 0;
    enqueueSave(i);
    stopPerQTimer(i);
  }

  function saveNav() {
    var flags = []; state.flagged.forEach(function (i) { flags.push(qid(i)); });
    post('nav', { current: state.cur, flags: JSON.stringify(flags) }).then(syncRemaining).catch(function () {});
  }

  // ── Flags ─────────────────────────────────────────────────────────────────
  function toggleFlag(i) {
    var btn = $('ap-flag-' + i);
    if (state.flagged.has(i)) { state.flagged.delete(i); btn.setAttribute('aria-pressed', 'false'); btn.querySelector('span').textContent = 'Flag'; }
    else { state.flagged.add(i); btn.setAttribute('aria-pressed', 'true'); btn.querySelector('span').textContent = 'Flagged'; }
    updateDot(i); saveNav();
  }

  // ── UI updates ────────────────────────────────────────────────────────────
  function updateDot(i) {
    var d = $('ap-dot-' + i); if (!d) return;
    d.classList.toggle('is-ans', state.answered.has(i));
    d.classList.toggle('is-flg', state.flagged.has(i));
    d.classList.toggle('is-cur', i === state.cur);
  }
  function updateProgress() {
    var a = state.answered.size;
    if (ansCount) ansCount.textContent = a;
    if (sbProg) sbProg.textContent = a + '/' + TQ;
    if (progFill) progFill.style.width = (TQ ? a / TQ * 100 : 0) + '%';
  }

  // ── Timers ────────────────────────────────────────────────────────────────
  function tickMain() {
    state.remaining = Math.max(0, state.remaining - 1);
    var m = Math.floor(state.remaining / 60), s = state.remaining % 60;
    timerVal.textContent = pad(m) + ':' + pad(s);
    timerBox.classList.toggle('is-warn', state.remaining <= 300 && state.remaining > 60);
    timerBox.classList.toggle('is-danger', state.remaining <= 60);
    [600, 300, 60].forEach(function (mark) {
      if (state.remaining === mark && !state.warned[mark]) {
        state.warned[mark] = true;
        showToast('⏱ ' + (mark >= 60 ? (mark / 60) + ' minute' + (mark > 60 ? 's' : '') : mark + ' seconds') + ' remaining');
      }
    });
    if (state.remaining <= 0) submit(true);
  }

  var perQInt = {};
  function startPerQTimer(i) {
    var limit = B.perQLimits[i] || 0; if (!limit) return;
    stopPerQTimer(i);
    perQInt[i] = setInterval(function () {
      var left = limit - spentOn(i);
      var tag = $('ap-qt-' + i);
      if (tag) { tag.querySelector('span').textContent = Math.max(0, left) + 's'; tag.classList.toggle('is-danger', left <= limit * 0.1); }
      if (left <= 0) { stopPerQTimer(i); lockQuestion(i); }
    }, 500);
  }
  function stopPerQTimer(i) { if (perQInt[i]) { clearInterval(perQInt[i]); perQInt[i] = null; } }
  function lockQuestion(i) {
    var q = document.getElementById('ap-q-' + i);
    q.classList.add('is-locked');
    q.querySelectorAll('input,textarea,button[data-multi]').forEach(function (el) { if (!el.name || el.name.indexOf('time_') === -1) el.disabled = true; });
    if (i < TQ - 1) setTimeout(function () { goto(i + 1); }, 1200);
  }

  // ── Network + anti-cheat ──────────────────────────────────────────────────
  function updateNet() {
    if (netEl) {
      netEl.classList.toggle('is-off', !state.online);
      netEl.querySelector('.ap-net-txt').textContent = state.online ? 'Online' : 'Offline';
      netEl.querySelector('i').className = state.online ? 'fa-solid fa-wifi' : 'fa-solid fa-wifi-slash';
    }
  }
  function signal(type, meta) {
    if (B.logSignals.indexOf(type) === -1) return;
    state.signalQueue.push({ type: type, at: new Date().toISOString(), meta: meta || {} });
  }
  function flushSignals() {
    if (!state.online || !state.signalQueue.length) return;
    var batch = state.signalQueue.splice(0, state.signalQueue.length);
    post('proctor', { signals: JSON.stringify(batch) }).then(function (resp) {
      syncRemaining(resp);
      if (resp && B.autoSubmitAfter > 0 && resp.violation_delta) {
        // server tracks the running count via bumpProctoring; we mirror a soft warning
      }
    }).catch(function () { state.signalQueue = batch.concat(state.signalQueue); });
  }

  var violationCount = 0;
  function proctorViolation(type, msg) {
    signal(type);
    violationCount++;
    showToast('⚠ ' + msg + (B.autoSubmitAfter > 0 ? ' (' + violationCount + '/' + B.autoSubmitAfter + ')' : ''), true);
    if (B.autoSubmitAfter > 0 && violationCount >= B.autoSubmitAfter) setTimeout(function () { submit(true); }, 800);
  }

  // ── Toast ─────────────────────────────────────────────────────────────────
  var toastT;
  function showToast(msg, danger) {
    if (!toast) return;
    toast.textContent = msg;
    toast.hidden = false;
    toast.classList.toggle('is-danger', !!danger);
    clearTimeout(toastT);
    toastT = setTimeout(function () { toast.hidden = true; }, 4500);
  }

  // ── Submit ────────────────────────────────────────────────────────────────
  function openConfirm() {
    var modal = $('ap-confirm');
    $('ap-confirm-ans').textContent = state.answered.size;
    var un = TQ - state.answered.size;
    $('ap-confirm-unans').textContent = un > 0 ? un + ' unanswered will score zero.' : '';
    modal.hidden = false;
    $('ap-confirm-yes').focus();
  }
  function closeConfirm() { $('ap-confirm').hidden = true; }
  function submit(auto) {
    if (state.submitting) return; state.submitting = true;
    window.onbeforeunload = null;
    leaveQuestion(state.cur);
    if (auto) $('ap-auto').value = '1';
    // best-effort final flush, then submit the form (which re-scores server-side)
    Promise.resolve().then(flushSaves).then(function () {
      setTimeout(function () { $('ap-form').submit(); }, state.saveQueue.length ? 400 : 60);
    });
  }

  // ── Fullscreen ────────────────────────────────────────────────────────────
  function requestFS() {
    var el = document.documentElement;
    (el.requestFullscreen || el.webkitRequestFullscreen || function () {}).call(el);
  }
  function onFSChange() {
    var fs = !!(document.fullscreenElement || document.webkitFullscreenElement);
    var btn = $('ap-fsbtn');
    if (btn) btn.style.display = fs ? 'none' : '';
    if (!fs && B.fsRequired) proctorViolation('fullscreen_exit', 'Fullscreen exited — please return to fullscreen.');
  }

  // ── Wiring ────────────────────────────────────────────────────────────────
  function wire() {
    document.querySelectorAll('[data-goto]').forEach(function (b) {
      b.addEventListener('click', function () { goto(parseInt(b.getAttribute('data-goto'), 10)); });
    });
    document.querySelectorAll('.ap-qdot').forEach(function (d) {
      d.addEventListener('click', function () { goto(parseInt(d.getAttribute('data-idx'), 10)); });
    });
    document.querySelectorAll('.ap-flag').forEach(function (f) {
      f.addEventListener('click', function () { toggleFlag(parseInt(f.getAttribute('data-idx'), 10)); });
    });
    // answer inputs → track + debounced autosave
    var saveTimers = {};
    document.querySelectorAll('.ap-question').forEach(function (q) {
      var i = parseInt(q.getAttribute('data-idx'), 10);
      q.addEventListener('input', function () { onAnswerChange(i); });
      q.addEventListener('change', function () { onAnswerChange(i); });
      // multi-select mirrors into is-sel + resp field
      q.querySelectorAll('[data-multi]').forEach(function (cb) {
        cb.addEventListener('change', function () { cb.closest('.ap-choice').classList.toggle('is-sel', cb.checked); });
      });
      q.querySelectorAll('input[type=radio]').forEach(function (r) {
        r.addEventListener('change', function () {
          q.querySelectorAll('.ap-choice').forEach(function (c) { c.classList.remove('is-sel'); });
          if (r.closest('.ap-choice')) r.closest('.ap-choice').classList.add('is-sel');
        });
      });
      // word count for textareas
      var ta = q.querySelector('.ap-textarea');
      if (ta) { var wc = q.querySelector('.ap-wc'); ta.addEventListener('input', function () {
        var w = ta.value.trim() ? ta.value.trim().split(/\s+/).length : 0; if (wc) wc.textContent = w + ' word' + (w === 1 ? '' : 's'); }); }
    });
    function onAnswerChange(i) {
      markAnswered(i, isAnswered(i));
      clearTimeout(saveTimers[i]);
      saveTimers[i] = setTimeout(function () { enqueueSave(i); }, 900);
    }

    var sb = $('ap-submit-btn'); if (sb) sb.addEventListener('click', openConfirm);
    var si = $('ap-submit-inline'); if (si) si.addEventListener('click', openConfirm);
    $('ap-confirm-cancel').addEventListener('click', closeConfirm);
    $('ap-confirm-yes').addEventListener('click', function () { closeConfirm(); submit(false); });
    var fsb = $('ap-fsbtn'); if (fsb) fsb.addEventListener('click', requestFS);

    // keyboard: arrows navigate, f flags
    document.addEventListener('keydown', function (e) {
      if (e.target.matches('textarea,input[type=text],.ap-code')) return;
      if (e.key === 'ArrowRight') goto(state.cur + 1);
      else if (e.key === 'ArrowLeft') goto(state.cur - 1);
      else if (e.key.toLowerCase() === 'f') toggleFlag(state.cur);
    });

    // anti-cheat listeners (log only)
    document.addEventListener('visibilitychange', function () { if (document.hidden) proctorViolation('tab_switch', 'Tab switch detected'); });
    window.addEventListener('blur', function () { signal('window_blur'); });
    document.addEventListener('copy', function () { signal('copy_attempt'); });
    document.addEventListener('paste', function () { signal('paste_attempt'); });
    document.addEventListener('fullscreenchange', onFSChange);
    document.addEventListener('webkitfullscreenchange', onFSChange);

    // network
    window.addEventListener('online', function () { state.online = true; updateNet(); showToast('✓ Back online — syncing…'); signal('reconnect'); flushSaves(); flushSignals(); });
    window.addEventListener('offline', function () { state.online = false; updateNet(); showToast('⚠ You are offline — answers are queued.', true); });

    window.onbeforeunload = function () { return 'Your assessment is in progress.'; };
  }

  // ── Boot ──────────────────────────────────────────────────────────────────
  function boot() {
    wire();
    updateNet(); updateProgress();
    if (B.resumed) showToast('↩ Resumed where you left off.');
    // main timer
    var m = Math.floor(state.remaining / 60), s = state.remaining % 60;
    timerVal.textContent = pad(m) + ':' + pad(s);
    setInterval(tickMain, 1000);
    // per-question timer for the starting question
    enterQuestion(state.cur);
    document.getElementById('ap-dot-' + state.cur).classList.add('is-cur');
    // periodic server reconciliation (timer truth + signal flush) every 25s
    setInterval(function () {
      flushSignals();
      post('ping', {}).then(syncRemaining).catch(function () { state.online = false; updateNet(); });
    }, 25000);
    if (B.fsRequired) requestFS();
  }

  boot();
})();
