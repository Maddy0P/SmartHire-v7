<?php /* Assessment Center — global search modal (Ctrl+K) + center behaviors.
   Server-rendered results when ?gs= is present; the modal is a GET form. */ ?>
<div class="modal-overlay <?= $D['global'] !== null ? 'show' : '' ?>" id="gsModal" role="dialog" aria-modal="true" aria-labelledby="gsTitle">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title" id="gsTitle">Search assessments</h2>
      <button class="modal-close" onclick="closeModal('gsModal')" aria-label="Close search">×</button>
    </div>
    <div class="modal-body">
      <form method="GET" action="assessment_center.php" role="search" aria-label="Global assessment search">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <div class="sh-topbar-search">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
          <label class="sh-sr-only" for="gsInput">Search questions, pools, templates, assessments, candidates, results</label>
          <input type="search" id="gsInput" name="gs" value="<?= e($searchQ) ?>" placeholder="Search everything… (Enter to search, Esc to close)" autocomplete="off">
        </div>
      </form>
      <?php if ($D['global'] !== null):
        $groups = ['questions' => ['fa-circle-question', 'Questions', 'view=bank&edit=%d'],
                   'pools' => ['fa-layer-group', 'Pools', 'view=pools&pool_id=%d'],
                   'templates' => ['fa-file-invoice', 'Templates', 'view=templates&edit=%d'],
                   'assessments' => ['fa-laptop-code', 'Assessments', 'view=results'],
                   'candidates' => ['fa-user', 'Candidates', 'candidate_detail.php?candidate_id=%d'],
                   'results' => ['fa-chart-column', 'Results', 'view=results&sid=%d']];
        $any = array_sum(array_map('count', $D['global'])); ?>
      <div class="sh-mt-3" role="region" aria-label="Search results" aria-live="polite">
        <?php if (!$any): ?><p class="sh-cell-sub">Nothing found for “<?= e($searchQ) ?>”.</p><?php endif; ?>
        <?php foreach ($groups as $g => [$icon, $label, $urlTpl]): $hits = $D['global'][$g] ?? []; if (!$hits) continue; ?>
        <h3 class="sh-card-title sh-panel-title sh-mt-3"><i class="fa-solid <?= $icon ?>" aria-hidden="true"></i> <?= $label ?></h3>
        <ul class="sh-gs-list">
          <?php foreach ($hits as $h):
            $url = str_contains($urlTpl, '.php') ? sprintf($urlTpl, (int)$h['id']) : 'assessment_center.php?' . sprintf($urlTpl, (int)$h['id']); ?>
          <li><a href="<?= e($url) ?>"><?= e(mb_strimwidth((string)($h['label'] ?? ('#' . (int)($h['id'] ?? 0))), 0, 70, '…')) ?> <span class="sh-cell-sub"><?= e((string)($h['meta'] ?? '')) ?></span></a></li>
          <?php endforeach; ?>
        </ul>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
// Assessment Center behaviors — single source (no duplicated JS across views).
function shOpenSearch() { openModal('gsModal'); var i = document.getElementById('gsInput'); if (i) { i.focus(); i.select(); } }
document.addEventListener('keydown', function (e) {
  if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); shOpenSearch(); }
  if (e.key === 'Escape') { var m = document.getElementById('gsModal'); if (m && m.classList.contains('show')) closeModal('gsModal'); }
});
<?php if ($D['global'] !== null): ?>shOpenSearch();<?php endif; ?>

// Debounced search auto-submit (450ms) on inputs marked data-debounce-submit
document.querySelectorAll('[data-debounce-submit]').forEach(function (inp) {
  var t = null;
  inp.addEventListener('input', function () {
    clearTimeout(t);
    t = setTimeout(function () { inp.form && inp.form.requestSubmit ? inp.form.requestSubmit() : inp.form.submit(); }, 450);
  });
});

// Bulk selection bar (question bank)
(function () {
  var bar = document.getElementById('bulkBar'), count = document.getElementById('bulkCount');
  if (!bar) return;
  function sync() {
    var n = document.querySelectorAll('.bulk-check:checked').length;
    bar.hidden = n === 0; if (count) count.textContent = n;
  }
  document.querySelectorAll('.bulk-check').forEach(function (cb) { cb.addEventListener('change', sync); });
  sync();
})();

// Question editor: show only the answer-key fields for the chosen type
(function () {
  var sel = document.querySelector('[data-qtype-switch]');
  if (!sel) return;
  function sync() {
    var type = sel.value;
    document.querySelectorAll('.sh-qtype-block').forEach(function (b) {
      b.hidden = (b.getAttribute('data-for') || '').split(' ').indexOf(type) === -1;
    });
  }
  sel.addEventListener('change', sync); sync();
})();

// Template builder: add another section row (server renders the first)
(function () {
  var btn = document.getElementById('tbAddSection'), wrap = document.getElementById('tbSections');
  if (!btn || !wrap) return;
  btn.addEventListener('click', function () {
    var i = wrap.querySelectorAll('.sh-tbsection').length;
    var first = wrap.querySelector('.sh-tbsection');
    var clone = first.cloneNode(true);
    clone.querySelector('legend').textContent = 'Section ' + (i + 1);
    clone.querySelectorAll('input, select').forEach(function (el) {
      el.name = el.name.replace(/sections\[\d+\]/, 'sections[' + i + ']');
      if (el.id) el.id = el.id.replace(/^sec\d+_/, 'sec' + i + '_');
      if (el.tagName === 'INPUT' && el.type === 'number') el.value = el.name.indexOf('question_count') > -1 ? 5 : (el.name.indexOf('mix_') > -1 ? 0 : '');
      if (el.tagName === 'INPUT' && el.type === 'text') el.value = '';
      if (el.tagName === 'SELECT') el.selectedIndex = 0;
    });
    clone.querySelectorAll('label').forEach(function (l) {
      var f = l.getAttribute('for'); if (f) l.setAttribute('for', f.replace(/^sec\d+_/, 'sec' + i + '_'));
    });
    wrap.appendChild(clone);
  });
})();
</script>
