<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Offer Approval workspace (Module 10 Phase 3). Renders the multi-tier approval
//  stepper, the offer summary/compensation, the immutable approval timeline, and
//  the action panel for whichever party may act (recruiter or approver).
//  Layout follows the deck's "Multi-Tier Approval Stepper" screen; the stepper is
//  composed from existing .sh-card / .sh-badge / .sh-timeline components — no new
//  CSS is introduced. Every rule (permissions, state, chain position) is decided
//  by OfferService; this controller only maps results to flash + redirect.
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
$actor = $me + ['ip' => $_SERVER['REMOTE_ADDR'] ?? null];

$id = (int)($_GET['id'] ?? $_POST['offer_id'] ?? 0);
if ($id <= 0) { header('Location: offers.php'); exit; }

// ── Write path ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = (string)($_POST['form_action'] ?? '');
    $comment = trim((string)($_POST['comment'] ?? '')) ?: null;

    $r = match ($action) {
        'submit'          => $svc->submitForApproval($id, $actor, $comment),
        'withdraw'        => $svc->withdraw($id, $actor, $comment),
        'approve'         => $svc->approve($id, $actor, $comment),
        'reject'          => $svc->reject($id, $actor, $comment),
        'request_changes' => $svc->requestChanges($id, $actor, $comment),
        default           => ['ok' => false, 'error' => 'bad_action'],
    };

    if (!empty($r['ok'])) {
        setFlash('success', match ($action) {
            'submit'          => 'Offer submitted for approval.',
            'withdraw'        => 'Offer withdrawn — it is editable again.',
            'approve'         => !empty($r['complete']) ? 'Final approval recorded — the offer is approved.' : 'Approval recorded. The offer moves to the next stage.',
            'reject'          => 'Offer rejected.',
            'request_changes' => 'Changes requested — returned to the recruiter.',
            default           => 'Done.',
        });
    } else {
        setFlash('error', match ($r['error'] ?? '') {
            'forbidden'     => 'You are not permitted to act on this approval stage.',
            'self_approval' => 'You cannot approve your own offer.',
            'bad_state'     => 'That action is not available for the offer\'s current status.',
            'expired'       => 'This offer has passed its expiry date.',
            'not_found'     => 'That offer no longer exists.',
            default         => 'Could not complete that action.',
        });
    }
    header('Location: offer_approval.php?id=' . $id); exit;
}

// ── Read path ───────────────────────────────────────────────────────────────
$offer = $svc->find($id);
if (!$offer) { setFlash('error', 'That offer no longer exists.'); header('Location: offers.php'); exit; }
if (!$svc->canAccess($offer, $me)) {
    audit_log('rbac_block', 'offer', $id, 'approval workspace denied');
    setFlash('error', 'You do not have access to that offer.');
    header('Location: offers.php'); exit;
}

$approvals = $svc->approvalsFor($id);
$state     = $svc->approvalState($offer, $approvals);
$timeline  = $svc->historyFor($id);

$status    = (string)$offer['status'];
$isOwner   = (int)($offer['created_by'] ?? 0) === (int)$me['id'] || (int)($offer['recruiter_id'] ?? 0) === (int)$me['id'];
$canDecide = $svc->canDecide($offer, $approvals, $me);
$canSubmit = $isOwner && in_array($status, ['draft', 'changes_requested'], true);
$canWithdraw = $isOwner && $status === 'pending_approval';

$label = fn(string $s) => ucwords(str_replace('_', ' ', $s));
$tone  = [
    'draft' => 'neutral', 'pending_approval' => 'warning', 'approved' => 'success',
    'rejected' => 'danger', 'changes_requested' => 'warning', 'sent' => 'info',
    'accepted' => 'success', 'declined' => 'danger', 'expired' => 'warning', 'cancelled' => 'neutral',
];
$nodeTone = ['complete' => 'success', 'current' => 'info', 'pending' => 'neutral', 'rejected' => 'danger', 'returned' => 'warning'];
$nodeIcon = ['complete' => 'fa-circle-check', 'current' => 'fa-circle-dot', 'pending' => 'fa-circle', 'rejected' => 'fa-circle-xmark', 'returned' => 'fa-rotate-left'];

renderHead('Offer approval', true);
renderSidebar('offers');
?>

<div class="sh-page-header">
  <div>
    <h1 class="sh-page-title">Offer approval</h1>
    <p class="sh-page-sub">
      <?= e((string)$offer['job_title']) ?> ·
      <span class="sh-badge sh-badge-<?= $tone[$status] ?? 'neutral' ?>"><?= e($label($status)) ?></span>
      <?php if ($state['cycle'] > 1): ?> · <span class="sh-muted">review round <?= (int)$state['cycle'] ?></span><?php endif; ?>
    </p>
  </div>
  <a href="offers.php" class="sh-btn sh-btn-secondary"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to offers</a>
</div>

<!-- Approval stepper — deck: Multi-Tier Approval Stepper -->
<section class="sh-card sh-mb-4" aria-labelledby="secStepper">
  <div class="sh-card-header">
    <div>
      <h2 class="sh-card-title" id="secStepper">Approval chain</h2>
      <p class="sh-card-sub">Sequential sign-off — the offer is approved once every stage has approved.</p>
    </div>
  </div>
  <ol class="sh-grid sh-grid-4" role="list">
    <?php foreach ($state['nodes'] as $i => $n): ?>
    <li class="sh-card" aria-label="Step <?= $i + 1 ?>: <?= e($n['label']) ?>">
      <div class="sh-flex sh-items-center sh-gap-2 sh-mb">
        <i class="fa-solid <?= $nodeIcon[$n['state']] ?? 'fa-circle' ?>" aria-hidden="true"></i>
        <strong><?= $i + 1 ?>. <?= e($n['label']) ?></strong>
      </div>
      <span class="sh-badge sh-badge-<?= $nodeTone[$n['state']] ?? 'neutral' ?>"><?= e($n['caption']) ?></span>
      <p class="sh-cell-sub sh-mt-2">
        <?= $n['approver'] !== '' ? e($n['approver']) : '<span class="sh-muted">Unassigned</span>' ?>
      </p>
      <?php if ($n['when'] !== ''): ?>
      <p class="sh-cell-sub sh-tnum"><?= e(substr($n['when'], 0, 16)) ?></p>
      <?php endif; ?>
      <?php if ($n['comment'] !== ''): ?>
      <p class="sh-cell-sub" title="<?= e($n['comment']) ?>">
        <i class="fa-solid fa-comment-dots" aria-hidden="true"></i> Comment
      </p>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
</section>

<div class="sh-grid sh-grid-2">
  <!-- Offer summary + compensation -->
  <section class="sh-card" aria-labelledby="secOffer">
    <div class="sh-card-header"><h2 class="sh-card-title" id="secOffer">Offer details</h2></div>
    <dl class="sh-grid sh-grid-2">
      <dt class="sh-cell-sub">Designation</dt><dd><?= e((string)$offer['job_title']) ?></dd>
      <dt class="sh-cell-sub">Department</dt><dd><?= e((string)$offer['department'] ?: '—') ?></dd>
      <dt class="sh-cell-sub">Location</dt><dd><?= e((string)$offer['location'] ?: '—') ?></dd>
      <dt class="sh-cell-sub">Employment type</dt><dd><?= e($label((string)$offer['employment_type'])) ?></dd>
      <dt class="sh-cell-sub">Compensation</dt>
      <dd class="sh-tnum"><strong><?= e((string)$offer['currency']) ?> <?= $offer['salary'] !== null ? number_format((float)$offer['salary'], 0) : '—' ?></strong></dd>
      <dt class="sh-cell-sub">Joining date</dt><dd class="sh-tnum"><?= $offer['joining_date'] ? date('d M Y', strtotime((string)$offer['joining_date'])) : '—' ?></dd>
      <dt class="sh-cell-sub">Offer expiry</dt><dd class="sh-tnum"><?= $offer['expiry_date'] ? date('d M Y', strtotime((string)$offer['expiry_date'])) : '—' ?></dd>
    </dl>
    <?php if ((string)$offer['benefits'] !== ''): ?>
    <p class="sh-cell-sub sh-mt-2">Benefits</p><p><?= e((string)$offer['benefits']) ?></p>
    <?php endif; ?>
    <?php if ((string)$offer['notes'] !== ''): ?>
    <p class="sh-cell-sub sh-mt-2">Internal notes</p><p><?= e((string)$offer['notes']) ?></p>
    <?php endif; ?>
  </section>

  <!-- Action panel -->
  <section class="sh-card" aria-labelledby="secAction">
    <div class="sh-card-header"><h2 class="sh-card-title" id="secAction">Actions</h2></div>

    <?php if ($canDecide): ?>
      <p class="sh-text-2">You are the approver for
        <strong><?= e(OfferWorkflow::stage((string)$state['next_stage'])['label'] ?? '') ?></strong>.</p>
      <form method="POST" action="offer_approval.php?id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="offer_id" value="<?= $id ?>">
        <div class="sh-field">
          <label class="sh-label" for="comment">Comment <span class="sh-muted">(optional)</span></label>
          <textarea class="sh-input" id="comment" name="comment" rows="3" placeholder="Context for your decision…"></textarea>
        </div>
        <div class="sh-flex sh-gap-2 sh-wrap">
          <button class="sh-btn sh-btn-primary" name="form_action" value="approve">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i> Approve
          </button>
          <button class="sh-btn sh-btn-secondary" name="form_action" value="request_changes">
            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Request changes
          </button>
          <button class="sh-btn sh-btn-secondary sh-danger-text" name="form_action" value="reject"
                  onclick="return confirm('Reject this offer? It becomes read-only.');">
            <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> Reject
          </button>
        </div>
      </form>

    <?php elseif ($canSubmit || $canWithdraw): ?>
      <p class="sh-text-2">
        <?= $canSubmit
            ? ($status === 'changes_requested'
                ? 'Changes were requested. Revise the offer, then resubmit it for approval.'
                : 'This offer is a draft. Submit it to start the approval chain.')
            : 'This offer is awaiting approval. You can withdraw it to make further edits.' ?>
      </p>
      <form method="POST" action="offer_approval.php?id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="offer_id" value="<?= $id ?>">
        <div class="sh-field">
          <label class="sh-label" for="comment">Comment <span class="sh-muted">(optional)</span></label>
          <textarea class="sh-input" id="comment" name="comment" rows="3" placeholder="Note for the approvers…"></textarea>
        </div>
        <div class="sh-flex sh-gap-2 sh-wrap">
          <?php if ($canSubmit): ?>
          <button class="sh-btn sh-btn-primary" name="form_action" value="submit">
            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> <?= $status === 'changes_requested' ? 'Resubmit for approval' : 'Submit for approval' ?>
          </button>
          <a class="sh-btn sh-btn-secondary" href="offer_form.php?id=<?= $id ?>">Edit offer</a>
          <?php else: ?>
          <button class="sh-btn sh-btn-secondary" name="form_action" value="withdraw">
            <i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i> Withdraw
          </button>
          <?php endif; ?>
        </div>
      </form>

    <?php else: ?>
      <div class="sh-empty sh-empty-pad">
        <div class="sh-empty-icon"><i class="fa-solid fa-lock" aria-hidden="true"></i></div>
        <p>
          <?php if (in_array($status, ['approved', 'rejected'], true)): ?>
            This offer is <?= e($label($status)) ?> and is now read-only.
          <?php elseif ($status === 'pending_approval'): ?>
            This offer is awaiting a decision from another approver.
          <?php else: ?>
            No approval actions are available to you for this offer.
          <?php endif; ?>
        </p>
      </div>
    <?php endif; ?>
  </section>
</div>

<!-- Immutable approval timeline -->
<section class="sh-card sh-mt-2" aria-labelledby="secTimeline">
  <div class="sh-card-header">
    <div>
      <h2 class="sh-card-title" id="secTimeline">Approval timeline</h2>
      <p class="sh-card-sub">Append-only history — entries are never edited or removed.</p>
    </div>
  </div>
  <?php if (!$timeline): ?>
    <div class="sh-empty sh-empty-pad"><p>No history recorded yet.</p></div>
  <?php else: ?>
  <ul class="sh-timeline">
    <?php foreach ($timeline as $h): ?>
    <li>
      <strong><?= e(substr((string)$h['created_at'], 0, 16)) ?> — </strong>
      <?= e(($h['from_status'] ? $label((string)$h['from_status']) . ' → ' : '') . $label((string)$h['to_status'])) ?>
      <?php if ((string)($h['actor_name'] ?? '') !== ''): ?>
        · <?= e((string)$h['actor_name']) ?><?php if ((string)($h['actor_role'] ?? '') !== ''): ?> <span class="sh-muted">(<?= e($label((string)$h['actor_role'])) ?>)</span><?php endif; ?>
      <?php endif; ?>
      <?php if ((string)($h['notes'] ?? '') !== ''): ?>
        <p class="sh-cell-sub"><?= e((string)$h['notes']) ?></p>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<?php renderFooter(); ?>
