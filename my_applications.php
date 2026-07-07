<?php
// ═════════════════════════════════════════════════════════════════════════════
//  my_applications.php — Candidate application tracking + offer accept/decline
//  Security: candidate login, CSRF, IDOR-safe (rows scoped to candidate_id).
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
require_once 'includes/recruitment.php';
requireCandidateLogin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

$cand = currentCandidate();

// ── Offer response ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act    = $_POST['form_action'] ?? '';
    $offerId= (int)($_POST['offer_id'] ?? 0);
    // IDOR guard: offer must belong to this candidate
    $offer  = dbFetchOne("SELECT * FROM offers WHERE id=? AND candidate_id=?", 'ii', $offerId, $cand['id']);
    if ($offer && in_array($act,['accept_offer','decline_offer'],true) && $offer['status']==='released') {
        if ($act === 'accept_offer') {
            dbExecute("UPDATE offers SET status='accepted', responded_at=NOW() WHERE id=?", 'i', $offerId);
            addNotification('offer_accepted', 'Offer accepted by candidate for application #'.$offer['application_id'], $cand['id']);
            audit_log('offer_accept','offer',$offerId);
            setFlash('success','Offer accepted! The recruiter has been notified.');
        } else {
            dbExecute("UPDATE offers SET status='declined', responded_at=NOW() WHERE id=?", 'i', $offerId);
            sh_move_stage((int)$offer['application_id'], 'rejected', 'Offer declined by candidate');
            addNotification('offer_declined', 'Offer declined by candidate for application #'.$offer['application_id'], $cand['id']);
            audit_log('offer_decline','offer',$offerId);
            setFlash('success','Offer declined. Thank you for letting us know.');
        }
    } else {
        setFlash('error','That offer can no longer be updated.');
    }
    redirect('my_applications.php');
}

$flash = getFlash();
$apps = dbFetchAll(
    "SELECT a.*, j.title AS job_title, j.location, j.department,
            o.id AS offer_id, o.designation AS offer_desig, o.ctc AS offer_ctc,
            o.joining_date AS offer_join, o.status AS offer_status
     FROM job_applications a
     JOIN jobs j ON j.id=a.job_id
     LEFT JOIN offers o ON o.application_id=a.id
     WHERE a.candidate_id=?
     ORDER BY a.applied_at DESC", 'i', $cand['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>My Applications — SmartHire</title>
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/main.css">
  <link rel="stylesheet" href="assets/css/v7.css">
  <style>
    body{background:linear-gradient(135deg,#0f172a,#1e1b4b);min-height:100vh}
    .cp-header{background:linear-gradient(135deg,#7c3aed,#4338ca);padding:18px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
    .cp-header .brand{display:flex;align-items:center;gap:12px;color:#fff}.cp-header .brand i{font-size:22px}.cp-header .brand h1{font-size:18px;font-weight:700;margin:0}
    .cp-nav{display:flex;gap:6px;flex-wrap:wrap}
    .cp-nav a{color:rgba(255,255,255,.82);text-decoration:none;font-size:13px;font-weight:600;padding:8px 14px;border-radius:9px;transition:all .15s}
    .cp-nav a:hover,.cp-nav a.active{background:rgba(255,255,255,.16);color:#fff}
    .cp-content{max-width:960px;margin:0 auto;padding:26px 20px}
    .cp-title{color:#fff;font-size:22px;font-weight:800;margin:0 0 4px}.cp-sub{color:#94a3b8;font-size:13.5px;margin:0 0 22px}
    .app-card{background:#fff;border-radius:16px;padding:22px;margin-bottom:16px;box-shadow:0 12px 30px -16px rgba(0,0,0,.5)}
    .mini-track{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
    .mini-step{font-size:10.5px;font-weight:700;padding:4px 9px;border-radius:14px;background:#f1f5f9;color:#94a3b8;white-space:nowrap}
    .mini-step.done{background:rgba(16,185,129,.14);color:#059669}
    .mini-step.current{background:var(--sh-grad,linear-gradient(135deg,#7c3aed,#4338ca));color:#fff}
    .offer-box{background:linear-gradient(135deg,rgba(16,185,129,.08),rgba(124,58,237,.08));border:1px solid rgba(16,185,129,.3);border-radius:12px;padding:16px;margin-top:14px}
  </style>
</head>
<body>
<div class="cp-header">
  <div class="brand"><i class="fa-solid fa-bolt"></i><h1>SmartHire</h1></div>
  <nav class="cp-nav">
    <a href="candidate_portal.php"><i class="fa-solid fa-house"></i> Portal</a>
    <a href="careers.php"><i class="fa-solid fa-briefcase"></i> Careers</a>
    <a href="my_applications.php" class="active"><i class="fa-solid fa-list-check"></i> My Applications</a>
    <a href="candidate_logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
  </nav>
</div>

<div class="cp-content">
  <?php if ($flash): ?>
  <div class="alert alert-<?= e($flash['type']) ?>" style="margin-bottom:18px"><i class="fa-solid <?= $flash['type']==='success'?'fa-check-circle':'fa-triangle-exclamation' ?>"></i> <?= e($flash['msg']) ?></div>
  <?php endif; ?>

  <h1 class="cp-title">My Applications</h1>
  <p class="cp-sub">Track where you are in each hiring pipeline.</p>

  <?php if (!$apps): ?>
  <div class="sh-empty" style="color:#94a3b8"><i class="fa-solid fa-list-check"></i><h3 style="color:#cbd5e1">No applications yet</h3>
    <p>Browse open roles and apply to get started.</p>
    <a href="careers.php" class="btn btn-primary"><i class="fa-solid fa-briefcase"></i> Browse Careers</a></div>
  <?php else: foreach ($apps as $a): $ci = sh_stage_index($a['stage']); ?>
  <div class="app-card">
    <div class="sh-between sh-wrap">
      <div>
        <h3 style="margin:0 0 4px;color:#0f172a"><?= e($a['job_title']) ?></h3>
        <p class="sh-muted" style="margin:0;font-size:13px">
          <?php if ($a['department']): ?><i class="fa-solid fa-building"></i> <?= e($a['department']) ?> · <?php endif; ?>
          <i class="fa-regular fa-clock"></i> Applied <?= date('M j, Y', strtotime($a['applied_at'])) ?></p>
      </div>
      <div style="text-align:right">
        <span class="stage-badge stage-<?= sh_stage_color($a['stage']) ?>"><i class="fa-solid <?= sh_stage_icon($a['stage']) ?>"></i> <?= sh_stage_label($a['stage']) ?></span>
        <div class="sh-muted" style="font-size:12px;margin-top:6px">ATS Score: <strong style="color:#7c3aed"><?= (int)$a['ats_score'] ?>%</strong>
          · <a href="candidate_ats_report.php?id=<?= (int)$a['id'] ?>" style="color:#7c3aed;font-weight:700;text-decoration:none">View ATS Report <i class="fa-solid fa-arrow-right" style="font-size:10px"></i></a></div>
      </div>
    </div>

    <?php if ($a['stage']!=='rejected'): ?>
    <div class="mini-track" aria-label="Pipeline progress">
      <?php foreach (sh_stage_flow() as $st): $si=sh_stage_index($st);
        $cls = $si<$ci?'done':($si===$ci?'current':''); ?>
      <span class="mini-step <?= $cls ?>"><?= sh_stage_label($st) ?></span>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="mini-track"><span class="mini-step" style="background:rgba(239,68,68,.14);color:#dc2626">Not selected<?= $a['rejection_reason']?' · '.e($a['rejection_reason']):'' ?></span></div>
    <?php endif; ?>

    <?php if ($a['offer_id'] && $a['offer_status']==='released'): ?>
    <div class="offer-box">
      <div class="sh-between sh-wrap">
        <div>
          <strong style="color:#0f172a"><i class="fa-solid fa-file-signature" style="color:#059669"></i> Offer: <?= e($a['offer_desig']) ?></strong>
          <div class="sh-muted" style="font-size:12.5px;margin-top:4px">
            <?php if ($a['offer_ctc']): ?><i class="fa-solid fa-indian-rupee-sign"></i> <?= number_format((int)$a['offer_ctc']) ?> · <?php endif; ?>
            <?php if ($a['offer_join']): ?>Joining <?= date('M j, Y', strtotime($a['offer_join'])) ?><?php endif; ?>
          </div>
        </div>
        <div class="sh-flex">
          <form method="POST" action="my_applications.php" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="form_action" value="accept_offer"><input type="hidden" name="offer_id" value="<?= (int)$a['offer_id'] ?>">
            <button class="btn btn-sm btn-success" data-confirm="Accept this offer?"><i class="fa-solid fa-check"></i> Accept</button>
          </form>
          <form method="POST" action="my_applications.php" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="form_action" value="decline_offer"><input type="hidden" name="offer_id" value="<?= (int)$a['offer_id'] ?>">
            <button class="btn btn-sm btn-danger" data-confirm="Decline this offer?"><i class="fa-solid fa-xmark"></i> Decline</button>
          </form>
        </div>
      </div>
    </div>
    <?php elseif ($a['offer_id']): ?>
    <div class="offer-box" style="border-color:rgba(124,58,237,.3)">
      <strong style="color:#0f172a"><i class="fa-solid fa-file-signature" style="color:#7c3aed"></i> Offer <?= e(ucfirst($a['offer_status'])) ?></strong> — <?= e($a['offer_desig']) ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>
</div>
<script src="assets/js/v7.js"></script>
</body>
</html>
