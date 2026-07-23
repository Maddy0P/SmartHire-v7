<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Offer Management Hub (Module 10 Phase 2). Recruiter-side listing surface:
//  KPI band → search/sort toolbar → status chips → data grid → 600px preview
//  drawer with offer history. Layout follows the SmartHire UI Architecture Deck
//  ("Offer Management Hub"); styling reuses the existing v8 .sh-* design system.
//  All data access + business rules go through OfferService — no SQL or offer
//  logic in this controller (handbook Ch6/Ch12).
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/layout.php';
require_once 'modules/offers/bootstrap.php';   // Module 10 — Offer Management
requireLogin();
requireRole('recruiter');
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

use SmartHire\Offer\OfferService;
use SmartHire\Offer\OfferWorkflow;

$svc   = OfferService::production();
$me    = currentUser();
$scope = OfferService::scopeUserId($me);   // null = full visibility (HR and above)

// ── Write path: delete a draft (create/edit live in offer_form.php) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'delete') {
    $r = $svc->deleteOffer((int)($_POST['offer_id'] ?? 0), $me);
    if ($r['ok'])                                   setFlash('success', 'Draft offer deleted.');
    elseif (($r['error'] ?? '') === 'forbidden')    setFlash('error', 'You do not have access to that offer.');
    elseif (($r['error'] ?? '') === 'not_draft')    setFlash('error', 'Only draft offers can be deleted.');
    elseif (($r['error'] ?? '') === 'not_found')    setFlash('error', 'That offer no longer exists.');
    else                                            setFlash('error', 'Could not delete the offer.');
    header('Location: offers.php'); exit;
}

// ── Read path ────────────────────────────────────────────────────────────────
$filter = (string)($_GET['status'] ?? '');
$q      = trim((string)($_GET['q'] ?? ''));
$sort   = (string)($_GET['sort'] ?? 'newest');

$offers   = $svc->hub($filter !== '' ? $filter : null, $q, $sort, $scope);
$counts   = $svc->counts($scope);
$expiring = $svc->expiring(7, $scope);
$history  = $svc->historyForMany(array_column($offers, 'id'));

$qs = fn(array $over = []) => 'offers.php?' . http_build_query(array_filter(
    array_merge(['status' => $filter, 'q' => $q, 'sort' => $sort], $over),
    fn($v) => $v !== '' && $v !== null));

// Presentation helpers -------------------------------------------------------
$label     = fn(string $s) => ucwords(str_replace('_', ' ', $s));
$money     = function (?float $amt, string $cur) {
    if ($amt === null) return '—';
    return e($cur) . ' ' . number_format($amt, 0);
};
$statusTone = [
    'draft' => 'neutral', 'pending_approval' => 'warning', 'approved' => 'success',
    'rejected' => 'danger', 'changes_requested' => 'warning', 'sent' => 'info', 'accepted' => 'success',
    'declined' => 'danger', 'expired' => 'warning', 'cancelled' => 'neutral',
];
$today   = date('Y-m-d');
$soon    = date('Y-m-d', strtotime('+7 days'));
$accRate = $counts['all'] > 0 ? round($counts['accepted'] / $counts['all'] * 100) : 0;

// Chips: always show the primary lifecycle; show the rest only when populated.
$primary   = ['draft', 'pending_approval', 'changes_requested', 'approved', 'sent', 'accepted'];
$secondary = array_filter(['rejected', 'declined', 'expired', 'cancelled'],
    fn($s) => ($counts[$s] ?? 0) > 0 || $filter === $s);
$chips = array_merge($primary, $secondary);

renderHead('Offers', true);
renderSidebar('offers');
?>

<div class="sh-page-header">
  <div>
    <h1 class="sh-page-title">Offers</h1>
    <p class="sh-page-sub">
      <span class="sh-tnum"><?= (int)$counts['all'] ?></span> total ·
      <span class="sh-tnum"><?= (int)$counts['draft'] ?></span> draft ·
      <span class="sh-tnum"><?= (int)$counts['sent'] ?></span> sent
      <?php if ($scope !== null): ?> · <span class="sh-muted">your offers</span><?php endif; ?>
    </p>
  </div>
  <a class="sh-btn sh-btn-primary" href="offer_form.php">
    <i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i> New offer
  </a>
</div>

<!-- KPI band — deck: SLA & pipeline metrics + expiration warnings -->
<div class="sh-kpi-grid">
  <div class="sh-kpi">
    <div class="sh-kpi-top"><i class="fa-solid fa-file-signature" aria-hidden="true"></i>Total offers</div>
    <div class="sh-kpi-value"><?= (int)$counts['all'] ?></div>
    <div class="sh-kpi-foot"><span>across every status</span><a href="<?= $qs(['status' => '']) ?>">View all →</a></div>
  </div>
  <div class="sh-kpi">
    <div class="sh-kpi-top"><i class="fa-solid fa-pen-ruler" aria-hidden="true"></i>Drafts</div>
    <div class="sh-kpi-value"><?= (int)$counts['draft'] ?></div>
    <div class="sh-kpi-foot"><span>awaiting completion</span><a href="<?= $qs(['status' => 'draft']) ?>">Open →</a></div>
  </div>
  <div class="sh-kpi">
    <div class="sh-kpi-top"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>Expiring soon</div>
    <div class="sh-kpi-value"><?= $expiring ?></div>
    <div class="sh-kpi-foot"><span>within the next 7 days</span><a href="<?= $qs(['sort' => 'expiry']) ?>">Review →</a></div>
  </div>
  <div class="sh-kpi">
    <div class="sh-kpi-top"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Accepted</div>
    <div class="sh-kpi-value"><?= (int)$counts['accepted'] ?></div>
    <div class="sh-kpi-foot"><span><?= $accRate ?>% acceptance rate</span><a href="<?= $qs(['status' => 'accepted']) ?>">View →</a></div>
  </div>
</div>

<form class="sh-flex sh-items-center sh-gap-3 sh-wrap sh-mb-4" method="GET" action="offers.php" role="search" aria-label="Search and sort offers">
  <?php if ($filter !== ''): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?>
  <div class="sh-topbar-search sh-search-inline">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <label class="sh-sr-only" for="offerSearch">Search offers</label>
    <input type="search" id="offerSearch" name="q" value="<?= e($q) ?>" placeholder="Search candidate, designation, department, location…" autocomplete="off">
  </div>
  <label class="sh-sr-only" for="offerSort">Sort offers</label>
  <select id="offerSort" class="sh-input sh-input-auto" name="sort">
    <?php foreach (['newest'=>'Newest first','oldest'=>'Oldest first','candidate'=>'Candidate A–Z','salary_desc'=>'Salary high → low','salary_asc'=>'Salary low → high','expiry'=>'Expiring soonest'] as $k => $v): ?>
    <option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $v ?></option>
    <?php endforeach; ?>
  </select>
  <button class="sh-btn sh-btn-secondary sh-btn-sm">Apply</button>
  <?php if ($q !== '' || $sort !== 'newest'): ?>
  <a class="sh-btn sh-btn-ghost sh-btn-sm" href="<?= $qs(['q' => '', 'sort' => '']) ?>">Clear</a>
  <?php endif; ?>
</form>

<nav class="sh-flex sh-gap-2 sh-mb-4 sh-wrap" aria-label="Filter offers by status">
  <a href="<?= $qs(['status' => '']) ?>" class="sh-chip <?= $filter === '' ? 'active' : '' ?>" <?= $filter === '' ? 'aria-current="page"' : '' ?>>All <span class="sh-count"><?= (int)$counts['all'] ?></span></a>
  <?php foreach ($chips as $st): ?>
  <a href="<?= $qs(['status' => $st]) ?>" class="sh-chip <?= $filter === $st ? 'active' : '' ?>" <?= $filter === $st ? 'aria-current="page"' : '' ?>><?= e($label($st)) ?> <span class="sh-count"><?= (int)($counts[$st] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>

<?php if (!$offers): ?>
<div class="sh-card"><div class="sh-empty">
  <div class="sh-empty-icon"><i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i></div>
  <h2><?= $q !== '' || $filter !== '' ? 'No offers match those filters' : 'No offers yet' ?></h2>
  <p><?= $q !== '' || $filter !== ''
        ? 'Try a different search term or clear the status filter.'
        : 'Create an offer once a candidate has cleared their interviews.' ?></p>
  <?php if ($q !== '' || $filter !== ''): ?>
    <a class="sh-btn sh-btn-secondary sh-mt-2" href="offers.php">Clear filters</a>
  <?php else: ?>
    <a class="sh-btn sh-btn-primary sh-mt-2" href="offer_form.php">Create the first offer</a>
  <?php endif; ?>
</div></div>
<?php else: ?>

<section class="sh-card sh-card-flush" aria-label="Offer list">
  <div class="sh-table-wrap">
    <table class="sh-table sh-rtable">
      <thead><tr>
        <th scope="col">Candidate</th>
        <th scope="col">Designation</th>
        <th scope="col">Compensation</th>
        <th scope="col">Expiry</th>
        <th scope="col">Status</th>
        <th scope="col" class="sh-right">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($offers as $o):
        $id      = (int)$o['id'];
        $status  = (string)$o['status'];
        $isDraft = $status === 'draft';
        $exp     = $o['expiry_date'] ?? null;
        $expSoon = $exp !== null && $exp >= $today && $exp <= $soon;
        $expPast = $exp !== null && $exp < $today;
        $payload = [
            'id'        => $id,
            'candidate' => (string)($o['candidate_name'] ?? '—'),
            'email'     => (string)($o['candidate_email'] ?? ''),
            'title'     => (string)$o['job_title'],
            'job'       => (string)($o['job_name'] ?? ''),
            'dept'      => (string)$o['department'],
            'location'  => (string)$o['location'],
            'type'      => $label((string)$o['employment_type']),
            'pay'       => $money($o['salary'] !== null ? (float)$o['salary'] : null, (string)$o['currency']),
            'joining'   => (string)($o['joining_date'] ?? ''),
            'expiry'    => (string)($exp ?? ''),
            'benefits'  => (string)$o['benefits'],
            'notes'     => (string)$o['notes'],
            'status'    => $label($status),
            'editable'  => OfferWorkflow::isEditable($status),
            'history'   => array_map(fn($h) => [
                'when' => (string)$h['created_at'],
                'what' => ($h['from_status'] ? $label((string)$h['from_status']) . ' → ' : '') . $label((string)$h['to_status']),
                'who'  => (string)($h['actor_name'] ?? ''),
                'note' => (string)($h['notes'] ?? ''),
            ], $history[$id] ?? []),
        ];
      ?>
        <tr>
          <td data-label="Candidate">
            <div class="sh-flex sh-items-center sh-gap-3">
              <span class="sh-avatar sh-avatar-sm" aria-hidden="true"><?= strtoupper(substr((string)($o['candidate_name'] ?? '?'), 0, 1)) ?></span>
              <div class="sh-truncate">
                <strong><?= e((string)($o['candidate_name'] ?? '—')) ?></strong>
                <p class="sh-cell-sub sh-truncate"><?= e((string)($o['candidate_email'] ?? '')) ?></p>
              </div>
            </div>
          </td>
          <td data-label="Designation">
            <?= e((string)$o['job_title']) ?>
            <p class="sh-cell-sub"><?= e(trim(((string)$o['department'] ?: '—') . ' · ' . ((string)$o['location'] ?: '—'))) ?></p>
          </td>
          <td data-label="Compensation" class="sh-tnum"><?= $money($o['salary'] !== null ? (float)$o['salary'] : null, (string)$o['currency']) ?>
            <p class="sh-cell-sub"><?= e($label((string)$o['employment_type'])) ?></p>
          </td>
          <td data-label="Expiry" class="sh-tnum">
            <?= $exp ? date('d M Y', strtotime($exp)) : '—' ?>
            <?php if ($expSoon): ?><p class="sh-cell-sub sh-warning-text">Expiring soon</p>
            <?php elseif ($expPast): ?><p class="sh-cell-sub sh-danger-text">Past expiry</p><?php endif; ?>
          </td>
          <td data-label="Status"><span class="sh-badge sh-badge-<?= $statusTone[$status] ?? 'neutral' ?>"><?= e($label($status)) ?></span></td>
          <td data-label="Actions" class="sh-right">
            <div class="sh-flex sh-gap-2 sh-right sh-wrap">
              <button type="button" class="sh-cellbtn" data-offer='<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE)) ?>' onclick="offerPreview(this)">Preview</button>
              <?php if (OfferWorkflow::isEditable($status)): ?>
              <a class="sh-cellbtn" href="offer_form.php?id=<?= $id ?>">Edit</a>
              <a class="sh-cellbtn" href="offer_approval.php?id=<?= $id ?>">Submit</a>
              <?php elseif (in_array($status, ['pending_approval', 'approved', 'rejected'], true)): ?>
              <a class="sh-cellbtn" href="offer_approval.php?id=<?= $id ?>">
                <?= $status === 'pending_approval' ? 'Review' : 'Approvals' ?>
              </a>
              <?php endif; ?>
              <?php if ($isDraft): ?>
              <form method="POST" action="offers.php" class="sh-inline-form" onsubmit="return confirm('Delete this draft offer? This cannot be undone.');">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="delete">
                <input type="hidden" name="offer_id" value="<?= $id ?>">
                <button type="submit" class="sh-cellbtn sh-danger-text">Delete</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<!-- Preview drawer — deck: 600px right-side slide-over, keeps the list in context -->
<aside class="sh-slideover" id="offerPanel" role="dialog" aria-modal="false" aria-labelledby="opName" aria-hidden="true">
  <div class="sh-slideover-head">
    <div>
      <h2 class="sh-card-title" id="opName">Offer</h2>
      <p class="sh-card-sub" id="opSub"></p>
    </div>
    <button class="sh-iconbtn" onclick="shCloseSlideover('offerPanel')" aria-label="Close panel"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>
  <div class="sh-slideover-body">
    <span class="sh-badge" id="opStatus"></span>
    <dl class="sh-grid sh-grid-2 sh-mt-2" id="opFields"></dl>
    <h3 class="sh-card-title sh-mt-2">Offer history</h3>
    <ul class="sh-timeline" id="opTimeline"></ul>
  </div>
  <div class="sh-slideover-foot">
    <a class="sh-btn sh-btn-primary" id="opEditLink" href="#">Edit offer</a>
    <a class="sh-btn sh-btn-secondary" id="opApprovalLink" href="#">Approval</a>
    <button class="sh-btn sh-btn-secondary" onclick="shCloseSlideover('offerPanel')">Close</button>
  </div>
</aside>

<script>
function offerPreview(trigger) {
  var o = JSON.parse(trigger.getAttribute('data-offer'));
  document.getElementById('opName').textContent = o.candidate;
  document.getElementById('opSub').textContent  = [o.title, o.dept, o.location].filter(Boolean).join(' · ');
  var st = document.getElementById('opStatus');
  st.textContent = o.status;

  var fields = [
    ['Designation', o.title], ['Job', o.job || '—'], ['Department', o.dept || '—'],
    ['Location', o.location || '—'], ['Employment type', o.type || '—'], ['Compensation', o.pay],
    ['Joining date', o.joining || '—'], ['Offer expiry', o.expiry || '—'],
    ['Candidate email', o.email || '—'], ['Benefits', o.benefits || '—'], ['Notes', o.notes || '—']
  ];
  var dl = document.getElementById('opFields');
  dl.textContent = '';
  fields.forEach(function (f) {
    var dt = document.createElement('dt'); dt.className = 'sh-cell-sub'; dt.textContent = f[0];
    var dd = document.createElement('dd'); dd.textContent = f[1];
    dl.appendChild(dt); dl.appendChild(dd);
  });

  var tl = document.getElementById('opTimeline');
  tl.textContent = '';
  if (!o.history.length) {
    var empty = document.createElement('li');
    empty.textContent = 'No history recorded yet.';
    tl.appendChild(empty);
  } else {
    o.history.forEach(function (h) {
      var li = document.createElement('li'), b = document.createElement('strong');
      b.textContent = (h.when ? h.when.substring(0, 16) + ' — ' : '');
      li.appendChild(b);
      li.appendChild(document.createTextNode(h.what + (h.who ? ' by ' + h.who : '') + (h.note ? ' · ' + h.note : '')));
      tl.appendChild(li);
    });
  }

  var edit = document.getElementById('opEditLink');
  edit.href = 'offer_form.php?id=' + o.id;
  edit.hidden = !o.editable;
  document.getElementById('opApprovalLink').href = 'offer_approval.php?id=' + o.id;

  shOpenSlideover('offerPanel', trigger);
}
</script>

<?php renderFooter(); ?>
