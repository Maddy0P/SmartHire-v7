<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Offer Builder (Module 10 Phase 2). Create or edit a draft offer. Layout
//  follows the deck's "Offer Builder" screen — grouped cards for Candidate &
//  Role, Compensation, Dates, and Additional details — rendered with the
//  existing v8 .sh-* components. Validation is server-side only and delegated
//  to OfferValidator via OfferService; this controller holds no offer rules.
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/layout.php';
require_once 'modules/offers/bootstrap.php';   // Module 10 — Offer Management
requireLogin();
requireRole('recruiter');
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

use SmartHire\Offer\OfferService;
use SmartHire\Offer\OfferWorkflow;

$svc = OfferService::production();
$me  = currentUser();

$id      = (int)($_GET['id'] ?? $_POST['offer_id'] ?? 0);
$isEdit  = $id > 0;
$errors  = [];
$offer   = null;

// ── Load + authorize the offer being edited ─────────────────────────────────
if ($isEdit) {
    $offer = $svc->find($id);
    if (!$offer) { setFlash('error', 'That offer no longer exists.'); header('Location: offers.php'); exit; }
    if (!$svc->canAccess($offer, $me)) {
        audit_log('rbac_block', 'offer', $id, 'offer access denied');
        setFlash('error', 'You do not have access to that offer.');
        header('Location: offers.php'); exit;
    }
    if (!OfferWorkflow::isEditable((string)$offer['status'])) {
        setFlash('error', 'This offer is no longer editable.');
        header('Location: offers.php'); exit;
    }
}

// Form state: POST input wins (so a failed submit keeps what was typed),
// otherwise the stored offer, otherwise blank defaults.
$val = fn(string $k, $default = '') => $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string)($_POST[$k] ?? '')
    : (string)($offer[$k] ?? $default);

// ── Write path ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST;
    if (!$isEdit && ($input['recruiter_id'] ?? '') === '') $input['recruiter_id'] = (int)$me['id'];

    $r = $isEdit
        ? $svc->updateOffer($id, $input, (int)$me['id'], (string)$me['name'], null, $me)
        : $svc->createOffer($input, (int)$me['id'], (string)$me['name']);

    if (!empty($r['ok'])) {
        setFlash('success', $isEdit ? 'Offer updated.' : 'Draft offer created.');
        header('Location: offers.php'); exit;
    }
    $errors = $r['errors'] ?? [];
    if (!$errors) {
        $msg = match ($r['error'] ?? '') {
            'forbidden' => 'You do not have access to that offer.',
            'locked'    => 'This offer is no longer editable.',
            'not_found' => 'That offer no longer exists.',
            default     => 'Could not save the offer. Please try again.',
        };
        setFlash('error', $msg);
        if (($r['error'] ?? '') !== 'db') { header('Location: offers.php'); exit; }
    }
}

// Reference data for the selects (candidate / job domain reads, explicit columns).
$candidates = dbFetchAll("SELECT id, name, position FROM candidates ORDER BY name");
$jobs       = dbFetchAll("SELECT id, title FROM jobs ORDER BY title");

$err   = fn(string $f) => $errors[$f] ?? null;
$label = fn(string $s) => ucwords(str_replace('_', ' ', $s));

renderHead($isEdit ? 'Edit offer' : 'New offer', true);
renderSidebar('offers');
?>

<div class="sh-page-header">
  <div>
    <h1 class="sh-page-title"><?= $isEdit ? 'Edit offer' : 'New offer' ?></h1>
    <p class="sh-page-sub">
      <?= $isEdit
          ? 'Update this draft before submitting it for approval.'
          : 'Build a draft offer. It stays editable until it is submitted for approval.' ?>
    </p>
  </div>
  <a href="offers.php" class="sh-btn sh-btn-secondary"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to offers</a>
</div>

<?php if ($errors): ?>
<div class="sh-card sh-mb-4" role="alert">
  <div class="sh-flex sh-items-center sh-gap-3">
    <i class="fa-solid fa-triangle-exclamation sh-danger-text" aria-hidden="true"></i>
    <div>
      <strong>Please correct <?= count($errors) ?> field<?= count($errors) > 1 ? 's' : '' ?>.</strong>
      <p class="sh-cell-sub">Nothing has been saved yet — your entries are preserved below.</p>
    </div>
  </div>
</div>
<?php endif; ?>

<form method="POST" action="offer_form.php<?= $isEdit ? '?id=' . $id : '' ?>" novalidate>
  <?= csrf_field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="offer_id" value="<?= $id ?>"><?php endif; ?>
  <?php if (!$isEdit): ?><input type="hidden" name="recruiter_id" value="<?= (int)$me['id'] ?>"><?php endif; ?>

  <!-- Candidate & role -->
  <section class="sh-card sh-mb-4" aria-labelledby="secRole">
    <div class="sh-card-header"><h2 class="sh-card-title" id="secRole">Candidate &amp; role</h2></div>

    <div class="sh-grid sh-grid-2">
      <div class="sh-field">
        <label class="sh-label" for="candidate_id">Candidate <span class="sh-danger-text" aria-hidden="true">*</span></label>
        <select class="sh-input" id="candidate_id" name="candidate_id" <?= $isEdit ? 'disabled' : '' ?>
                <?= $err('candidate_id') ? 'aria-invalid="true" aria-describedby="e_candidate"' : '' ?>>
          <option value="">Select a candidate…</option>
          <?php foreach ($candidates as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (string)$val('candidate_id') === (string)$c['id'] ? 'selected' : '' ?>>
            <?= e($c['name']) ?><?= $c['position'] ? ' — ' . e($c['position']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php if ($isEdit): ?>
          <input type="hidden" name="candidate_id" value="<?= (int)$offer['candidate_id'] ?>">
          <p class="sh-cell-sub">The candidate cannot be changed after the offer is created.</p>
        <?php endif; ?>
        <?php if ($err('candidate_id')): ?><p class="sh-cell-sub sh-danger-text" id="e_candidate"><?= e($err('candidate_id')) ?></p><?php endif; ?>
      </div>

      <div class="sh-field">
        <label class="sh-label" for="job_id">Job</label>
        <select class="sh-input" id="job_id" name="job_id">
          <option value="">Not linked to a requisition</option>
          <?php foreach ($jobs as $j): ?>
          <option value="<?= (int)$j['id'] ?>" <?= (string)$val('job_id') === (string)$j['id'] ? 'selected' : '' ?>><?= e($j['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="sh-field">
        <label class="sh-label" for="job_title">Designation <span class="sh-danger-text" aria-hidden="true">*</span></label>
        <input class="sh-input" type="text" id="job_title" name="job_title" maxlength="150"
               value="<?= e($val('job_title')) ?>" placeholder="e.g. Senior Backend Engineer"
               <?= $err('job_title') ? 'aria-invalid="true" aria-describedby="e_title"' : '' ?>>
        <?php if ($err('job_title')): ?><p class="sh-cell-sub sh-danger-text" id="e_title"><?= e($err('job_title')) ?></p><?php endif; ?>
      </div>

      <div class="sh-field">
        <label class="sh-label" for="department">Department</label>
        <input class="sh-input" type="text" id="department" name="department" maxlength="100"
               value="<?= e($val('department')) ?>" placeholder="e.g. Engineering">
      </div>

      <div class="sh-field">
        <label class="sh-label" for="location">Location</label>
        <input class="sh-input" type="text" id="location" name="location" maxlength="120"
               value="<?= e($val('location')) ?>" placeholder="e.g. Pune, India">
      </div>

      <div class="sh-field">
        <label class="sh-label" for="employment_type">Employment type</label>
        <select class="sh-input" id="employment_type" name="employment_type"
                <?= $err('employment_type') ? 'aria-invalid="true" aria-describedby="e_type"' : '' ?>>
          <?php foreach (OfferWorkflow::EMPLOYMENT_TYPES as $t): ?>
          <option value="<?= $t ?>" <?= $val('employment_type', 'full_time') === $t ? 'selected' : '' ?>><?= e($label($t)) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($err('employment_type')): ?><p class="sh-cell-sub sh-danger-text" id="e_type"><?= e($err('employment_type')) ?></p><?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Compensation -->
  <section class="sh-card sh-mb-4" aria-labelledby="secComp">
    <div class="sh-card-header">
      <div>
        <h2 class="sh-card-title" id="secComp">Compensation</h2>
        <p class="sh-card-sub">Annual figures, entered in the offer currency.</p>
      </div>
    </div>

    <div class="sh-grid sh-grid-2">
      <div class="sh-field">
        <label class="sh-label" for="salary">Annual salary <span class="sh-danger-text" aria-hidden="true">*</span></label>
        <input class="sh-input sh-tnum" type="number" id="salary" name="salary" min="0" step="1" inputmode="numeric"
               value="<?= e($val('salary')) ?>" placeholder="e.g. 1800000"
               <?= $err('salary') ? 'aria-invalid="true" aria-describedby="e_salary"' : '' ?>>
        <?php if ($err('salary')): ?><p class="sh-cell-sub sh-danger-text" id="e_salary"><?= e($err('salary')) ?></p><?php endif; ?>
      </div>

      <div class="sh-field">
        <label class="sh-label" for="currency">Currency</label>
        <input class="sh-input" type="text" id="currency" name="currency" maxlength="3" size="3"
               value="<?= e($val('currency', 'INR')) ?>" placeholder="INR" style="text-transform:uppercase"
               <?= $err('currency') ? 'aria-invalid="true" aria-describedby="e_currency"' : '' ?>>
        <p class="sh-cell-sub">Three-letter code, e.g. INR or USD.</p>
        <?php if ($err('currency')): ?><p class="sh-cell-sub sh-danger-text" id="e_currency"><?= e($err('currency')) ?></p><?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Dates -->
  <section class="sh-card sh-mb-4" aria-labelledby="secDates">
    <div class="sh-card-header"><h2 class="sh-card-title" id="secDates">Dates</h2></div>
    <div class="sh-grid sh-grid-2">
      <div class="sh-field">
        <label class="sh-label" for="joining_date">Joining date</label>
        <input class="sh-input" type="date" id="joining_date" name="joining_date"
               value="<?= e(substr($val('joining_date'), 0, 10)) ?>"
               <?= $err('joining_date') ? 'aria-invalid="true" aria-describedby="e_joining"' : '' ?>>
        <?php if ($err('joining_date')): ?><p class="sh-cell-sub sh-danger-text" id="e_joining"><?= e($err('joining_date')) ?></p><?php endif; ?>
      </div>
      <div class="sh-field">
        <label class="sh-label" for="expiry_date">Offer expiry date</label>
        <input class="sh-input" type="date" id="expiry_date" name="expiry_date"
               value="<?= e(substr($val('expiry_date'), 0, 10)) ?>"
               <?= $err('expiry_date') ? 'aria-invalid="true" aria-describedby="e_expiry"' : '' ?>>
        <p class="sh-cell-sub">The candidate must respond on or before this date.</p>
        <?php if ($err('expiry_date')): ?><p class="sh-cell-sub sh-danger-text" id="e_expiry"><?= e($err('expiry_date')) ?></p><?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Additional details -->
  <section class="sh-card sh-mb-4" aria-labelledby="secMore">
    <div class="sh-card-header"><h2 class="sh-card-title" id="secMore">Benefits &amp; notes</h2></div>
    <div class="sh-field">
      <label class="sh-label" for="benefits">Benefits</label>
      <textarea class="sh-input" id="benefits" name="benefits" rows="3"
                placeholder="Health cover, leave policy, allowances…"><?= e($val('benefits')) ?></textarea>
    </div>
    <div class="sh-field">
      <label class="sh-label" for="notes">Internal notes</label>
      <textarea class="sh-input" id="notes" name="notes" rows="3"
                placeholder="Context for approvers — not shown to the candidate."><?= e($val('notes')) ?></textarea>
    </div>
  </section>

  <div class="sh-card sh-scorefoot" role="group" aria-label="Save offer">
    <p class="sh-text-2">Saved as a <strong>draft</strong> — approval and sending come later in the offer workflow.</p>
    <div class="sh-flex sh-gap-3">
      <a href="offers.php" class="sh-btn sh-btn-secondary">Cancel</a>
      <button type="submit" class="sh-btn sh-btn-primary">
        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> <?= $isEdit ? 'Save changes' : 'Create draft offer' ?>
      </button>
    </div>
  </div>
</form>

<?php renderFooter(); ?>
